/* The email builder.
 *
 * Carried over from TzizamHub's builder (app/builder.js and app/email-render.js) so that the two work
 * alike: a rail of Content / Rows / Sections / Styles on the left, the email in the middle with on-stage
 * controls to move, duplicate and delete, and the selected thing's properties on the right, with the
 * pre-send checks there when nothing is selected.
 *
 * The email is a JSON document (settings + rows of cells of blocks). The stage below draws its blocks in
 * the browser; the HTML that is saved, previewed and sent is made from the same document on the server by
 * Model/Design/Html.php. The two are kept block for block alike: change them together.
 */
(function () {
  'use strict';
  const C = window.EC_DESIGNER;
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const clone = (o) => JSON.parse(JSON.stringify(o));

  // ─── Icons ───
  const P = {
    back: '<path d="m15 18-6-6 6-6"/>', plus: '<path d="M12 5v14M5 12h14"/>', split: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M12 4v16"/>',
    sections: '<rect x="3" y="3" width="18" height="6" rx="1.5"/><rect x="3" y="12" width="18" height="9" rx="1.5"/>', styles: '<circle cx="12" cy="12" r="9"/><circle cx="8.5" cy="10" r="1"/><circle cx="12" cy="7.5" r="1"/><circle cx="15.5" cy="10" r="1"/><path d="M12 21a3 3 0 0 1 0-6h2a3 3 0 0 0 3-3"/>',
    desktop: '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>', mobile: '<rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/>',
    eye: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>', copy: '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 0 1 2-2h9"/>',
    trash: '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>', menu: '<path d="M4 6h16M4 12h16M4 18h16"/>', x: '<path d="M18 6 6 18M6 6l12 12"/>',
    check: '<path d="m5 12 5 5 9-10"/>', link: '<path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1"/><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"/>',
    upload: '<path d="M12 16V4M7 9l5-5 5 5M4 20h16"/>', download: '<path d="M12 4v12M7 11l5 5 5-5M4 20h16"/>', info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7.5v.5"/>',
    shield: '<path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6l-8-3Z"/>', send: '<path d="m21 3-9 18-3-8-8-3 20-7Z"/>', mail: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
  };
  const ic = (n, s = 17, w = 1.8) => `<svg width="${s}" height="${s}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="${w}" stroke-linecap="round" stroke-linejoin="round">${P[n] || ''}</svg>`;

  function toast(m) { const t = $('#toast'); t.textContent = m; t.classList.add('show'); clearTimeout(toast.t); toast.t = setTimeout(() => t.classList.remove('show'), 2400); }

  // Email HTML drawn on the stage cannot run anything: no scripts, frames, forms, event handlers or script URLs.
  const UNSAFE = new Set(['SCRIPT', 'IFRAME', 'FRAME', 'FRAMESET', 'OBJECT', 'EMBED', 'APPLET', 'LINK', 'META', 'BASE', 'FORM', 'INPUT', 'BUTTON', 'TEXTAREA', 'SELECT', 'SVG', 'MATH', 'NOSCRIPT', 'TEMPLATE', 'STYLE']);
  function safeHtml(html) {
    const t = document.createElement('template');
    t.innerHTML = String(html == null ? '' : html);
    [...t.content.querySelectorAll('*')].forEach((el) => {
      if (UNSAFE.has(el.tagName.toUpperCase())) { el.remove(); return; }
      [...el.attributes].forEach((a) => {
        const n = a.name.toLowerCase(), v = a.value.replace(/[\s\u0000-\u001f]/g, '').toLowerCase();
        const url = ['href', 'src', 'xlink:href', 'action', 'formaction', 'background', 'poster', 'srcset'].includes(n);
        if (n.startsWith('on') || n === 'srcdoc' || (url && (v.startsWith('javascript:') || v.startsWith('vbscript:') || (v.startsWith('data:') && !v.startsWith('data:image/'))))) el.removeAttribute(a.name);
      });
    });
    return t.innerHTML;
  }

  function post(url, fields, files) {
    const body = new FormData();
    body.append('form_key', C.formKey);
    Object.keys(fields || {}).forEach((k) => body.append(k, fields[k]));
    (files || []).forEach((f) => body.append('files[]', f));
    return fetch(url, { method: 'POST', body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then((r) => r.json());
  }

  // ─── Rendering blocks on the stage (the server's Design/Html.php, drawn here) ───
  const SOCIAL = C.social;
  const socialIcon = (k, size = 32) => { const [n, c, g] = SOCIAL[k]; return `<span title="${n}" style="display:inline-block;width:${size}px;height:${size}px;border-radius:50%;background:${c};color:#fff;font:700 ${Math.round(size * .38)}px/${size}px Helvetica,Arial,sans-serif;text-align:center;margin:0 4px">${g}</span>`; };
  const padCss = (p) => `${p[0]}px ${p[1]}px ${p[2]}px ${p[3]}px`;
  const TAG = /\{\{\s*([a-zA-Z0-9_.]+)\s*(\|[^}]*)?\}\}/g;
  const tagLabel = (k) => (C.mergeTags.find((m) => m.token === k) || { label: k }).label;
  // A placeholder is shown as a chip while editing, and put back as text when the words are read off the stage
  const mergeView = (h) => String(h).replace(TAG, (m, k, fb) => `<span class="mtag" contenteditable="false" data-tag="${esc(m)}">${esc(tagLabel(k))}${fb && fb.length > 1 ? '' : (/firstname|lastname/.test(k) ? ' ⚠' : '')}</span>`);
  const menuItems = (items) => String(items || '').split(',').map((x) => x.split('|').map((y) => y.trim())).filter((x) => x[0]).map(([l, u]) => [l, u || '#']);
  const widgetCache = {};

  function blockHtml(b, s) {
    const p = b.props, wrap = (inner, extra = '') => `<div style="padding:${padCss(p.pad || [0, 0, 0, 0])};${extra}">${inner}</div>`;
    const ff = `font-family:${s.font}`;
    switch (b.type) {
      case 'heading': return wrap(`<h2 class="eb-edit" style="margin:0;${ff};font-size:${p.size}px;line-height:1.25;font-weight:${p.weight};color:${p.color || s.heading};text-align:${p.align}">${mergeView(p.html)}</h2>`);
      case 'text': return wrap(`<div class="eb-edit" style="margin:0;${ff};font-size:${p.size}px;line-height:${p.lh};color:${p.color || s.text};text-align:${p.align}">${mergeView(p.html)}</div>`);
      case 'image': { const img = p.src ? `<img src="${esc(p.src)}" alt="${esc(p.alt)}" style="display:inline-block;width:${p.width}%;max-width:100%;height:auto;border:0;border-radius:${p.radius}px">` : `<span class="eb-noimg" style="width:${p.width}%">${ic('upload', 20)}<span>Choose an image</span></span>`; return wrap(img, `text-align:${p.align};line-height:0`); }
      case 'button': { const bg = p.bg || s.btn, r = p.radius == null ? s.radius : p.radius;
        return wrap(`<a href="${esc(p.href)}" class="eb-edit-btn" style="display:${p.full ? 'block' : 'inline-block'};background:${bg};color:${p.color || s.btnText};${ff};font-size:${p.size}px;font-weight:700;text-decoration:none;padding:13px 26px;border-radius:${r}px;text-align:center"><span class="eb-edit">${esc(p.label)}</span></a>`, `text-align:${p.align}`); }
      case 'divider': return wrap(`<div style="border-top:${p.thick}px solid ${p.color};width:${p.width}%;margin:0 auto;font-size:0;line-height:0">&nbsp;</div>`);
      case 'spacer': return `<div style="height:${p.h}px;line-height:${p.h}px;font-size:0">&nbsp;</div>`;
      case 'social': return wrap(p.nets.length ? p.nets.map((k) => socialIcon(k, p.size)).join('') : '<span class="faint" style="font-size:13px">Choose networks →</span>', `text-align:${p.align}`);
      case 'coupon': return wrap(`<div style="border:2px dashed ${s.btn};border-radius:14px;padding:18px;text-align:center;${ff}"><div style="font:700 26px/1.2 'Courier New',monospace;letter-spacing:.12em;color:${s.heading}">${esc(p.code)}</div><div style="font-size:13px;color:${s.text};margin-top:6px">${esc(p.note)}</div></div>`);
      case 'quote': return wrap(`<div style="${ff};border-left:3px solid ${s.btn};padding-left:18px"><div class="eb-edit" style="font-size:18px;line-height:1.5;color:${s.heading};font-style:italic">${mergeView(p.html)}</div><div style="font-size:13px;color:${s.text};margin-top:12px">${esc(p.who)}</div></div>`);
      case 'menu': { const items = menuItems(p.items); return wrap(items.length ? items.map(([l]) => `<span style="${ff};font-size:14px;font-weight:600;color:${s.heading};margin:0 9px;display:inline-block">${esc(l)}</span>`).join('') : '<span class="faint" style="font-size:13px">Add menu items →</span>', `text-align:${p.align}`); }
      case 'html': return isFullDoc() ? `<div class="eb-fulldoc">${ic('mail', 22)}<b>A complete HTML email</b><span>This design is one HTML document, sent exactly as written. Edit its code on the right, or use Preview to see it.</span></div>` : wrap(p.code);
      case 'footer': return wrap(`<div style="${ff};font-size:12px;line-height:1.6;color:#71717A;text-align:center"><div>${esc(p.note)}</div><div style="margin-top:6px"><b style="color:#52525B">${esc(p.company)}</b>${p.address ? ' · ' + esc(p.address) : ''}</div><div style="margin-top:8px"><u>${esc(p.unsubscribe)}</u></div></div>`);
      case 'products': { const key = JSON.stringify(p.options); return `<div class="eb-widget" data-wkey="${esc(key)}">${widgetCache[key] !== undefined ? (widgetCache[key] || `<div class="eb-wnote">${esc(C.widget.t.empty)}</div>`) : '<div class="eb-wnote">Loading products…</div>'}</div>`; }
      case 'storeheader': return `<div class="eb-store">${C.brand.header}</div>`;
      case 'storefooter': return `<div class="eb-store">${C.brand.footer}</div>`;
    }
    return '';
  }

  // One block holding a whole HTML document: sent exactly as written (as Design/Html.php::fullDocument)
  const isFullDoc = () => { const r = doc.rows; const b = r.length === 1 && r[0].cells.length === 1 && r[0].cells[0].length === 1 ? r[0].cells[0][0] : null; return !!b && b.type === 'html' && /^\s*(<!doctype|<html)/i.test(String(b.props.code || '')); };

  // ─── Model ───
  let uidN = 0; const uid = (p) => p + Date.now().toString(36).slice(-4) + (uidN++).toString(36);
  const BLOCKS = {
    heading: ['Heading', 'Text'], text: ['Paragraph', 'Text'], button: ['Button', 'Text'], quote: ['Testimonial', 'Text'],
    image: ['Image', 'Media'], social: ['Social links', 'Media'],
    products: ['Store products', 'Commerce'], coupon: ['Coupon code', 'Commerce'],
    divider: ['Divider', 'Layout'], spacer: ['Spacer', 'Layout'], menu: ['Menu', 'Layout'], footer: ['Footer', 'Layout'],
    storeheader: ['Store header', 'Store'], storefooter: ['Store footer', 'Store'],
    html: ['Custom HTML', 'Advanced'],
  };
  const block = (type, props) => ({ id: uid('b'), type, props: Object.assign(clone(C.defaults[type] || {}), props || {}) });
  const row = (layout, cells, props) => ({ id: uid('r'), layout, cells: cells || layout.map(() => []), bg: '', pad: [8, 8], stack: true, hide: '', ...props });
  const LAYOUTS = [[1], [1, 1], [1, 2], [2, 1], [1, 1, 1], [1, 1, 1, 1]];
  const B = C.brand;
  // The store's web font, so the stage is set in what the email is sent in
  if (B.fontImport) { const st = document.createElement('style'); st.textContent = B.fontImport; document.head.appendChild(st); }
  const SECTIONS = {
    header: ['Header', 'Logo and menu', () => row([1], [[B.logo ? block('image', { src: B.logo, alt: B.storeName, href: B.storeUrl, width: 40, pad: [16, 28, 8, 28] }) : block('heading', { html: esc(B.storeName), size: 24, align: 'center', weight: 400, pad: [20, 28, 8, 28] }), block('menu', { items: B.menu })]])],
    hero: ['Hero', 'Image, headline, button', () => row([1], [[block('image', { pad: [0, 0, 8, 0] }), block('heading', { html: 'Your headline', size: 30, align: 'center' }), block('text', { html: 'Hi {{ customer.firstname|there }}, a line or two about what this email is for.', align: 'center' }), block('button', { href: B.storeUrl })]], { pad: [0, 16] })],
    features: ['Three columns', 'A heading and a line in each', () => row([1, 1, 1], ['First', 'Second', 'Third'].map((h) => [block('heading', { html: h, size: 17, pad: [8, 16, 2, 16] }), block('text', { html: 'A short line about it.', size: 14, pad: [2, 16, 8, 16] })]), { pad: [16, 12] })],
    products: ['Store products', 'New arrivals from the catalog', () => row([1], [[block('products')]], { pad: [0, 0] })],
    deals: ['Deals', 'Three products on sale', () => row([1], [[block('products', { options: Object.assign({}, (C.widget.layouts.find((l) => l.id === 'deals') || {}).defaults || {}, { layout: 'deals' }) })]], { pad: [0, 0] })],
    testimonial: ['Testimonial', 'A customer’s words', () => row([1], [[block('quote')]], { bg: '#FAFAFA', pad: [16, 16] })],
    cta: ['Call to action', 'Coloured band', () => row([1], [[block('heading', { html: 'Ready when you are', size: 24, align: 'center', color: '#FFFFFF' }), block('button', { align: 'center', bg: '#FFFFFF', color: doc.settings.btn, href: B.storeUrl })]], { bg: doc.settings.btn, pad: [28, 28] })],
    coupon: ['Offer', 'Coupon code', () => row([1], [[block('heading', { html: 'A little thank-you', size: 22, align: 'center' }), block('coupon')]], { pad: [16, 16] })],
    footer: ['Footer', 'Address and unsubscribe', () => row([1], [[block('footer', { company: B.storeName, address: B.address })]], { bg: '#FAFAFA', pad: [16, 16] })],
  };

  let doc = C.doc;
  const s = () => doc.settings;
  let sel = null, tab = 'blocks', device = 'desktop', view = 'design', saved = [];
  const meta = { name: C.name, subject: C.subject, preheader: C.preheader };
  const past = [JSON.stringify(doc)], future = [];
  let dirty = false, saving = null, saveTimer = null, lastChecks = C.checks || [];

  const find = (id) => { for (const r of doc.rows) { if (r.id === id) return { row: r }; for (let c = 0; c < r.cells.length; c++) { const i = r.cells[c].findIndex((b) => b.id === id); if (i > -1) return { row: r, cell: r.cells[c], c, i, block: r.cells[c][i] }; } } return null; };
  const hasFooter = (r) => r && r.cells.flat().some((b) => b.type === 'footer' || b.type === 'storefooter');

  // ─── Saving: a moment after each change, and on the way out ───
  const status = (t) => { const e = $('#ebsaved'); if (e) e.textContent = t; };
  function commit() { past.push(JSON.stringify(doc)); if (past.length > 80) past.shift(); future.length = 0; updateUndo(); touch(); }
  function touch() { dirty = true; status('Unsaved changes…'); clearTimeout(saveTimer); saveTimer = setTimeout(save, 1500); }
  function save() {
    clearTimeout(saveTimer);
    if (!dirty) return saving || Promise.resolve(true);
    dirty = false; status('Saving…');
    const run = () => post(C.urls.save, { doc: JSON.stringify(doc), name: meta.name, subject: meta.subject, preheader: meta.preheader }).then((j) => {
      if (!j || !j.ok) throw new Error((j && j.message) || 'The design could not be saved.');
      lastChecks = j.checks || []; status('Saved'); if (!sel) renderProps(); return true;
    }).catch((e) => { dirty = true; status('Not saved'); toast(e.message); return false; });
    saving = (saving || Promise.resolve()).then(run);
    return saving;
  }
  window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

  // ─── Shell ───
  const v = $('#ecd');
  v.innerHTML = `<div class="eb">
    <header class="jx-bar eb-bar"><a class="jx-back" href="${esc(C.urls.back)}" id="back" title="${C.kind === 'campaign' ? 'Back to the campaign' : 'Back to templates'}">${ic('back', 16)}</a>
      <div class="jx-name">${C.kind === 'template' ? `<input id="tname" value="${esc(meta.name)}" maxlength="255" aria-label="Template name">` : `<b class="eb-title">${esc(meta.name)}</b>`}<small id="ebsaved">${C.kind === 'campaign' ? 'Campaign email' : 'Template'} · saved</small></div>
      <div class="eb-undo"><button class="icon-btn" id="undo" title="Undo (Ctrl+Z)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/></svg></button><button class="icon-btn" id="redo" title="Redo (Ctrl+Shift+Z)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 14 5-5-5-5"/><path d="M20 9H9.5a5.5 5.5 0 0 0 0 11H13"/></svg></button></div>
      <div class="spacer"></div>
      <div class="seg" id="views"><button data-view="design" class="on">Design</button><button data-view="html">HTML</button></div>
      <div class="seg" id="devs"><button data-dev="desktop" class="on" title="Desktop">${ic('desktop', 14)}Desktop</button><button data-dev="mobile" title="Mobile">${ic('mobile', 14)}Mobile</button></div>
      <button class="btn" id="prev">${ic('eye', 15)}Preview${C.urls.test ? ' & test' : ''}</button>
      <button class="btn btn-primary" id="done">Save & exit</button>
    </header>
    <div class="eb-body">
      <aside class="eb-left"><nav class="eb-rail">${[['blocks', 'plus', 'Content'], ['rows', 'split', 'Rows'], ['sections', 'sections', 'Sections'], ['styles', 'styles', 'Styles']].map(([k, i, l]) => `<button data-tab="${k}">${ic(i, 18)}<span>${l}</span></button>`).join('')}</nav><div class="eb-lib" id="lib"></div></aside>
      <main class="eb-stage" id="stage"><div class="eb-stage-in" id="stagein"></div></main>
      <aside class="eb-right" id="props"></aside>
    </div></div><div class="toast" id="toast"></div>`;

  // ─── Left library ───
  const field = (label, ctrl, help) => `<div class="ebf"><label>${label}</label><div class="ebf-c">${ctrl}</div>${help ? `<small>${help}</small>` : ''}</div>`;
  const colorField = (label, key, val, scope) => `<label class="cf"><input type="color" data-${scope}="${key}" value="${val || '#ffffff'}"><span><b>${label}</b><small class="mono">${(val || 'default').toUpperCase()}</small></span></label>`;
  const blockGlyph = (k) => {
    const G = { heading: '<b style="font-size:17px;font-weight:800">H</b>', text: '<span class="g-lines"><i></i><i></i><i style="width:60%"></i></span>', image: '<span class="g-img"></span>', button: '<span class="g-btn"></span>', divider: '<span class="g-div"></span>', spacer: '<span class="g-sp">↕</span>', social: '<span class="g-soc"><i></i><i></i><i></i></span>', products: '<span class="g-prod"><i></i><b></b></span>', coupon: '<span class="g-coupon">10%</span>', quote: '<b style="font-size:22px;font-family:Georgia">“</b>', menu: '<span class="g-menu"><i></i><i></i><i></i></span>', html: '<b class="mono" style="font-size:12px">&lt;/&gt;</b>', footer: '<span class="g-foot"><i></i><i style="width:70%"></i></span>', storeheader: '<span class="g-foot"><i style="width:40%"></i><i></i></span>', storefooter: '<span class="g-foot"><i></i><i style="width:40%"></i></span>' };
    return `<span class="lt-g">${G[k] || ''}</span>`;
  };
  const sectionThumb = (k) => ({ header: '<i class="st-logo"></i><i class="st-menu"></i>', hero: '<i class="st-hero"></i><i class="st-h"></i><i class="st-btn"></i>', features: '<i class="st-3"></i><i class="st-3"></i><i class="st-3"></i>', products: '<i class="st-2"></i><i class="st-2"></i>', deals: '<i class="st-3"></i><i class="st-3"></i><i class="st-3"></i>', testimonial: '<i class="st-q"></i>', cta: '<i class="st-cta"></i>', coupon: '<i class="st-cp"></i>', footer: '<i class="st-ft"></i>' })[k] || '<i class="st-h"></i>';

  function renderLib() {
    $$('.eb-rail button', v).forEach((b) => b.classList.toggle('on', b.dataset.tab === tab));
    const lib = $('#lib');
    if (tab === 'blocks') {
      const groups = {}; Object.entries(BLOCKS).forEach(([k, [n, g]]) => { if (g === 'Store' && !B.enabled) return; (groups[g] = groups[g] || []).push([k, n]); });
      lib.innerHTML = `<div class="lib-head"><b>Content</b><small>Drag onto the email, or click to add below the selection</small></div>${Object.entries(groups).map(([g, items]) => `<div class="lib-group">${g}</div><div class="lib-grid">${items.map(([k, n]) => `<button class="lib-tile" draggable="true" data-add="${k}">${blockGlyph(k)}<span>${n}</span></button>`).join('')}</div>`).join('')}`;
    } else if (tab === 'rows') {
      lib.innerHTML = `<div class="lib-head"><b>Rows</b><small>Column layouts. Drop a row, then drop content into its columns.</small></div><div class="lib-rows">${LAYOUTS.map((l, i) => `<button class="lib-row" draggable="true" data-row="${i}"><span class="lr-cols">${l.map((w) => `<i style="flex:${w}"></i>`).join('')}</span><small>${l.length === 1 ? 'One column' : l.join(' : ')}</small></button>`).join('')}</div>
        <div class="lib-note">${ic('info', 13)} Columns stack on phones unless you turn it off for a row.</div>`;
    } else if (tab === 'sections') {
      lib.innerHTML = `<div class="lib-head"><b>Sections</b><small>Ready-made rows with content, in this store's colours.</small></div>${Object.entries(SECTIONS).map(([k, [n, d]]) => `<button class="lib-sec" draggable="true" data-sec="${k}"><span class="ls-thumb">${sectionThumb(k)}</span><span><b>${n}</b><small>${d}</small></span></button>`).join('')}
        <div class="lib-group">Saved</div>${saved.length ? saved.map((x) => `<div class="lib-saved"><button class="lib-sec" draggable="true" data-saved="${x.id}"><span class="ls-thumb"><i class="st-h"></i></span><span><b>${esc(x.name)}</b><small>Saved section</small></span></button><button class="icon-btn" data-unsave="${x.id}" title="Delete this saved section">${ic('trash', 13)}</button></div>`).join('') : `<div class="lib-note">${ic('info', 13)} Select a row and choose “Save as section” to keep it here for other emails.</div>`}`;
    } else {
      const st = s();
      lib.innerHTML = `<div class="lib-head"><b>Styles</b><small>Defaults for the whole email. Blocks can override them.</small></div>
        ${C.kind === 'template' ? field('Subject', `<input data-meta="subject" value="${esc(meta.subject)}" maxlength="255" placeholder="The subject a campaign starts with">`) : ''}
        ${field('Preview text', `<input data-meta="preheader" value="${esc(meta.preheader)}" maxlength="255" placeholder="Shown after the subject in the inbox">`)}
        <div class="brand-pull"><span>${ic('shield', 15, 2)}</span><div><b>Use your brand</b><small>Colours and font from this store's Brand settings</small></div><button class="btn btn-sm" id="brand">Apply</button></div>
        ${field('Content width', `<div class="eb-seg">${[560, 600, 640].map((w) => `<button data-setv="width" data-val="${w}" class="${st.width === w ? 'on' : ''}">${w}px</button>`).join('')}</div>`)}
        ${field('Font', `<select data-set="font">${[...new Set([st.font, ...C.fonts])].map((f) => `<option value="${esc(f)}" ${st.font === f ? 'selected' : ''}>${esc(f.split(',')[0].replace(/['"]/g, ''))}</option>`).join('')}</select>`, 'Email-safe fonts look the same everywhere; a web font falls back in Outlook.')}
        <div class="color-grid">${[['bodyBg', 'Background'], ['contentBg', 'Email body'], ['heading', 'Headings'], ['text', 'Text'], ['link', 'Links'], ['btn', 'Buttons'], ['btnText', 'Button text']].map(([k, l]) => colorField(l, k, st[k], 'set')).join('')}</div>
        ${field('Button corners', `<input type="range" min="0" max="24" data-set="radius" value="${st.radius}"><span class="range-v">${st.radius}px</span>`)}`;
    }
    bindLib();
  }
  function bindLib() {
    $$('[data-add]', v).forEach((b) => { b.ondragstart = (e) => startDrag(e, { kind: 'block', type: b.dataset.add }); b.onclick = () => addBlockNearSelection(b.dataset.add); });
    $$('[data-row]', v).forEach((b) => { b.ondragstart = (e) => startDrag(e, { kind: 'row', layout: LAYOUTS[+b.dataset.row] }); b.onclick = () => { insertRow(row(LAYOUTS[+b.dataset.row].slice())); }; });
    $$('[data-sec]', v).forEach((b) => { b.ondragstart = (e) => startDrag(e, { kind: 'section', key: b.dataset.sec }); b.onclick = () => insertRow(SECTIONS[b.dataset.sec][2]()); });
    $$('[data-saved]', v).forEach((b) => { b.ondragstart = (e) => startDrag(e, { kind: 'saved', id: b.dataset.saved }); b.onclick = () => insertRow(savedRow(b.dataset.saved)); });
    $$('[data-unsave]', v).forEach((b) => b.onclick = () => post(C.urls.sections, { delete: b.dataset.unsave }).then((j) => { saved = j.sections || []; renderLib(); }));
    $$('[data-set]', v).forEach((inp) => { const ev = inp.type === 'range' || inp.tagName === 'INPUT' ? 'input' : 'change'; inp.addEventListener(ev, () => { const k = inp.dataset.set; s()[k] = inp.type === 'range' ? +inp.value : inp.value; if (inp.type === 'range') inp.nextElementSibling.textContent = inp.value + 'px'; if (inp.type === 'color') inp.parentElement.querySelector('small').textContent = inp.value.toUpperCase(); renderStage(true); }); inp.addEventListener('change', commit); });
    $$('[data-setv]', v).forEach((b) => b.onclick = () => { s()[b.dataset.setv] = +b.dataset.val; commit(); renderLib(); renderStage(); });
    $$('[data-meta]', v).forEach((inp) => inp.oninput = () => { meta[inp.dataset.meta] = inp.value; touch(); });
    const br = $('#brand'); if (br) br.onclick = () => { Object.assign(s(), clone(B.settings), { width: s().width }); commit(); renderLib(); renderStage(); toast('Applied this store’s brand colours and font'); };
  }
  const savedRow = (id) => { const x = saved.find((y) => String(y.id) === String(id)); const r = clone(x.row); r.id = uid('r'); r.cells.forEach((cell) => cell.forEach((b) => (b.id = uid('b')))); return r; };
  function insertRow(r) { const f = sel && find(sel); const idx = f ? doc.rows.findIndex((x) => x.id === f.row.id) + 1 : doc.rows.length - (hasFooter(doc.rows[doc.rows.length - 1]) ? 1 : 0); doc.rows.splice(Math.max(0, idx), 0, r); commit(); select(r.id); const el = $(`.eb-row[data-r="${r.id}"]`); el && el.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
  function addBlockNearSelection(type) {
    const nb = block(type), f = sel && find(sel);
    if (f && f.block) f.cell.splice(f.i + 1, 0, nb);
    else if (f && f.row) f.row.cells[0].push(nb);
    else { const body = doc.rows.filter((r) => !hasFooter(r)).slice(-1)[0]; if (body) body.cells[0].push(nb); else doc.rows.push(row([1], [[nb]])); }
    commit(); select(nb.id);
  }

  // ─── Stage ───
  function renderStage(light) {
    const st = s(), mob = device === 'mobile';
    const stage = $('#stagein');
    if (view === 'html') {
      stage.innerHTML = `<div class="eb-code"><div class="ec-head"><b>HTML</b><span class="faint" id="codekb">Rendering…</span><button class="btn btn-sm" id="cpy">${ic('copy', 13)}Copy</button><button class="btn btn-sm" id="dl">${ic('download', 13)}Download .html</button></div><pre id="code"></pre></div>`;
      post(C.urls.render, { doc: JSON.stringify(doc) }).then((j) => { const h = (j && j.raw) || ''; if (!$('#code')) return; $('#code').textContent = h; $('#codekb').textContent = `${(new Blob([h]).size / 1024).toFixed(1)} KB · table-based, Outlook-safe, columns stack on phones`; $('#cpy').onclick = () => { try { navigator.clipboard.writeText(h); } catch (e) {} toast('Copied to clipboard'); }; $('#dl').onclick = () => { const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([h], { type: 'text/html' })); a.download = 'email.html'; a.click(); }; });
      if (!light) renderProps();
      return;
    }
    stage.innerHTML = `<div class="eb-frame ${mob ? 'mobile' : ''}" style="background:${st.bodyBg}">
      ${mob ? '<div class="eb-phone-top"><i></i></div>' : `<div class="eb-inbox-head"><span class="eb-av">${esc((B.storeName || 'S').charAt(0).toUpperCase())}</span><div><b>${esc(C.from.name)}</b> <span class="faint">&lt;${esc(C.from.email)}&gt;</span><div class="eb-subj">${esc(meta.subject || 'Your subject line')} <span class="faint">— ${esc(meta.preheader || 'preview text')}</span></div></div></div>`}
      <div class="eb-email" id="email" style="width:${mob ? 375 : st.width}px;background:${st.contentBg};font-family:${st.font}">
      ${doc.rows.map((r, ri) => { const tot = r.layout.reduce((a, b) => a + b, 0); const stack = mob && r.stack; const hidden = (mob && r.hide === 'mobile') || (!mob && r.hide === 'desktop');
        return `<div class="eb-row ${sel === r.id ? 'sel' : ''} ${hidden ? 'hidden-here' : ''}" data-r="${r.id}" style="background:${r.bg || 'transparent'};padding:${r.pad[0]}px 0 ${r.pad[1]}px">
          <div class="eb-row-tools"><span class="rt-drag" draggable="true" data-mrow="${r.id}" title="Drag to move">${ic('menu', 13)}</span><span class="rt-label">Row ${ri + 1} · ${r.layout.length === 1 ? '1 column' : r.layout.join(':')}${r.hide ? ' · hidden on ' + r.hide : ''}</span><button data-rup="${r.id}" title="Move up">↑</button><button data-rdown="${r.id}" title="Move down">↓</button><button data-rdup="${r.id}" title="Duplicate">${ic('copy', 12)}</button><button data-rsave="${r.id}" title="Save as section">${ic('download', 12)}</button><button data-rdel="${r.id}" title="Delete" class="danger">${ic('trash', 12)}</button></div>
          ${hidden ? `<div class="eb-hidden-note">Hidden on ${r.hide}</div>` : ''}
          <div class="eb-cols" style="${stack ? 'flex-direction:column' : ''}">${r.cells.map((cell, ci) => `<div class="eb-cell" data-r="${r.id}" data-c="${ci}" style="flex:${stack ? '1 1 auto' : r.layout[ci] / tot}">${cell.length ? cell.map((b) => `<div class="eb-blk ${sel === b.id ? 'sel' : ''}" data-b="${b.id}"><div class="eb-blk-tools"><span class="bt-name">${BLOCKS[b.type][0]}</span><span class="bt-drag" draggable="true" data-mblk="${b.id}" title="Drag to move">${ic('menu', 12)}</span><button data-bdup="${b.id}" title="Duplicate">${ic('copy', 12)}</button><button data-bdel="${b.id}" title="Delete" class="danger">${ic('trash', 12)}</button></div>${safeHtml(blockHtml(b, st))}</div>`).join('') : `<div class="eb-empty-cell">${ic('plus', 16)}<span>Drop content here</span></div>`}</div>`).join('')}</div></div>`; }).join('')}
      <div class="eb-add-row" id="addrow">${ic('plus', 14)}Add a row</div></div></div>
      <div class="eb-ind" id="ind"></div>`;
    bindStage();
    loadWidgets();
    if (!light) renderProps();
  }

  // Store product blocks are drawn by the server, as they would be sent right now
  function loadWidgets() {
    $$('.eb-widget', v).forEach((el) => {
      const key = el.dataset.wkey;
      if (widgetCache[key] !== undefined || widgetCache['…' + key]) return;
      widgetCache['…' + key] = true;
      post(C.urls.widget, { options: key, store_id: C.storeId || '' }).then((j) => { widgetCache[key] = (j && j.html) || ''; $$('.eb-widget', v).forEach((w) => { if (w.dataset.wkey === key) w.innerHTML = safeHtml(widgetCache[key]) || `<div class="eb-wnote">${esc(C.widget.t.empty)}</div>`; }); }).catch(() => { delete widgetCache['…' + key]; });
    });
  }

  function bindStage() {
    const stage = $('#stagein');
    stage.onclick = (e) => {
      const t = e.target;
      if (t.closest('a') && !t.closest('.eb-edit')) e.preventDefault();
      const btn = t.closest('button');
      if (btn) {
        const d = btn.dataset;
        if (d.bdel) { const f = find(d.bdel); f.cell.splice(f.i, 1); sel = null; commit(); return renderStage(); }
        if (d.bdup) { const f = find(d.bdup); const c = clone(f.block); c.id = uid('b'); f.cell.splice(f.i + 1, 0, c); commit(); return select(c.id); }
        if (d.rdel) { doc.rows = doc.rows.filter((r) => r.id !== d.rdel); sel = null; commit(); return renderStage(); }
        if (d.rdup) { const i = doc.rows.findIndex((r) => r.id === d.rdup); const c = clone(doc.rows[i]); c.id = uid('r'); c.cells.forEach((cell) => cell.forEach((b) => (b.id = uid('b')))); doc.rows.splice(i + 1, 0, c); commit(); return select(c.id); }
        if (d.rup || d.rdown) { const id = d.rup || d.rdown, i = doc.rows.findIndex((r) => r.id === id), j = d.rup ? i - 1 : i + 1; if (j < 0 || j >= doc.rows.length) return; [doc.rows[i], doc.rows[j]] = [doc.rows[j], doc.rows[i]]; commit(); return renderStage(); }
        if (d.rsave) return saveSection(d.rsave);
      }
      if (t.closest('#addrow')) { doc.rows.splice(doc.rows.length - (hasFooter(doc.rows[doc.rows.length - 1]) ? 1 : 0), 0, row([1])); commit(); tab = 'blocks'; renderLib(); return renderStage(); }
      const bEl = t.closest('.eb-blk'); if (bEl) { if (sel !== bEl.dataset.b) select(bEl.dataset.b); return; }
      const rEl = t.closest('.eb-row'); if (rEl) return select(rEl.dataset.r);
      select(null);
    };
    // inline editing for text-like blocks
    $$('.eb-blk.sel .eb-edit', stage).forEach((el) => {
      el.contentEditable = 'true'; el.spellcheck = true;
      el.addEventListener('input', () => { const f = find(sel); if (!f || !f.block) return; if (f.block.type === 'button') f.block.props.label = el.textContent; else f.block.props.html = serializeEdit(el); status('Editing…'); });
      el.addEventListener('blur', () => { commit(); });
      el.addEventListener('focus', () => showFormatBar(el));
      el.addEventListener('keydown', (e) => { if (e.key === 'Enter' && el.closest('.eb-edit-btn')) e.preventDefault(); });
      el.addEventListener('paste', (e) => { e.preventDefault(); document.execCommand('insertText', false, (e.clipboardData || window.clipboardData).getData('text/plain')); });
    });
    $$('[data-mrow]', stage).forEach((h) => (h.ondragstart = (e) => startDrag(e, { kind: 'mrow', id: h.dataset.mrow })));
    $$('[data-mblk]', stage).forEach((h) => (h.ondragstart = (e) => startDrag(e, { kind: 'mblk', id: h.dataset.mblk })));
  }
  const serializeEdit = (el) => { const c = el.cloneNode(true); $$('.mtag', c).forEach((m) => m.replaceWith(document.createTextNode(m.dataset.tag))); return c.innerHTML; };
  function saveSection(id) {
    const f = find(id); const name = prompt('Name this section', 'My section'); if (!name) return;
    post(C.urls.sections, { name, row: JSON.stringify(f.row) }).then((j) => { if (j.error) return toast(j.error); saved = j.sections || []; toast('Saved. Find it under Sections → Saved'); if (tab === 'sections') renderLib(); });
  }

  // floating format bar for text editing
  function showFormatBar(el) {
    let bar = $('#fmt'); if (!bar) { bar = document.createElement('div'); bar.id = 'fmt'; bar.className = 'fmt-bar'; $('#stagein').appendChild(bar); }
    const isBtn = !!el.closest('.eb-edit-btn');
    bar.innerHTML = isBtn ? `<span class="fmt-note">Editing button label</span>` : `<button data-cmd="bold" title="Bold"><b>B</b></button><button data-cmd="italic" title="Italic"><i>I</i></button><button data-cmd="underline" title="Underline"><u>U</u></button><button data-cmd="link" title="Link">${ic('link', 13)}</button><span class="fmt-sep"></span><button data-merge="1" title="Insert personalization">{{ }} Personalize</button>`;
    const r = el.getBoundingClientRect(), base = $('#stagein').getBoundingClientRect();
    bar.style.left = Math.max(8, r.left - base.left) + 'px'; bar.style.top = (r.top - base.top - 44) + 'px'; bar.classList.add('on');
    $$('[data-cmd]', bar).forEach((b) => b.onmousedown = (e) => { e.preventDefault(); if (b.dataset.cmd === 'link') { const u = prompt('Link URL', 'https://'); if (u) document.execCommand('createLink', false, u); } else document.execCommand(b.dataset.cmd); el.dispatchEvent(new Event('input')); });
    const mg = $('[data-merge]', bar); if (mg) mg.onmousedown = (e) => { e.preventDefault(); const old = $('.merge-menu', bar); if (old) return old.remove(); const menu = document.createElement('div'); menu.className = 'merge-menu'; menu.innerHTML = C.mergeTags.filter((m) => !/_url$|\.url$/.test(m.token)).map((m) => `<button data-k="${esc(m.token)}" data-fb="${m.fallback && /firstname/.test(m.token) ? 'there' : ''}"><span class="mono">{{ ${esc(m.token)} }}</span><small>${esc(m.label)}</small></button>`).join(''); bar.appendChild(menu); $$('button', menu).forEach((m) => m.onmousedown = (ev) => { ev.preventDefault(); const tag = `{{ ${m.dataset.k}${m.dataset.fb ? '|' + m.dataset.fb : ''} }}`; document.execCommand('insertHTML', false, `<span class="mtag" contenteditable="false" data-tag="${esc(tag)}">${esc(tagLabel(m.dataset.k))}</span>&nbsp;`); el.dispatchEvent(new Event('input')); menu.remove(); }); };
    el.addEventListener('blur', () => setTimeout(() => bar.classList.remove('on'), 150), { once: true });
  }

  // ─── Drag & drop with a live drop indicator ───
  let dragging = null;
  function startDrag(e, payload) { dragging = payload; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', payload.kind); document.body.classList.add('eb-dragging'); }
  function dropTarget(e) {
    const isRow = dragging && ['row', 'section', 'saved', 'mrow'].includes(dragging.kind);
    const email = $('#email'); if (!email) return null;
    if (!isRow) {
      const cell = e.target.closest && e.target.closest('.eb-cell');
      if (cell) { const blks = $$(':scope > .eb-blk', cell); let i = blks.length; for (let k = 0; k < blks.length; k++) { const r = blks[k].getBoundingClientRect(); if (e.clientY < r.top + r.height / 2) { i = k; break; } } const ref = blks[i] || blks[blks.length - 1]; const rr = cell.getBoundingClientRect(); const y = blks.length ? (blks[i] ? blks[i].getBoundingClientRect().top : ref.getBoundingClientRect().bottom) : rr.top + rr.height / 2; return { kind: 'cell', r: cell.dataset.r, c: +cell.dataset.c, i, x: rr.left, w: rr.width, y }; }
    }
    const rows = $$('.eb-row', email); let i = rows.length; for (let k = 0; k < rows.length; k++) { const r = rows[k].getBoundingClientRect(); if (e.clientY < r.top + r.height / 2) { i = k; break; } }
    const er = email.getBoundingClientRect(); const y = rows[i] ? rows[i].getBoundingClientRect().top : (rows.length ? rows[rows.length - 1].getBoundingClientRect().bottom : er.top + 20);
    return { kind: 'rows', i, x: er.left, w: er.width, y };
  }
  function showInd(t) { const ind = $('#ind'); if (!ind) return; const base = $('#stagein').getBoundingClientRect(); if (!t) return ind.classList.remove('on'); ind.style.left = (t.x - base.left) + 'px'; ind.style.width = t.w + 'px'; ind.style.top = (t.y - base.top - 2) + 'px'; ind.className = 'eb-ind on ' + t.kind; ind.dataset.label = t.kind === 'rows' ? (dragging.kind === 'mrow' ? 'Move row here' : dragging.kind === 'section' || dragging.kind === 'saved' ? 'Add section here' : 'New row here') : 'Drop here'; }
  const stg = $('#stage');
  stg.addEventListener('dragover', (e) => { if (!dragging) return; e.preventDefault(); showInd(dropTarget(e)); });
  stg.addEventListener('dragleave', (e) => { if (!stg.contains(e.relatedTarget)) showInd(null); });
  stg.addEventListener('drop', (e) => {
    if (!dragging) return; e.preventDefault(); const t = dropTarget(e); showInd(null); const d = dragging; dragging = null; document.body.classList.remove('eb-dragging'); if (!t) return;
    let newSel = null;
    if (d.kind === 'block' || d.kind === 'mblk') {
      let b;
      if (d.kind === 'block') b = block(d.type); else { const f = find(d.id); b = f.block; f.cell.splice(f.i, 1); if (t.kind === 'cell' && f.row.id === t.r && f.c === t.c && f.i < t.i) t.i--; }
      if (t.kind === 'cell') find(t.r).row.cells[t.c].splice(t.i, 0, b); else doc.rows.splice(t.i, 0, row([1], [[b]]));
      newSel = b.id;
    } else if (d.kind === 'row' || d.kind === 'section' || d.kind === 'saved') { const r = d.kind === 'row' ? row(d.layout.slice()) : d.kind === 'saved' ? savedRow(d.id) : SECTIONS[d.key][2](); doc.rows.splice(t.i, 0, r); newSel = r.id; }
    else if (d.kind === 'mrow') { const i = doc.rows.findIndex((r) => r.id === d.id); const [r] = doc.rows.splice(i, 1); doc.rows.splice(t.i > i ? t.i - 1 : t.i, 0, r); newSel = r.id; }
    commit(); select(newSel);
  });
  document.addEventListener('dragend', () => { dragging = null; document.body.classList.remove('eb-dragging'); const ind = $('#ind'); if (ind) ind.classList.remove('on'); });

  // ─── Right: properties, or checks + layers when nothing is selected ───
  function select(id) { sel = id; renderStage(); const el = id && $(`[data-b="${id}"], [data-r="${id}"].eb-row`); if (el) { const ed = $('.eb-edit', el); const f = find(id); if (ed && el.classList.contains('eb-blk') && f.block && ['text', 'heading', 'quote'].includes(f.block.type)) setTimeout(() => ed.focus(), 0); } }
  const padCtl = (p) => `<div class="pad4">${['Top', 'Right', 'Bottom', 'Left'].map((l, i) => `<label><input type="number" min="0" max="80" data-pad="${i}" value="${p[i]}"><small>${l[0]}</small></label>`).join('')}</div>`;
  const alignCtl = (val, key = 'align') => `<div class="eb-seg">${['left', 'center', 'right'].map((a) => `<button data-pv="${key}" data-val="${a}" class="${val === a ? 'on' : ''}" title="${a}"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">${a === 'left' ? '<path d="M4 6h16M4 12h10M4 18h13"/>' : a === 'center' ? '<path d="M4 6h16M7 12h10M5 18h14"/>' : '<path d="M4 6h16M10 12h10M7 18h13"/>'}</svg></button>`).join('')}</div>`;
  const linkHelp = (href) => (!href || /^(https?:\/\/[^/]+\.[a-z]{2,}|\{\{|mailto:|tel:|\/)/i.test(href) ? '' : '<span style="color:var(--s-deferred)">Set a real address before sending.</span>');
  let media = null;
  const mediaGrid = (src) => `<div class="ebf"><label>Your images</label><div class="asset-grid photos" id="media">${media === null ? '<span class="faint" style="font-size:12.5px">Loading…</span>' : media.map((m) => `<button data-pv="src" data-val="${esc(m.src)}" class="${src === m.src ? 'on' : ''}" title="${esc(m.name || '')}"><img src="${esc(m.src)}" loading="lazy" alt=""></button>`).join('')}<button class="asset-up" title="Upload an image">${ic('upload', 16)}</button></div><input type="file" id="upfile" accept="image/png,image/jpeg,image/gif,image/webp" multiple hidden></div>`;
  const loadMedia = () => post(C.urls.assets, {}).then((j) => { media = (j && j.data) || []; });

  // The options of a store product block: the same ones the storefront's product widget has
  function productFields(o) {
    const W = C.widget, T = W.t;
    const sel2 = (k, opts) => `<select data-o="${k}">${opts.map((x) => `<option value="${esc(x.id)}" ${String(o[k]) === String(x.id) ? 'selected' : ''}>${esc(x.name || x.label)}</option>`).join('')}</select>`;
    const txt = (k, ph = '') => `<input data-o="${k}" value="${esc(o[k])}" placeholder="${esc(ph)}">`;
    const num = (k, min, max) => `<input type="number" min="${min}" max="${max}" data-o="${k}" value="${esc(o[k])}">`;
    const yes = (k) => `<label class="check"><input type="checkbox" data-oc="${k}" ${String(o[k]) === '1' ? 'checked' : ''}> ${esc(T[k])}</label>`;
    const feature = o.layout === 'feature';
    return field('Layout', `<div class="lay-list">${W.layouts.map((l) => `<button data-layout="${l.id}" class="${o.layout === l.id ? 'on' : ''}"><b>${esc(l.label)}</b><small>${esc(l.hint)}</small></button>`).join('')}</div>`)
      + field(T.source, sel2('source', W.sources))
      + (o.source === 'category' ? field(T.categoryField, sel2('category', W.categories)) : '')
      + (o.source === 'skus' ? field(T.skus, txt('skus', 'SKU-1, SKU-2')) : '')
      + field(T.heading, txt('heading')) + (feature ? field(T.label, txt('label')) + field(T.blurb, `<textarea data-o="blurb" rows="3">${esc(o.blurb)}</textarea>`) + field(T.button, txt('button')) : field(T.intro, txt('intro')))
      + (feature ? '' : `<div class="two">${field(T.limit, num('limit', 1, 24))}${field(T.columns, num('columns', 1, 4))}</div>` + field(T.sort, sel2('sort', W.sorts)) + field(T.min_products, num('min_products', 0, 24)))
      + `<div class="ebf">${yes('show_price')}${yes('show_brand')}${feature ? '' : yes('show_view_all')}</div>`
      + (!feature && String(o.show_view_all) === '1' ? field(T.view_all_url, txt('view_all_url', 'The category’s own page')) + field(T.view_all_label, txt('view_all_label')) : '')
      + field(T.image_width, num('image_width', 60, 600));
  }

  function renderProps() {
    const pane = $('#props'), f = sel && find(sel);
    if (!f) return renderChecks(pane);
    if (f.block) {
      const b = f.block, p = b.props, st = s(); let h = '';
      const t = b.type;
      if (t === 'heading' || t === 'text') h += field('Size', `<input type="range" min="${t === 'heading' ? 16 : 12}" max="${t === 'heading' ? 44 : 22}" data-p="size" value="${p.size}"><span class="range-v">${p.size}px</span>`) + field('Alignment', alignCtl(p.align)) + (t === 'heading' ? field('Weight', `<div class="eb-seg">${[[400, 'Regular'], [700, 'Bold']].map(([w, l]) => `<button data-pv="weight" data-val="${w}" data-num="1" class="${+p.weight === w ? 'on' : ''}">${l}</button>`).join('')}</div>`) : '') + `<div class="color-grid one">${colorField('Text colour', 'color', p.color || (t === 'heading' ? st.heading : st.text), 'p')}</div>` + `<p class="ebf-tip">Click the text on the email to edit it. Use <b>{{ }} Personalize</b> to add the reader's name.</p>`;
      if (t === 'image') h += mediaGrid(p.src) + field('Image address', `<input data-p="src" value="${esc(p.src)}" placeholder="https://">`) + field('Alt text', `<input data-p="alt" value="${esc(p.alt)}" placeholder="Describe the image">`, p.alt ? 'Read aloud by screen readers and shown when images are blocked.' : '<span style="color:var(--s-deferred)">Missing. Shown when images are blocked, and read by screen readers.</span>') + field('Link', `<input data-p="href" value="${esc(p.href)}" placeholder="https://">`, linkHelp(p.href)) + field('Width', `<input type="range" min="10" max="100" data-p="width" value="${p.width}"><span class="range-v">${p.width}%</span>`) + field('Alignment', alignCtl(p.align)) + field('Corners', `<input type="range" min="0" max="32" data-p="radius" value="${p.radius}"><span class="range-v">${p.radius}px</span>`);
      if (t === 'button') h += field('Label', `<input data-p="label" value="${esc(p.label)}">`) + field('Link', `<input data-p="href" value="${esc(p.href)}" placeholder="https://">`, p.href ? linkHelp(p.href) : '<span style="color:var(--s-deferred)">Set where the button goes before sending.</span>') + `<div class="color-grid">${colorField('Button', 'bg', p.bg || st.btn, 'p')}${colorField('Label', 'color', p.color || st.btnText, 'p')}</div>` + field('Alignment', alignCtl(p.align)) + field('Corners', `<input type="range" min="0" max="30" data-p="radius" value="${p.radius == null ? st.radius : p.radius}"><span class="range-v">${p.radius == null ? st.radius : p.radius}px</span>`) + `<label class="check"><input type="checkbox" data-pc="full" ${p.full ? 'checked' : ''}> Full width</label>`;
      if (t === 'divider') h += `<div class="color-grid one">${colorField('Line', 'color', p.color, 'p')}</div>` + field('Thickness', `<input type="range" min="1" max="6" data-p="thick" value="${p.thick}"><span class="range-v">${p.thick}px</span>`) + field('Width', `<input type="range" min="10" max="100" data-p="width" value="${p.width}"><span class="range-v">${p.width}%</span>`);
      if (t === 'spacer') h += field('Height', `<input type="range" min="8" max="96" data-p="h" value="${p.h}"><span class="range-v">${p.h}px</span>`);
      if (t === 'social') h += `<div class="ebf"><label>Networks</label><div class="soc-pick">${Object.keys(SOCIAL).map((k) => `<label class="${p.nets.includes(k) ? 'on' : ''}"><input type="checkbox" data-net="${k}" ${p.nets.includes(k) ? 'checked' : ''}>${socialIcon(k, 22)}<span>${SOCIAL[k][0]}</span></label>`).join('')}</div></div>` + p.nets.map((k) => field(SOCIAL[k][0] + ' address', `<input data-url="${k}" value="${esc((p.urls || {})[k] || '')}" placeholder="https://">`)).join('') + field('Alignment', alignCtl(p.align)) + field('Icon size', `<input type="range" min="20" max="44" data-p="size" value="${p.size}"><span class="range-v">${p.size}px</span>`);
      if (t === 'products') h += productFields(p.options) + `<p class="ebf-tip">${ic('info', 13)} Filled from the catalog when the email is sent, so prices and stock are right on the day.</p>`;
      if (t === 'coupon') h += field('Code', `<input class="mono" data-p="code" value="${esc(p.code)}">`) + field('Note', `<input data-p="note" value="${esc(p.note)}">`);
      if (t === 'quote') h += field('Person', `<input data-p="who" value="${esc(p.who)}">`) + `<p class="ebf-tip">Click the quote on the email to edit it.</p>`;
      if (t === 'menu') h += field('Items', `<textarea data-p="items" rows="4" placeholder="Books | https://…, Sale | https://…">${esc(p.items)}</textarea>`, 'Comma separated. Each is a label, a bar, and where it goes.') + field('Alignment', alignCtl(p.align));
      if (t === 'html') h += field('HTML', `<textarea class="mono" data-p="code" rows="${/^\s*(<!doctype|<html)/i.test(p.code) ? 26 : 12}" spellcheck="false">${esc(p.code)}</textarea>`, 'Inline styles only; most inboxes strip &lt;style&gt; and scripts. Put {{ unsubscribe_url }} where the unsubscribe link goes.');
      if (t === 'footer') h += field('Company', `<input data-p="company" value="${esc(p.company)}">`) + field('Postal address', `<input data-p="address" value="${esc(p.address)}">`, 'Required by anti-spam law.') + field('Why they get this', `<input data-p="note" value="${esc(p.note)}" placeholder="You are receiving this because you subscribed at…">`) + field('Unsubscribe wording', `<input data-p="unsubscribe" value="${esc(p.unsubscribe)}">`) + `<div class="ebf-lock">${ic('shield', 13)} The unsubscribe link is always included and can't be removed.</div>`;
      if (t === 'storeheader' || t === 'storefooter') h += `<p class="ebf-tip">${ic('shield', 13)} This is the store's own ${t === 'storeheader' ? 'header' : 'footer'}. It is the same in every email and follows the Brand settings, so a change there reaches every email at once.</p><a class="btn btn-sm" href="${esc(C.urls.brand)}" target="_blank" rel="noopener">Open Brand settings</a>`;
      if (p.pad) h += field('Padding', padCtl(p.pad));
      pane.innerHTML = `<div class="ebpp-head"><span class="ebpp-ic">${blockGlyph(t)}</span><div><small>Block</small><b>${BLOCKS[t][0]}</b></div><button class="icon-btn" id="pclose">${ic('x', 15)}</button></div><div class="ebpp-body">${h}</div><div class="ebpp-foot"><button class="btn btn-sm" data-act="dup">${ic('copy', 13)}Duplicate</button><button class="btn btn-sm" data-act="del">${ic('trash', 13)}Delete</button><button class="btn btn-sm" data-act="row" style="margin-left:auto">Select row</button></div>`;
      bindProps(pane, p, f);
      if (t === 'image' && media === null) loadMedia().then(() => { if (sel === b.id) renderProps(); });
    } else {
      const r = f.row;
      pane.innerHTML = `<div class="ebpp-head"><span class="ebpp-ic">${ic('split', 15)}</span><div><small>Row</small><b>${r.layout.length === 1 ? 'One column' : r.layout.join(' : ') + ' columns'}</b></div><button class="icon-btn" id="pclose">${ic('x', 15)}</button></div><div class="ebpp-body">
        <div class="ebf"><label>Columns</label><div class="lay-pick">${LAYOUTS.map((l, i) => `<button data-lay="${i}" class="${l.join() === r.layout.join() ? 'on' : ''}">${l.map((w) => `<i style="flex:${w}"></i>`).join('')}</button>`).join('')}</div><small>Content moves into the remaining columns if you reduce them.</small></div>
        <div class="color-grid one">${colorField('Row background', 'bg', r.bg || s().contentBg, 'r')}</div>
        ${field('Padding', `<div class="pad4 pad2">${['Top', 'Bottom'].map((l, i) => `<label><input type="number" min="0" max="80" data-rpad="${i}" value="${r.pad[i]}"><small>${l}</small></label>`).join('')}</div>`)}
        <div class="ebf"><label>On phones</label><label class="check"><input type="checkbox" data-rc="stack" ${r.stack ? 'checked' : ''}> Stack columns</label></div>
        <div class="ebf"><label>Show on</label><div class="eb-seg">${[['', 'All devices'], ['mobile', 'Desktop only'], ['desktop', 'Mobile only']].map(([k, l]) => `<button data-rhide="${k}" class="${r.hide === k ? 'on' : ''}">${l}</button>`).join('')}</div></div></div>
        <div class="ebpp-foot"><button class="btn btn-sm" data-act="rdup">${ic('copy', 13)}Duplicate</button><button class="btn btn-sm" data-act="rsave">${ic('download', 13)}Save as section</button><button class="btn btn-sm" data-act="rdel">${ic('trash', 13)}</button></div>`;
      $$('[data-lay]', pane).forEach((b) => b.onclick = () => { const nl = LAYOUTS[+b.dataset.lay].slice(); const cells = nl.map((_, i) => r.cells[i] || []); r.cells.slice(nl.length).forEach((c) => cells[nl.length - 1].push(...c)); r.layout = nl; r.cells = cells; commit(); renderStage(); });
      $$('[data-r]', pane).forEach((inp) => { inp.oninput = () => { r[inp.dataset.r] = inp.value; inp.parentElement.querySelector('small').textContent = inp.value.toUpperCase(); renderStage(true); }; inp.onchange = commit; });
      $$('[data-rpad]', pane).forEach((inp) => { inp.oninput = () => { r.pad[+inp.dataset.rpad] = +inp.value; renderStage(true); }; inp.onchange = commit; });
      $$('[data-rc]', pane).forEach((inp) => inp.onchange = () => { r[inp.dataset.rc] = inp.checked; commit(); renderStage(); });
      $$('[data-rhide]', pane).forEach((b) => b.onclick = () => { r.hide = b.dataset.rhide; commit(); renderStage(); });
      $$('[data-act]', pane).forEach((b) => b.onclick = () => { const a = b.dataset.act; if (a === 'rdel') { doc.rows = doc.rows.filter((x) => x.id !== r.id); sel = null; commit(); renderStage(); } if (a === 'rdup') $(`[data-rdup="${r.id}"]`).click(); if (a === 'rsave') saveSection(r.id); });
    }
    const pc = $('#pclose'); if (pc) pc.onclick = () => select(null);
  }
  /*
   * Redraw after a field has been changed, without taking the cursor away. A change is reported as its
   * field loses focus, which is the moment the next field is gaining it: redrawing the panel right then
   * would remove the field being moved to. So wait for focus to land, redraw, and put it back.
   */
  function refresh() {
    setTimeout(() => {
      const a = document.activeElement, pane = $('#props');
      let key = null;
      if (a && pane.contains(a)) { const d = Object.keys(a.dataset)[0]; if (d) key = '[data-' + d.replace(/[A-Z]/g, (m) => '-' + m.toLowerCase()) + '="' + a.dataset[d] + '"]'; }
      renderStage();
      const el = key && $(key, $('#props'));
      if (el) { el.focus(); if (el.tagName === 'INPUT' && /^(text|number|url|search|)$/.test(el.type || '')) { try { el.select(); } catch (e) {} } }
    }, 0);
  }
  function bindProps(pane, p, f) {
    const live = () => renderStage(true);
    $$('[data-p]', pane).forEach((inp) => { inp.addEventListener('input', () => { const k = inp.dataset.p; p[k] = inp.type === 'range' || inp.type === 'number' ? +inp.value : inp.value; if (inp.type === 'range') inp.nextElementSibling.textContent = inp.value + (/(width)/.test(k) ? '%' : 'px'); if (inp.type === 'color') inp.parentElement.querySelector('small').textContent = inp.value.toUpperCase(); live(); }); inp.addEventListener('change', () => { commit(); refresh(); }); });
    $$('[data-pc]', pane).forEach((inp) => inp.onchange = () => { p[inp.dataset.pc] = inp.checked; commit(); renderStage(); });
    $$('[data-pv]', pane).forEach((b) => b.onclick = () => { p[b.dataset.pv] = b.dataset.num ? +b.dataset.val : b.dataset.val; commit(); renderStage(); });
    $$('[data-pad]', pane).forEach((inp) => { inp.oninput = () => { p.pad[+inp.dataset.pad] = +inp.value; live(); }; inp.onchange = commit; });
    $$('[data-net]', pane).forEach((inp) => inp.onchange = () => { p.nets = Object.keys(SOCIAL).filter((k) => $(`[data-net="${k}"]`, pane).checked); p.urls = p.urls || {}; commit(); renderStage(); });
    $$('[data-url]', pane).forEach((inp) => { inp.oninput = () => { p.urls = Array.isArray(p.urls) || !p.urls ? {} : p.urls; p.urls[inp.dataset.url] = inp.value; }; inp.onchange = commit; });
    // store products: an option changed means a new picture of the block from the server
    $$('[data-o]', pane).forEach((inp) => inp.onchange = () => { p.options[inp.dataset.o] = inp.value; commit(); refresh(); });
    $$('[data-oc]', pane).forEach((inp) => inp.onchange = () => { p.options[inp.dataset.oc] = inp.checked ? '1' : '0'; commit(); renderStage(); });
    $$('[data-layout]', pane).forEach((b) => b.onclick = () => { const l = C.widget.layouts.find((x) => x.id === b.dataset.layout); const keep = { heading: p.options.heading, category: p.options.category, skus: p.options.skus }; p.options = Object.assign({}, l.defaults, { layout: l.id }, Object.fromEntries(Object.entries(keep).filter(([, val]) => val))); Object.keys(p.options).forEach((k) => (p.options[k] = String(p.options[k]))); commit(); renderStage(); });
    $$('[data-act]', pane).forEach((b) => b.onclick = () => { const a = b.dataset.act; if (a === 'del') { f.cell.splice(f.i, 1); sel = null; commit(); renderStage(); } if (a === 'dup') $(`[data-bdup="${f.block.id}"]`).click(); if (a === 'row') select(f.row.id); });
    const up = $('#upfile', pane);
    $$('.asset-up', pane).forEach((b) => b.onclick = () => up && up.click());
    if (up) up.onchange = () => { if (!up.files.length) return; toast('Uploading…'); post(C.urls.assets, {}, [...up.files]).then((j) => { if (j.error || !j.data || !j.data.length) return toast(j.error || 'That image could not be uploaded.'); p.src = j.data[0].src; return loadMedia().then(() => { commit(); renderStage(); }); }); };
  }
  function renderChecks(pane) {
    const list = lastChecks, bad = list.filter((x) => x.level !== 'ok').length;
    pane.innerHTML = `<div class="ebpp-head"><span class="ebpp-ic" style="background:var(--accent-soft);color:var(--accent-text)">${ic('shield', 15, 2)}</span><div><small>Before you send</small><b>Email checks</b></div><span class="ck-score ${bad ? 'warn' : 'ok'}">${bad ? bad + ' to fix' : 'All good'}</span></div>
      <div class="ebpp-body" id="ckbody"><div class="ck-list">${list.map((c) => `<div class="ck2 ${c.level}"><span class="ck2-ic">${c.level === 'ok' ? ic('check', 12, 3) : '!'}</span><div><b>${esc(c.label)}</b><small>${esc(c.message)}</small></div></div>`).join('') || '<p class="ebf-tip">Checks appear here once the design is saved.</p>'}</div>
      <div class="ebf" style="margin-top:18px"><label>Layers</label><div class="outline">${doc.rows.map((r, i) => `<button class="ol-row" data-sel="${r.id}">${ic('split', 12)}Row ${i + 1} <small>${r.layout.join(':')}</small></button>${r.cells.flat().map((b) => `<button class="ol-blk" data-sel="${b.id}">${BLOCKS[b.type][0]}<small>${esc(String(b.props.label || b.props.html || b.props.alt || (b.props.options || {}).heading || b.props.code || '').replace(/<[^>]+>|\{\{[^}]+\}\}/g, '').slice(0, 26))}</small></button>`).join('')}`).join('')}</div></div></div>`;
    $$('[data-sel]', pane).forEach((b) => b.onclick = () => { select(b.dataset.sel); const el = $(`[data-b="${b.dataset.sel}"], .eb-row[data-r="${b.dataset.sel}"]`); el && el.scrollIntoView({ behavior: 'smooth', block: 'center' }); });
  }

  // ─── Preview & test overlay: the email as the server renders it, for a sample reader ───
  function preview() {
    const o = document.createElement('div'); o.className = 'eb-prev';
    o.innerHTML = `<div class="ep-bar"><b>Preview</b><span class="faint">${esc(meta.subject || 'Your subject line')} — ${esc(meta.preheader || 'no preview text')}</span><div class="spacer"></div>${C.urls.test ? `<div class="ep-test"><input id="pvto" value="${esc(C.adminEmail)}" aria-label="Send test to"><button class="btn btn-primary btn-sm" id="pvsend">${ic('send', 13)}Send test</button></div>` : ''}<button class="ep-close" id="pvx" title="Close preview">${ic('x', 15, 2.2)}<span>Close</span><kbd>Esc</kbd></button></div>
      <div class="ep-stage"><div class="ep-desk"><div class="ep-chrome"><i></i><i></i><i></i><span>Desktop</span></div><iframe id="pv-d" sandbox></iframe></div><div class="ep-phone"><div class="ep-notch"></div><iframe id="pv-m" sandbox></iframe><span class="ep-cap">Phone</span></div></div>`;
    document.body.appendChild(o);
    post(C.urls.render, { doc: JSON.stringify(doc), preheader: meta.preheader }).then((j) => { const h = (j && j.html) || '<p style="font-family:sans-serif;padding:24px">The preview could not be made.</p>'; $('#pv-d', o).srcdoc = h; $('#pv-m', o).srcdoc = h; });
    const close = () => { o.remove(); document.removeEventListener('keydown', onEsc); };
    const onEsc = (e) => { if (e.key === 'Escape') close(); };
    $('#pvx', o).onclick = close; document.addEventListener('keydown', onEsc);
    const send = $('#pvsend', o);
    if (send) send.onclick = () => { send.disabled = true; save().then((ok) => ok && post(C.urls.test, { test_email: $('#pvto', o).value }).then((j) => toast(j && j.ok ? 'Test sent to ' + $('#pvto', o).value : (j && j.message) || 'The test could not be sent.'))).then(() => { send.disabled = false; }); };
  }

  // ─── Top bar ───
  const updateUndo = () => { $('#undo').disabled = past.length < 2; $('#redo').disabled = !future.length; };
  const undo = () => { if (past.length < 2) return; future.push(past.pop()); doc = JSON.parse(past[past.length - 1]); sel = null; renderLib(); renderStage(); updateUndo(); touch(); };
  const redo = () => { if (!future.length) return; const n = future.pop(); past.push(n); doc = JSON.parse(n); renderLib(); renderStage(); updateUndo(); touch(); };
  $('#undo').onclick = undo; $('#redo').onclick = redo;
  $$('#devs button', v).forEach((b) => b.onclick = () => { device = b.dataset.dev; $$('#devs button', v).forEach((x) => x.classList.toggle('on', x === b)); renderStage(); });
  $$('#views button', v).forEach((b) => b.onclick = () => { view = b.dataset.view; $$('#views button', v).forEach((x) => x.classList.toggle('on', x === b)); sel = null; renderStage(); });
  $('#prev').onclick = preview;
  const leave = (e) => { if (e) e.preventDefault(); save().then((ok) => { if (ok) { dirty = false; location.href = C.urls.back; } }); };
  $('#done').onclick = leave; $('#back').onclick = leave;
  const tn = $('#tname'); if (tn) tn.oninput = () => { meta.name = tn.value; touch(); };
  $$('.eb-rail button', v).forEach((b) => b.onclick = () => { tab = b.dataset.tab; renderLib(); });
  document.addEventListener('keydown', (e) => {
    const editing = e.target.closest && e.target.closest('input,textarea,select,[contenteditable="true"]');
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'z' && !editing) { e.preventDefault(); e.shiftKey ? redo() : undo(); }
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 's') { e.preventDefault(); dirty = true; save().then((ok) => ok && toast('Saved')); }
    if ((e.key === 'Delete' || e.key === 'Backspace') && !editing && sel && !$('.eb-prev')) { e.preventDefault(); const f = find(sel); if (f.block) f.cell.splice(f.i, 1); else doc.rows = doc.rows.filter((r) => r.id !== sel); sel = null; commit(); renderStage(); }
    if (e.key === 'Escape' && !editing && !$('.eb-prev')) select(null);
  });

  post(C.urls.sections, {}).then((j) => { saved = (j && j.sections) || []; if (tab === 'sections') renderLib(); });
  renderLib(); renderStage(); updateUndo();
  // A design opened for the first time in the builder is kept in its new form straight away
  if (C.unsaved) { dirty = true; save(); }
})();
