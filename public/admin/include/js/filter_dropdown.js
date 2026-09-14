/**
 * Filterlistorna för org-taggar och domäner på adminens statistik- och
 * användarsidor.
 *
 * - Ett val slår igenom direkt. Tidigare krävdes "Tillämpa" längst ned i listan,
 *   vilket betydde mycket scrollande när en organisation har 50+ taggar.
 * - Listan öppnas igen efter omladdningen, på samma scrollposition och med samma
 *   sökning, så att man kan välja flera taggar i följd.
 * - Långa listor får ett sökfält som stannar kvar överst när listan scrollas.
 *
 * Kopplar in sig på varje dropdown-meny i ett GET-formulär som innehåller
 * kryssrutor för org_tags[] eller domains[]. Utan JavaScript fungerar
 * formuläret som förut, med Tillämpa-knappen.
 */
(function () {
    'use strict';

    var STORE_KEY = 'stimmaFilterDropdown';
    var SEARCH_MIN = 8;       // sökfält först när listan är så här lång
    var SUBMIT_DELAY = 350;   // ms, låter snabba klick på flera rutor bli ett anrop
    var RESTORE_MAX_AGE = 15000;

    function save(state) {
        try { sessionStorage.setItem(STORE_KEY, JSON.stringify(state)); } catch (e) { /* ingen lagring, inget återställt läge */ }
    }

    function takeSaved() {
        try {
            var raw = sessionStorage.getItem(STORE_KEY);
            sessionStorage.removeItem(STORE_KEY);
            return raw ? JSON.parse(raw) : null;
        } catch (e) {
            return null;
        }
    }

    var saved = takeSaved();
    if (saved && Date.now() - saved.time > RESTORE_MAX_AGE) {
        saved = null;
    }

    var menus = [];
    document.querySelectorAll(
        '.dropdown-menu input[type="checkbox"][name="org_tags[]"], .dropdown-menu input[type="checkbox"][name="domains[]"]'
    ).forEach(function (box) {
        var menu = box.closest('.dropdown-menu');
        if (menus.indexOf(menu) === -1) {
            menus.push(menu);
        }
    });

    menus.forEach(function (menu) {
        var form = menu.closest('form');
        if (!form || (form.getAttribute('method') || 'get').toLowerCase() !== 'get') {
            return;
        }
        var boxes = menu.querySelectorAll('input[type="checkbox"]');
        var fieldName = boxes[0].name;
        var key = location.pathname + '|' + fieldName;
        var toggle = menu.parentElement.querySelector('[data-bs-toggle="dropdown"]');
        var isTags = fieldName === 'org_tags[]';

        // Tillämpa behövs inte när valet slår igenom direkt. Rensa ligger kvar.
        var applyButton = menu.querySelector('button[type="submit"]');
        if (applyButton) {
            applyButton.hidden = true;
        }

        var search = null;
        if (boxes.length >= SEARCH_MIN) {
            var wrap = document.createElement('div');
            wrap.className = 'bg-body';
            // Menyn har p-2 (0.5rem); sticky top med negativ marginal täcker paddingen
            // så att rader inte syns ovanför fältet när listan scrollas.
            wrap.style.cssText = 'position:sticky;top:-0.5rem;z-index:1;margin:-0.5rem -0.5rem 0.25rem;padding:0.5rem 0.5rem 0.25rem;';
            search = document.createElement('input');
            search.type = 'search';
            search.className = 'form-control form-control-sm';
            search.placeholder = isTags ? 'Sök tagg …' : 'Sök domän …';
            search.setAttribute('aria-label', isTags ? 'Sök bland organisationstaggarna' : 'Sök bland domänerna');
            search.autocomplete = 'off';
            wrap.appendChild(search);
            menu.insertBefore(wrap, menu.firstChild);

            var noHits = document.createElement('div');
            noHits.className = 'small text-muted px-1';
            noHits.textContent = 'Inga träffar';
            noHits.hidden = true;
            noHits.setAttribute('role', 'status');
            boxes[boxes.length - 1].closest('.form-check').after(noHits);

            var applySearch = function () {
                var needle = search.value.trim().toLocaleLowerCase('sv');
                var visible = 0;
                boxes.forEach(function (box) {
                    var row = box.closest('.form-check');
                    var hit = needle === '' || row.textContent.toLocaleLowerCase('sv').indexOf(needle) !== -1;
                    row.hidden = !hit;
                    if (hit) {
                        visible++;
                    }
                });
                noHits.hidden = visible > 0;
            };
            search.addEventListener('input', applySearch);
            // Enter i sökfältet ska inte skicka formuläret.
            search.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                }
            });
            search._applySearch = applySearch;
        }

        var timer = null;
        boxes.forEach(function (box) {
            box.addEventListener('change', function () {
                clearTimeout(timer);
                timer = setTimeout(function () {
                    save({
                        key: key,
                        time: Date.now(),
                        scrollTop: menu.scrollTop,
                        search: search ? search.value : ''
                    });
                    menu.setAttribute('aria-busy', 'true');
                    menu.style.opacity = '0.6';
                    form.submit();
                }, SUBMIT_DELAY);
            });
        });

        if (saved && saved.key === key && toggle && window.bootstrap) {
            bootstrap.Dropdown.getOrCreateInstance(toggle).show();
            if (search && saved.search) {
                search.value = saved.search;
                search._applySearch();
            }
            menu.scrollTop = saved.scrollTop || 0;
        }
    });
})();
