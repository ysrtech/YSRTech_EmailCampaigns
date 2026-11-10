import React, { createContext, useContext, useEffect, useRef, useState } from 'react';
import { BaseEdge, EdgeLabelRenderer, Handle, Position, getSmoothStepPath } from '@xyflow/react';
import { t } from './i18n';
import { NODE_DELAY, NODE_SEND, EVENT_ORDER_STATUS, EVENT_PRODUCT } from './graph';

/**
 * What every node and connection needs to know about the flow around it:
 * its problems, its counts, whether anything may be changed. Handed down by
 * context rather than written into each node's data, because none of it is
 * part of the drawing and all of it would otherwise have to be stripped back
 * out before saving.
 */
export const FlowContext = createContext({
  readOnly: false,
  problemsByNode: {},
  countsByNode: {},
  tails: new Set(),
  templates: [],
  events: [],
  orderStatuses: [],
  onInsert: () => {},
  onAppend: () => {},
});

/**
 * The "+" on a connection and under the last step: a button that opens the
 * two things a chain can be made of.
 */
function AddMenu({ onPick, title }) {
  const [open, setOpen] = useState(false);
  const ref = useRef(null);

  useEffect(() => {
    if (!open) return undefined;
    const close = (e) => {
      if (ref.current && !ref.current.contains(e.target)) setOpen(false);
    };
    document.addEventListener('mousedown', close);
    return () => document.removeEventListener('mousedown', close);
  }, [open]);

  const pick = (type) => (e) => {
    e.stopPropagation();
    setOpen(false);
    onPick(type);
  };

  return (
    // nodrag/nopan: React Flow would otherwise take the press as the start
    // of a drag and the click would never arrive.
    <span className={'ysr-flow-add nodrag nopan' + (open ? ' is-open' : '')} ref={ref}>
      <button
        type="button"
        className="ysr-flow-add__button"
        title={title}
        aria-label={title}
        aria-expanded={open}
        onClick={(e) => {
          e.stopPropagation();
          setOpen(!open);
        }}
      >
        +
      </button>
      {open && (
        <span className="ysr-flow-add__menu" role="menu">
          <button type="button" role="menuitem" data-add={NODE_DELAY} onClick={pick(NODE_DELAY)}>{t('Wait')}</button>
          <button type="button" role="menuitem" data-add={NODE_SEND} onClick={pick(NODE_SEND)}>{t('Send Email')}</button>
        </span>
      )}
    </span>
  );
}

function Badge({ id }) {
  const { problemsByNode } = useContext(FlowContext);
  const problems = problemsByNode[id];

  if (!problems || !problems.length) return null;

  return (
    <span className="ysr-flow-node__badge" title={problems.map((p) => p.message).join('\n')}>
      {problems.length > 1 ? problems.length : '!'}
    </span>
  );
}

/** Under a step nothing follows, so the chain can be carried on from there. */
function Tail({ id }) {
  const { tails, readOnly, onAppend } = useContext(FlowContext);

  if (readOnly || !tails.has(id)) return null;

  return (
    <span className="ysr-flow-node__tail">
      <AddMenu title={t('Add a step after this one')} onPick={(type) => onAppend(id, type)} />
    </span>
  );
}

function shell(kind, id, selected, problemsByNode) {
  return 'ysr-flow-node ysr-flow-node--' + kind
    + (selected ? ' is-selected' : '')
    + (problemsByNode[id] && problemsByNode[id].length ? ' has-problem' : '');
}

export function TriggerNode({ id, data, selected }) {
  const { events, orderStatuses, problemsByNode } = useContext(FlowContext);
  const event = events.find((e) => e.value === data.trigger_type);
  let detail = '';

  if (data.trigger_type === EVENT_ORDER_STATUS && data.order_status) {
    const status = orderStatuses.find((s) => s.value === data.order_status);
    detail = status ? status.label : data.order_status;
  } else if (data.trigger_type === EVENT_PRODUCT && data.product_id) {
    detail = t('Product #%d', data.product_id);
  }

  return (
    <div className={shell('trigger', id, selected, problemsByNode)}>
      <Badge id={id} />
      <div className="ysr-flow-node__label">{t('Trigger')}</div>
      <div className="ysr-flow-node__body">{event ? event.label : t('Choose a trigger…')}</div>
      {detail && <div className="ysr-flow-node__meta">{detail}</div>}
      <Handle type="source" position={Position.Bottom} />
      <Tail id={id} />
    </div>
  );
}

export function DelayNode({ id, data, selected }) {
  const { problemsByNode } = useContext(FlowContext);
  const units = { minutes: t('minutes'), hours: t('hours'), days: t('days') };

  return (
    <div className={shell('delay', id, selected, problemsByNode)}>
      <Handle type="target" position={Position.Top} />
      <Badge id={id} />
      <div className="ysr-flow-node__label">{t('Wait')}</div>
      <div className="ysr-flow-node__body">{data.value ?? 0} {units[data.unit] || units.days}</div>
      <Handle type="source" position={Position.Bottom} />
      <Tail id={id} />
    </div>
  );
}

export function SendNode({ id, data, selected }) {
  const { templates, problemsByNode, countsByNode } = useContext(FlowContext);
  const template = templates.find((tpl) => tpl.id === data.template_id);
  const counts = countsByNode[id];

  return (
    <div className={shell('send', id, selected, problemsByNode)}>
      <Handle type="target" position={Position.Top} />
      <Badge id={id} />
      <div className="ysr-flow-node__label">{t('Send Email')}</div>
      <div className="ysr-flow-node__body">
        {template ? template.name : data.template_id ? t('Template removed') : t('Choose a template…')}
      </div>
      {counts && (
        <div className="ysr-flow-node__counts">
          <span title={t('Messages this step has sent')}><b>{counts.sent.toLocaleString()}</b> {t('sent')}</span>
          <span title={t('Queued and not yet due, or being sent now')}><b>{counts.waiting.toLocaleString()}</b> {t('waiting')}</span>
          <span className={counts.failed ? 'is-bad' : ''} title={t('Gave up after retrying')}>
            <b>{counts.failed.toLocaleString()}</b> {t('failed')}
          </span>
        </div>
      )}
      <Handle type="source" position={Position.Bottom} />
      <Tail id={id} />
    </div>
  );
}

/** A connection, with the "+" that puts a step in the middle of it. */
export function InsertEdge({ id, sourceX, sourceY, targetX, targetY, sourcePosition, targetPosition, markerEnd, selected }) {
  const { readOnly, onInsert } = useContext(FlowContext);
  const [path, labelX, labelY] = getSmoothStepPath({
    sourceX, sourceY, targetX, targetY, sourcePosition, targetPosition, borderRadius: 4,
  });

  return (
    <>
      <BaseEdge id={id} path={path} markerEnd={markerEnd} className={selected ? 'is-selected' : ''} />
      {!readOnly && (
        <EdgeLabelRenderer>
          <div
            className="ysr-flow-edge-add"
            style={{ transform: `translate(-50%, -50%) translate(${labelX}px, ${labelY}px)` }}
          >
            <AddMenu title={t('Add a step here')} onPick={(type) => onInsert(id, type)} />
          </div>
        </EdgeLabelRenderer>
      )}
    </>
  );
}

export const NODE_TYPES = {
  trigger: TriggerNode,
  delay: DelayNode,
  action_send_email: SendNode,
};

export const EDGE_TYPES = { insert: InsertEdge };

export const PALETTE = [
  { type: NODE_DELAY, label: 'Wait' },
  { type: NODE_SEND, label: 'Send Email' },
];
