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
 * WYSIWYG-editor med TinyMCE för rik lektionsformatering
 *
 * @param string $content Innehållet som ska redigeras
 * @param string $name Namnet på input-fältet
 * @param string $id ID för editorn (default: 'editor')
 */
function renderEditor($content, $name = 'content', $id = 'editor') {
    ?>
    <textarea id="<?= $id ?>" name="<?= $name ?>" class="stimma-editor"><?= htmlspecialchars($content ?? '') ?></textarea>
    <?php
}
?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Vänta på att TinyMCE ska vara laddat
    if (typeof tinymce === 'undefined') return;

    // Hitta alla renderEditor-textareas (markerade med .stimma-editor)
    var editorTextareas = document.querySelectorAll('textarea.stimma-editor');
    editorTextareas.forEach(function(ta) {
        if (tinymce.get(ta.id)) return;

        // Innehållseditorn (contentEditor) får fullständig toolbar
        // Övriga får en enklare variant
        var isMainEditor = (ta.id === 'contentEditor');
        initStimmaEditor('#' + ta.id, isMainEditor);
    });
});

function initStimmaEditor(selector, fullToolbar) {
    var editorHeight = fullToolbar ? 500 : 250;

    tinymce.init({
        selector: selector,
        language: 'sv_SE',
        height: editorHeight,
        menubar: false,
        branding: false,
        promotion: false,
        statusbar: true,
        resize: true,
        elementpath: true,

        plugins: fullToolbar
            ? 'lists image link table code fullscreen autolink image'
            : 'lists autolink',

        toolbar: fullToolbar
            ? [
                'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough | forecolor backcolor',
                'alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | lineheight',
                'link image table | lessonblocks | removeformat fullscreen code'
              ]
            : 'undo redo | bold italic | bullist numlist | removeformat',

        block_formats: 'Brödtext=p; Rubrik 2=h2; Rubrik 3=h3; Rubrik 4=h4; Rubrik 5=h5',

        font_family_formats: 'Standard=; Arial=arial,helvetica,sans-serif; Georgia=georgia,serif; Times New Roman=times new roman,times,serif; Courier New=courier new,courier,monospace; Verdana=verdana,geneva,sans-serif; Trebuchet MS=trebuchet ms,geneva,sans-serif; Comic Sans MS=comic sans ms,sans-serif',

        font_size_formats: '8pt 10pt 12pt 14pt 16pt 18pt 20pt 24pt 28pt 32pt 36pt 48pt 60pt 72pt',

        line_height_formats: '1 1.2 1.4 1.6 1.8 2 2.5 3',

        // Tillåt style-attribut på alla element för typsnitt, färger, storlekar etc.
        valid_elements: '*[style|class],p[style|class],span[style|class],br,strong/b,em/i,u,s,h2[style|class],h3[style|class],h4[style|class],h5[style|class],ul[style],ol[style],li[style],div[style|class],img[src|alt|class|style|width|height],a[href|target|rel|style],table[style|class],thead,tbody,tr[style],td[colspan|rowspan|style],th[colspan|rowspan|style],sub,sup,blockquote[style|class],hr',

        // Bilder fritt placerbara och storleksändringsbara
        image_advtab: true,
        image_caption: true,
        object_resizing: true,
        image_dimensions: true,

        // Bilduppladdning via befintligt upload_image.php
        images_upload_handler: function(blobInfo, progress) {
            return new Promise(function(resolve, reject) {
                var formData = new FormData();
                formData.append('image', blobInfo.blob(), blobInfo.filename());
                formData.append('csrf_token', typeof CSRF_TOKEN !== 'undefined' ? CSRF_TOKEN : '');

                var xhr = new XMLHttpRequest();
                xhr.open('POST', 'upload_image.php');

                xhr.upload.onprogress = function(e) {
                    if (e.lengthComputable) {
                        progress(Math.round(e.loaded / e.total * 100));
                    }
                };

                xhr.onload = function() {
                    if (xhr.status !== 200) {
                        reject({ message: 'HTTP-fel: ' + xhr.status, remove: true });
                        return;
                    }
                    try {
                        var data = JSON.parse(xhr.responseText);
                        if (data.success) {
                            resolve('../upload/' + data.url);
                        } else {
                            reject({ message: data.error || 'Uppladdning misslyckades', remove: true });
                        }
                    } catch (e) {
                        reject({ message: 'Ogiltigt svar från servern', remove: true });
                    }
                };

                xhr.onerror = function() {
                    reject({ message: 'Nätverksfel vid uppladdning', remove: true });
                };

                xhr.send(formData);
            });
        },

        // Bildklasser för storlek och placering
        image_class_list: [
            { title: 'Liten (25%)', value: 'img-sm img-center' },
            { title: 'Medel (50%)', value: 'img-md img-center' },
            { title: 'Stor (75%)', value: 'img-lg img-center' },
            { title: 'Fullbredd (100%)', value: 'img-full' },
            { title: 'Medel - vänster', value: 'img-md img-left' },
            { title: 'Medel - höger', value: 'img-md img-right' },
            { title: 'Liten - vänster', value: 'img-sm img-left' },
            { title: 'Liten - höger', value: 'img-sm img-right' }
        ],

        // Inklistra-hantering
        paste_as_text: false,
        paste_word_valid_elements: 'b,strong,i,em,u,p,br,ul,ol,li,h3,h4,table,tr,td,th,thead,tbody',

        // WYSIWYG-preview med samma stilar som frontend
        content_css: 'include/css/editor-content.css',

        // Tabellinställningar
        table_default_styles: { 'border-collapse': 'collapse', 'width': '100%' },
        table_default_attributes: {},
        table_responsive_width: true,

        // Länkinställningar
        link_default_target: '_blank',
        link_assume_external_targets: 'https',

        setup: function(editor) {
            // Innehållsblock dropdown-meny
            editor.ui.registry.addMenuButton('lessonblocks', {
                text: 'Innehållsblock',
                icon: 'template',
                fetch: function(callback) {
                    var blocks = [
                        { text: 'Introduktion', cls: 'lesson-intro', icon: 'info' },
                        { text: 'Tips', cls: 'lesson-tip', icon: 'checkmark' },
                        { text: 'Information', cls: 'lesson-info', icon: 'notice' },
                        { text: 'Exempel', cls: 'lesson-example', icon: 'code-sample' },
                        { text: 'Varning', cls: 'lesson-warning', icon: 'warning' },
                        { text: 'Sammanfattning', cls: 'lesson-summary', icon: 'bookmark' }
                    ];

                    var items = blocks.map(function(block) {
                        return {
                            type: 'menuitem',
                            text: block.text,
                            icon: block.icon,
                            onAction: function() {
                                var content = editor.selection.getContent();
                                if (!content || content.trim() === '') {
                                    content = '<p>Skriv här...</p>';
                                }
                                editor.insertContent(
                                    '<div class="' + block.cls + '">' + content + '</div><p>&nbsp;</p>'
                                );
                            }
                        };
                    });
                    callback(items);
                }
            });
        }
    });
}
</script>
