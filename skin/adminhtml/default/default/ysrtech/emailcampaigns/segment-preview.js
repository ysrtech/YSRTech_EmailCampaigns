/**
 * The segment rule editor's "Right now" panel: who the rules as they stand
 * would match, asked of the server a moment after they stop changing.
 */
document.observe('dom:loaded', function () {
    var panel = $('ysrtech-ec-now');
    var form  = $('edit_form');

    if (!panel || !form) {
        return;
    }

    var count  = panel.down('[data-role=count]');
    var sample = panel.down('[data-role=sample]');
    var timer  = null;
    var latest = 0;

    function refresh() {
        var mine = ++latest;

        panel.setStyle({opacity: 0.6});

        new Ajax.Request(panel.readAttribute('data-url'), {
            method: 'post',
            parameters: form.serialize(true),
            loaderArea: false,
            onComplete: function (transport) {
                // A slower, older answer must not overwrite a newer one
                if (mine !== latest) {
                    return;
                }

                panel.setStyle({opacity: 1});

                var data = null;

                try { data = transport.responseText.evalJSON(); } catch (e) {}

                sample.update('');

                if (!data || !data.ok) {
                    count.update('&ndash;');
                    return;
                }

                count.update(Number(data.count).toLocaleString());

                data.sample.each(function (who) {
                    var li = new Element('li');
                    li.update((who.name ? who.name.escapeHTML() + ' &middot; ' : '') + who.email.escapeHTML());
                    sample.insert(li);
                });

                if (data.count > data.sample.length) {
                    var more = new Element('li');
                    more.update('&hellip; and ' + Number(data.count - data.sample.length).toLocaleString() + ' more');
                    sample.insert(more);
                }
            }
        });
    }

    function soon() {
        clearTimeout(timer);
        timer = setTimeout(refresh, 600);
    }

    // Values change through inputs; rows come and go through the tree itself
    form.observe('change', soon);
    form.observe('keyup', soon);

    var tree = $('rule_conditions_fieldset') || form;

    if (window.MutationObserver) {
        new MutationObserver(soon).observe(tree, {childList: true, subtree: true});
    }

    refresh();
});
