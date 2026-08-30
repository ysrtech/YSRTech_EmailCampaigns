import React from 'react';
import { Handle, Position } from '@xyflow/react';

/**
 * One component per node type the backend engine (Flow/Engine.php) can
 * actually execute — trigger / delay / action_send_email. Each node's
 * on-canvas `data` mirrors the config shape the engine reads, plus
 * UI-only convenience fields (e.g. templateName) stripped back out at save
 * time (see main.jsx's toGraphJson).
 */

export function TriggerNode() {
  return (
    <div className="ysr-flow-node ysr-flow-node--trigger">
      <div className="ysr-flow-node__label">Trigger</div>
      <div className="ysr-flow-node__body">Order Placed</div>
      <Handle type="source" position={Position.Bottom} />
    </div>
  );
}

export function DelayNode({ data }) {
  return (
    <div className="ysr-flow-node ysr-flow-node--delay">
      <Handle type="target" position={Position.Top} />
      <div className="ysr-flow-node__label">Delay</div>
      <div className="ysr-flow-node__body">Wait {data.value ?? 1} {data.unit ?? 'days'}</div>
      <Handle type="source" position={Position.Bottom} />
    </div>
  );
}

export function ActionSendEmailNode({ data }) {
  return (
    <div className="ysr-flow-node ysr-flow-node--action">
      <Handle type="target" position={Position.Top} />
      <div className="ysr-flow-node__label">Send Email</div>
      <div className="ysr-flow-node__body">{data.templateName || 'Select a template…'}</div>
    </div>
  );
}

export const NODE_TYPES = {
  trigger: TriggerNode,
  delay: DelayNode,
  action_send_email: ActionSendEmailNode,
};

export const PALETTE = [
  { type: 'delay', label: 'Delay', defaultData: { unit: 'days', value: 1 } },
  { type: 'action_send_email', label: 'Send Email', defaultData: { template_id: null, templateName: '' } },
];
