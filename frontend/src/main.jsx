import React, { useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import {
  EmailEditor,
  EmailEditorProvider,
  IEmailBlock,
} from '@easy-email/editor';
import '@easy-email/editor/dist/index.css';

/**
 * YSRTech EmailCampaigns — Easy Email (MJML-based) drag & drop editor.
 * Mounted by skin/adminhtml/.../editor.phtml with a global config:
 *
 *   window.YsrEmailEditorConfig = {
 *     saveUrl: '...',
 *     formKey: '...',
 *     templateId: 1,
 *     designJson: {...} | null
 *   };
 */

const cfg = window.YsrEmailEditorConfig || {};

function Editor() {
  const ref = useRef(null);
  const [saving, setSaving] = useState(false);
  const [status, setStatus] = useState('');

  // Load existing design once.
  useEffect(() => {
    if (cfg.designJson && ref.current) {
      // Easy Email accepts the saved values structure directly.
      ref.current.loadDesign(cfg.designJson);
    }
  }, []);

  async function save() {
    if (!ref.current) return;
    setSaving(true);
    setStatus('Saving…');
    try {
      const design = await new Promise((resolve, reject) => {
        ref.current.saveDesign((values) => resolve(values));
      });
      // Export final responsive HTML via MJML.
      const html = await new Promise((resolve) => {
        ref.current.getHtml((htmlStr) => resolve(htmlStr));
      });

      const body = new FormData();
      body.append('form_key', cfg.formKey);
      body.append('design_json', JSON.stringify(design));
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
    <div style={{ display: 'flex', flexDirection: 'column', height: '100vh' }}>
      <div style={{ padding: '8px 16px', background: '#f3f4f6', borderBottom: '1px solid #d1d5db', display: 'flex', gap: 12, alignItems: 'center' }}>
        <strong>YSRTech Email Designer</strong>
        <button onClick={save} disabled={saving} style={{ padding: '6px 18px' }}>
          {saving ? 'Saving…' : 'Save'}
        </button>
        <span>{status}</span>
      </div>
      <div style={{ flex: 1, minHeight: 0 }}>
        <EmailEditorProvider
          data={cfg.designJson || undefined}
          onReady={(_, editor) => { ref.current = editor; }}
        >
          <EmailEditor />
        </EmailEditorProvider>
      </div>
    </div>
  );
}

createRoot(document.getElementById('ysr-email-editor-root')).render(<Editor />);
