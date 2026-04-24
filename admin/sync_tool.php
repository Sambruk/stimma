<?php
/**
 * Stimma - Lär dig i små steg
 * Copyright (C) 2025 Christian Alfredsson
 *
 * This program is free software; licensed under GPL v2.
 * See LICENSE and LICENSE-AND-TRADEMARK.md for details.
 *
 * The name "Stimma" is a trademark and subject to restrictions.
 *
 * Integrerat synkverktyg för användarsynkronisering.
 * Portat från stimma-sync-tool.
 */

require_once '../include/config.php';
require_once '../include/database.php';
require_once '../include/functions.php';
require_once '../include/auth.php';

$page_title = 'Synkverktyg';
require_once 'include/header.php';

// Kräv admin eller super_admin
if (!$isAdmin && !$isSuperAdmin) {
    $_SESSION['message'] = 'Du har inte behörighet att använda synkverktyget.';
    $_SESSION['message_type'] = 'danger';
    header('Location: index.php');
    exit;
}

$userDomain = getUserDomain($_SESSION['user_email']);

// Bestäm organisationens domäner + primärdomän
$userOrg = getOrganizationByDomain($userDomain);
$orgDomains = $userOrg ? getOrganizationDomains($userOrg['id']) : [$userDomain];
$primaryDomain = null;
if ($userOrg) {
    $primRow = queryOne(
        "SELECT domain FROM " . DB_DATABASE . ".organization_domains WHERE organization_id = ? AND is_primary = 1 LIMIT 1",
        [$userOrg['id']]
    );
    $primaryDomain = $primRow ? $primRow['domain'] : null;
}
$isOnPrimaryDomain = $primaryDomain && strtolower($userDomain) === strtolower($primaryDomain);
$canSync = $isSuperAdmin || $isOnPrimaryDomain;
?>

<style>
/* Sync tool styles */
.sync-table th {
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: #6c757d;
    border-bottom-width: 1px;
}
.sync-table td {
    vertical-align: middle;
    font-size: 0.85rem;
}
.sync-table .email-cell {
    font-family: 'SFMono-Regular', Consolas, monospace;
    font-size: 0.82rem;
}
.sync-table .org-cell {
    max-width: 200px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.sync-log {
    max-height: 400px;
    overflow-y: auto;
    font-size: 0.82rem;
}
.sync-log .log-entry {
    padding: 8px 12px;
    border-bottom: 1px solid #eee;
}
.sync-log .log-entry:last-child { border-bottom: none; }
.sync-log .log-time {
    color: #6c757d;
    font-family: monospace;
    font-size: 0.75rem;
}
.sync-log .log-success { border-left: 3px solid #198754; }
.sync-log .log-error { border-left: 3px solid #dc3545; }
.sync-log .log-info { border-left: 3px solid #0d6efd; }
.badge-role-student { background-color: #6c757d; }
.badge-role-teacher { background-color: #0dcaf0; color: #000; }
.badge-role-admin { background-color: #0d6efd; }
.user-row-editing { background-color: #fff3cd !important; }
#syncFormCard.editing .card-header {
    background-color: #ffc107 !important;
    color: #000 !important;
}
.btn-action { padding: 2px 6px; font-size: 0.78rem; }
</style>

<?php if (!$canSync && !$isSuperAdmin): ?>
<div class="alert alert-warning">
    <strong><i class="bi bi-shield-lock me-1"></i>Du kan inte synka från denna domän.</strong>
    Synkverktyget får endast användas av en admin som tillhör organisationens primärdomän
    <?php if ($primaryDomain): ?>(<strong><?= htmlspecialchars($primaryDomain) ?></strong>)<?php endif; ?>.
    Din inloggning: <code><?= htmlspecialchars($userDomain) ?></code>.
    <?php if (!$userOrg): ?>
    <br>Din domän är inte heller grupperad i en organisation — kontakta superadmin.
    <?php endif; ?>
</div>
<?php elseif ($userOrg): ?>
<div class="alert alert-info">
    <i class="bi bi-info-circle me-1"></i>
    Du synkar till alla <strong><?= count($orgDomains) ?></strong> domäner i organisationen
    <strong><?= htmlspecialchars($userOrg['name']) ?></strong>:
    <code><?= htmlspecialchars(implode(', ', $orgDomains)) ?></code>.
    Användare vars e-postdomän inte finns med kommer att hoppas över.
</div>
<?php endif; ?>

<div class="row">
    <!-- Vänster: Användarlista -->
    <div class="col-lg-8 mb-4">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <h6 class="m-0"><i class="bi bi-people me-2"></i>Användare att synka</h6>
                    <span class="badge bg-secondary" id="userCount">0</span>
                    <?php if ($userOrg): ?>
                    <span class="badge bg-primary" title="Organisation">
                        <i class="bi bi-diagram-3 me-1"></i><?= htmlspecialchars($userOrg['name']) ?>
                    </span>
                    <span class="small text-muted" title="Domäner som synkas mot">
                        <?= htmlspecialchars(implode(', ', $orgDomains)) ?>
                    </span>
                    <?php else: ?>
                    <span class="badge bg-secondary"><?= htmlspecialchars($userDomain) ?></span>
                    <?php endif; ?>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <div class="input-group input-group-sm" style="width: 220px;">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" id="searchInput" class="form-control" placeholder="Sök...">
                    </div>
                    <button class="btn btn-sm btn-primary" id="addUserBtn">
                        <i class="bi bi-plus-circle me-1"></i>Lägg till
                    </button>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-secondary" id="importCsvBtn" title="Importera CSV">
                            <i class="bi bi-upload"></i> CSV
                        </button>
                        <button class="btn btn-outline-secondary" id="exportCsvBtn" title="Exportera CSV">
                            <i class="bi bi-download"></i> CSV
                        </button>
                    </div>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-striped sync-table mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 30px;">
                                    <input type="checkbox" class="form-check-input" id="selectAll">
                                </th>
                                <th>E-post</th>
                                <th>Namn</th>
                                <th>Roll</th>
                                <th>Organisation</th>
                                <th style="width: 100px;">Åtgärder</th>
                            </tr>
                        </thead>
                        <tbody id="userTableBody"></tbody>
                    </table>
                </div>
                <div id="emptyState" class="text-center py-5 text-muted">
                    <i class="bi bi-people" style="font-size: 3rem;"></i>
                    <p class="mt-2">Inga användare tillagda ännu.<br>
                    Klicka "Lägg till" eller importera en CSV-fil.</p>
                </div>
            </div>
            <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex gap-2">
                    <button class="btn btn-sm btn-outline-danger" id="deleteSelectedBtn" disabled>
                        <i class="bi bi-trash me-1"></i>Radera valda
                    </button>
                </div>
                <div class="d-flex align-items-center gap-3">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="deactivateToggle">
                        <label class="form-check-label small" for="deactivateToggle">Inaktivera saknade</label>
                    </div>
                    <button class="btn btn-success" id="pushSyncBtn" <?= $canSync ? '' : 'disabled' ?>>
                        <i class="bi bi-arrow-repeat me-2"></i>Synka nu
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Höger: Formulär + Logg -->
    <div class="col-lg-4">
        <!-- Lägg till/redigera -->
        <div class="card mb-4" id="syncFormCard">
            <div class="card-header bg-success text-white">
                <h6 class="m-0" id="formTitle"><i class="bi bi-person-plus me-2"></i>Lägg till användare</h6>
            </div>
            <div class="card-body">
                <form id="userForm">
                    <input type="hidden" id="editIndex" value="-1">
                    <div class="mb-3">
                        <label for="userEmail" class="form-label">E-post <span class="text-danger">*</span></label>
                        <input type="email" id="userEmail" class="form-control form-control-sm"
                               placeholder="anna.svensson@<?= htmlspecialchars($userDomain) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="userName" class="form-label">Namn <span class="text-danger">*</span></label>
                        <input type="text" id="userName" class="form-control form-control-sm" placeholder="Anna Svensson" required>
                    </div>
                    <div class="mb-3">
                        <label for="userRole" class="form-label">Roll</label>
                        <select id="userRole" class="form-select form-select-sm">
                            <option value="student">Student</option>
                            <option value="teacher">Lärare</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="userOrg" class="form-label">Organisation</label>
                        <input type="text" id="userOrg" class="form-control form-control-sm" placeholder="Kommun/Förvaltning/Avdelning">
                        <div class="form-text">Hierarki separerad med / blir taggar i Stimma</div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-success btn-sm flex-fill" id="saveUserBtn">
                            <i class="bi bi-check-circle me-1"></i>Spara
                        </button>
                        <button type="button" class="btn btn-secondary btn-sm" id="cancelEditBtn" style="display:none;">Avbryt</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Synk-logg -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="m-0"><i class="bi bi-journal-text me-2"></i>Synk-logg</h6>
                <button class="btn btn-sm btn-outline-secondary" id="clearLogBtn" title="Rensa logg">
                    <i class="bi bi-x-circle"></i>
                </button>
            </div>
            <div class="card-body p-0">
                <div id="syncLog" class="sync-log">
                    <div class="text-center text-muted py-4">
                        <small>Ingen synk utförd ännu</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- CSV Import Modal -->
<div class="modal fade" id="csvImportModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-upload me-2"></i>Importera CSV</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <strong>CSV-format:</strong> email, namn, roll, organisation<br>
                    <small>Första raden hoppas över om den innehåller rubriker.</small>
                </div>
                <div class="mb-3">
                    <label class="form-label">Välj CSV-fil</label>
                    <input type="file" id="csvFileInput" class="form-control" accept=".csv,.txt">
                </div>
                <div class="mb-3">
                    <label class="form-label">Avgränsare</label>
                    <select id="csvDelimiter" class="form-select form-select-sm">
                        <option value=";">Semikolon (;)</option>
                        <option value=",">Komma (,)</option>
                        <option value="\t">Tab</option>
                    </select>
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="csvReplace">
                    <label class="form-check-label" for="csvReplace">Ersätt befintlig lista (annars läggs till)</label>
                </div>
                <div id="csvPreview" class="d-none">
                    <hr>
                    <p class="fw-bold mb-2">Förhandsvisning (5 första):</p>
                    <div id="csvPreviewTable"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Avbryt</button>
                <button type="button" class="btn btn-primary" id="csvImportConfirmBtn" disabled>
                    <i class="bi bi-upload me-1"></i>Importera
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Push Confirmation Modal -->
<div class="modal fade" id="pushModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-arrow-repeat me-2"></i>Synka användare</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="pushDeactivateWarning" class="alert alert-warning d-none">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <strong>Observera:</strong> Synkade användare i domänen som <em>inte</em> finns i denna lista
                    markeras som <code>sync_status='inactive'</code>. De kan fortfarande logga in.
                </div>
                <div id="pushTestModeInfo" class="alert alert-info">
                    <i class="bi bi-shield-check me-2"></i>
                    <strong>Säkert läge:</strong> Inaktivering av saknade användare är <strong>avstängt</strong>.
                    Bara nya/uppdaterade användare berörs.
                </div>
                <p>Du synkar <strong id="pushCount">0</strong> användare till organisationens domäner. Varje användares e-post måste tillhöra en av domänerna — övriga hoppas över.</p>
                <p class="text-muted small mb-0">Synkroniseringen sker direkt mot databasen (ingen API-nyckel behövs).</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Avbryt</button>
                <button type="button" class="btn btn-success" id="confirmPushBtn">
                    <i class="bi bi-arrow-repeat me-1"></i>Synka nu
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';

    const STORAGE_KEY = 'stimma_sync_tool_users';
    let users = loadUsers();
    let csvParsedData = null;

    const $ = (sel) => document.querySelector(sel);
    const $$ = (sel) => document.querySelectorAll(sel);

    // DOM refs
    const userTableBody = $('#userTableBody');
    const userCountBadge = $('#userCount');
    const emptyState = $('#emptyState');
    const searchInput = $('#searchInput');
    const userForm = $('#userForm');
    const editIndexInput = $('#editIndex');
    const formCard = $('#syncFormCard');
    const formTitle = $('#formTitle');
    const cancelEditBtn = $('#cancelEditBtn');
    const deleteSelectedBtn = $('#deleteSelectedBtn');
    const selectAllCheckbox = $('#selectAll');
    const syncLogEl = $('#syncLog');

    // Init
    renderTable();

    // Event listeners
    $('#addUserBtn').addEventListener('click', () => resetForm());
    userForm.addEventListener('submit', handleFormSubmit);
    cancelEditBtn.addEventListener('click', resetForm);
    searchInput.addEventListener('input', renderTable);
    selectAllCheckbox.addEventListener('change', handleSelectAll);
    deleteSelectedBtn.addEventListener('click', deleteSelected);
    $('#importCsvBtn').addEventListener('click', () => new bootstrap.Modal($('#csvImportModal')).show());
    $('#exportCsvBtn').addEventListener('click', exportCsv);
    $('#csvFileInput').addEventListener('change', handleCsvFile);
    $('#csvDelimiter').addEventListener('change', () => { if (csvParsedData) previewCsv(); });
    $('#csvImportConfirmBtn').addEventListener('click', confirmCsvImport);
    $('#pushSyncBtn').addEventListener('click', openPushModal);
    $('#confirmPushBtn').addEventListener('click', executePush);
    $('#clearLogBtn').addEventListener('click', clearLog);

    // Persistence (localStorage)
    function loadUsers() {
        try { return JSON.parse(localStorage.getItem(STORAGE_KEY)) || []; }
        catch { return []; }
    }
    function saveUsers() {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(users));
    }

    // Render
    function renderTable() {
        const search = searchInput.value.toLowerCase().trim();
        const filtered = users.map((u, i) => ({...u, _idx: i}))
            .filter(u => {
                if (!search) return true;
                return u.email.toLowerCase().includes(search) ||
                       u.name.toLowerCase().includes(search) ||
                       (u.organization || '').toLowerCase().includes(search);
            });

        userCountBadge.textContent = users.length;
        emptyState.style.display = users.length === 0 ? '' : 'none';
        userTableBody.closest('.table-responsive').style.display = users.length === 0 ? 'none' : '';

        const roleBadge = (role) => ({
            student: '<span class="badge badge-role-student">Student</span>',
            teacher: '<span class="badge badge-role-teacher">Lärare</span>',
            admin: '<span class="badge badge-role-admin">Admin</span>'
        }[role] || '<span class="badge badge-role-student">Student</span>');

        userTableBody.innerHTML = filtered.map(u => `
            <tr data-idx="${u._idx}" class="${parseInt(editIndexInput.value) === u._idx ? 'user-row-editing' : ''}">
                <td><input type="checkbox" class="form-check-input row-check" data-idx="${u._idx}"></td>
                <td class="email-cell">${esc(u.email)}</td>
                <td>${esc(u.name)}</td>
                <td>${roleBadge(u.role)}</td>
                <td class="org-cell" title="${esc(u.organization || '')}">${esc(u.organization || '-')}</td>
                <td>
                    <button class="btn btn-sm btn-outline-primary btn-action me-1 edit-btn" data-idx="${u._idx}" title="Redigera"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-sm btn-outline-danger btn-action delete-btn" data-idx="${u._idx}" title="Radera"><i class="bi bi-trash"></i></button>
                </td>
            </tr>
        `).join('');

        $$('.edit-btn').forEach(b => b.addEventListener('click', () => editUser(parseInt(b.dataset.idx))));
        $$('.delete-btn').forEach(b => b.addEventListener('click', () => deleteUser(parseInt(b.dataset.idx))));
        $$('.row-check').forEach(cb => cb.addEventListener('change', updateDeleteBtn));
        selectAllCheckbox.checked = false;
        updateDeleteBtn();
    }

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    // CRUD
    function handleFormSubmit(e) {
        e.preventDefault();
        const email = $('#userEmail').value.trim().toLowerCase();
        const name = $('#userName').value.trim();
        const role = $('#userRole').value;
        const organization = $('#userOrg').value.trim();
        if (!email || !name) return;

        const idx = parseInt(editIndexInput.value);
        if (idx >= 0) {
            users[idx] = {email, name, role, organization};
        } else {
            if (users.some(u => u.email === email)) {
                alert('En användare med e-post ' + email + ' finns redan i listan.');
                return;
            }
            users.push({email, name, role, organization});
        }
        saveUsers();
        resetForm();
        renderTable();
    }

    function editUser(idx) {
        const u = users[idx];
        if (!u) return;
        editIndexInput.value = idx;
        $('#userEmail').value = u.email;
        $('#userName').value = u.name;
        $('#userRole').value = u.role || 'student';
        $('#userOrg').value = u.organization || '';
        formTitle.innerHTML = '<i class="bi bi-pencil me-2"></i>Redigera användare';
        formCard.classList.add('editing');
        cancelEditBtn.style.display = '';
        $('#saveUserBtn').innerHTML = '<i class="bi bi-check-circle me-1"></i>Uppdatera';
        renderTable();
        formCard.scrollIntoView({behavior: 'smooth', block: 'start'});
    }

    function deleteUser(idx) {
        if (!confirm('Radera ' + users[idx].email + '?')) return;
        users.splice(idx, 1);
        saveUsers();
        resetForm();
        renderTable();
    }

    function resetForm() {
        userForm.reset();
        editIndexInput.value = -1;
        formTitle.innerHTML = '<i class="bi bi-person-plus me-2"></i>Lägg till användare';
        formCard.classList.remove('editing');
        cancelEditBtn.style.display = 'none';
        $('#saveUserBtn').innerHTML = '<i class="bi bi-check-circle me-1"></i>Spara';
        renderTable();
    }

    // Select / delete multiple
    function handleSelectAll() {
        const checked = selectAllCheckbox.checked;
        $$('.row-check').forEach(cb => { cb.checked = checked; });
        updateDeleteBtn();
    }

    function updateDeleteBtn() {
        const count = $$('.row-check:checked').length;
        deleteSelectedBtn.disabled = count === 0;
        deleteSelectedBtn.innerHTML = count > 0
            ? `<i class="bi bi-trash me-1"></i>Radera valda (${count})`
            : '<i class="bi bi-trash me-1"></i>Radera valda';
    }

    function deleteSelected() {
        const indices = Array.from($$('.row-check:checked'))
            .map(cb => parseInt(cb.dataset.idx))
            .sort((a, b) => b - a);
        if (indices.length === 0) return;
        if (!confirm('Radera ' + indices.length + ' användare?')) return;
        indices.forEach(i => users.splice(i, 1));
        saveUsers();
        resetForm();
        renderTable();
    }

    // CSV Import/Export
    function handleCsvFile(e) {
        const file = e.target.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = function(ev) {
            csvParsedData = ev.target.result;
            previewCsv();
        };
        reader.readAsText(file, 'UTF-8');
    }

    function previewCsv() {
        if (!csvParsedData) return;
        let delim = $('#csvDelimiter').value;
        if (delim === '\\t') delim = '\t';

        const lines = csvParsedData.split(/\r?\n/).filter(l => l.trim());
        if (lines.length === 0) return;

        const first = lines[0].toLowerCase();
        const hasHeader = first.includes('email') || first.includes('e-post') || first.includes('namn');
        const dataLines = hasHeader ? lines.slice(1) : lines;
        const parsed = dataLines.map(line => {
            const cols = line.split(delim).map(c => c.trim().replace(/^["']|["']$/g, ''));
            return {email: cols[0] || '', name: cols[1] || '', role: cols[2] || 'student', organization: cols[3] || ''};
        }).filter(u => u.email);

        const preview = parsed.slice(0, 5);
        let html = '<table class="table table-sm table-bordered mb-0"><thead><tr><th>E-post</th><th>Namn</th><th>Roll</th><th>Organisation</th></tr></thead><tbody>';
        preview.forEach(u => {
            html += `<tr><td>${esc(u.email)}</td><td>${esc(u.name)}</td><td>${esc(u.role)}</td><td>${esc(u.organization)}</td></tr>`;
        });
        html += '</tbody></table>';
        if (parsed.length > 5) html += `<small class="text-muted">...och ${parsed.length - 5} fler</small>`;

        $('#csvPreviewTable').innerHTML = html;
        $('#csvPreview').classList.remove('d-none');
        $('#csvImportConfirmBtn').disabled = false;
    }

    function confirmCsvImport() {
        if (!csvParsedData) return;
        let delim = $('#csvDelimiter').value;
        if (delim === '\\t') delim = '\t';

        const lines = csvParsedData.split(/\r?\n/).filter(l => l.trim());
        const first = lines[0].toLowerCase();
        const hasHeader = first.includes('email') || first.includes('e-post') || first.includes('namn');
        const dataLines = hasHeader ? lines.slice(1) : lines;

        const parsed = dataLines.map(line => {
            const cols = line.split(delim).map(c => c.trim().replace(/^["']|["']$/g, ''));
            return {
                email: (cols[0] || '').toLowerCase(),
                name: cols[1] || '',
                role: normalizeRole(cols[2] || 'student'),
                organization: cols[3] || ''
            };
        }).filter(u => u.email && u.email.includes('@'));

        if ($('#csvReplace').checked) {
            users = parsed;
        } else {
            const existing = new Set(users.map(u => u.email));
            parsed.forEach(u => {
                if (!existing.has(u.email)) {
                    users.push(u);
                    existing.add(u.email);
                }
            });
        }

        saveUsers();
        renderTable();
        bootstrap.Modal.getInstance($('#csvImportModal')).hide();
        csvParsedData = null;
        $('#csvFileInput').value = '';
        $('#csvPreview').classList.add('d-none');
        $('#csvImportConfirmBtn').disabled = true;
        addLogEntry('info', `Importerade ${parsed.length} användare från CSV`);
    }

    function normalizeRole(r) {
        r = r.toLowerCase().trim();
        if (r === 'lärare' || r === 'teacher' || r === 'larare') return 'teacher';
        if (r === 'admin' || r === 'administrator') return 'admin';
        return 'student';
    }

    function exportCsv() {
        if (users.length === 0) { alert('Inga användare att exportera.'); return; }
        let csv = 'email;namn;roll;organisation\n';
        users.forEach(u => { csv += `${u.email};${u.name};${u.role};${u.organization || ''}\n`; });

        const blob = new Blob(['\uFEFF' + csv], {type: 'text/csv;charset=utf-8;'});
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'stimma_users_' + new Date().toISOString().slice(0, 10) + '.csv';
        a.click();
        URL.revokeObjectURL(a.href);
        addLogEntry('info', `Exporterade ${users.length} användare till CSV`);
    }

    // Push / Sync
    function openPushModal() {
        if (users.length === 0) {
            alert('Inga användare att synka. Lägg till användare eller importera CSV först.');
            return;
        }

        const deactivate = $('#deactivateToggle').checked;
        $('#pushCount').textContent = users.length;

        if (deactivate) {
            $('#pushDeactivateWarning').classList.remove('d-none');
            $('#pushTestModeInfo').classList.add('d-none');
        } else {
            $('#pushDeactivateWarning').classList.add('d-none');
            $('#pushTestModeInfo').classList.remove('d-none');
        }

        new bootstrap.Modal($('#pushModal')).show();
    }

    async function executePush() {
        const btn = $('#confirmPushBtn');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Synkar...';

        const deactivate = $('#deactivateToggle').checked;

        // Skicka e-postadresserna som de är — servern validerar domän mot orgen
        const mappedUsers = users.map(u => ({
            email: u.email,
            name: u.name,
            role: u.role || 'student',
            organization: u.organization || ''
        }));

        addLogEntry('info', `Synkar ${mappedUsers.length} användare till organisationen...`);

        try {
            const resp = await fetch('ajax/sync_users_direct.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': CSRF_TOKEN
                },
                body: JSON.stringify({
                    users: mappedUsers,
                    deactivate_missing: deactivate
                })
            });

            const data = await resp.json();

            if (data.success) {
                const s = data.summary;
                addLogEntry('success',
                    `Synk klar mot ${data.domains.length} domän(er)! Skapade: ${s.created}, Uppdaterade: ${s.updated}, ` +
                    `Inaktiverade: ${s.deactivated}, Reaktiverade: ${s.reactivated}`
                );
                if (data.skipped_count > 0) {
                    addLogEntry('error',
                        `⚠ ${data.skipped_count} användare hoppades över — deras e-postdomän tillhör inte organisationen. ` +
                        `Först 10: ${data.skipped_emails.slice(0, 10).join(', ')}`
                    );
                }
            } else {
                let msg = `Fel: ${data.error || 'Okänt fel'}`;
                if (data.validation_errors) {
                    msg += '\n' + data.validation_errors.slice(0, 5).join('\n');
                }
                if (data.skipped_count) {
                    msg += `\n${data.skipped_count} användare skippade (fel domän).`;
                }
                addLogEntry('error', msg);
            }
        } catch (err) {
            addLogEntry('error', 'Nätverksfel: ' + err.message);
        }

        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i>Synka nu';
        bootstrap.Modal.getInstance($('#pushModal')).hide();
    }

    // Log
    function addLogEntry(type, message) {
        const now = new Date().toLocaleTimeString('sv-SE');
        const cls = type === 'success' ? 'log-success' : type === 'error' ? 'log-error' : 'log-info';
        const icon = type === 'success' ? 'check-circle-fill text-success' :
                     type === 'error' ? 'x-circle-fill text-danger' : 'info-circle-fill text-primary';

        if (syncLogEl.querySelector('.text-center')) syncLogEl.innerHTML = '';

        const entry = document.createElement('div');
        entry.className = 'log-entry ' + cls;
        entry.innerHTML = `
            <div class="d-flex justify-content-between align-items-start">
                <div><i class="bi bi-${icon} me-1"></i>${esc(message).replace(/\n/g, '<br>')}</div>
                <span class="log-time ms-2">${now}</span>
            </div>`;
        syncLogEl.prepend(entry);
    }

    function clearLog() {
        syncLogEl.innerHTML = '<div class="text-center text-muted py-4"><small>Ingen synk utförd ännu</small></div>';
    }

})();
</script>

<?php require_once 'include/footer.php'; ?>
