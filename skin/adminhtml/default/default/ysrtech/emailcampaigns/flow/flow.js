/* The flow canvas.
 *
 * Laid out and behaving as TzizamHub's journey editor does (app/journeys.js): a bar with the flow's name
 * and its on/off control, a dotted canvas that pans and zooms, steps as cards joined left to right with a
 * "+" on every connection and after the last step, a panel that slides in to choose what comes next, and
 * a panel for the selected step's settings.
 *
 * What a flow can be made of is this module's engine: one trigger, then waits and emails in a line. So
 * there is no branching here, and the steps are placed for you rather than dragged about.
 *
 * It saves by itself while a flow is a draft. A flow that is on cannot be changed until it is paused,
 * which is the engine's rule, and the canvas says so rather than letting an edit be refused.
 */
(function () {
  'use strict';
  const C = window.EC_FLOW;
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fmt = (n) => Math.round(n).toLocaleString('en-US');

  const P = {
    back: '<path d="m15 18-6-6 6-6"/>', plus: '<path d="M12 5v14M5 12h14"/>', x: '<path d="M18 6 6 18M6 6l12 12"/>', zap: '<path d="M13 2 4 14h7l-1 8 9-12h-7l1-8Z"/>',
    mail: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>', clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    gear: '<circle cx="12" cy="12" r="3"/><path d="M19 12a7 7 0 0 0-.1-1.2l2-1.5-2-3.4-2.3 1a7 7 0 0 0-2-1.2L14.2 3h-4l-.4 2.6a7 7 0 0 0-2 1.2l-2.4-1-2 3.4 2 1.6a7 7 0 0 0 0 2.4l-2 1.6 2 3.4 2.400-1a7 7 0 0 0 2 1.2l.4 2.6h4l.4-2.600a7 7 0 0 0 2-1.200l2.300 1 2-3.400-2-1.600A7 7 0 0 0 19 12Z"/>',
    trash: '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>', alert: '<path d="M12 3 2 20h20L12 3Z"/><path d="M12 10v4M12 17.5v.5"/>', search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.500-3.500"/>',
    cart: '<path d="M3 4h2l2.500 11h10l2-8H6"/><circle cx="9" cy="19" r="1.500"/><circle cx="17" cy="19" r="1.500"/>', box: '<path d="M3 8 12 3l9 5v8l-9 5-9-5V8Z"/><path d="m3 8 9 5 9-5M12 13v8"/>',
    truck: '<path d="M3 6h11v10H3zM14 10h4l3 3v3h-7z"/><circle cx="7" cy="18" r="1.800"/><circle cx="17.500" cy="18" r="1.800"/>', receipt: '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3Z"/><path d="M9 8h6M9 12h6"/>',
    undo: '<path d="M9 14 4 9l5-5"/><path d="M4 9h10.500a5.500 5.500 0 0 1 0 11H11"/>', tag: '<path d="M3 12V4h8l10 10-8 8L3 12Z"/><circle cx="7.500" cy="8.500" r="1.200"/>', edit: '<path d="M4 20h4L19 9l-4-4L4 16v4Z"/>', left: '<path d="m15 18-6-6 6-6"/>', right: '<path d="m9 18 6-6-6-6"/>',
  };
  const ic = (n, s = 17, w = 1.800) => `<svg width="${s}" height="${s}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="${w}" stroke-linecap="round" stroke-linejoin="round">${P[n] || ''}</svg>`;
  function toast(m) { const t = $('#toast'); t.textContent = m; t.classList.add('show'); clearTimeout(toast.t); toast.t = setTimeout(() => t.classList.remove('show'), 2600); }

  // ─── What a flow is made of ───
  const SEND = 'action_send_email', WAIT = 'delay';
  const TYPE = {
    trigger: ['zap', 'var(--accent-soft)', 'var(--accent-text)', 'Trigger'],
    [WAIT]: ['clock', 'var(--s-deferred-bg)', 'var(--s-deferred)', 'Timing'],
    [SEND]: ['mail', 'var(--s-opened-bg)', 'var(--s-opened)', 'Message'],
  };
  const TRIG_ICON = { new_order: 'receipt', order_status: 'tag', product_purchased: 'box', abandoned_cart: 'cart', shipment: 'truck', invoice: 'receipt', creditmemo: 'undo' };
  const TRIG_NOTE = {
    new_order: 'As soon as an order is placed.', order_status: 'When an order moves to the status you choose.', product_purchased: 'When an order contains one particular product.',
    abandoned_cart: 'When a cart has been left with things in it.', shipment: 'When a shipment is created for an order.', invoice: 'When an invoice is created for an order.', creditmemo: 'When an order is refunded.',
  };
  const STEPS = [[SEND, 'Send an email', 'One of your templates, to the person the flow is about'], [WAIT, 'Wait', 'Hold for minutes, hours or days before the next step']];
  const NODE_W = 236, GAP = 96, TOP = 140, LEFT = 80;

  // The flow as it is edited: the trigger's settings, then the steps in order. The server keeps it as
  // nodes and connections; this is that drawing walked from the trigger.
  let uidN = 0; const uid = () => 's' + Date.now().toString(36).slice(-5) + (uidN++).toString(36);
  function fromGraph(g) {
    const nodes = (g && g.nodes) || [], edges = (g && g.edges) || [], byId = Object.fromEntries(nodes.map((n) => [n.id, n]));
    const trig = nodes.find((n) => n.type === 'trigger') || { id: 'trigger', config: {} };
    const steps = [], seen = new Set([trig.id]);
    let at = trig.id;
    for (;;) { const e = edges.find((x) => x.source === at && byId[x.target] && !seen.has(x.target)); if (!e) break; const n = byId[e.target]; seen.add(n.id); steps.push({ id: n.id, type: n.type, config: Object.assign({}, n.config) }); at = n.id; }
    return { trigger: { id: trig.id, config: Object.assign({ trigger_type: '', order_status: '', product_id: 0 }, trig.config) }, steps };
  }
  const xOf = (i) => LEFT + i * (NODE_W + GAP);
  function toGraph() {
    const all = [{ id: f.trigger.id, type: 'trigger', config: f.trigger.config }].concat(f.steps);
    return { nodes: all.map((n, i) => ({ id: n.id, type: n.type, position: { x: xOf(i), y: TOP }, config: n.config })), edges: all.slice(1).map((n, i) => ({ source: all[i].id, target: n.id })) };
  }

  const f = fromGraph(C.graph);
  // A flow nobody has given a trigger yet starts with the "Add first step" placeholder
  if (!C.id && !C.graphStored) f.trigger.config.trigger_type = '';
  const meta = { name: C.name || 'Untitled flow', storeId: C.storeId, settings: Object.assign({}, C.settings) };
  let id = C.id, status = C.status, version = C.version, publishedLabel = C.publishedLabel, problems = C.problems || [];
  let sel = null, panel = null, insertAt = null, q = '', view = { x: 0, y: 0, k: 1 };
  let dirty = false, saveTimer = null, saving = null, saveState = '';
  const ro = () => status === 'active';

  const eventLabel = (code) => (C.events.find((e) => e.value === code) || { label: code }).label;
  const tplName = (tid) => (C.templates.find((t) => t.id === +tid) || {}).name;
  const plural = (n, one, many) => n + ' ' + (n === 1 ? one : many);
  function describe(n) {
    const c = n.config;
    if (n.type === 'trigger') {
      if (c.trigger_type === 'order_status') return c.order_status ? 'Status: ' + ((C.orderStatuses.find((s) => s.value === c.order_status) || {}).label || c.order_status) : 'Choose a status';
      if (c.trigger_type === 'product_purchased') return +c.product_id ? 'Product #' + c.product_id : 'Choose a product';
      return TRIG_NOTE[c.trigger_type] || '';
    }
    if (n.type === WAIT) return +c.value > 0 ? 'Then carry on' : 'Set how long';
    return tplName(c.template_id) ? 'Template' : 'Choose a template';
  }
  function titleOf(n) {
    const c = n.config;
    if (n.type === 'trigger') return eventLabel(c.trigger_type);
    if (n.type === WAIT) return +c.value > 0 ? 'Wait ' + plural(+c.value, c.unit.replace(/s$/, ''), c.unit) : 'Wait';
    return tplName(c.template_id) || 'Send an email';
  }
  const all = () => [Object.assign({ type: 'trigger' }, f.trigger)].concat(f.steps);
  const find = (nid) => all().find((n) => n.id === nid);
  const probFor = (nid) => problems.filter((p) => p.node === nid);
  // A message's figures are kept by its place among the messages
  const sendIndex = (nid) => f.steps.filter((s) => s.type === SEND).findIndex((s) => s.id === nid);

  // ─── Shell ───
  const v = $('#ecf');
  v.innerHTML = `<div class="jx">
    <header class="jx-bar"><a class="jx-back" href="${esc(C.urls.back)}" id="back" title="Back to automations">${ic('back', 16)}</a><div class="jx-name"><input id="jname" value="${esc(meta.name)}" maxlength="255" aria-label="Flow name"><small id="jsub"></small></div>
      <div class="spacer"></div>
      <button class="btn" id="jset">${ic('gear', 15)}Settings</button>
      <span id="jctl" class="jx-ctl"></span>
    </header>
    <div class="jx-body">
      <div class="jx-canvas" id="cv"><div class="jx-world" id="world"><svg class="jx-edges" id="edges"></svg><div id="nodes"></div></div>
        <div class="jx-tools"><button id="zin" title="Zoom in">${ic('plus', 15)}</button><button id="zout" title="Zoom out"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M5 12h14"/></svg></button><button id="zfit" title="Fit to view"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/></svg></button><span id="zpct">100%</span></div>
        <div class="jx-probs" id="probs"></div>
        <div class="jx-hint" id="hint">Drag to pan · Ctrl + scroll to zoom · click a step to set it up</div>
      </div>
      <aside class="jx-panel right creator" id="pal"></aside>
      <aside class="jx-panel right" id="insp"></aside>
    </div></div><div class="toast" id="toast"></div>`;

  // ─── Saving ───
  function post(fields) {
    const body = new FormData();
    body.append('form_key', C.formKey);
    Object.keys(fields).forEach((k) => body.append(k, fields[k]));
    return fetch(C.urls.save.replace('__ID__', id || 0), { method: 'POST', body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then((r) => r.json());
  }
  function send(want) {
    return post({
      graph_json: JSON.stringify(toGraph()), name: meta.name.trim() || 'Untitled flow', status: want, store_id: meta.storeId,
      cancel_on: meta.settings.cancel_on, respect_subscription: meta.settings.respect_subscription ? 1 : 0, active_from: meta.settings.active_from || '', active_to: meta.settings.active_to || '',
    }).then((j) => {
      if (!j || !j.success) throw new Error((j && j.message) || 'Could not save.');
      if (!id && j.id) { id = j.id; try { history.replaceState(null, '', C.urls.canvas.replace('__ID__', id)); } catch (e) {} }
      status = j.status; version = j.version; publishedLabel = j.published_label; problems = j.problems || [];
      return j;
    });
  }
  const touch = () => { if (ro()) return; dirty = true; saveState = 'Unsaved changes…'; renderHead(); clearTimeout(saveTimer); saveTimer = setTimeout(save, 1200); };
  function save() {
    clearTimeout(saveTimer);
    if (!dirty) return saving || Promise.resolve(true);
    dirty = false; saveState = 'Saving…'; renderHead();
    const run = () => send('draft').then(() => { saveState = ''; renderHead(); markProblems(); return true; }).catch((e) => { dirty = true; saveState = 'Not saved: ' + e.message; renderHead(); return false; });
    saving = (saving || Promise.resolve()).then(run);
    return saving;
  }
  window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

  // ─── Header: status, on and off, save state ───
  function renderHead() {
    $('#jsub').textContent = saveState || (status === 'active' ? `On · version ${version}${publishedLabel ? ' · since ' + publishedLabel : ''}` : id ? 'Draft · not sending' : 'New flow · not saved yet');
    $('#jname').disabled = ro();
    $('#jctl').innerHTML = (status === 'active' ? '<span class="jx-status on"><span class="dot"></span>Active</span>' : '<span class="jx-status"><span class="dot"></span>Draft</span>')
      + (status === 'active' ? '<button class="btn" id="jpause">Pause to edit</button>' : `<button class="btn btn-primary" id="jpub">${ic('zap', 15)}Turn on</button>`);
    const pb = $('#jpub'); if (pb) pb.onclick = publish;
    const pz = $('#jpause'); if (pz) pz.onclick = pause;
  }
  function publish() {
    clearTimeout(saveTimer); dirty = false;
    (saving || Promise.resolve()).then(() => send('active')).then((j) => {
      saveState = ''; delete $('#probs').dataset.hidden;
      if (j.demoted) toast(plural(problems.length, 'thing', 'things') + ' to fix before it can go live'); else { sel = null; panel = null; toast('This flow is on.'); }
      renderAll();
    }).catch((e) => { toast(e.message); saveState = 'Not saved: ' + e.message; renderHead(); });
  }
  function pause() {
    send('draft').then(() => { toast('Paused. Nothing new starts; messages already queued are still sent.'); renderAll(); }).catch((e) => toast(e.message));
  }

  // ─── Canvas ───
  const cv = $('#cv'), world = $('#world');
  const apply = () => { world.style.transform = `translate(${view.x}px,${view.y}px) scale(${view.k})`; cv.style.backgroundSize = `${22 * view.k}px ${22 * view.k}px`; cv.style.backgroundPosition = `${view.x}px ${view.y}px`; $('#zpct').textContent = Math.round(view.k * 100) + '%'; };
  const nodeH = (nid) => { const el = $(`[data-n="${nid}"]`, world); return el ? el.offsetHeight : 66; };
  function nodeStats(n) {
    if (n.type !== SEND) return '';
    const c = C.counts[sendIndex(n.id)];
    if (!c || !(c.sent + c.waiting + c.failed + c.cancelled)) return '';
    return `<div class="nd-stats"><span><b>${fmt(c.sent)}</b>Sent</span><span><b>${fmt(c.waiting)}</b>Waiting</span>${c.cancelled ? `<span><b>${fmt(c.cancelled)}</b>Stopped</span>` : ''}${c.failed ? `<span><b>${fmt(c.failed)}</b>Failed</span>` : ''}</div>`;
  }
  function renderNodes() {
    const list = all(), empty = !f.trigger.config.trigger_type;
    $('#nodes').innerHTML = list.map((n, i) => {
      const [, bg, col, kind] = TYPE[n.type], bad = probFor(n.id);
      if (n.type === 'trigger' && empty) return `<div class="nd nd-start" data-n="${n.id}" style="left:${xOf(0)}px;top:${TOP - 40}px;width:${NODE_W}px" title="Add first step"><span class="ns-box">${ic('plus', 30, 2)}</span><b>Add first step…</b><small>What starts this flow?</small></div>`;
      const glyph = n.type === 'trigger' ? TRIG_ICON[n.config.trigger_type] || 'zap' : TYPE[n.type][0];
      return `<div class="nd t-${n.type === SEND ? 'email' : n.type === WAIT ? 'wait' : 'trigger'} ${sel === n.id ? 'nd-sel' : ''} ${bad.length ? 'nd-bad' : ''}" data-n="${n.id}" style="left:${xOf(i)}px;top:${TOP}px;width:${NODE_W}px">
        <div class="nd-main"><span class="nd-ic" style="background:${bg};color:${col}">${ic(glyph, 18)}</span><div class="nd-txt"><small>${n.type === 'trigger' ? ic('zap', 10) + ' Trigger' : kind}</small><b>${esc(titleOf(n))}</b><span>${esc(describe(n))}</span></div></div>
        ${nodeStats(n)}${bad.length ? `<span class="nd-warn" title="${esc(bad[0].message)}">!</span>` : ''}</div>`;
    }).join('');
    $$('.nd', world).forEach((el) => el.onclick = (e) => { e.stopPropagation(); const n = find(el.dataset.n); if (n.type === 'trigger' && !f.trigger.config.trigger_type) return openPanel('triggers'); select(n.id); });
    renderEdges();
  }
  function renderEdges() {
    const list = all(), empty = !f.trigger.config.trigger_type, svg = $('#edges');
    svg.setAttribute('width', xOf(list.length) + 400); svg.setAttribute('height', TOP + 400);
    if (empty) { svg.innerHTML = ''; return; }
    const mid = (n, i) => TOP + nodeH(n.id) / 2;
    let h = '';
    for (let i = 1; i < list.length; i++) {
      const x1 = xOf(i - 1) + NODE_W, y1 = mid(list[i - 1]), x2 = xOf(i), y2 = mid(list[i]), dx = Math.max(40, (x2 - x1) / 2), d = `M${x1},${y1} C${x1 + dx},${y1} ${x2 - dx},${y2} ${x2},${y2}`;
      h += `<g class="edge"><path d="${d}" class="e-hit"/><path d="${d}" class="e-line" style="stroke:var(--border-strong);stroke-width:1.6"/>${ro() ? '' : `<foreignObject x="${(x1 + x2) / 2 - 14}" y="${(y1 + y2) / 2 - 13}" width="28" height="26" class="e-plus-wrap"><div class="e-acts"><button class="e-plus" data-ins="${i - 1}" title="Add a step here">+</button></div></foreignObject>`}</g>`;
    }
    if (!ro()) { // the last step ends in a +, as every open end does
      const last = list[list.length - 1], x1 = xOf(list.length - 1) + NODE_W, y1 = mid(last), x2 = x1 + 64;
      h += `<g class="stub"><path d="M${x1},${y1} L${x2},${y1}" class="stub-line"/><foreignObject x="${x2}" y="${y1 - 13}" width="28" height="26"><button class="stub-plus" data-ins="${list.length - 1}" title="Add a step">+</button></foreignObject></g>`;
    }
    svg.innerHTML = h;
    $$('[data-ins]', svg).forEach((b) => b.onclick = (e) => { e.stopPropagation(); insertAt = +b.dataset.ins; openPanel('steps'); });
  }
  function fit() {
    const r = cv.getBoundingClientRect(), n = all().length, w = xOf(n - 1) + NODE_W + 110 - LEFT, padR = sel || panel ? 410 : 40, aw = Math.max(240, r.width - 40 - padR);
    view.k = Math.max(.45, Math.min(1, aw / w));
    view.x = 40 + Math.max(0, (aw - w * view.k) / 2) - LEFT * view.k;
    view.y = Math.max(20, (r.height - 120) / 2 - (TOP + 40) * view.k);
    apply();
  }
  const zoomAt = (k, cx, cy) => { k = Math.max(.3, Math.min(1.800, k)); view.x = cx - (cx - view.x) * (k / view.k); view.y = cy - (cy - view.y) * (k / view.k); view.k = k; apply(); };
  $('#zin').onclick = () => { const r = cv.getBoundingClientRect(); zoomAt(view.k * 1.200, r.width / 2, r.height / 2); };
  $('#zout').onclick = () => { const r = cv.getBoundingClientRect(); zoomAt(view.k / 1.200, r.width / 2, r.height / 2); };
  $('#zfit').onclick = fit;
  cv.addEventListener('wheel', (e) => { e.preventDefault(); if (e.ctrlKey || e.metaKey) { const r = cv.getBoundingClientRect(); zoomAt(view.k * (e.deltaY < 0 ? 1.100 : 1 / 1.100), e.clientX - r.left, e.clientY - r.top); } else { view.x -= e.deltaX; view.y -= e.deltaY; apply(); } }, { passive: false });
  let pan = null;
  cv.addEventListener('mousedown', (e) => { if (e.target.closest('.nd, button, .jx-tools, .jx-probs')) return; pan = { x: e.clientX - view.x, y: e.clientY - view.y, moved: false }; cv.classList.add('panning'); });
  window.addEventListener('mousemove', (e) => { if (!pan) return; pan.moved = true; view.x = e.clientX - pan.x; view.y = e.clientY - pan.y; apply(); });
  window.addEventListener('mouseup', () => { if (!pan) return; const moved = pan.moved; pan = null; cv.classList.remove('panning'); if (!moved) { select(null); closePanel(); } });

  // ─── The panel that offers what can be added ───
  function openPanel(mode) { panel = mode; q = ''; sel = null; renderNodes(); renderInspector(); renderPanel(); const i = $('#plq'); if (i) i.focus(); }
  function closePanel() { panel = null; insertAt = null; renderPanel(); }
  function renderPanel() {
    const pal = $('#pal');
    pal.classList.toggle('open', !!panel);
    if (!panel) { pal.innerHTML = ''; return; }
    const trig = panel === 'triggers', match = (s) => s.toLowerCase().includes(q.trim().toLowerCase());
    const items = trig
      ? C.events.filter((e) => match(e.label + ' ' + (TRIG_NOTE[e.value] || ''))).map((e) => `<button class="pl-item" data-trig="${esc(e.value)}"><span class="pl-ic trig">${ic(TRIG_ICON[e.value] || 'zap', 15)}</span><span><b>${esc(e.label)}</b><small>${esc(TRIG_NOTE[e.value] || '')}</small></span></button>`)
      : STEPS.filter(([, a, b]) => match(a + ' ' + b)).map(([t, a, b]) => `<button class="pl-item" data-step="${t}"><span class="pl-ic" style="background:${TYPE[t][1]};color:${TYPE[t][2]}">${ic(TYPE[t][0], 15)}</span><span><b>${a}</b><small>${b}</small></span></button>`);
    const after = !trig && insertAt != null ? all()[insertAt] : null;
    pal.innerHTML = `<div class="pl-head"><div><b>${trig ? 'What starts this flow?' : 'What happens next?'}</b><small>${trig ? 'A flow starts from one thing that happens in the store.' : after ? `After “${esc(titleOf(after))}”` : ''}</small></div><button class="icon-btn" id="plx" aria-label="Close panel">${ic('x', 15)}</button></div>
      <div class="pl-search">${ic('search', 14)}<input id="plq" placeholder="Search ${trig ? 'triggers' : 'steps'}…" value="${esc(q)}" autocomplete="off"></div>
      <div class="pl-list">${items.join('') || '<p class="pl-none">Nothing matches. Try another word.</p>'}</div>
      ${trig ? '' : `<div class="pl-note">A flow is one line of waits and emails, up to ${C.limits.maxSends} emails. What ends it early, and who may receive it, are under Settings.</div>`}`;
    $('#plx').onclick = closePanel;
    const qi = $('#plq'); qi.oninput = () => { q = qi.value; const pos = qi.selectionStart; renderPanel(); const n2 = $('#plq'); n2.focus(); n2.setSelectionRange(pos, pos); };
    qi.onkeydown = (e) => { if (e.key === 'Escape') closePanel(); if (e.key === 'Enter') { const first = $('.pl-item', pal); if (first) first.click(); } };
    $$('[data-trig]', pal).forEach((b) => b.onclick = () => { f.trigger.config.trigger_type = b.dataset.trig; panel = null; touch(); renderAll(); select(f.trigger.id); fit(); });
    $$('[data-step]', pal).forEach((b) => b.onclick = () => {
      const t = b.dataset.step, n = { id: uid(), type: t, config: t === WAIT ? { value: 1, unit: 'days' } : { template_id: 0 } };
      f.steps.splice(insertAt == null ? f.steps.length : insertAt, 0, n);
      panel = null; insertAt = null; touch(); renderAll(); select(n.id); fit();
    });
  }

  // ─── The panel for the selected step, or for the flow's settings ───
  function select(nid) { sel = nid; if (nid) panel = null; renderPanel(); renderNodes(); renderInspector(); }
  const field = (label, ctrl, help) => `<div class="field"><label>${label}</label>${ctrl}${help ? `<p class="ins-help">${help}</p>` : ''}</div>`;
  function renderInspector() {
    const insp = $('#insp'), n = sel === '#settings' ? null : find(sel), dis = ro() ? 'disabled' : '';
    insp.classList.toggle('open', !!n || sel === '#settings');
    if (sel === '#settings') {
      const s = meta.settings;
      insp.innerHTML = `<div class="in-head"><span class="nd-ic" style="background:var(--surface-2);color:var(--text-2)">${ic('gear', 18)}</span><div><small>Flow</small><b>Settings</b></div><button class="icon-btn" id="inx">${ic('x', 15)}</button></div><div class="in-body">
        ${ro() ? '<div class="ins-lock">This flow is on. Pause it to change its settings.</div>' : ''}
        ${field('Stop the flow if', `<select class="select" id="scancel" ${dis}><option value="never" ${s.cancel_on === 'never' ? 'selected' : ''}>Nothing: send every message</option><option value="order_placed" ${s.cancel_on === 'order_placed' ? 'selected' : ''}>They place an order</option></select>`, 'Checked as each message comes due, so somebody who orders an hour before a reminder does not receive it.')}
        ${field('Kind of message', `<select class="select" id="skind" ${dis}><option value="1" ${s.respect_subscription ? 'selected' : ''}>Marketing: hold back from anyone unsubscribed</option><option value="0" ${s.respect_subscription ? '' : 'selected'}>Transactional: send regardless</option></select>`, 'A receipt is transactional and should reach somebody who left the newsletter. A cart reminder is marketing and must not.')}
        ${field('Store view', `<select class="select" id="sstore" ${dis}>${C.storeOptions.map((o) => Array.isArray(o.value) ? `<optgroup label="${esc(String(o.label).trim())}">${o.value.map((x) => `<option value="${esc(x.value)}" ${+x.value === +meta.storeId ? 'selected' : ''}>${esc(String(x.label).trim())}</option>`).join('')}</optgroup>` : `<option value="${esc(o.value)}" ${+o.value === +meta.storeId ? 'selected' : ''}>${esc(String(o.label).trim())}</option>`).join('')}</select>`, 'Whose orders and carts start this flow, and whose name and links its emails use.')}
        <div class="row2">${field('Runs from', `<input type="date" id="sfrom" value="${esc((s.active_from || '').slice(0, 10))}" ${dis}>`)}${field('Runs until', `<input type="date" id="sto" value="${esc((s.active_to || '').slice(0, 10))}" ${dis}>`)}</div>
        <p class="ins-help">Leave both dates empty to run until you pause it. Useful for a seasonal message you do not want to remember to switch off.</p></div>`;
      $('#inx').onclick = () => select(null);
      if (!ro()) {
        $('#scancel').onchange = (e) => { s.cancel_on = e.target.value; touch(); };
        $('#skind').onchange = (e) => { s.respect_subscription = e.target.value === '1'; touch(); };
        $('#sstore').onchange = (e) => { meta.storeId = +e.target.value; touch(); };
        $('#sfrom').onchange = (e) => { s.active_from = e.target.value; touch(); };
        $('#sto').onchange = (e) => { s.active_to = e.target.value; touch(); };
      }
      return;
    }
    if (!n) { insp.innerHTML = ''; return; }
    const [, bg, col, kind] = TYPE[n.type], c = n.config, bad = probFor(n.id), i = f.steps.findIndex((x) => x.id === n.id);
    let body = bad.map((p) => `<div class="ins-warn">${ic('alert', 14)}<span>${esc(p.message)}</span></div>`).join('');
    if (ro()) body += '<div class="ins-lock">This flow is on. Pause it to make changes.</div>';
    if (n.type === 'trigger') {
      body += `<div class="trig-card"><span class="tc-ic">${ic(TRIG_ICON[c.trigger_type] || 'zap', 16, 2)}</span><div class="tc-txt"><small>Trigger</small><b>${esc(eventLabel(c.trigger_type))}</b><span>${esc(TRIG_NOTE[c.trigger_type] || '')}</span></div>${ro() ? '' : '<button class="btn btn-sm" id="chg">Change</button>'}</div>`
        + (c.trigger_type === 'order_status' ? field('Which status', `<select class="select" id="tstatus" ${dis}><option value="">Choose a status…</option>${C.orderStatuses.map((s) => `<option value="${esc(s.value)}" ${c.order_status === s.value ? 'selected' : ''}>${esc(s.label)}</option>`).join('')}</select>`) : '')
        + (c.trigger_type === 'product_purchased' ? field('Product ID', `<input type="number" min="1" id="tproduct" value="${+c.product_id || ''}" placeholder="e.g. 1234" ${dis}>`, 'The number in the product’s address in Catalog > Manage Products.') : '')
        + (c.trigger_type === 'abandoned_cart' ? '<p class="ins-help">Carts are looked at every fifteen minutes. A cart counts as left once it has sat untouched for the time set under System > Configuration > Email Campaigns.</p>' : '');
    } else if (n.type === WAIT) {
      body += field('Wait for', `<div class="rv-days"><input type="number" min="1" id="wval" value="${+c.value || ''}" ${dis}> <select class="select" id="wunit" ${dis}>${['minutes', 'hours', 'days'].map((u) => `<option ${c.unit === u ? 'selected' : ''}>${u}</option>`).join('')}</select></div>`, 'Counted from the step before it. The next message is held this long, then sent.');
    } else {
      const sent = C.counts[sendIndex(n.id)];
      body += field('Template', `<select class="select" id="stpl" ${dis}><option value="0">Choose a template…</option>${C.templates.map((t) => `<option value="${t.id}" ${+c.template_id === t.id ? 'selected' : ''}>${esc(t.name)}</option>`).join('')}</select>`, 'The email this step sends. Its subject and design come from the template.')
        + `<div class="ins-acts">${+c.template_id && tplName(c.template_id) ? `<a class="btn btn-sm" href="${esc(C.urls.templateDesigner.replace('__ID__', c.template_id))}" target="_blank" rel="noopener">${ic('edit', 13)}Edit this template</a>` : ''}<a class="btn btn-sm" href="${esc(C.urls.templateNew)}" target="_blank" rel="noopener">${ic('plus', 13)}New template</a></div>`
        + (sent && sent.sent + sent.waiting + sent.failed + sent.cancelled ? `<div class="ins-sec">So far</div><div class="kpis">${[['Sent', sent.sent], ['Waiting', sent.waiting], ['Stopped', sent.cancelled], ['Failed', sent.failed]].map(([l, x]) => `<div><small>${l}</small><b>${fmt(x)}</b></div>`).join('')}</div><p class="ins-help">Counted for the message at this place in the flow.</p>` : '');
    }
    insp.innerHTML = `<div class="in-head"><span class="nd-ic" style="background:${bg};color:${col}">${ic(n.type === 'trigger' ? TRIG_ICON[c.trigger_type] || 'zap' : TYPE[n.type][0], 18)}</span><div><small>${kind}</small><b>${esc(titleOf(n))}</b></div><button class="icon-btn" id="inx">${ic('x', 15)}</button></div>
      <div class="in-body">${body}</div>
      ${n.type !== 'trigger' && !ro() ? `<div class="in-foot"><button class="btn btn-sm" id="mvl" ${i === 0 ? 'disabled' : ''} title="Move earlier">${ic('left', 13)}Earlier</button><button class="btn btn-sm" id="mvr" ${i === f.steps.length - 1 ? 'disabled' : ''} title="Move later">Later${ic('right', 13)}</button><button class="btn btn-sm" id="del" style="margin-left:auto">${ic('trash', 13)}Remove</button></div>` : ''}`;
    $('#inx').onclick = () => select(null);
    if (ro()) return;
    const changed = () => { touch(); renderNodes(); $('.in-head b', insp).textContent = titleOf(n); };
    const on = (sel2, ev, fn) => { const el = $(sel2, insp); if (el) el[ev] = fn; };
    on('#chg', 'onclick', () => openPanel('triggers'));
    on('#tstatus', 'onchange', (e) => { c.order_status = e.target.value; changed(); });
    on('#tproduct', 'oninput', (e) => { c.product_id = Math.max(0, parseInt(e.target.value, 10) || 0); changed(); });
    on('#wval', 'oninput', (e) => { c.value = Math.max(0, parseInt(e.target.value, 10) || 0); changed(); });
    on('#wunit', 'onchange', (e) => { c.unit = e.target.value; changed(); });
    on('#stpl', 'onchange', (e) => { c.template_id = +e.target.value; touch(); renderNodes(); renderInspector(); });
    on('#mvl', 'onclick', () => { f.steps.splice(i - 1, 0, f.steps.splice(i, 1)[0]); touch(); renderNodes(); renderInspector(); });
    on('#mvr', 'onclick', () => { f.steps.splice(i + 1, 0, f.steps.splice(i, 1)[0]); touch(); renderNodes(); renderInspector(); });
    on('#del', 'onclick', () => { f.steps.splice(i, 1); sel = null; touch(); renderAll(); });
  }

  // ─── What stands between this flow and being on ───
  function renderProblems() {
    const box = $('#probs');
    box.innerHTML = problems.length ? `<div class="jp-head">${ic('alert', 14)}${problems.length} to fix before it can be turned on<button class="icon-btn" id="pbx" title="Hide">${ic('x', 13)}</button></div>${problems.map((p, i) => `<button class="jp-row" data-pi="${i}">${esc(p.message)}</button>`).join('')}` : '';
    box.classList.toggle('on', !!problems.length && !box.dataset.hidden && status !== 'active');
    const x = $('#pbx'); if (x) x.onclick = () => { box.dataset.hidden = '1'; box.classList.remove('on'); };
    $$('.jp-row', box).forEach((b) => b.onclick = () => { const p = problems[+b.dataset.pi]; if (p.node && find(p.node)) select(p.node); });
  }
  function markProblems() { renderNodes(); renderProblems(); if (sel && sel !== '#settings') renderInspector(); }
  function renderAll() { renderHead(); renderNodes(); renderPanel(); renderInspector(); renderProblems(); $('#hint').style.display = ro() ? 'none' : ''; }

  $('#jname').oninput = (e) => { meta.name = e.target.value; touch(); };
  $('#jset').onclick = () => select(sel === '#settings' ? null : '#settings');
  $('#back').onclick = (e) => { if (!dirty) return; e.preventDefault(); save().then((ok) => { if (ok) { dirty = false; location.href = C.urls.back; } }); };
  document.addEventListener('keydown', (e) => {
    const editing = e.target.closest && e.target.closest('input,textarea,select');
    if (e.key === 'Escape') { if (panel) closePanel(); else select(null); }
    if ((e.key === 'Delete' || e.key === 'Backspace') && !editing && sel && sel !== '#settings' && !ro()) { const i = f.steps.findIndex((x) => x.id === sel); if (i > -1) { e.preventDefault(); f.steps.splice(i, 1); sel = null; touch(); renderAll(); } }
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 's') { e.preventDefault(); if (!ro()) { dirty = true; save().then((ok) => ok && toast('Saved')); } }
  });
  window.addEventListener('resize', fit);

  renderAll(); fit();
  // The problems box is for somebody trying to turn a flow on, not for one just opened
  $('#probs').dataset.hidden = '1'; renderProblems();
  if (!f.trigger.config.trigger_type && !ro()) openPanel('triggers');
})();
