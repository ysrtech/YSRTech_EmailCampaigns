import React from 'react';

export default function NodePanel({ node, templates, onChange, onDelete }) {
  if (!node) {
    return (
      <div className="ysr-flow-panel">
        <p className="ysr-flow-panel__hint">
          Select a node to edit it, or drag one from the palette onto the canvas.
        </p>
      </div>
    );
  }

  if (node.type === 'trigger') {
    return (
      <div className="ysr-flow-panel">
        <h3>Trigger</h3>
        <p className="ysr-flow-panel__hint">
          Fires when a customer places an order. This is the flow's entry point and can't be removed.
        </p>
      </div>
    );
  }

  if (node.type === 'delay') {
    const { value = 1, unit = 'days' } = node.data;
    return (
      <div className="ysr-flow-panel">
        <h3>Delay</h3>
        <label>Wait</label>
        <div className="ysr-flow-panel__row">
          <input
            type="number"
            min="1"
            value={value}
            onChange={(e) => onChange({ ...node.data, value: Math.max(1, parseInt(e.target.value, 10) || 1) })}
          />
          <select value={unit} onChange={(e) => onChange({ ...node.data, unit: e.target.value })}>
            <option value="minutes">minutes</option>
            <option value="hours">hours</option>
            <option value="days">days</option>
          </select>
        </div>
        <button type="button" className="ysr-flow-panel__delete" onClick={onDelete}>Delete node</button>
      </div>
    );
  }

  if (node.type === 'action_send_email') {
    const { template_id: templateId = null } = node.data;
    return (
      <div className="ysr-flow-panel">
        <h3>Send Email</h3>
        <label>Template</label>
        <select
          value={templateId ?? ''}
          onChange={(e) => {
            const id = e.target.value ? parseInt(e.target.value, 10) : null;
            const tpl = templates.find((t) => t.id === id);
            onChange({ ...node.data, template_id: id, templateName: tpl ? tpl.name : '' });
          }}
        >
          <option value="">Select a template…</option>
          {templates.map((t) => (
            <option key={t.id} value={t.id}>{t.name}</option>
          ))}
        </select>
        <button type="button" className="ysr-flow-panel__delete" onClick={onDelete}>Delete node</button>
      </div>
    );
  }

  return null;
}
