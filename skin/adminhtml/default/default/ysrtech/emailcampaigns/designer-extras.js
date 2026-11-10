/*
 * Additions to the GrapesJS designer: ready-made and saved sections in the
 * block panel, placeholders that sit in the text as one piece, and the
 * pre-send checks.
 *
 * Called by designer.phtml once the editor exists. Everything is inside one
 * function and touches the page only through the editor's own API, so it
 * stays out of prototype.js's way.
 */
window.ysrtechDesignerExtras = function (editor, cfg) {
    'use strict';

    var blocks = editor.BlockManager;
    var t = cfg.labels;

    // ------------------------------------------------------------------
    // Block panel: the preset's own blocks are grouped as Content and Rows,
    // and two groups are added - ready-made sections, and saved ones.
    var rows = {'sect100': 1, 'sect50': 1, 'sect30': 1, 'sect37': 1, 'grid-items': 1, 'list-items': 1};

    blocks.getAll().forEach(function (block) {
        block.set('category', rows[block.getId()] ? t.rows : t.content);
    });

    cfg.sections.forEach(function (section) {
        blocks.add('ec-section-' + section.id, {
            label: section.label,
            category: t.sections,
            content: section.html,
            attributes: {'class': 'gjs-fonts gjs-f-b1'}
        });
    });

    function post(params, done) {
        var body = new FormData();
        body.append('form_key', cfg.formKey);
        Object.keys(params).forEach(function (key) { body.append(key, params[key]); });

        fetch(cfg.sectionsUrl, {method: 'POST', body: body, credentials: 'same-origin'})
            .then(function (r) { return r.json(); })
            .then(done)
            ['catch'](function () { window.alert(t.failed); });
    }

    var saved = [];

    function showSaved(payload) {
        if (payload && payload.error) {
            window.alert(payload.error);
        }

        saved.forEach(function (id) { blocks.remove(id); });
        saved = [];

        ((payload && payload.sections) || []).forEach(function (section) {
            var id = 'ec-saved-' + section.id;
            saved.push(id);
            blocks.add(id, {
                label: section.name,
                category: t.saved,
                content: section.html,
                attributes: {'class': 'gjs-fonts gjs-f-b1', title: t.savedHint}
            });
        });
    }

    post({}, showSaved);

    // "Save as section" on whatever is selected
    editor.Commands.add('ec-save-section', {
        run: function (ed) {
            var selected = ed.getSelected();

            if (!selected) {
                window.alert(t.selectFirst);
                return;
            }

            var name = window.prompt(t.sectionName, '');

            if (!name) {
                return;
            }

            // The selected piece on its own, with its styles written inline
            // so that it looks the same wherever it is dropped.
            var html = selected.toHTML();
            var css = ed.CodeManager.getCode(selected, 'css', {cssc: ed.CssComposer});
            var holder = document.createElement('div');
            holder.innerHTML = html;

            if (css && window.juice) {
                html = window.juice('<style>' + css + '</style>' + html);
            }

            post({name: name, html: html}, showSaved);
        }
    });

    editor.Commands.add('ec-manage-sections', {
        run: function (ed) {
            var box = document.createElement('div');

            function draw(payload) {
                showSaved(payload);
                box.innerHTML = '';
                var list = (payload && payload.sections) || [];

                if (!list.length) {
                    box.appendChild(document.createTextNode(t.noSaved));
                }

                list.forEach(function (section) {
                    var row = document.createElement('div');
                    row.style.cssText = 'display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid rgba(0,0,0,.15)';
                    var label = document.createElement('span');
                    label.appendChild(document.createTextNode(section.name));
                    var remove = document.createElement('a');
                    remove.href = '#';
                    remove.appendChild(document.createTextNode(t.remove));
                    remove.onclick = function () {
                        if (window.confirm(t.removeConfirm)) {
                            post({'delete': section.id}, draw);
                        }
                        return false;
                    };
                    row.appendChild(label);
                    row.appendChild(remove);
                    box.appendChild(row);
                });
            }

            post({}, draw);
            ed.Modal.setTitle(t.saved).setContent(box).open();
        }
    });

    // ------------------------------------------------------------------
    // Placeholders. Inserted as one non-editable piece, so that half of
    // {{ customer.firstname|there }} cannot be deleted by a stray backspace
    // and leave the other half to be mailed to everybody.
    editor.DomComponents.addType('ec-merge-tag', {
        isComponent: function (el) {
            return el.tagName === 'SPAN' && el.hasAttribute && el.hasAttribute('data-ec-tag');
        },
        model: {
            defaults: {
                tagName: 'span',
                editable: false,
                droppable: false,
                textable: true,
                layerable: false,
                name: t.placeholder
            }
        }
    });

    // Shown as a chip in the designer only. This is the canvas's stylesheet,
    // not the email's: nothing here is saved or sent.
    editor.on('load', function () {
        var doc = editor.Canvas.getDocument();

        if (doc) {
            var style = doc.createElement('style');
            style.appendChild(doc.createTextNode(
                '[data-ec-tag]{background:#e7f1fb;border:1px solid #9ec5f4;border-radius:3px;padding:0 3px;white-space:nowrap;cursor:default}'
                + (cfg.canvasCss || '')
            ));
            doc.head.appendChild(style);
        }
    });

    var options = cfg.mergeTags.map(function (tag) {
        return '<option value="' + tag.token + '">' + tag.label.replace(/</g, '&lt;') + '</option>';
    }).join('');

    editor.RichTextEditor.add('ec-merge-tag', {
        icon: '<select class="gjs-field" style="max-width:130px;color:inherit;background:transparent">'
            + '<option value="">' + t.insert + '</option>' + options + '</select>',
        event: 'change',
        attributes: {title: t.insertHint, style: 'width:auto'},
        result: function (rte, action) {
            var select = action.btn.firstChild;
            var token = select.value;
            select.value = '';

            if (!token) {
                return;
            }

            var tag = cfg.mergeTags.filter(function (m) { return m.token === token; })[0];
            var text = token;

            if (tag && tag.fallback) {
                var fallback = window.prompt(t.fallback, token === 'customer.firstname' ? t.fallbackDefault : '');

                if (fallback === null) {
                    return;
                }

                fallback = fallback.replace(/[{}|]/g, '').trim();

                if (fallback) {
                    text += '|' + fallback;
                }
            }

            rte.insertHTML('<span data-ec-tag="1" contenteditable="false">{{ ' + text + ' }}</span>&nbsp;');
        }
    });

    // ------------------------------------------------------------------
    // Pre-send checks, on the saved template.
    editor.Commands.add('ec-checks', {
        run: function (ed) {
            var box = document.createElement('div');
            box.appendChild(document.createTextNode(t.checking));
            ed.Modal.setTitle(t.checks).setContent(box).open();

            fetch(cfg.checksUrl, {credentials: 'same-origin'})
                .then(function (r) { return r.json(); })
                .then(function (payload) {
                    box.innerHTML = '';
                    var note = document.createElement('p');
                    note.style.marginBottom = '10px';
                    note.appendChild(document.createTextNode(t.checksNote));
                    box.appendChild(note);

                    (payload.checks || []).forEach(function (check) {
                        var row = document.createElement('div');
                        row.style.cssText = 'padding:6px 0;border-bottom:1px solid rgba(0,0,0,.15)';
                        var dot = document.createElement('span');
                        dot.style.cssText = 'display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:8px;background:'
                            + (check.level === 'bad' ? '#df280a' : (check.level === 'warn' ? '#e8a100' : '#3d9b35'));
                        var label = document.createElement('strong');
                        label.appendChild(document.createTextNode(check.label + ': '));
                        row.appendChild(dot);
                        row.appendChild(label);
                        row.appendChild(document.createTextNode(check.message));
                        box.appendChild(row);
                    });
                })
                ['catch'](function () { box.innerHTML = ''; box.appendChild(document.createTextNode(t.failed)); });
        }
    });
};
