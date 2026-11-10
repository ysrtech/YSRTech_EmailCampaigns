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
    // Product blocks. In the email's HTML a block is one empty element with
    // its options as data attributes; the server fills it from the catalog
    // when the email is sent. Here it is drawn with the products it would
    // show right now, and its options are edited in the settings panel.
    if (cfg.widgets) {
        var w = cfg.widgets;
        var wt = w.t;
        var optionNames = ['layout', 'source', 'category', 'skus', 'heading', 'intro', 'limit', 'columns', 'sort', 'min_products',
            'show_view_all', 'view_all_url', 'view_all_label', 'show_price', 'show_brand', 'image_width', 'label', 'blurb', 'button'];
        var yesNo = [{id: '1', name: wt.yes}, {id: '0', name: wt.no}];

        var traitsFor = function (layout) {
            var traits = [
                {type: 'select', name: 'data-source', label: wt.source, options: w.sources},
                {type: 'select', name: 'data-category', label: wt.categoryField, options: w.categories},
                {type: 'text', name: 'data-skus', label: wt.skus, placeholder: 'ABC-1, ABC-2'}
            ];

            if (layout === 'feature') {
                traits.push({type: 'text', name: 'data-label', label: wt.label},
                    {type: 'text', name: 'data-blurb', label: wt.blurb},
                    {type: 'text', name: 'data-button', label: wt.button},
                    {type: 'select', name: 'data-show_price', label: wt.show_price, options: yesNo},
                    {type: 'number', name: 'data-image_width', label: wt.image_width, min: 40, max: 300});
            } else {
                traits.push({type: 'text', name: 'data-heading', label: wt.heading},
                    {type: 'text', name: 'data-intro', label: wt.intro},
                    {type: 'number', name: 'data-limit', label: wt.limit, min: 1, max: 24},
                    {type: 'number', name: 'data-columns', label: wt.columns, min: 1, max: 4},
                    {type: 'select', name: 'data-sort', label: wt.sort, options: w.sorts},
                    {type: 'number', name: 'data-min_products', label: wt.min_products, min: 0, max: 24},
                    {type: 'select', name: 'data-show_view_all', label: wt.show_view_all, options: yesNo},
                    {type: 'text', name: 'data-view_all_url', label: wt.view_all_url},
                    {type: 'text', name: 'data-view_all_label', label: wt.view_all_label},
                    {type: 'select', name: 'data-show_price', label: wt.show_price, options: yesNo},
                    {type: 'select', name: 'data-show_brand', label: wt.show_brand, options: yesNo},
                    {type: 'number', name: 'data-image_width', label: wt.image_width, min: 40, max: 600});
            }

            return traits;
        };

        editor.DomComponents.addType('ec-products', {
            isComponent: function (el) {
                return !!el.getAttribute && el.getAttribute('data-ec-widget') === 'products';
            },
            model: {
                defaults: {
                    tagName: 'div',
                    name: wt.name,
                    droppable: false,
                    editable: false,
                    traits: []
                },
                init: function () {
                    this.set('traits', traitsFor(this.getAttributes()['data-layout'] || 'grid'));
                    // Whatever was inside the element is not part of the
                    // email: the server writes the products in when it sends
                    this.components('');
                    this.on('change:attributes', function () { this.trigger('ec:refresh'); });
                },
                // Saved as the empty element and nothing else
                toHTML: function () {
                    var attrs = this.getAttributes();
                    var out = '<div data-ec-widget="products"';

                    Object.keys(attrs).forEach(function (name) {
                        var value = attrs[name];
                        if (name.indexOf('data-') === 0 && name !== 'data-ec-widget' && value !== '' && value !== null && value !== undefined) {
                            out += ' ' + name + '="' + String(value).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;') + '"';
                        }
                    });

                    return out + '></div>';
                }
            },
            view: {
                init: function () {
                    this.listenTo(this.model, 'ec:refresh', this.refresh);
                },
                onRender: function () {
                    this.refresh();
                },
                refresh: function () {
                    var view = this;
                    var attrs = this.model.getAttributes();
                    var options = {};

                    optionNames.forEach(function (name) {
                        var value = attrs['data-' + name];
                        if (value !== undefined && value !== null && value !== '') { options[name] = String(value); }
                    });

                    clearTimeout(this._timer);
                    this._timer = setTimeout(function () {
                        var body = new FormData();
                        body.append('form_key', cfg.formKey);
                        body.append('store_id', w.storeId || '');
                        body.append('options', JSON.stringify(options));
                        var ticket = view._ticket = (view._ticket || 0) + 1;

                        fetch(w.previewUrl, {method: 'POST', body: body, credentials: 'same-origin'})
                            .then(function (r) { return r.json(); })
                            .then(function (json) {
                                // Only the latest answer is drawn
                                if (ticket !== view._ticket) { return; }

                                if (json.html) {
                                    // The server's own markup for this block, built from the catalog
                                    view.el.innerHTML = json.html;
                                } else {
                                    view.el.innerHTML = '';
                                    var note = document.createElement('div');
                                    note.style.cssText = 'padding:24px;margin:8px 40px;border:1px dashed #b5b5b5;color:#777;font:13px Arial,sans-serif;text-align:center';
                                    note.appendChild(document.createTextNode(wt.empty));
                                    view.el.appendChild(note);
                                }

                                // Links in the preview must not navigate the canvas
                                Array.prototype.forEach.call(view.el.querySelectorAll('a'), function (a) {
                                    a.addEventListener('click', function (e) { e.preventDefault(); });
                                });
                            })
                            ['catch'](function () {});
                    }, 350);
                }
            }
        });

        w.layouts.forEach(function (layout) {
            var attributes = {'data-ec-widget': 'products'};

            Object.keys(layout.defaults).forEach(function (name) {
                var value = layout.defaults[name];
                if (value !== '' && value !== null) { attributes['data-' + name] = String(value); }
            });

            blocks.add('ec-products-' + layout.id, {
                label: layout.label,
                category: wt.category,
                attributes: {'class': 'gjs-fonts gjs-f-b' + (layout.id === 'feature' ? '1' : (layout.id === 'deals' ? '3' : '2')), title: layout.hint},
                content: {type: 'ec-products', attributes: attributes}
            });
        });
    }

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
