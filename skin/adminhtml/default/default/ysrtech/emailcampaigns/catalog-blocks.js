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

    var FONT = 'font-family:Arial,Helvetica,sans-serif;';

    function button(url, label) {
        return '<tr><td align="center" style="padding:8px 0 0;">'
            + '<a href="' + esc(url) + '" style="background:#2563eb;color:#ffffff;padding:12px 28px;border-radius:6px;'
            + 'text-decoration:none;font-weight:bold;display:inline-block;' + FONT + '">' + esc(label) + '</a>'
            + '</td></tr>';
    }

    /** @param useTags  true for export (toHTML), false for the canvas preview */
    function productRowsHtml(model, useTags) {
        var id = model.get('catalogId');
        var hasId = id !== null && id !== undefined && id !== '';
        var tag = function (field) { return '{{product.' + id + '.' + field + '}}'; };

        var image = hasId && useTags ? tag('image') : (model.get('catalogImage') || PLACEHOLDER_IMAGE);
        var name = hasId && useTags ? tag('name') : (model.get('catalogName') || 'Select a product…');
        var price = hasId && useTags ? tag('price') : (model.get('catalogPrice') || '');
        var url = hasId && useTags ? tag('url') : (model.get('catalogUrl') || '#');

        var html = '<tr><td align="center" style="padding:0 0 12px;">'
            + '<img src="' + esc(image) + '" alt="" width="300" style="max-width:100%;display:block;border:0;" />'
            + '</td></tr>';
        html += '<tr><td align="center" style="' + FONT + 'font-weight:bold;font-size:16px;color:#000000;padding:0 0 4px;">'
            + esc(name) + '</td></tr>';
        if (price) {
            html += '<tr><td align="center" style="' + FONT + 'color:#666666;font-size:14px;padding:0 0 12px;">'
                + esc(price) + '</td></tr>';
        }
        html += button(url, 'Shop Now');
        return html;
    }

    /** A 2-per-row cell for one sample product in the category grid's canvas preview. */
    function sampleProductCell(product) {
        var image = (product.image || PLACEHOLDER_IMAGE);
        return '<td width="50%" align="center" style="padding:0 8px 16px;">'
            + '<img src="' + esc(image) + '" alt="" width="150" style="max-width:100%;display:block;border:0;margin:0 auto 8px;" />'
            + '<div style="' + FONT + 'font-weight:bold;font-size:14px;color:#000000;">' + esc(product.name) + '</div>'
            + '<div style="' + FONT + 'color:#666666;font-size:13px;">' + esc(product.price || '') + '</div>'
            + '</td>';
    }

    /**
     * @param useTags  true for export (toHTML), false for the canvas preview.
     *
     * The grid itself (min 4 products, 2 per row) is one merge tag on
     * export — {{category.<id>.products_html}} — not four nested product
     * tags: Renderer.php's renderMergeVars() only substitutes scalar
     * {{a.b.c}} leaves, so a whole sub-grid has to arrive pre-rendered the
     * same way order.items_html (Automation/Variables.php) and
     * product.items_html already do, rather than inventing a loop construct
     * in the template syntax just for this one block.
     */
    function categoryRowsHtml(model, useTags) {
        var id = model.get('catalogId');
        var hasId = id !== null && id !== undefined && id !== '';
        var tag = function (field) { return '{{category.' + id + '.' + field + '}}'; };

        var name = hasId && useTags ? tag('name') : (model.get('catalogName') || 'Select a category…');
        var url = hasId && useTags ? tag('url') : (model.get('catalogUrl') || '#');
        var products = model.get('catalogProducts') || [];

        var html = '<tr><td align="center" style="' + FONT + 'font-weight:bold;font-size:18px;color:#000000;padding:0 0 12px;">'
            + esc(name) + '</td></tr>';

        if (hasId && useTags) {
            html += '<tr><td>' + tag('products_html') + '</td></tr>';
        } else if (products.length) {
            var rows = '';
            for (var i = 0; i < products.length; i += 2) {
                var pair = products.slice(i, i + 2);
                var cells = pair.map(sampleProductCell).join('');
                if (pair.length === 1) { cells += '<td width="50%">&nbsp;</td>'; }
                rows += '<tr>' + cells + '</tr>';
            }
            html += '<tr><td><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'
                + rows + '</table></td></tr>';
        } else {
            html += '<tr><td align="center" style="' + FONT + 'color:#999999;font-size:13px;padding:0 0 12px;">'
                + 'No products in this category yet</td></tr>';
        }

        html += button(url, 'Shop Category');
        return html;
    }

    function rowsHtml(kind, model, useTags) {
        return kind === 'product' ? productRowsHtml(model, useTags) : categoryRowsHtml(model, useTags);
    }

    function registerComponents(editor) {
        KINDS.forEach(function (kind) {
            var typeName = 'ysr-' + kind;

            editor.DomComponents.addType(typeName, {
                isComponent: function (el) {
                    if (el.getAttribute && el.getAttribute('data-ysr-type') === kind) {
                        // Seeds catalogId straight from the saved marker
                        // attribute, rather than leaving init() to go
                        // digging for it — see the reopened-template note
                        // on the model below.
                        var savedId = parseInt(el.getAttribute('data-ysr-id'), 10) || null;
                        return {type: typeName, catalogId: savedId};
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
                        // Deliberately no `components` / children: see the
                        // view below for why the inner markup is rendered as
                        // opaque HTML instead of a child component tree.
                        traits: [{type: 'ysr-catalog-picker', name: 'catalog_id', catalogType: kind}],
                        // The picked item, cached for the canvas preview — see
                        // rowsHtml()'s useTags=false path. Not what gets saved;
                        // toHTML() below ignores these in favour of merge tags.
                        catalogId: null,
                        catalogName: '',
                        catalogImage: '',
                        catalogPrice: '',
                        catalogUrl: '',
                        // Category blocks only: up to 4 sample products for
                        // the grid's canvas preview (categoryRowsHtml).
                        catalogProducts: []
                    },

                    init: function () {
                        // A reopened template's isComponent() match seeds
                        // catalogId but nothing else — the saved HTML only
                        // ever had the merge-tag id, not the cached preview
                        // fields — so look the rest back up by id.
                        if (this.get('catalogId')) {
                            this._lookupPreview();
                        }
                    },

                    setCatalogData: function (item) {
                        this.set({
                            catalogId: item.id,
                            catalogName: item.name || '',
                            catalogImage: item.image || '',
                            catalogPrice: item.price || '',
                            catalogUrl: item.url || '',
                            catalogProducts: item.products || []
                        });
                        this.addAttributes({'data-ysr-id': item.id});
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
                },

                // GrapesJS parses whatever this.components(htmlString) is
                // given into its own real, independently selectable child
                // components — an <img> inside becomes GrapesJS's own
                // built-in "image" type, and a click on it then selects
                // *that*, not this block, so the trait panel for the catalog
                // picker never appears. Rendering the preview as raw,
                // un-parsed innerHTML here instead keeps the whole block one
                // opaque, atomic unit with nothing nested to steal a click.
                view: {
                    onRender: function () {
                        this._updatePreview();
                        this.listenTo(
                            this.model,
                            'change:catalogId change:catalogName change:catalogImage change:catalogPrice '
                                + 'change:catalogUrl change:catalogProducts',
                            this._updatePreview
                        );
                    },
                    _updatePreview: function () {
                        this.el.innerHTML = rowsHtml(kind, this.model, false);
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

    /**
     * A GrapesJS plugin, in the same shape grapesjs-preset-newsletter.js
     * exports — not a function called after grapesjs.init() returns.
     *
     * That was the first version's real bug: registering addType() after
     * init() meant the editor had already parsed `components: startingHtml`
     * through its own default table/image/text types before these ever
     * existed, so isComponent() never got a chance to match on load — a
     * reopened template showed raw {{product.1.name}} text, not even the
     * placeholder. A plugin passed in `plugins: [...]` runs as part of
     * init()'s own setup, before that initial parse — same as how the
     * newsletter preset's own blocks are already present for it.
     *
     * Referenced by the global name grapesjs resolves a string plugins[]
     * entry against (confirmed in grapes.min.js: `window[e]` is its fallback
     * when a plugin name isn't in its own registry) — same convention
     * preset-newsletter.js's own UMD wrapper relies on.
     *
     * @param editor  the grapesjs.init() instance, mid-construction
     * @param opts    pluginsOpts['ysrCatalogBlocks'] — {searchUrl: string}
     */
    window.ysrCatalogBlocks = function (editor, opts) {
        // Read back by registerComponents()'s _lookupPreview() and the
        // trait's createInput() — grapesjs exposes init options back out
        // through getConfig(), so this is the one place options travel from
        // designer.phtml into both without extra plumbing.
        editor.getConfig().ysrCatalog = opts || {};
        registerTrait(editor);
        registerComponents(editor);
    };
}());
