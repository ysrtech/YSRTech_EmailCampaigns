import React, { useCallback, useMemo, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import {
  ReactFlow,
  ReactFlowProvider,
  Background,
  Controls,
  addEdge,
  useNodesState,
  useEdgesState,
  useReactFlow,
} from '@xyflow/react';
import '@xyflow/react/dist/style.css';
import './style.css';
import { NODE_TYPES, PALETTE } from './nodes';
import NodePanel from './NodePanel';

/**
 * YSRTech EmailCampaigns — automation ("drip") flow canvas.
 * Mounted by skin/adminhtml/.../flow_editor.phtml with a global config:
 *
 *   window.YsrFlowEditorConfig = {
 *     saveUrl, formKey, flowId, name, status, triggerType, storeId,
 *     graph: {nodes: [{id, type, position, config}], edges: [{source, target}]} | null,
 *     templates: [{id, name}, ...],
 *     storeOptions: [{label, value: storeId | [{label, value: storeId}, ...]}, ...]
 *   };
 *
 * graph_json's node shape ({id, type, position, config}) is what
 * Flow/Engine.php reads back on the backend (position is UI-only, ignored
 * there) — see that file's _graph() for the executing side of this contract.
 */

const cfg = window.YsrFlowEditorConfig || {};

function defaultGraph() {
  return {
    nodes: [{ id: 'trigger-1', type: 'trigger', position: { x: 250, y: 40 }, config: { trigger_type: 'order_placed' } }],
    edges: [],
  };
}

function hydrateNode(saved, templates) {
  const data = { ...(saved.config || {}) };
  if (saved.type === 'action_send_email') {
    const tpl = templates.find((t) => t.id === data.template_id);
    data.templateName = tpl ? tpl.name : '';
  }
  return { id: saved.id, type: saved.type, position: saved.position || { x: 250, y: 40 }, data };
}

function toGraphJson(nodes, edges) {
  return {
    nodes: nodes.map((n) => {
      const { templateName, ...config } = n.data; // eslint-disable-line no-unused-vars
      return { id: n.id, type: n.type, position: n.position, config };
    }),
    edges: edges.map((e) => ({ source: e.source, target: e.target })),
  };
}

let nodeSeq = 1;
function nextNodeId(type) {
  return `${type}-${Date.now()}-${nodeSeq++}`;
}

// getStoreValuesForForm()'s shape: a flat array where an entry's `value` is
// either a store id (a plain <option>) or an array of {label, value} children
// (an <optgroup>) — see Block/Adminhtml/Flow/Editor.php's getStoreOptionsJson().
function renderStoreOptions(options) {
  return options.map((opt, i) =>
    Array.isArray(opt.value) ? (
      <optgroup key={i} label={opt.label}>
        {opt.value.map((child, j) => (
          <option key={j} value={child.value}>{child.label}</option>
        ))}
      </optgroup>
    ) : (
      <option key={i} value={opt.value}>{opt.label}</option>
    )
  );
}

function Toolbar({ name, setName, status, setStatus, storeId, setStoreId, storeOptions, onSave, saving, statusMessage }) {
  return (
    <div className="ysr-flow-toolbar">
      <strong>Automation Flow</strong>
      <input type="text" value={name} placeholder="Flow name" onChange={(e) => setName(e.target.value)} />
      <select value={status} onChange={(e) => setStatus(e.target.value)}>
        <option value="draft">Draft</option>
        <option value="active">Active</option>
        <option value="paused">Paused</option>
      </select>
      <select value={storeId} onChange={(e) => setStoreId(parseInt(e.target.value, 10) || 0)}>
        {renderStoreOptions(storeOptions)}
      </select>
      <button type="button" onClick={onSave} disabled={saving}>{saving ? 'Saving…' : 'Save'}</button>
      <span>{statusMessage}</span>
    </div>
  );
}

function Palette() {
  return (
    <div className="ysr-flow-palette">
      <h4>Add a step</h4>
      {PALETTE.map((item) => (
        <div
          key={item.type}
          className="ysr-flow-palette__item"
          draggable
          onDragStart={(e) => {
            e.dataTransfer.setData('application/ysr-node-type', item.type);
            e.dataTransfer.effectAllowed = 'move';
          }}
        >
          {item.label}
        </div>
      ))}
    </div>
  );
}

function Canvas({ nodes, edges, onNodesChange, onEdgesChange, onConnect, onNodeClick, onPaneClick, onDropNode }) {
  const wrapperRef = useRef(null);
  const { screenToFlowPosition } = useReactFlow();

  return (
    <div
      className="ysr-flow-canvas"
      ref={wrapperRef}
      onDragOver={(e) => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
      }}
      onDrop={(e) => {
        e.preventDefault();
        const type = e.dataTransfer.getData('application/ysr-node-type');
        if (!type) return;
        const position = screenToFlowPosition({ x: e.clientX, y: e.clientY });
        onDropNode(type, position);
      }}
    >
      <ReactFlow
        nodes={nodes}
        edges={edges}
        nodeTypes={NODE_TYPES}
        onNodesChange={onNodesChange}
        onEdgesChange={onEdgesChange}
        onConnect={onConnect}
        onNodeClick={onNodeClick}
        onPaneClick={onPaneClick}
        deleteKeyCode={['Backspace', 'Delete']}
        fitView
      >
        <Background />
        <Controls />
      </ReactFlow>
    </div>
  );
}

function App() {
  const initialGraph = useMemo(() => cfg.graph || defaultGraph(), []);
  const templates = cfg.templates || [];

  const [nodes, setNodes, onNodesChange] = useNodesState(
    initialGraph.nodes.map((n) => hydrateNode(n, templates))
  );
  const [edges, setEdges, onEdgesChange] = useEdgesState(
    initialGraph.edges.map((e) => ({ id: `e-${e.source}-${e.target}`, source: e.source, target: e.target }))
  );
  const [selectedId, setSelectedId] = useState(null);
  const [name, setName] = useState(cfg.name || '');
  const [status, setStatus] = useState(cfg.status || 'draft');
  const [storeId, setStoreId] = useState(cfg.storeId || 0);
  const storeOptions = cfg.storeOptions || [];
  const [saving, setSaving] = useState(false);
  const [statusMessage, setStatusMessage] = useState('');

  const selectedNode = nodes.find((n) => n.id === selectedId) || null;

  const onConnect = useCallback(
    (params) => setEdges((eds) => addEdge(params, eds.filter((e) => e.source !== params.source))),
    [setEdges]
  );

  const onDropNode = useCallback(
    (type, position) => {
      const preset = PALETTE.find((p) => p.type === type);
      const id = nextNodeId(type);
      setNodes((nds) => nds.concat({ id, type, position, data: { ...(preset?.defaultData || {}) } }));
    },
    [setNodes]
  );

  const updateSelectedData = useCallback(
    (data) => {
      setNodes((nds) => nds.map((n) => (n.id === selectedId ? { ...n, data } : n)));
    },
    [selectedId, setNodes]
  );

  const deleteSelected = useCallback(() => {
    setNodes((nds) => nds.filter((n) => n.id !== selectedId));
    setEdges((eds) => eds.filter((e) => e.source !== selectedId && e.target !== selectedId));
    setSelectedId(null);
  }, [selectedId, setNodes, setEdges]);

  async function save() {
    setSaving(true);
    setStatusMessage('Saving…');
    try {
      const body = new FormData();
      body.append('form_key', cfg.formKey);
      body.append('id', cfg.flowId || '');
      body.append('name', name);
      body.append('status', status);
      body.append('trigger_type', cfg.triggerType || 'order_placed');
      body.append('store_id', storeId);
      body.append('graph_json', JSON.stringify(toGraphJson(nodes, edges)));

      const res = await fetch(cfg.saveUrl, { method: 'POST', body });
      const json = await res.json();
      if (!res.ok || !json.success) throw new Error(json.message || `HTTP ${res.status}`);
      // Remember the assigned id — this page never navigates, so without this
      // every later save on a brand-new flow would insert another duplicate
      // row instead of updating the one just created.
      cfg.flowId = json.id;
      setStatusMessage('Saved ✓');
    } catch (e) {
      setStatusMessage('Save failed: ' + e.message);
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="ysr-flow-app">
      <Toolbar
        name={name} setName={setName}
        status={status} setStatus={setStatus}
        storeId={storeId} setStoreId={setStoreId} storeOptions={storeOptions}
        onSave={save} saving={saving} statusMessage={statusMessage}
      />
      <div className="ysr-flow-body">
        <Palette />
        <Canvas
          nodes={nodes}
          edges={edges}
          onNodesChange={onNodesChange}
          onEdgesChange={onEdgesChange}
          onConnect={onConnect}
          onNodeClick={(_, node) => setSelectedId(node.id)}
          onPaneClick={() => setSelectedId(null)}
          onDropNode={onDropNode}
        />
        <NodePanel
          node={selectedNode}
          templates={templates}
          onChange={updateSelectedData}
          onDelete={deleteSelected}
        />
      </div>
    </div>
  );
}

createRoot(document.getElementById('ysr-flow-editor-root')).render(
  <ReactFlowProvider>
    <App />
  </ReactFlowProvider>
);
