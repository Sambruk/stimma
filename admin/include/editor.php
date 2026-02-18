<?php
/**
 * Stimma - Lär dig i små steg
 * Copyright (C) 2025 Christian Alfredsson
 *
 * This program is free software; licensed under GPL v2.
 * See LICENSE and LICENSE-AND-TRADEMARK.md for details.
 *
 * The name "Stimma" is a trademark and subject to restrictions.
 */
?>

<?php
/**
 * Enkel texteditor med grundläggande formatering
 *
 * @param string $content Innehållet som ska redigeras
 * @param string $name Namnet på input-fältet
 * @param string $id ID för editorn (default: 'editor')
 */
function renderEditor($content, $name = 'content', $id = 'editor') {
    ?>
    <div class="editor-toolbar">
        <button type="button" onclick="formatText('bold', '<?= $id ?>')" title="Fet">
            <i class="bi bi-type-bold"></i>
        </button>
        <button type="button" onclick="formatText('italic', '<?= $id ?>')" title="Kursiv">
            <i class="bi bi-type-italic"></i>
        </button>
        <button type="button" onclick="formatText('underline', '<?= $id ?>')" title="Understruken">
            <i class="bi bi-type-underline"></i>
        </button>
        <button type="button" onclick="formatList('bullet', '<?= $id ?>')" title="Punktlista">
            <i class="bi bi-list-ul"></i>
        </button>
        <button type="button" onclick="formatList('number', '<?= $id ?>')" title="Nummerlista">
            <i class="bi bi-list-ol"></i>
        </button>
        <span class="editor-separator"></span>
        <button type="button" onclick="editorInsertImage('<?= $id ?>')" title="Infoga bild">
            <i class="bi bi-image"></i>
        </button>
        <button type="button" onclick="cleanFormat('<?= $id ?>')" title="Rensa format">
            <i class="bi bi-eraser"></i>
        </button>
    </div>
    <div id="<?= $id ?>" class="editor-content" contenteditable="true"><?= $content ?></div>
    <input type="hidden" name="<?= $name ?>" id="<?= $id ?>Input">
    <input type="file" id="<?= $id ?>FileInput" accept="image/jpeg,image/png,image/gif" style="display:none">
    <?php
}
?>

<style>
.editor-toolbar {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-bottom: none;
    padding: 0.5rem;
    border-radius: 0.25rem 0.25rem 0 0;
    display: flex;
    gap: 0.25rem;
    flex-wrap: wrap;
    align-items: center;
}
.editor-toolbar button {
    background: none;
    border: none;
    padding: 0.25rem 0.5rem;
    border-radius: 0.25rem;
    color: var(--dark-gray);
}
.editor-toolbar button:hover {
    background: #e9ecef;
}
.editor-toolbar button.active {
    background: #e9ecef;
}
.editor-separator {
    width: 1px;
    height: 1.2rem;
    background: #dee2e6;
    margin: 0 0.15rem;
}
.editor-content {
    border: 1px solid #dee2e6;
    padding: 1rem;
    min-height: 300px;
    border-radius: 0 0 0.25rem 0.25rem;
    line-height: 1.6;
}
.editor-content:focus {
    outline: none;
    border-color: var(--accent-blue);
}

/* Bilder i editorn */
.editor-content img {
    max-width: 100%;
    height: auto;
    border-radius: 0.25rem;
    cursor: pointer;
}
.editor-content img.editor-img-selected {
    outline: 2px solid var(--accent-blue, #0F3B5F);
    outline-offset: 2px;
}

/* Bildstorlekar */
.editor-content img.img-sm,
.content img.img-sm { width: 25%; }
.editor-content img.img-md,
.content img.img-md { width: 50%; }
.editor-content img.img-lg,
.content img.img-lg { width: 75%; }
.editor-content img.img-full,
.content img.img-full { width: 100%; }

/* Bildplacering */
.editor-content img.img-left,
.content img.img-left { float: left; margin: 0 1rem 0.75rem 0; }
.editor-content img.img-center,
.content img.img-center { display: block; margin: 0.75rem auto; float: none; }
.editor-content img.img-right,
.content img.img-right { float: right; margin: 0 0 0.75rem 1rem; }

/* Bildverktygsfält */
.editor-img-toolbar {
    position: absolute;
    background: #fff;
    border: 1px solid #dee2e6;
    border-radius: 0.5rem;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    padding: 0.4rem;
    display: flex;
    gap: 0.15rem;
    z-index: 1000;
    flex-wrap: wrap;
    max-width: 320px;
}
.editor-img-toolbar .img-tb-group {
    display: flex;
    gap: 0.1rem;
    align-items: center;
}
.editor-img-toolbar .img-tb-sep {
    width: 1px;
    height: 1.4rem;
    background: #dee2e6;
    margin: 0 0.2rem;
}
.editor-img-toolbar button {
    background: none;
    border: 1px solid transparent;
    padding: 0.2rem 0.4rem;
    border-radius: 0.25rem;
    font-size: 0.8rem;
    color: #495057;
    cursor: pointer;
    white-space: nowrap;
}
.editor-img-toolbar button:hover {
    background: #e9ecef;
}
.editor-img-toolbar button.active {
    background: #e2e6ea;
    border-color: #adb5bd;
}
.editor-img-toolbar button.img-tb-delete {
    color: #dc3545;
}
.editor-img-toolbar button.img-tb-delete:hover {
    background: #f8d7da;
}
</style>

<script>
function formatText(command, editorId) {
    document.execCommand(command, false, null);
    document.getElementById(editorId).focus();
}

function formatList(type, editorId) {
    document.execCommand(type === 'bullet' ? 'insertUnorderedList' : 'insertOrderedList', false, null);
    document.getElementById(editorId).focus();
}

function cleanFormat(editorId) {
    const editor = document.getElementById(editorId);
    const content = editor.innerHTML;

    const tempDiv = document.createElement('div');
    tempDiv.innerHTML = content;

    const elements = tempDiv.getElementsByTagName('*');
    for (let element of elements) {
        if (!['B', 'STRONG', 'I', 'EM', 'U', 'UL', 'OL', 'LI', 'BR', 'P', 'DIV', 'IMG'].includes(element.tagName)) {
            const parent = element.parentNode;
            while (element.firstChild) {
                parent.insertBefore(element.firstChild, element);
            }
            parent.removeChild(element);
        } else if (element.tagName === 'IMG') {
            // Behåll src, alt och tillåtna klasser på bilder
            var src = element.getAttribute('src') || '';
            var alt = element.getAttribute('alt') || '';
            var cls = element.getAttribute('class') || '';
            var allowed = ['img-sm','img-md','img-lg','img-full','img-left','img-center','img-right'];
            var filtered = cls.split(/\s+/).filter(function(c) { return allowed.indexOf(c) !== -1; }).join(' ');
            // Ta bort alla attribut
            while (element.attributes.length > 0) {
                element.removeAttribute(element.attributes[0].name);
            }
            if (src) element.setAttribute('src', src);
            if (alt) element.setAttribute('alt', alt);
            if (filtered) element.setAttribute('class', filtered);
        } else {
            const attributes = element.attributes;
            for (let i = attributes.length - 1; i >= 0; i--) {
                const attr = attributes[i];
                if (attr.name !== 'style') {
                    element.removeAttribute(attr.name);
                }
            }
            if (element.style) {
                const allowedStyles = ['font-weight', 'font-style', 'text-decoration'];
                for (let style of element.style) {
                    if (!allowedStyles.includes(style)) {
                        element.style[style] = '';
                    }
                }
            }
        }
    }

    editor.innerHTML = tempDiv.innerHTML;
    editor.focus();
}

/* --- Bildhantering i editor --- */

function editorInsertImage(editorId) {
    var fileInput = document.getElementById(editorId + 'FileInput');
    fileInput.onchange = function() {
        if (!this.files || !this.files[0]) return;
        var file = this.files[0];

        if (file.size > 5 * 1024 * 1024) {
            alert('Bilden är för stor. Max 5 MB.');
            this.value = '';
            return;
        }

        var formData = new FormData();
        formData.append('image', file);
        formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);

        var editor = document.getElementById(editorId);
        // Visa laddningsindikator
        var placeholder = document.createElement('span');
        placeholder.textContent = 'Laddar upp bild...';
        placeholder.style.cssText = 'color:#6c757d;font-style:italic;';
        editor.appendChild(placeholder);

        fetch('upload_image.php', { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (placeholder.parentNode) placeholder.parentNode.removeChild(placeholder);
                if (data.success) {
                    var img = document.createElement('img');
                    img.src = '../upload/' + data.url;
                    img.alt = 'Bild';
                    img.className = 'img-md img-center';
                    editor.appendChild(document.createElement('br'));
                    editor.appendChild(img);
                    editor.appendChild(document.createElement('br'));
                    editorSelectImage(img, editorId);
                } else {
                    alert(data.error || 'Kunde inte ladda upp bilden.');
                }
            })
            .catch(function() {
                if (placeholder.parentNode) placeholder.parentNode.removeChild(placeholder);
                alert('Ett fel uppstod vid uppladdning.');
            });

        this.value = '';
    };
    fileInput.click();
}

// Aktuellt vald bild och verktygsfält
var _editorSelectedImg = null;
var _editorImgToolbar = null;

function editorSelectImage(img, editorId) {
    editorDeselectImage();
    _editorSelectedImg = img;
    img.classList.add('editor-img-selected');
    showImageToolbar(img, editorId);
}

function editorDeselectImage() {
    if (_editorSelectedImg) {
        _editorSelectedImg.classList.remove('editor-img-selected');
        _editorSelectedImg = null;
    }
    hideImageToolbar();
}

function showImageToolbar(img, editorId) {
    hideImageToolbar();
    var tb = document.createElement('div');
    tb.className = 'editor-img-toolbar';

    var sizes = [
        { cls: 'img-sm', label: 'S', title: 'Liten (25%)' },
        { cls: 'img-md', label: 'M', title: 'Medel (50%)' },
        { cls: 'img-lg', label: 'L', title: 'Stor (75%)' },
        { cls: 'img-full', label: '100%', title: 'Fullbredd' }
    ];
    var aligns = [
        { cls: 'img-left', icon: 'bi-text-left', title: 'Vänster' },
        { cls: 'img-center', icon: 'bi-text-center', title: 'Centrerad' },
        { cls: 'img-right', icon: 'bi-text-right', title: 'Höger' }
    ];

    // Storleksknappar
    var sizeGroup = document.createElement('span');
    sizeGroup.className = 'img-tb-group';
    sizes.forEach(function(s) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = s.label;
        btn.title = s.title;
        if (img.classList.contains(s.cls)) btn.classList.add('active');
        btn.onclick = function(e) {
            e.stopPropagation();
            sizes.forEach(function(x) { img.classList.remove(x.cls); });
            img.classList.add(s.cls);
            showImageToolbar(img, editorId);
        };
        sizeGroup.appendChild(btn);
    });
    tb.appendChild(sizeGroup);

    // Separator
    var sep = document.createElement('span');
    sep.className = 'img-tb-sep';
    tb.appendChild(sep);

    // Placeringsknappar
    var alignGroup = document.createElement('span');
    alignGroup.className = 'img-tb-group';
    aligns.forEach(function(a) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.innerHTML = '<i class="bi ' + a.icon + '"></i>';
        btn.title = a.title;
        if (img.classList.contains(a.cls)) btn.classList.add('active');
        btn.onclick = function(e) {
            e.stopPropagation();
            aligns.forEach(function(x) { img.classList.remove(x.cls); });
            img.classList.add(a.cls);
            showImageToolbar(img, editorId);
        };
        alignGroup.appendChild(btn);
    });
    tb.appendChild(alignGroup);

    // Separator
    var sep2 = document.createElement('span');
    sep2.className = 'img-tb-sep';
    tb.appendChild(sep2);

    // Ta bort-knapp
    var delBtn = document.createElement('button');
    delBtn.type = 'button';
    delBtn.innerHTML = '<i class="bi bi-trash"></i>';
    delBtn.title = 'Ta bort bild';
    delBtn.className = 'img-tb-delete';
    delBtn.onclick = function(e) {
        e.stopPropagation();
        img.parentNode.removeChild(img);
        editorDeselectImage();
    };
    tb.appendChild(delBtn);

    document.body.appendChild(tb);
    _editorImgToolbar = tb;

    // Positionera ovanför bilden
    positionImageToolbar(img, tb);
}

function positionImageToolbar(img, tb) {
    var rect = img.getBoundingClientRect();
    var tbRect = tb.getBoundingClientRect();
    var left = rect.left + (rect.width - tbRect.width) / 2;
    if (left < 4) left = 4;
    if (left + tbRect.width > window.innerWidth - 4) left = window.innerWidth - tbRect.width - 4;
    var top = rect.top + window.scrollY - tbRect.height - 8;
    if (top < window.scrollY + 4) top = rect.bottom + window.scrollY + 8;
    tb.style.position = 'absolute';
    tb.style.left = left + 'px';
    tb.style.top = top + 'px';
}

function hideImageToolbar() {
    if (_editorImgToolbar && _editorImgToolbar.parentNode) {
        _editorImgToolbar.parentNode.removeChild(_editorImgToolbar);
    }
    _editorImgToolbar = null;
}

// Spara innehållet i hidden input innan formuläret skickas
document.addEventListener('DOMContentLoaded', function() {
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function() {
            // Hitta alla editorer i formuläret
            const editors = form.querySelectorAll('.editor-content');
            editors.forEach(editor => {
                const editorId = editor.id;
                const inputId = editorId + 'Input';
                const input = document.getElementById(inputId);
                if (input) {
                    // Ta bort editor-img-selected-klass före sparning
                    editor.querySelectorAll('.editor-img-selected').forEach(function(el) {
                        el.classList.remove('editor-img-selected');
                    });
                    input.value = editor.innerHTML;
                }
            });
        });
    });

    // Lägg till paste-hanterare för alla editorer
    const editors = document.querySelectorAll('.editor-content');
    editors.forEach(editor => {
        editor.addEventListener('paste', function(e) {
            setTimeout(() => {
                cleanFormat(editor.id);
            }, 0);
        });

        // Klick på bild i editor → visa verktygsfält
        editor.addEventListener('click', function(e) {
            if (e.target.tagName === 'IMG') {
                e.preventDefault();
                editorSelectImage(e.target, editor.id);
            } else {
                editorDeselectImage();
            }
        });
    });

    // Stäng bildverktygsfält vid klick utanför
    document.addEventListener('click', function(e) {
        if (_editorSelectedImg && !e.target.closest('.editor-img-toolbar') && e.target.tagName !== 'IMG' && !e.target.closest('.editor-content')) {
            editorDeselectImage();
        }
    });

    // Uppdatera toolbar-position vid scroll/resize
    var repositionTimer;
    function onRepositionNeeded() {
        clearTimeout(repositionTimer);
        repositionTimer = setTimeout(function() {
            if (_editorSelectedImg && _editorImgToolbar) {
                positionImageToolbar(_editorSelectedImg, _editorImgToolbar);
            }
        }, 50);
    }
    window.addEventListener('scroll', onRepositionNeeded, true);
    window.addEventListener('resize', onRepositionNeeded);
});
</script>
