/**
 * Dynamic Product/Category blocks for the GrapesJS designer.
 *
 * Not written against grapesjs.com's docs — this sandbox can't reach that
 * host, so the API used here (createInput(opts) receiving
 * {component, trait, elInput}, with opts.component the component being
 * edited) was confirmed by reading it straight out of the vendored
 * grapes.min.js (0.23.6)'s own getClbOpts(), rather than assumed.
 *
 * Two render paths per block, the same split Renderer.php's
 * injectCatalogData() expects:
 *  - on canvas: the picked item's cached name/price/image, for a preview
 *    that looks like the real thing
 *  - on export (toHTML(), called by the designer's save/preview/inliner):
 *    {{product.<id>.field}} / {{category.<id>.field}} markers instead, so
 *    price and stock are resolved fresh against the live catalog at actual
 *    send time rather than frozen as of whenever the template was designed
 *
 * Reopening a saved template only has the id (inside the markers) to go on,
 * not the cached name/price/image — isComponent() below restores the id and
 * then looks the rest back up by id (catalogSearchAction's `id` param) so
 * the canvas shows a real preview again instead of a blank block.
 */
(function () {
    'use strict';

    var KINDS = ['product', 'category'];

    var PLACEHOLDER_IMAGE =
        'data:image/svg+xml;utf8,' +
        encodeURIComponent(
            '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="200">' +
            '<rect width="300" height="200" fill="#e5e7eb"/>' +
            '<text x="150" y="104" font-family="Arial" font-size="14" fill="#9ca3af" text-anchor="middle">No image</text>' +
            '</svg>'
        );

    function esc(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    /** @param useTags  true for export (toHTML), false for the canvas preview */
    function rowsHtml(kind, model, useTags) {
        var id = model.get('catalogId');
        var hasId = id !== null && id !== undefined && id !== '';
        var tag = function (field) { return '{{' + kind + '.' + id + '.' + field + '}}'; };

        var image = hasId && useTags ? tag('image') : (model.get('catalogImage') || PLACEHOLDER_IMAGE);
        var name = hasId && useTags ? tag('name') : (model.get('catalogName') || ('Select a ' + kind + '…'));
        var price = kind === 'product' ? (hasId && useTags ? tag('price') : (model.get('catalogPrice') || '')) : '';
        var url = hasId && useTags ? tag('url') : (model.get('catalogUrl') || '#');
        var cta = kind === 'product' ? 'Shop Now' : 'Shop Category';
        var font = 'font-family:Arial,Helvetica,sans-serif;';

        var html = '<tr><td align="center" style="padding:0 0 12px;">'
            + '<img src="' + esc(image) + '" alt="" width="300" style="max-width:100%;display:block;border:0;" />'
            + '</td></tr>';
        html += '<tr><td align="center" style="' + font + 'font-weight:bold;font-size:16px;color:#000000;padding:0 0 4px;">'
            + esc(name) + '</td></tr>';
        if (price) {
            html += '<tr><td align="center" style="' + font + 'color:#666666;font-size:14px;padding:0 0 12px;">'
                + esc(price) + '</td></tr>';
        }
        html += '<tr><td align="center">'
            + '<a href="' + esc(url) + '" style="background:#2563eb;color:#ffffff;padding:12px 28px;border-radius:6px;'
            + 'text-decoration:none;font-weight:bold;display:inline-block;' + font + '">' + esc(cta) + '</a>'
            + '</td></tr>';
        return html;
    }

    function registerComponents(editor) {
        KINDS.forEach(function (kind) {
            var typeName = 'ysr-' + kind;

            editor.DomComponents.addType(typeName, {
                isComponent: function (el) {
                    if (el.getAttribute && el.getAttribute('data-ysr-type') === kind) {
                        return {type: typeName};
                    }
                },
                model: {
                    defaults: {
                        tagName: 'table',
                        attributes: {
                            'data-ysr-type': kind, role: 'presentation',
                            width: '100%', cellpadding: '0', cellspacing: '0', border: '0',
                            style: 'margin:0 0 20px;'
                        },
                        removable: true, draggable: true, copyable: true,
                        traits: [{type: 'ysr-catalog-picker', name: 'catalog_id', catalogType: kind}],
                        // The picked item, cached for the canvas preview — see
                        // rowsHtml()'s useTags=false path. Not what gets saved;
                        // toHTML() below ignores these in favour of merge tags.
                        catalogId: null,
                        catalogName: '',
                        catalogImage: '',
                        catalogPrice: '',
                        catalogUrl: ''
                    },

                    init: function () {
                        // A reopened template's isComponent() match sets
                        // data-ysr-id but nothing else — refill the preview.
                        var attrs = this.getAttributes();
                        if (attrs['data-ysr-id'] && !this.get('catalogId')) {
                            this.set('catalogId', parseInt(attrs['data-ysr-id'], 10));
                            this._lookupPreview();
                        } else {
                            this.components(rowsHtml(kind, this, false));
                        }
                    },

                    setCatalogData: function (item) {
                        this.set({
                            catalogId: item.id,
                            catalogName: item.name || '',
                            catalogImage: item.image || '',
                            catalogPrice: item.price || '',
                            catalogUrl: item.url || ''
                        });
                        this.addAttributes({'data-ysr-id': item.id});
                        this.components(rowsHtml(kind, this, false));
                    },

                    _lookupPreview: function () {
                        var self = this;
                        var opts = editor.getConfig().ysrCatalog || {};
                        if (!opts.searchUrl) { return; }
                        fetch(opts.searchUrl + '?type=' + kind + '&id=' + this.get('catalogId'), {credentials: 'same-origin'})
                            .then(function (r) { return r.json(); })
                            .then(function (items) {
                                if (items && items[0]) { self.setCatalogData(items[0]); }
                            })
                            .catch(function () {});
                    },

                    // Called instead of the default child-serializing toHTML
                    // on export (save / preview / the inliner the Save button
                    // runs) — see the file header for why this differs from
                    // what the canvas shows.
                    toHTML: function () {
                        var attrs = this.getAttributes();
                        var attrStr = Object.keys(attrs).map(function (k) {
                            return k + '="' + esc(attrs[k]) + '"';
                        }).join(' ');
                        return '<table ' + attrStr + '>' + rowsHtml(kind, this, true) + '</table>';
                    }
                }
            });

            editor.BlockManager.add(typeName + '-block', {
                label: kind === 'product' ? 'Product' : 'Category',
                category: 'Catalog',
                content: {type: typeName}
            });
        });
    }

    function registerTrait(editor) {
        editor.TraitManager.addType('ysr-catalog-picker', {
            createInput: function (opts) {
                var component = opts.component;
                var kind = opts.trait.get('catalogType');
                var searchUrl = (editor.getConfig().ysrCatalog || {}).searchUrl;

                var wrap = document.createElement('div');
                wrap.className = 'ysr-catalog-trait';

                var label = document.createElement('div');
                label.className = 'ysr-catalog-trait__label';
                label.textContent = (kind === 'product' ? 'Product' : 'Category');
                wrap.appendChild(label);

                var selected = document.createElement('div');
                selected.className = 'ysr-catalog-trait__selected';

                var input = document.createElement('input');
                input.type = 'text';
                input.placeholder = 'Search ' + kind + 's…';
                input.className = 'ysr-catalog-trait__input';

                var results = document.createElement('ul');
                results.className = 'ysr-catalog-trait__results';

                function renderSelected() {
                    var name = component.get('catalogName');
                    selected.textContent = name ? 'Selected: ' + name : 'Nothing selected yet.';
                }

                function renderResults(items) {
                    results.innerHTML = '';
                    (items || []).forEach(function (item) {
                        var li = document.createElement('li');
                        li.textContent = item.name;
                        li.addEventListener('click', function () {
                            component.setCatalogData(item);
                            renderSelected();
                            results.innerHTML = '';
                            input.value = '';
                        });
                        results.appendChild(li);
                    });
                }

                var timer = null;
                input.addEventListener('input', function () {
                    clearTimeout(timer);
                    var q = input.value.trim();
                    if (q.length < 2 || !searchUrl) { results.innerHTML = ''; return; }
                    timer = setTimeout(function () {
                        fetch(searchUrl + '?type=' + kind + '&q=' + encodeURIComponent(q), {credentials: 'same-origin'})
                            .then(function (r) { return r.json(); })
                            .then(renderResults)
                            .catch(function () { results.innerHTML = ''; });
                    }, 300);
                });

                renderSelected();
                wrap.appendChild(input);
                wrap.appendChild(results);
                wrap.appendChild(selected);
                return wrap;
            }
        });
    }

    window.ysrCatalogBlocks = {
        /**
         * @param editor     a grapesjs.init() instance
         * @param options    {searchUrl: string}
         */
        register: function (editor, options) {
            // Read back by registerComponents()'s _lookupPreview() and the
            // trait's createInput() — grapesjs exposes init options back out
            // through getConfig(), so this is the one place options travel
            // from designer.phtml into both without extra plumbing.
            editor.getConfig().ysrCatalog = options || {};
            registerTrait(editor);
            registerComponents(editor);
        }
    };
}());
