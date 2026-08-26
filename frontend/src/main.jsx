import React, { useMemo, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { BasicType, AdvancedType, BlockManager, JsonToMjml } from 'easy-email-core';
import { EmailEditor, EmailEditorProvider } from 'easy-email-editor';
import { StandardLayout } from 'easy-email-extensions';
import mjml2html from 'mjml-browser';
import { registerCatalogBlocks, registerCatalogAttributePanels, CATALOG_CATEGORY } from './catalogBlocks';

import 'easy-email-editor/lib/style.css';
import 'easy-email-extensions/lib/style.css';

registerCatalogBlocks();
registerCatalogAttributePanels();

/**
 * YSRTech EmailCampaigns — Easy Email (MJML-based) drag & drop editor.
 * Mounted by skin/adminhtml/.../editor.phtml with a global config:
 *
 *   window.YsrEmailEditorConfig = {
 *     saveUrl: '...',
 *     formKey: '...',
 *     templateId: 1,
 *     designJson: {subject, subTitle, content} | null
 *   };
 */

const cfg = window.YsrEmailEditorConfig || {};

const CATEGORIES = [
  {
    label: 'Content',
    active: true,
    blocks: [
      { type: AdvancedType.TEXT },
      { type: AdvancedType.IMAGE, payload: { attributes: { padding: '0px 0px 0px 0px' } } },
      { type: AdvancedType.BUTTON },
      { type: AdvancedType.SOCIAL },
      { type: AdvancedType.DIVIDER },
      { type: AdvancedType.SPACER },
      { type: AdvancedType.HERO },
      { type: AdvancedType.WRAPPER },
    ],
  },
  {
    label: 'Layout',
    active: true,
    displayType: 'column',
    blocks: [
      { title: '2 columns', payload: [['50%', '50%'], ['33%', '67%'], ['67%', '33%'], ['25%', '75%'], ['75%', '25%']] },
      { title: '3 columns', payload: [['33.33%', '33.33%', '33.33%'], ['25%', '25%', '50%'], ['50%', '25%', '25%']] },
      { title: '4 columns', payload: [['25%', '25%', '25%', '25%']] },
    ],
  },
  CATALOG_CATEGORY,
];

function emptyTemplate() {
  return {
    subject: '',
    subTitle: '',
    content: BlockManager.getBlockByType(BasicType.PAGE).create({}),
  };
}

/** Compile the block tree to final responsive-table HTML via MJML. */
function renderHtml(content) {
  const mjml = JsonToMjml({ data: content, mode: 'production', dataSource: {} });
  return mjml2html(mjml).html;
}

function Toolbar({ values }) {
  const [saving, setSaving] = useState(false);
  const [status, setStatus] = useState('');

  async function save() {
    setSaving(true);
    setStatus('Saving…');
    try {
      const html = renderHtml(values.content);
      const body = new FormData();
      body.append('form_key', cfg.formKey);
      body.append('design_json', JSON.stringify(values));
      body.append('html', html);

      const res = await fetch(cfg.saveUrl, { method: 'POST', body });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      setStatus('Saved ✓');
    } catch (e) {
      setStatus('Save failed: ' + e.message);
    } finally {
      setSaving(false);
    }
  }

  return (
    <div style={{ padding: '8px 16px', background: '#f3f4f6', borderBottom: '1px solid #d1d5db', display: 'flex', gap: 12, alignItems: 'center' }}>
      <strong>YSRTech Email Designer</strong>
      <button onClick={save} disabled={saving} style={{ padding: '6px 18px' }}>
        {saving ? 'Saving…' : 'Save'}
      </button>
      <span>{status}</span>
    </div>
  );
}

function App() {
  const initialValues = useMemo(() => cfg.designJson || emptyTemplate(), []);

  return (
    <EmailEditorProvider data={initialValues} height="calc(100vh - 49px)">
      {({ values }) => (
        <div style={{ display: 'flex', flexDirection: 'column', height: '100vh' }}>
          <Toolbar values={values} />
          <div style={{ flex: 1, minHeight: 0 }}>
            <StandardLayout categories={CATEGORIES} showSourceCode>
              <EmailEditor />
            </StandardLayout>
          </div>
        </div>
      )}
    </EmailEditorProvider>
  );
}

createRoot(document.getElementById('ysr-email-editor-root')).render(<App />);
