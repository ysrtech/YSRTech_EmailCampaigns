/*
 * The campaign editor: five steps and a live preview.
 *
 * One function, no globals beyond the config it is handed, and nothing from
 * prototype.js. The page is a rendering of `state`, which comes from the
 * server; every change is posted, and what comes back replaces it. Whether
 * a step is done, how many people will get the email and what is wrong with
 * it are the server's answers, never worked out here.
 */
(function () {
    'use strict';

    var cfg = window.ysrtechCampaignEditorConfig;
    var root = document.getElementById('ecc-root');

    if (!cfg || !root) {
        return;
    }

    var t = cfg.t;
    var state = cfg.state;
    var open = cfg.state.name ? firstUnfinished() : null;
    var nameAsked = !cfg.state.name;
    var device = 'desktop';
    var when = {mode: null, date: '', time: ''};
    // When the page's idea of "now" was taken, to count on from it without trusting this computer's clock
    var loadedAt = Date.now();
    var saveTimer = null;
    var pending = {};
    var savedText = t.saved;
    var savedError = false;
    var renderLater = false;

    if (state.status === 'scheduled' && state.when.scheduled_at) {
        when = {mode: 'scheduled', date: state.when.scheduled_at.date, time: state.when.scheduled_at.time};
    }

    // A later send is first offered for today, on the next half hour, by the store's clock: a date
    // that starts on tomorrow is easily missed by somebody who only meant to change the time
    if (!when.date && state.when.suggested) {
        when.date = state.when.suggested.date;
        when.time = state.when.suggested.time;
    }

    // ------------------------------------------------------------ helpers

    function pad(n) { return (n < 10 ? '0' : '') + n; }

    function esc(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function fmt(n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }

    function tr(key) {
        var text = t[key] || key;
        for (var i = 1; i < arguments.length; i++) {
            text = text.replace('%' + i, arguments[i]);
        }
        return text;
    }

    function $(selector, scope) { return (scope || root).querySelector(selector); }

    function $$(selector, scope) { return Array.prototype.slice.call((scope || root).querySelectorAll(selector)); }

    // What a merge tag looks like to a reader, for the inbox row
    function sample(text) {
        return String(text || '')
            .replace(/\{\{\s*customer\.firstname[^}]*\}\}/g, 'Chaya')
            .replace(/\{\{\s*customer\.lastname[^}]*\}\}/g, 'Rosenberg')
            .replace(/\{\{\s*store\.name\s*\}\}/g, state.store_name || '')
            .replace(/\{\{[^}]+\}\}/g, '');
    }

    /*
     * How long a subject is to the person reading it. A placeholder is
     * counted as what replaces it: "{{ customer.firstname|there }}" is
     * thirty characters to type and five in the inbox.
     */
    function seen(text) {
        return Array.from(sample(text).trim()).length;
    }

    function words(text) {
        return (String(text || '').trim().match(/\S+/g) || []).length;
    }

    function firstUnfinished() {
        var s = state;
        if (!s.editable) { return null; }
        if (s.problems.aud) { return 'aud'; }
        if (s.problems.subj) { return 'subj'; }
        if (s.problems.content) { return 'content'; }
        return s.problems.from ? 'from' : 'when';
    }

    /*
     * A campaign is named before anything else about it is set. The name is
     * read from the box as it stands, so the steps open the moment a letter
     * is typed, without waiting for a save to come back.
     */
    function named() {
        var box = $('#ecc-name');
        return (box ? box.value : state.name).trim() !== '';
    }

    function askName() {
        var box = $('#ecc-name');
        toast(t.nameFirst);
        if (box) {
            box.focus();
            box.classList.remove('nudge');
            void box.offsetWidth;
            box.classList.add('nudge');
        }
    }

    function paintNamed() {
        root.classList.toggle('unnamed', state.editable && !named());
    }

    // ------------------------------------------------------------ the clock

    // Seconds from now until an instant, by the server's clock carried forward
    function secondsUntil(ts) {
        return ts - (state.when.now_ts + Math.round((Date.now() - loadedAt) / 1000));
    }

    function span(seconds, far) {
        var m = Math.max(0, Math.round(seconds / 60));
        if (m >= 2880) { return tr(far ? 'inDays' : 'agoHours', far ? Math.floor(m / 1440) : Math.floor(m / 60), far ? Math.floor((m % 1440) / 60) : m % 60); }
        if (m >= 60) { return tr(far ? 'inHours' : 'agoHours', Math.floor(m / 60), m % 60); }
        return tr(far ? 'inMinutes' : 'agoMinutes', m);
    }

    // "in 3 h 20 min", for a time still to come
    function until(seconds) {
        return seconds < 90 ? t.inMoment : span(seconds, true);
    }

    /*
     * How far off the date and time in the two boxes are. Both they and the
     * store's "now" are wall-clock readings in the store's timezone, so their
     * difference is the wait (but for the hour a clock change adds or takes).
     */
    function chosenIn() {
        if (!when.date || !when.time || !state.when.now) { return null; }
        var stamp = function (date, time) {
            var d = date.split('-'), h = time.split(':');
            return Date.UTC(+d[0], +d[1] - 1, +d[2], +h[0], +h[1]) / 1000;
        };
        return stamp(when.date, when.time) - stamp(state.when.now.date, state.when.now.time) - Math.round((Date.now() - loadedAt) / 1000);
    }

    function chosenNote() {
        var seconds = chosenIn();
        if (seconds === null) { return ''; }
        return seconds <= 0 ? '<span class="bad">' + esc(t.thatPassed) + '</span>' : esc(tr('thatIs', until(seconds)));
    }

    // Said only when it matters: this computer keeps different time from the store
    function clockNote() {
        try {
            var read = function (zone) {
                return new Intl.DateTimeFormat('en-GB', zone ? {timeZone: zone, hour: '2-digit', minute: '2-digit', hour12: false} : {hour: '2-digit', minute: '2-digit', hour12: false}).format(new Date());
            };
            var here = read(null), there = read(state.when.timezone);
            return here === there ? '' : tr('clockDiffers', here, there, state.when.timezone);
        } catch (e) {
            return '';
        }
    }

    // The line under the title of a campaign that is waiting to go out
    function schedLine() {
        if (state.status !== 'scheduled' || !state.when.scheduled_ts) { return ''; }
        var seconds = secondsUntil(state.when.scheduled_ts);
        // Five minutes' grace: the scheduler runs each minute, and building a large queue takes a little
        if (seconds < -300) {
            return '<div class="banner warn" id="ecc-sched">' + esc(tr('overdue', span(-seconds, false))) + '</div>';
        }
        return '<div class="banner" id="ecc-sched">' + esc(tr('sendsAt', state.when.scheduled_at.label, state.when.timezone, until(seconds))) + '</div>';
    }

    // The waits on the page are kept true while it sits open
    setInterval(function () {
        var line = $('#ecc-sched'), note = $('#ecc-wnote');
        if (line) { line.outerHTML = schedLine(); }
        if (note) { note.innerHTML = chosenNote(); }
    }, 30000);

    function typing() {
        var el = document.activeElement;
        return !!el && root.contains(el) && /^(INPUT|TEXTAREA)$/.test(el.tagName)
            && el.type !== 'checkbox' && el.type !== 'radio';
    }

    /*
     * The last suggestions offered, kept here because the page redraws a
     * step whenever a save comes back, and choosing one is a save.
     */
    var aiOffer = null;

    function aiList() {
        if (!aiOffer) { return ''; }
        var group = function (title, items, kind, current) {
            return items.length ? '<div class="ai-group"><div class="ai-title">' + esc(title) + '</div>' + items.map(function (item) {
                return '<button type="button" class="ai-option' + (item === current ? ' on' : '') + '" data-kind="' + kind + '" data-value="' + esc(item) + '">' + esc(item) + '</button>';
            }).join('') + '</div>' : '';
        };
        return group(t.suggestSubjects, aiOffer.subjects, 'subject', state.subject) + group(t.suggestPreviews, aiOffer.preheaders, 'preheader', state.preheader)
            + '<p class="help">' + esc(t.suggestHint) + '</p>';
    }

    function aiBind() {
        $$('#ecc-ai-out .ai-option').forEach(function (option) {
            option.onclick = function () {
                var kind = option.getAttribute('data-kind');
                var box = $(kind === 'subject' ? '#ecc-sj' : '#ecc-pr');
                box.value = option.getAttribute('data-value');
                box.oninput();
                $$('#ecc-ai-out .ai-option[data-kind="' + kind + '"]').forEach(function (o) { o.classList.remove('on'); });
                option.classList.add('on');
            };
        });
    }

    function post(url, fields) {
        var body = new FormData();
        body.append('form_key', cfg.formKey);
        Object.keys(fields || {}).forEach(function (key) { body.append(key, fields[key]); });

        return fetch(url, {method: 'POST', body: body, credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}})
            .then(function (response) { return response.json(); })
            .then(function (json) {
                if (!json || !json.ok) {
                    throw new Error((json && json.message) || t.failed);
                }
                return json;
            });
    }

    function toast(message) {
        var el = document.createElement('div');
        el.className = 'ecc-toast';
        el.textContent = message;
        root.appendChild(el);
        setTimeout(function () { if (el.parentNode) { el.parentNode.removeChild(el); } }, 3800);
    }

    /*
     * A small dialog. `body` is markup built here from escaped values;
     * `onConfirm` may return a promise, and a rejection is shown in the
     * dialog instead of closing it.
     */
    function modal(options) {
        var back = document.createElement('div');
        back.className = 'ecc-modal-back';
        back.innerHTML = '<div class="ecc-modal" role="dialog" aria-modal="true"><h4>' + esc(options.title) + '</h4>'
            + (options.desc ? '<div class="desc">' + esc(options.desc) + '</div>' : '')
            + (options.body || '')
            + '<div class="err" hidden></div><div class="acts">'
            + '<button type="button" class="btn" data-x>' + esc(options.cancel || 'Cancel') + '</button>'
            + '<button type="button" class="btn ' + (options.danger ? 'btn-danger' : 'btn-primary') + '" data-ok>' + esc(options.label) + '</button>'
            + '</div></div>';
        root.appendChild(back);

        function close() { if (back.parentNode) { back.parentNode.removeChild(back); } }

        back.addEventListener('click', function (e) { if (e.target === back) { close(); } });
        $('[data-x]', back).onclick = close;
        $('[data-ok]', back).onclick = function () {
            var button = this;
            var result = options.onConfirm(back);

            if (result && typeof result.then === 'function') {
                button.disabled = true;
                result.then(close, function (error) {
                    button.disabled = false;
                    var err = $('.err', back);
                    err.hidden = false;
                    err.textContent = error.message;
                });
            } else if (result !== false) {
                close();
            }
        };

        var first = $('input', back);
        if (first) { first.focus(); first.select(); }
    }

    // ------------------------------------------------------------- saving

    function paintSaved() {
        var el = $('#ecc-saved');
        if (el) {
            el.textContent = savedText;
            el.className = 'saved' + (savedError ? ' err' : '');
        }
    }

    /*
     * Saves merge, so a quick edit to a second field never drops the first.
     * After a save the page is redrawn only if nobody is typing: a redraw
     * mid-word would take the caret away. Otherwise it waits for focus to
     * leave the field.
     */
    function save(fields, immediately) {
        if (!state.editable) { return Promise.resolve(); }

        if (!named()) {
            // No name, or the name of a campaign that had one emptied: say so, keep nothing
            clearTimeout(saveTimer);
            savedText = t.notSaved + ': ' + t.nameFirst; savedError = true; paintSaved();
            if (!('name' in fields)) { askName(); }
            return Promise.resolve();
        }

        Object.keys(fields).forEach(function (key) { pending[key] = fields[key]; });
        clearTimeout(saveTimer);
        savedText = t.saving; savedError = false; paintSaved();

        function run() {
            var body = pending;
            pending = {};

            return post(cfg.urls.autosave, body).then(function (json) {
                applyState(json, immediately);
            }, function (error) {
                savedText = t.notSaved + ': ' + error.message; savedError = true; paintSaved();
                toast(error.message);
            });
        }

        if (immediately) { return run(); }

        return new Promise(function (resolve) { saveTimer = setTimeout(function () { run().then(resolve); }, 600); });
    }

    function applyState(json, forceRender) {
        state = json.state;
        loadedAt = Date.now();
        savedText = t.saved; savedError = false;

        if (json.unscheduled) {
            when.mode = null;
            toast(t.unscheduled);
        }

        if (!forceRender && typing()) {
            renderLater = true;
            paintSaved();
            paintLive();
            // A letter is typed here, so its preview follows the typing
            if (state.content.letter.is) { paintFrames(); }
        } else {
            renderLater = false;
            render();
        }
    }

    root.addEventListener('focusout', function () {
        setTimeout(function () { if (renderLater && !typing()) { renderLater = false; render(); } }, 0);
    });

    // ------------------------------------------------------------ sections

    function audienceSummary() {
        if (!state.to.included.length && !state.to.all) { return ''; }
        var n = state.to.recipients;
        return state.to.names.join(', ') + (n === null ? '' : ' · ' + (n === 1 ? t.recipient : tr('recipients', fmt(n))));
    }

    function segmentRows(kind) {
        var chosen = state.to[kind];
        var other = state.to[kind === 'included' ? 'excluded' : 'included'];

        return cfg.segments.map(function (segment) {
            var on = chosen.indexOf(segment.id) >= 0;
            var taken = other.indexOf(segment.id) >= 0 && !(state.to.all && kind === 'excluded');
            return '<label class="aud ' + (on ? 'on' : '') + '"><input type="checkbox" data-seg="' + kind + '" value="' + segment.id + '"'
                + (on ? ' checked' : '') + (taken || !state.editable ? ' disabled' : '') + '>'
                + '<span class="nm">' + esc(segment.name) + '</span><span class="n">' + fmt(segment.count) + '</span></label>';
        }).join('');
    }

    function sections() {
        var p = state.problems;
        var ro = state.editable ? '' : ' disabled';
        var content = state.content;
        var notOk = content.checks.filter(function (c) { return c.level !== 'ok'; }).length;
        var letter = content.letter;

        return [
            {
                k: 'aud', title: t.to, summary: audienceSummary(), done: !p.aud, problem: state.to.included.length || state.to.all ? p.aud : null,
                body: function () {
                    // Everybody, or the people in chosen segments
                    var who = '<div class="radio-cards aud-who">' + [['1', t.toAll, t.toAllNote], ['0', t.toSegments, t.toSegmentsNote]].map(function (o) {
                        return '<label class="radio-card ' + ((state.to.all ? '1' : '0') === o[0] ? 'on' : '') + '" data-all="' + o[0] + '"><span class="dot"></span><div><b>' + esc(o[1]) + '</b><span>' + esc(o[2]) + '</span></div></label>';
                    }).join('') + '</div>';
                    var none = '<p class="faint">' + esc(t.noSegments) + ' <a href="' + esc(cfg.urls.segments) + '">' + esc(t.manageSegments) + '</a></p>';

                    return '<div class="field"><label for="ecc-store">' + esc(t.storeView) + '</label><select id="ecc-store"' + ro + '>'
                        + cfg.stores.map(function (store) {
                            return '<option value="' + store.id + '"' + (store.id === state.store_id ? ' selected' : '') + '>' + esc(store.name) + '</option>';
                        }).join('') + '</select><span class="help">' + esc(t.storeViewNote) + '</span></div>'
                        + who
                        + (state.to.all ? '' : '<div class="aud-label">' + esc(t.include) + '</div>' + (cfg.segments.length ? '<div class="aud-list">' + segmentRows('included') + '</div>' : none))
                        + (cfg.segments.length ? '<div class="aud-label">' + esc(t.exclude) + '</div><div class="aud-list">' + segmentRows('excluded') + '</div>' : '')
                        + (state.to.recipients !== null ? '<div class="aud-total"><b>' + esc(state.to.recipients === 1 ? t.recipient : tr('recipients', fmt(state.to.recipients)))
                            + '</b> <span class="faint">' + esc(t.recipientsNote) + '</span></div>' : '')
                        + '<p class="help" style="margin-top:8px"><a href="' + esc(cfg.urls.segments) + '">' + esc(t.manageSegments) + '</a></p>';
                }
            },
            {
                k: 'from', title: t.from, summary: state.from.email ? state.from.display_name + ' <' + state.from.email + '>' : '', done: !p.from, problem: p.from,
                body: function () {
                    return '<div class="row2"><div class="field"><label for="ecc-fn">' + esc(t.senderName) + '</label>'
                        + '<input type="text" id="ecc-fn" maxlength="120" value="' + esc(state.from.name) + '" placeholder="' + esc(state.from.display_name) + '"' + ro + '></div>'
                        + '<div class="field"><label for="ecc-fl">' + esc(t.senderAddress) + '</label><div class="addr">'
                        + '<input type="text" id="ecc-fl" maxlength="64" value="' + esc(state.from.local) + '" placeholder="' + esc((state.from.email || '').split('@')[0]) + '"' + ro + '>'
                        + '<span>@' + esc(state.from.domain) + '</span></div></div></div>'
                        + '<p class="help" style="margin:-6px 0 14px">' + esc(tr('domainLocked', state.from.domain)) + '</p>'
                        + '<div class="field"><label for="ecc-rt">' + esc(t.replyTo) + ' <span class="faint">(' + esc(t.optional) + ')</span></label>'
                        + '<input type="email" id="ecc-rt" value="' + esc(state.from.reply_to) + '" placeholder="' + esc(t.replyHint) + '"' + ro + '></div>';
                }
            },
            {
                k: 'subj', title: t.subject, summary: state.subject ? state.subject + (state.preheader ? ' — ' + state.preheader : '') : '',
                done: !p.subj, warn: seen(state.subject) > 60, problem: null,
                body: function () {
                    return '<div class="field"><label for="ecc-sj">' + esc(t.subjectLine) + '</label>'
                        + '<input type="text" id="ecc-sj" maxlength="255" value="' + esc(state.subject) + '" placeholder="' + esc(t.subjectHint) + '"' + ro + '>'
                        + '<div class="counter' + (seen(state.subject) > 60 ? ' over' : '') + '" id="ecc-sjc">' + esc(tr('recommended', seen(state.subject))) + '</div></div>'
                        + '<div class="field"><label for="ecc-pr">' + esc(t.previewText) + '</label>'
                        + '<input type="text" id="ecc-pr" maxlength="255" value="' + esc(state.preheader) + '" placeholder="' + esc(t.previewHint) + '"' + ro + '></div>'
                        + (cfg.ai && !ro ? '<div class="ai-suggest"><button type="button" class="btn btn-sm" id="ecc-ai">' + esc(aiOffer ? t.suggestAgain : t.suggest) + '</button><div id="ecc-ai-out">' + aiList() + '</div></div>' : '');
                }
            },
            {
                k: 'content', title: t.content,
                summary: letter.is ? (letter.text.trim() ? t.letter + ' · ' + tr('letterWords', words(letter.text)) : '')
                    : (content.has ? t.yourDesign + ' · ' + content.size_kb + ' KB' + (notOk ? ' · ' + notOk + ' !' : '') : ''),
                done: content.has && !p.content, problem: content.has ? p.content : null,
                body: function () {
                    if (letter.is) {
                        // A letter: the words, and how it is sent
                        return '<div class="field"><label for="ecc-lt">' + esc(t.letterLabel) + '</label>'
                            + '<textarea id="ecc-lt" class="letter" rows="14" maxlength="60000"' + ro + '>' + esc(letter.text) + '</textarea>'
                            + '<div class="help">' + esc(t.letterHelp) + '</div></div>'
                            + '<p class="help letter-out">' + (letter.own_optout ? esc(t.letterOwnOptout)
                                : esc(t.letterOptout) + '<br><span class="mono">' + esc(sample(letter.optout).replace(/\s*$/, '')) + ' …</span>') + '</p>'
                            + '<label class="brand-sw"><input type="checkbox" id="ecc-ltk"' + (letter.track ? ' checked' : '') + ro + '> <span><b>' + esc(t.letterTrack) + '</b><br>'
                            + '<span class="help">' + esc(t.letterTrackNote) + '</span></span></label>'
                            + '<p class="help" style="margin-top:12px">' + esc(t.letterNote) + '</p>'
                            + (state.editable ? '<div class="ct-acts"><button type="button" class="btn btn-sm" id="ecc-unletter">' + esc(t.letterSwitch) + '</button></div>' : '');
                    }

                    if (content.has) {
                        return '<div class="ct-design"><div class="ct-thumb" id="ecc-thumb"><iframe id="ecc-thumbf" sandbox="" tabindex="-1" title="' + esc(t.preview) + '"></iframe>'
                            + (state.editable ? '<span class="ct-hover">' + esc(t.editDesign) + '</span>' : '') + '</div>'
                            + '<div class="ct-side"><b>' + esc(t.yourDesign) + '</b><small>' + esc(tr('sizeNote', content.size_kb)) + '</small>'
                            + '<ul class="checks">' + content.checks.map(function (c) {
                                return '<li class="' + esc(c.level) + '">' + esc(c.level === 'ok' ? c.label : c.label + ': ' + c.message) + '</li>';
                            }).join('') + '</ul>'
                            + (content.own ? '' : '<p class="help" style="margin-top:8px">' + esc(t.usesTemplate) + '</p>')
                            + (state.brand.own ? '<p class="help" style="margin-top:10px">' + esc(t.ownDesign) + '</p>' : (''
                            + '<label class="brand-sw"><input type="checkbox" id="ecc-brand"' + (state.brand.use ? ' checked' : '') + ro + '> <span><b>' + esc(t.useBrand) + '</b><br>'
                            + '<span class="help">' + (state.brand.enabled
                                ? esc(t.useBrandNote).replace('%1', '<a href="' + esc(cfg.brandUrl) + '" target="_blank" rel="noopener">' + esc(t.brandSettings) + '</a>')
                                : esc(t.brandOff)) + '</span></span></label>'))
                            + (state.editable ? '<div class="ct-acts"><button type="button" class="btn btn-primary btn-sm" id="ecc-edit">' + esc(t.editDesign) + '</button>'
                                + '<button type="button" class="btn btn-sm" id="ecc-over">' + esc(t.startOver) + '</button></div>' : '')
                            + '</div></div>';
                    }

                    return '<div class="ct-start"><button type="button" class="ct-opt" data-starter="plain"' + ro + '><b>' + esc(t.fromScratch) + '</b><small>' + esc(t.fromScratchNote) + '</small></button>'
                        + '<button type="button" class="ct-opt" id="ecc-letter"' + ro + '><b>' + esc(t.letterStart) + '</b><small>' + esc(t.letterStartNote) + '</small></button></div>'
                        + '<div class="ins-label">' + esc(t.fromStarter) + '</div><div class="ct-start">'
                        + cfg.starters.filter(function (s) { return s.code !== 'plain'; }).map(function (s) {
                            return '<button type="button" class="ct-opt" data-starter="' + esc(s.code) + '"' + ro + '><b>' + esc(s.name) + '</b><small>' + esc(s.description) + '</small></button>';
                        }).join('') + '</div>'
                        + (cfg.templates.length ? '<div class="ins-label">' + esc(t.fromTemplate) + '</div><div class="ct-start">'
                            + cfg.templates.slice(0, 18).map(function (tpl) {
                                return '<button type="button" class="ct-opt" data-tpl="' + tpl.id + '"' + ro + '><b>' + esc(tpl.name) + '</b></button>';
                            }).join('') + '</div>' : '')
                        + '<p class="help" style="margin-top:12px">' + esc(t.copyNote) + '</p>';
                }
            },
            {
                k: 'when', title: t.when,
                summary: state.status === 'scheduled' && state.when.scheduled_at ? tr('scheduledFor', state.when.scheduled_at.label)
                    : (when.mode === 'now' ? t.now : (when.mode === 'scheduled' && when.date ? when.date + ' ' + when.time : '')),
                done: state.status === 'scheduled' || when.mode === 'now' || (when.mode === 'scheduled' && !!when.date && !!when.time), problem: null,
                body: function () {
                    return '<div class="radio-cards">' + [['now', t.now, t.nowNote], ['scheduled', t.later, t.laterNote]].map(function (o) {
                        return '<label class="radio-card ' + (when.mode === o[0] ? 'on' : '') + '" data-when="' + o[0] + '"><span class="dot"></span><div><b>' + esc(o[1]) + '</b><span>' + esc(o[2]) + '</span></div></label>';
                    }).join('') + '</div>'
                        + (when.mode === 'scheduled' ? '<div class="row2" style="margin-top:14px"><div class="field"><label for="ecc-wd">' + esc(t.date) + '</label>'
                            + '<input type="date" id="ecc-wd" value="' + esc(when.date) + '"' + ro + '></div><div class="field"><label for="ecc-wt">' + esc(t.time) + '</label>'
                            + '<input type="time" id="ecc-wt" value="' + esc(when.time) + '"' + ro + '></div></div>'
                            + '<p class="help" style="margin-top:6px">' + esc(tr('storeTime', state.when.timezone)) + ' <b id="ecc-wnote">' + chosenNote() + '</b></p>'
                            + (clockNote() ? '<p class="help warn-note">' + esc(clockNote()) + '</p>' : '') : '');
                }
            }
        ];
    }

    // ------------------------------------------------------------- render

    function isReady() {
        return !Object.keys(state.problems).filter(function (k) { return k !== 'name'; }).length && !!when.mode;
    }

    function topButtons() {
        var html = '';

        if (state.status === 'scheduled') {
            html += '<button type="button" class="btn" id="ecc-unsch">' + esc(t.backToDraft) + '</button>';
        }
        if (state.status === 'sending') {
            html += '<button type="button" class="btn" data-go="pause">' + esc(t.pause) + '</button>';
        }
        if (state.status === 'paused') {
            html += '<button type="button" class="btn" data-go="resume">' + esc(t.resume) + '</button>';
        }
        if (['scheduled', 'sending', 'paused'].indexOf(state.status) >= 0) {
            html += '<button type="button" class="btn btn-danger" data-go="cancel" data-confirm="' + esc(t.confirmCancel) + '">' + esc(t.cancel) + '</button>';
        }

        html += '<button type="button" class="btn" data-go="duplicate">' + esc(t.duplicate) + '</button>';

        // Once it has started there is nothing left to delete or try out from here
        if (state.editable && state.status === 'draft') {
            html += '<button type="button" class="btn btn-danger" data-go="delete" data-confirm="' + esc(t.confirmDelete) + '">' + esc(t.delete) + '</button>';
        }
        if (state.editable && state.content.has) {
            html += '<button type="button" class="btn" id="ecc-test">' + esc(t.sendTest) + '</button>';
        }
        if (state.editable) {
            var problems = Object.keys(state.problems);
            var why = problems.length ? state.problems[problems[0]] : (when.mode ? '' : t.chooseTime);
            html += '<button type="button" class="btn btn-primary" id="ecc-go"' + (isReady() && named() ? '' : ' disabled') + ' title="' + esc(why) + '">'
                + esc(state.status === 'scheduled' ? t.updateSchedule : (when.mode === 'now' ? t.sendNow : t.schedule)) + '</button>';
        }

        return html;
    }

    /*
     * What became of a campaign that has started: the counts, the addresses
     * the provider has said nothing about yet, and, for a letter, the words
     * that went out.
     */
    function report() {
        var r = state.report;
        if (!r) { return ''; }

        var pct = function (n, of) { return of ? ' <small>' + (Math.round(n / of * 1000) / 10) + '%</small>' : ''; };
        var tile = function (label, value, extra, cls) {
            return '<div class="rt ' + (cls || '') + '"><span>' + esc(label) + '</span><b>' + value + '</b>' + (extra || '') + '</div>';
        };
        var untracked = '<small>' + esc(t.rNotTracked) + '</small>';

        var html = '<div class="report"><div class="head"><span>' + esc(t.report) + '</span>'
            + (r.last_sent_at ? '<span class="faint">' + esc(tr('rLast', r.last_sent_at)) + '</span>' : '') + '</div><div class="rts">'
            + tile(t.rSent, fmt(r.sent))
            + (r.pending ? tile(t.rPending, fmt(r.pending)) : '')
            + (r.purged ? '' : tile(t.rDelivered, fmt(r.delivered), pct(r.delivered, r.sent))
                + (r.tracks_opens ? tile(t.rOpened, fmt(r.opened), pct(r.opened, r.sent)) + tile(t.rClicked, fmt(r.clicked), pct(r.clicked, r.sent))
                    : tile(t.rOpened, '–', untracked) + tile(t.rClicked, '–', untracked))
                + (r.tracks_orders ? tile(t.rOrders, fmt(r.orders), pct(r.orders, r.sent), r.orders ? 'good' : '')
                    + tile(t.rRevenue, esc(r.revenue), r.revenue_each && r.orders ? '<small>' + esc(tr('rRevenueEach', r.revenue_each)) + '</small>' : '', r.orders ? 'good' : '') : '')
                + tile(t.rBounced, fmt(r.bounced), pct(r.bounced, r.sent), r.bounced ? 'warn' : '')
                + tile(t.rComplained, fmt(r.complained), '', r.complained ? 'bad' : '')
                + tile(t.rWaiting, fmt(r.waiting), pct(r.waiting, r.sent)))
            + (r.failed ? tile(t.rFailed, fmt(r.failed), '', 'bad') : '')
            + (r.cancelled ? tile(t.rCancelled, fmt(r.cancelled)) : '')
            + '</div>';

        if (r.purged) {
            html += '<p class="help">' + esc(t.rPurged) + '</p>';
        }

        if (r.tracks_orders) {
            html += '<p class="help orders-note">' + esc(tr('rOrdersNote', r.conversion_days))
                + (r.orders_by_address ? ' ' + esc(tr('rByAddress', fmt(r.orders_by_address))) : '') + '</p>';
        }

        if (r.tracks_opens && (!r.purged || r.links.length)) {
            html += '<details class="links"' + (r.links.length ? ' open' : '') + '><summary>' + esc(t.rLinks) + (r.links.length ? ' (' + r.links.length + ')' : '') + '</summary>'
                + (r.links.length ? '<table><thead><tr><th>' + esc(t.rLink) + '</th><th>' + esc(t.rPeople) + '</th><th>' + esc(t.rClicks) + '</th></tr></thead><tbody>'
                    + r.links.map(function (link) {
                        // Shown as text and opened only as a web address: what was clicked came from outside
                        var safe = /^https?:\/\//i.test(link.url);
                        return '<tr><td>' + (safe ? '<a href="' + esc(link.url) + '" target="_blank" rel="noopener noreferrer">' + esc(link.url) + '</a>' : esc(link.url))
                            + '</td><td>' + fmt(link.people) + (r.clicked ? ' <small>' + (Math.round(link.people / r.clicked * 1000) / 10) + '%</small>' : '') + '</td><td>' + fmt(link.clicks) + '</td></tr>';
                    }).join('') + '</tbody></table>'
                    : '<p class="help">' + esc(t.rLinksNone) + '</p>')
                + '</details>';
        }

        if (r.waiting_emails.length) {
            html += '<details class="waiting"><summary>' + esc(t.rWaitingTitle) + ' (' + fmt(r.waiting) + ')</summary><p class="help">' + esc(t.rWaitingNote) + '</p><ul>'
                + r.waiting_emails.map(function (email) { return '<li>' + esc(email) + '</li>'; }).join('')
                + (r.waiting > r.waiting_emails.length ? '<li class="faint">' + esc(tr('rWaitingMore', fmt(r.waiting - r.waiting_emails.length))) + '</li>' : '')
                + '</ul></details>';
        }

        if (state.content.letter.is && state.content.letter.text) {
            html += '<details class="words" open><summary>' + esc(t.rWords) + '</summary><pre>' + esc(state.content.letter.text)
                + (state.content.letter.own_optout ? '' : '\n\n' + esc(state.content.letter.optout.replace(/\{\{[^}]*\}\}/, '…'))) + '</pre></details>';
        }

        return html + '</div>';
    }

    function steps(list, doneCount) {
        return '<div class="ready"><b>' + esc(isReady() ? t.ready : tr('ofReady', doneCount, list.length)) + '</b><div class="bars">'
            + list.map(function (s) { return '<span class="' + (s.done && !s.problem ? 'on' : '') + '"></span>'; }).join('') + '</div></div>'
            + list.map(function (s) {
                var cls = s.problem || s.warn ? 'warn' : (s.done ? 'done' : '');
                return '<div class="sec ' + (open === s.k ? 'open ' : '') + cls + '" data-k="' + s.k + '"><div class="sec-head">'
                    + '<span class="sec-st">' + (s.problem || s.warn ? '!' : (s.done ? '✓' : '')) + '</span><div class="mid"><div class="t">' + esc(s.title) + '</div>'
                    + '<div class="s ' + (s.summary ? '' : 'unset') + '" data-sum="' + s.k + '">' + esc(s.summary || t.notSet) + '</div>'
                    + (s.problem ? '<div class="s problem">' + esc(s.problem) + '</div>' : '') + '</div>'
                    // Closed, a step offers to be opened. Open, the way on is at the bottom, where the eye is
                    // after filling it in: Next to the step below, Done on the last
                    + (open === s.k ? '' : '<button type="button" class="btn btn-sm">' + esc(s.done ? t.edit : t.add) + '</button>') + '</div>'
                    + '<div class="sec-body">' + (open === s.k ? s.body()
                        + '<div class="sec-next"><button type="button" class="btn btn-primary btn-sm" data-next="' + s.k + '">'
                        + esc(list[list.length - 1].k === s.k ? t.done : t.next) + '</button></div>' : '') + '</div></div>';
            }).join('')
            + (state.content.checks.length ? '<div class="precheck"><div class="head"><span>' + esc(t.checks) + '</span><span class="badge">'
                + esc(tr('passing', state.content.checks.filter(function (c) { return c.level === 'ok'; }).length, state.content.checks.length)) + '</span></div>'
                + '<ul class="checks">' + state.content.checks.map(function (c) {
                    return '<li class="' + esc(c.level) + '"><span><b>' + esc(c.label) + '</b> — ' + esc(c.message) + '</span></li>';
                }).join('') + '</ul></div>' : '');
    }

    /*
     * A campaign that has started has nothing left to set, so its settings
     * are not steps any more: one list of what went out, to whom and when.
     */
    function details() {
        var row = function (label, value) {
            return '<dt>' + esc(label) + '</dt><dd>' + (value ? esc(value) : '<span class="faint">' + esc(t.dNone) + '</span>') + '</dd>';
        };
        var letter = state.content.letter;
        var at = state.when.sent_at || state.when.scheduled_at;

        return '<div class="details"><div class="head">' + esc(t.details) + '</div><dl>'
            + row(t.to, state.to.names.join(', '))
            + (state.to.excluded_names.length ? row(t.dNotTo, state.to.excluded_names.join(', ')) : '')
            + row(t.from, state.from.email ? state.from.display_name + ' <' + state.from.email + '>' : '')
            + (state.from.reply_to ? row(t.replyTo, state.from.reply_to) : '')
            + row(t.subject, state.subject)
            + row(t.previewText, state.preheader)
            + row(t.content, letter.is ? t.letter + ' · ' + tr('letterWords', words(letter.text)) : (state.content.has ? t.dDesign + ' · ' + state.content.size_kb + ' KB' : ''))
            + row(t.storeView, state.store_name)
            + row(state.when.sent_at ? t.dSent : t.dStarted, at ? at.label : '')
            + '</dl></div>';
    }

    function render() {
        var list = sections();
        var doneCount = list.filter(function (s) { return s.done && !s.problem; }).length;

        // The page is redrawn whenever a step opens or a save comes back, and the name may have been
        // typed since the last save: what is in the box wins over what the server last heard
        var nameNow = $('#ecc-name') ? $('#ecc-name').value : state.name;
        var nameField = '<div class="name-field"><label for="ecc-name">' + esc(t.nameLabel) + '</label>'
            + '<input type="text" class="title" id="ecc-name" maxlength="255" value="' + esc(nameNow) + '" placeholder="' + esc(t.namePlaceholder) + '">'
            + '<span class="name-note">' + esc(t.nameNote) + '</span></div>';

        root.innerHTML = '<a class="back" href="' + esc(cfg.urls.back) + '">← ' + esc(t.back) + '</a>'
            + '<div class="top">' + (state.editable ? nameField : '<h2 class="title-ro">' + esc(state.name) + '</h2>')
            + '<span class="chip ' + esc(state.status) + '">' + esc(t['status_' + state.status] || state.status) + '</span>'
            + (state.editable ? '<span class="saved" id="ecc-saved"></span>' : '') + '<span class="spacer"></span>' + topButtons() + '</div>'
            + schedLine()
            + '<div class="builder"><div>'
            + report()
            + (state.editable ? steps(list, doneCount) : details())
            + '</div><aside class="preview-pane"><div class="pp-head"><span>' + esc(t.preview) + '</span><div class="seg">'
            + '<button type="button" data-dev="desktop" class="' + (device === 'desktop' ? 'on' : '') + '">' + esc(t.desktop) + '</button>'
            + '<button type="button" data-dev="mobile" class="' + (device === 'mobile' ? 'on' : '') + '">' + esc(t.mobile) + '</button></div></div>'
            + '<div class="inbox-mock"><div class="cap">' + esc(t.inbox) + '</div><div class="im-row"><span class="dot"></span>'
            + '<span class="fr" id="ecc-im-from"></span><span class="sj"><b id="ecc-im-subj"></b> <span id="ecc-im-pre"></span></span><span class="tm">09:30</span></div></div>'
            + '<div class="pp-body"><div class="pp-fit" id="ecc-fit"><iframe class="pp-frame ' + device + '" id="ecc-frame" sandbox="" title="' + esc(t.preview) + '"></iframe></div></div></aside></div>';

        paintNamed();
        paintSaved();
        paintLive();
        paintFrames();
        bind();
    }

    // The inbox row, from what is in the fields right now
    function paintLive() {
        var fn = $('#ecc-fn'), sj = $('#ecc-sj'), pr = $('#ecc-pr');
        var from = (fn && fn.value.trim()) || state.from.display_name || state.from.email;
        var subject = sj ? sj.value : state.subject;
        var pre = pr ? pr.value : state.preheader;

        $('#ecc-im-from').textContent = from;
        $('#ecc-im-subj').textContent = sample(subject) || t.yourSubject;
        $('#ecc-im-pre').textContent = '— ' + (sample(pre) || t.previewHere);
    }

    /*
     * The desktop preview is the email at the width a desktop mail client
     * gives it, scaled down to fit the pane - not the email squeezed into
     * the pane, which would show a layout no recipient ever sees.
     */
    var DESKTOP_WIDTH = 700;
    var FRAME_HEIGHT = 900;

    function fitFrame() {
        var fit = $('#ecc-fit'), frame = $('#ecc-frame');
        if (!fit || !frame) { return; }

        var room = fit.parentNode.clientWidth - 24;
        var width = device === 'mobile' ? 375 : DESKTOP_WIDTH;
        var scale = Math.min(1, room / width);

        frame.style.width = width + 'px';
        frame.style.height = FRAME_HEIGHT + 'px';
        frame.style.transform = 'scale(' + scale + ')';
        fit.style.width = Math.round(width * scale) + 'px';
        fit.style.height = Math.round(FRAME_HEIGHT * scale) + 'px';
    }

    window.addEventListener('resize', fitFrame);

    function paintFrames() {
        fitFrame();
        var empty = '<html><body style="font-family:-apple-system,Segoe UI,sans-serif;display:grid;place-items:center;height:90vh;color:#a1a1aa;margin:0;background:#f4f4f5">'
            + esc(t.designFirst) + '</body></html>';
        $('#ecc-frame').srcdoc = state.content.html || empty;

        var thumb = $('#ecc-thumbf');
        if (thumb) { thumb.srcdoc = state.content.html || empty; }
    }

    function readSegments() {
        var chosen = {included: [], excluded: [], all: state.to.all ? 1 : 0};

        // Sent to everybody, the boxes for "people in" are not on the page: keep what was ticked
        if (state.to.all) { chosen.included = state.to.included.slice(); }

        $$('[data-seg]').forEach(function (box) { if (box.checked) { chosen[box.getAttribute('data-seg')].push(parseInt(box.value, 10)); } });
        if (arguments.length) { chosen.all = arguments[0] ? 1 : 0; }
        return JSON.stringify(chosen);
    }

    function content(fields) {
        return post(cfg.urls.content, fields).then(function (json) { applyState(json, true); }, function (error) { toast(error.message); });
    }

    function bind() {
        $$('.sec-head').forEach(function (head) {
            head.onclick = function () {
                if (!named()) { askName(); return; }
                var k = head.parentNode.getAttribute('data-k');
                open = open === k ? null : k;
                render();
            };
        });

        $$('[data-next]').forEach(function (button) {
            button.onclick = function (e) {
                if (!named()) { e.stopPropagation(); askName(); return; }
                var keys = sections().map(function (s) { return s.k; });
                var at = keys.indexOf(button.getAttribute('data-next'));
                open = state.editable && at < keys.length - 1 ? keys[at + 1] : null;
                render();
                var el = open && $('.sec[data-k="' + open + '"]');
                if (el && el.scrollIntoView) { el.scrollIntoView({behavior: 'smooth', block: 'nearest'}); }
            };
        });

        $$('[data-dev]').forEach(function (button) {
            button.onclick = function () { device = button.getAttribute('data-dev'); render(); };
        });

        $$('[data-go]').forEach(function (button) {
            button.onclick = function () {
                var go = function () {
                    var form = document.createElement('form');
                    form.method = 'post';
                    form.action = cfg.urls[button.getAttribute('data-go')];
                    var key = document.createElement('input');
                    key.type = 'hidden'; key.name = 'form_key'; key.value = cfg.formKey;
                    form.appendChild(key);
                    document.body.appendChild(form);
                    form.submit();
                };
                var question = button.getAttribute('data-confirm');
                if (question) {
                    modal({title: button.textContent, desc: question, label: button.textContent, danger: true, onConfirm: go});
                } else {
                    go();
                }
            };
        });

        var test = $('#ecc-test');
        if (test) {
            test.onclick = function () {
                modal({
                    title: t.sendTest, label: t.sendTest,
                    body: '<div class="field"><label for="ecc-testto">' + esc(t.testTo) + '</label><input type="email" id="ecc-testto" value="' + esc(cfg.adminEmail) + '"></div>',
                    onConfirm: function (dialog) {
                        var to = $('#ecc-testto', dialog).value.trim();
                        return post(cfg.urls.test, {test_email: to}).then(function () { toast(tr('testSent', to)); });
                    }
                });
            };
        }

        if (!state.editable) { return; }

        var nameBox = $('#ecc-name');
        nameBox.oninput = function () {
            paintNamed();
            var send = $('#ecc-go');
            if (send) { send.disabled = !(isReady() && named()); }
            save({name: this.value});
        };
        nameBox.onkeydown = function (e) { if (e.key === 'Enter') { e.preventDefault(); nameBox.blur(); } };
        // Just named, and nothing open yet: on to the first step that needs filling in
        nameBox.onblur = function () {
            if (nameAsked && named() && open === null) {
                nameAsked = false;
                open = firstUnfinished();
                renderLater = true;
            }
        };
        // Only while it is still empty: taking the cursor back after that would get in the way of the next click
        if (!named() && !document.querySelector('.ecc-modal-back')) { nameBox.focus(); }

        $$('[data-seg]').forEach(function (box) {
            box.onchange = function () { save({segments: readSegments()}, true); };
        });
        $$('[data-all]').forEach(function (card) {
            card.onclick = function () {
                var all = card.getAttribute('data-all') === '1';
                if (all !== state.to.all) { save({segments: readSegments(all)}, true); }
            };
        });

        var storeSelect = $('#ecc-store'), brandBox = $('#ecc-brand');
        if (storeSelect) { storeSelect.onchange = function () { save({store_id: storeSelect.value}, true); }; }
        if (brandBox) { brandBox.onchange = function () { save({use_brand: brandBox.checked ? 1 : 0}, true); }; }

        var fn = $('#ecc-fn'), fl = $('#ecc-fl'), rt = $('#ecc-rt'), sj = $('#ecc-sj'), pr = $('#ecc-pr');
        if (fn) { fn.oninput = function () { paintLive(); save({from_name: fn.value}); }; }
        if (fl) { fl.oninput = function () { save({from_local: fl.value}); }; }
        if (rt) { rt.onchange = function () { save({reply_to: rt.value}); }; }
        if (sj) {
            sj.oninput = function () {
                var counter = $('#ecc-sjc');
                counter.textContent = tr('recommended', seen(sj.value));
                counter.className = 'counter' + (seen(sj.value) > 60 ? ' over' : '');
                paintLive();
                save({subject: sj.value});
            };
        }
        if (pr) { pr.oninput = function () { paintLive(); save({preheader: pr.value}); }; }

        /*
         * "Suggest with AI": a few subject lines and previews written from
         * the email. Choosing one puts it in the box and saves it like typing
         * would; nothing changes until one is chosen.
         */
        var ai = $('#ecc-ai');
        if (ai) {
            ai.onclick = function () {
                ai.disabled = true;
                ai.textContent = t.suggesting;
                post(cfg.urls.suggest, {subject: sj.value, preheader: pr.value}).then(function (json) {
                    aiOffer = {subjects: json.subjects || [], preheaders: json.preheaders || []};
                    $('#ecc-ai-out').innerHTML = aiList();
                    aiBind();
                }).catch(function (error) {
                    $('#ecc-ai-out').innerHTML = '<p class="help bad">' + esc(error.message) + '</p>';
                }).then(function () {
                    var button = $('#ecc-ai');
                    if (button) { button.disabled = false; button.textContent = aiOffer ? t.suggestAgain : t.suggest; }
                });
            };
            aiBind();
        }

        $$('[data-starter]').forEach(function (button) {
            button.onclick = function () { button.disabled = true; content({source: 'starter', starter: button.getAttribute('data-starter')}); };
        });
        $$('[data-tpl]').forEach(function (button) {
            button.onclick = function () { button.disabled = true; content({source: 'template', template_id: button.getAttribute('data-tpl')}); };
        });

        var designer = function () { window.location.href = cfg.urls.designer; };
        var startLetter = $('#ecc-letter'), lt = $('#ecc-lt'), ltk = $('#ecc-ltk'), unletter = $('#ecc-unletter');
        if (startLetter) { startLetter.onclick = function () { startLetter.disabled = true; content({source: 'letter'}); }; }
        if (lt) { lt.oninput = function () { save({letter_text: lt.value}); }; }
        if (ltk) { ltk.onchange = function () { save({letter_track: ltk.checked ? 1 : 0}, true); }; }
        if (unletter) { unletter.onclick = function () { content({source: 'clear'}); }; }

        var thumb = $('#ecc-thumb'), edit = $('#ecc-edit'), over = $('#ecc-over');
        if (thumb) { thumb.onclick = designer; }
        if (edit) { edit.onclick = designer; }
        if (over) {
            over.onclick = function () {
                modal({title: t.startOver, desc: t.startOverConfirm, label: t.startOver, danger: true,
                    onConfirm: function () { return content({source: 'clear'}); }});
            };
        }

        $$('[data-when]').forEach(function (card) {
            card.onclick = function () { when.mode = card.getAttribute('data-when'); render(); };
        });
        var wd = $('#ecc-wd'), wt = $('#ecc-wt');
        var wnote = function () { var n = $('#ecc-wnote'); if (n) { n.innerHTML = chosenNote(); } };
        if (wd) { wd.onchange = function () { when.date = wd.value; wnote(); }; wd.oninput = wd.onchange; }
        if (wt) { wt.onchange = function () { when.time = wt.value; wnote(); }; wt.oninput = wt.onchange; }

        var unschedule = $('#ecc-unsch');
        if (unschedule) {
            unschedule.onclick = function () {
                post(cfg.urls.unschedule, {}).then(function (json) { when.mode = null; applyState(json, true); }, function (error) { toast(error.message); });
            };
        }

        var go = $('#ecc-go');
        if (go) {
            go.onclick = function () {
                if (!when.mode) { open = 'when'; render(); return; }

                var now = when.mode === 'now';
                var n = state.to.recipients === null ? '' : fmt(state.to.recipients);

                modal({
                    title: now ? t.sendNow : t.schedule,
                    desc: now ? tr('confirmNow', n) : tr('confirmLater', when.date, when.time, state.when.timezone),
                    body: '<p class="faint">' + esc(state.subject) + '</p>',
                    label: now ? t.sendNow : t.schedule,
                    onConfirm: function () {
                        return post(cfg.urls.send, now ? {mode: 'now'} : {mode: 'scheduled', date: when.date, time: when.time})
                            .then(function (json) {
                                state = json.state;
                                open = null;
                                render();
                                toast(now ? t.started : t.scheduled);
                            });
                    }
                });
            };
        }
    }

    // Leaving with a save still waiting would lose it
    window.addEventListener('beforeunload', function (e) {
        if (Object.keys(pending).length) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    render();
}());
