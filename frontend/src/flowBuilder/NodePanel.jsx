import React from 'react';
import { t } from './i18n';
import { NODE_TRIGGER, NODE_DELAY, NODE_SEND, EVENT_ORDER_STATUS, EVENT_PRODUCT } from './graph';

function Problems({ problems, onPick }) {
  if (!problems.length) return null;

  return (
    <ul className="ysr-flow-problems">
      {problems.map((p, i) => (
        <li key={i}>
          {onPick && p.node ? (
            <a href="#" onClick={(e) => { e.preventDefault(); onPick(p.node); }}>{p.message}</a>
          ) : p.message}
        </li>
      ))}
    </ul>
  );
}

/**
 * The side panel: the selected step's settings, or with nothing selected
 * everything that stands between the flow and going live.
 */
export default function NodePanel({
  node, problems, allProblems, templates, events, orderStatuses, counts, readOnly, onChange, onDelete, onPick,
}) {
  if (!node) {
    return (
      <div className="ysr-flow-panel">
        <h3>{t('This flow')}</h3>
        {allProblems.length ? (
          <>
            <p className="ysr-flow-panel__hint">{t('To fix before this can go live:')}</p>
            <Problems problems={allProblems} onPick={onPick} />
          </>
        ) : (
          <p className="ysr-flow-panel__ok">{t('Nothing to fix. This flow can go live.')}</p>
        )}
        {!readOnly && (
          <p className="ysr-flow-panel__hint">
            {t('Select a step to change it. Use the + on a connection to put a step between two others, or the + under the last step to carry on.')}
          </p>
        )}
      </div>
    );
  }

  const set = (patch) => onChange({ ...node.data, ...patch });

  if (node.type === NODE_TRIGGER) {
    return (
      <div className="ysr-flow-panel">
        <h3>{t('Trigger')}</h3>
        <Problems problems={problems} />
        <label htmlFor="ysr-flow-trigger">{t('Starts when')}</label>
        <select
          id="ysr-flow-trigger"
          value={node.data.trigger_type || ''}
          disabled={readOnly}
          onChange={(e) => set({ trigger_type: e.target.value })}
        >
          {!events.some((ev) => ev.value === node.data.trigger_type) && <option value="">{t('Choose a trigger…')}</option>}
          {events.map((ev) => <option key={ev.value} value={ev.value}>{ev.label}</option>)}
        </select>

        {node.data.trigger_type === EVENT_ORDER_STATUS && (
          <>
            <label htmlFor="ysr-flow-order-status">{t('Which status')}</label>
            <select
              id="ysr-flow-order-status"
              value={node.data.order_status || ''}
              disabled={readOnly}
              onChange={(e) => set({ order_status: e.target.value })}
            >
              <option value="">{t('Choose a status…')}</option>
              {orderStatuses.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
            </select>
            <p className="ysr-flow-panel__hint">{t('Fires when the order changes into this status, not on every later save.')}</p>
          </>
        )}

        {node.data.trigger_type === EVENT_PRODUCT && (
          <>
            <label htmlFor="ysr-flow-product-id">{t('Product ID')}</label>
            <input
              id="ysr-flow-product-id"
              type="number"
              min="1"
              value={node.data.product_id || ''}
              disabled={readOnly}
              onChange={(e) => set({ product_id: Math.max(0, parseInt(e.target.value, 10) || 0) })}
            />
          </>
        )}

        <p className="ysr-flow-panel__hint">{t('Every flow starts from one trigger, so this step cannot be removed.')}</p>
      </div>
    );
  }

  if (node.type === NODE_DELAY) {
    return (
      <div className="ysr-flow-panel">
        <h3>{t('Wait')}</h3>
        <Problems problems={problems} />
        <label htmlFor="ysr-flow-delay-value">{t('Wait for')}</label>
        <div className="ysr-flow-panel__row">
          <input
            id="ysr-flow-delay-value"
            type="number"
            min="1"
            value={node.data.value ?? ''}
            disabled={readOnly}
            // Not clamped while typing: clearing the box to type a new
            // number would snap back to 1 under the cursor. Zero shows as a
            // problem on the step instead.
            onChange={(e) => set({ value: parseInt(e.target.value, 10) || 0 })}
          />
          <select value={node.data.unit || 'days'} disabled={readOnly} onChange={(e) => set({ unit: e.target.value })}>
            <option value="minutes">{t('minutes')}</option>
            <option value="hours">{t('hours')}</option>
            <option value="days">{t('days')}</option>
          </select>
        </div>
        <p className="ysr-flow-panel__hint">{t('Counted from the step before, as drawn.')}</p>
        {!readOnly && <button type="button" className="ysr-flow-panel__delete" onClick={onDelete}>{t('Remove this step')}</button>}
      </div>
    );
  }

  if (node.type === NODE_SEND) {
    const known = templates.some((tpl) => tpl.id === node.data.template_id);

    return (
      <div className="ysr-flow-panel">
        <h3>{t('Send Email')}</h3>
        <Problems problems={problems} />
        <label htmlFor="ysr-flow-template">{t('Template')}</label>
        <select
          id="ysr-flow-template"
          value={known ? node.data.template_id : ''}
          disabled={readOnly}
          onChange={(e) => set({ template_id: parseInt(e.target.value, 10) || 0 })}
        >
          <option value="">{t('Choose a template…')}</option>
          {templates.map((tpl) => <option key={tpl.id} value={tpl.id}>{tpl.name}</option>)}
        </select>

        {counts && (
          <>
            <table className="ysr-flow-panel__counts">
              <tbody>
                <tr><th>{t('Sent')}</th><td>{counts.sent.toLocaleString()}</td></tr>
                <tr><th>{t('Waiting')}</th><td>{counts.waiting.toLocaleString()}</td></tr>
                <tr><th>{t('Failed')}</th><td>{counts.failed.toLocaleString()}</td></tr>
                <tr><th>{t('Cancelled')}</th><td>{counts.cancelled.toLocaleString()}</td></tr>
              </tbody>
            </table>
            <p className="ysr-flow-panel__hint">{t('Counted for the message at this position in the saved flow.')}</p>
          </>
        )}
        {!readOnly && <button type="button" className="ysr-flow-panel__delete" onClick={onDelete}>{t('Remove this step')}</button>}
      </div>
    );
  }

  return null;
}
