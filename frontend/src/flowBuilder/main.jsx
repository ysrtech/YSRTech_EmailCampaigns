import './compat';
import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import {
  ReactFlow,
  ReactFlowProvider,
  Background,
  Controls,
  MiniMap,
  useNodesState,
  useEdgesState,
  useReactFlow,
} from '@xyflow/react';
import '@xyflow/react/dist/style.css';
import './style.css';
import { t } from './i18n';
import { NODE_TYPES, EDGE_TYPES, PALETTE, FlowContext } from './nodes';
import NodePanel from './NodePanel';
import {
  NODE_TRIGGER, NODE_SEND, EVENT_ORDER_STATUS, EVENT_PRODUCT,
  walk, validate, tidy, serialize, hydrate, makeEdge, newNode, insertOnEdge, appendAfter,
} from './graph';

/**
 * YSRTech EmailCampaigns - the automation canvas.
 *
 * Mounted by automation_canvas.phtml, which writes one global from
 * Block/Adminhtml/Automation/Canvas.php:
 *
 *   window.YsrFlowEditorConfig = {
 *     saveUrl, canvasUrl ('…/id/__ID__/'), formKey, flowId, name, storeId,
 *     status: 'draft' | 'active', version, publishedLabel,
 *     graph: {nodes: [{id, type, position, config}], edges: [{source, target}]},
 *     templates: [{id, name}], events: [{value, label}], orderStatuses: [{value, label}],
 *     storeOptions, counts: {<step position>: {sent, waiting, failed, cancelled}},
 *     limits: {maxSends, maxDelayMinutes}, i18n: {<English>: <translated>}
 *   };
 *
 * The page never calls back except to save. The graph it posts is read by
 * Model/Automation/Graph.php, which turns it into the ordered steps the
 * engine runs and is the authority on what may go live; the checks here are
 * the same rules run early, so a problem shows as it is drawn.
 *
 * The engine is a single chain - trigger, then waits and messages in order -
 * so the canvas keeps the drawing to one connection out of each step.
 */

const cfg = window.YsrFlowEditorConfig || {};
const LIMITS = cfg.limits || { maxSends: 20, maxDelayMinutes: 525600 };
const TEMPLATES = cfg.templates || [];
const EVENTS = cfg.events || [];
const ORDER_STATUSES = cfg.orderStatuses || [];
const STORE_OPTIONS = cfg.storeOptions || [];
const VALIDATION = {
  limits: LIMITS,
  events: EVENTS.map((e) => e.value),
  templateIds: TEMPLATES.map((tpl) => tpl.id),
};

function defaultGraph() {
  return {
    nodes: [{
      id: 'trigger-1',
      type: NODE_TRIGGER,
      position: { x: 250, y: 40 },
      config: { trigger_type: EVENTS.length ? EVENTS[0].value : '', order_status: '', product_id: 0 },
    }],
    edges: [],
  };
}

/**
 * The trigger's conditions as the server will store them: one that does not
 * apply to the chosen trigger is not kept. Done here as well so that what is
 * compared against the saved flow is what the saved flow will be.
 */
function canonical(graph) {
  return {
    ...graph,
    nodes: graph.nodes.map((n) => (n.type !== NODE_TRIGGER ? n : {
      ...n,
      config: {
        trigger_type: n.config.trigger_type || '',
        order_status: n.config.trigger_type === EVENT_ORDER_STATUS ? (n.config.order_status || '') : '',
        product_id: n.config.trigger_type === EVENT_PRODUCT ? (parseInt(n.config.product_id, 10) || 0) : 0,
      },
    })),
  };
}

function snapshotOf(nodes, edges, name, storeId) {
  return JSON.stringify({ graph: canonical(serialize(nodes, edges)), name, storeId });
}

/*
 * The local draft. Everything is wrapped because storage can be full, or
 * switched off, or forbidden in a private window, and none of that should
 * stop somebody drawing a flow - they only lose the safety net.
 */
const draftKey = (id) => `ysr-flow-draft:${id || 'new'}`;

const drafts = {
  read(id) {
    try {
      const draft = JSON.parse(window.localStorage.getItem(draftKey(id)) || 'null');
      return draft && typeof draft.snapshot === 'string' ? draft : null;
    } catch (e) {
      return null;
    }
  },
  write(id, snapshot) {
    try {
      window.localStorage.setItem(draftKey(id), JSON.stringify({ snapshot, at: Date.now() }));
    } catch (e) { /* storage unavailable */ }
  },
  clear(id) {
    try {
      window.localStorage.removeItem(draftKey(id));
    } catch (e) { /* storage unavailable */ }
  },
};

// getStoreValuesForForm()'s shape: an entry's `value` is either a store id
// (a plain <option>) or an array of {label, value} children (an <optgroup>).
function renderStoreOptions(options) {
  return options.map((opt, i) =>
    Array.isArray(opt.value) ? (
      <optgroup key={i} label={opt.label}>
        {opt.value.map((child, j) => (
          // The admin pads nested labels with non-breaking spaces for its own
          // selects; inside an optgroup that reads as a stray indent.
          <option key={j} value={child.value}>{String(child.label).replace(/^[\s ]+/, '')}</option>
        ))}
      </optgroup>
    ) : (
      <option key={i} value={opt.value}>{opt.label}</option>
    )
  );
}

function Palette() {
  return (
    <div className="ysr-flow-palette">
      <h4>{t('Add a step')}</h4>
      {PALETTE.map((item) => (
        <div
          key={item.type}
          className={'ysr-flow-palette__item ysr-flow-palette__item--' + item.type}
          draggable
          onDragStart={(e) => {
            e.dataTransfer.setData('application/ysr-node-type', item.type);
            e.dataTransfer.effectAllowed = 'move';
          }}
        >
          {t(item.label)}
        </div>
      ))}
      <p>{t('Drag onto the canvas, or use a + on the chain.')}</p>
    </div>
  );
}

function App() {
  const flow = useReactFlow();
  const server = useMemo(() => hydrate(cfg.graph || defaultGraph()), []);

  const [nodes, setNodes, onNodesChange] = useNodesState(server.nodes);
  const [edges, setEdges, onEdgesChange] = useEdgesState(server.edges);
  const [name, setName] = useState(cfg.name || '');
  const [storeId, setStoreId] = useState(cfg.storeId || 0);
  const [flowId, setFlowId] = useState(cfg.flowId || 0);

  // What the server says the flow is, and what the next save will ask for.
  const [status, setStatus] = useState(cfg.status === 'active' ? 'active' : 'draft');
  const [wanted, setWanted] = useState(cfg.status === 'active' ? 'active' : 'draft');
  const [version, setVersion] = useState(cfg.version || 1);
  const [publishedLabel, setPublishedLabel] = useState(cfg.publishedLabel || '');
  const [counts] = useState(cfg.counts || {});

  const [saved, setSaved] = useState(() => snapshotOf(server.nodes, server.edges, cfg.name || '', cfg.storeId || 0));
  const [serverProblems, setServerProblems] = useState([]);
  const [saving, setSaving] = useState(false);
  const [notice, setNotice] = useState(null);
  const [pendingDraft, setPendingDraft] = useState(null);

  /*
   * A live flow is looked at, not edited. People are partway through it, and
   * the server refuses a change to a live flow anyway; drawing it read-only
   * says so before anybody has spent ten minutes on changes that cannot be
   * saved.
   */
  const readOnly = status === 'active';

  const snapshot = useMemo(() => snapshotOf(nodes, edges, name, storeId), [nodes, edges, name, storeId]);
  const dirty = snapshot !== saved;

  // ---- local draft ------------------------------------------------------

  useEffect(() => {
    const draft = drafts.read(cfg.flowId);

    if (!draft) return;

    if (draft.snapshot === saved || cfg.status === 'active') {
      // Nothing in it the server does not already have, or nothing that
      // could be applied: a live flow has to be paused before it changes.
      drafts.clear(cfg.flowId);
    } else {
      setPendingDraft(draft);
    }
    // Once, on mount: this is about what was left behind by an earlier visit.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    if (readOnly || !dirty) return undefined;

    // An edit made while the offer to restore is still showing answers it:
    // the flow on screen is the one being worked on now.
    setPendingDraft(null);

    const timer = window.setTimeout(() => drafts.write(flowId, snapshot), 400);
    return () => window.clearTimeout(timer);
  }, [snapshot, dirty, readOnly, flowId]);

  useEffect(() => {
    if (!dirty) return undefined;

    const warn = (e) => {
      e.preventDefault();
      e.returnValue = '';
    };
    window.addEventListener('beforeunload', warn);
    return () => window.removeEventListener('beforeunload', warn);
  }, [dirty]);

  const applySnapshot = useCallback((text) => {
    const parsed = JSON.parse(text);
    const restored = hydrate(parsed.graph);
    setNodes(restored.nodes);
    setEdges(restored.edges);
    setName(parsed.name || '');
    setStoreId(parsed.storeId || 0);
    window.setTimeout(() => flow.fitView({ padding: 0.2, maxZoom: 1 }), 0);
  }, [flow, setNodes, setEdges]);

  // ---- what the nodes are told -------------------------------------------

  const chain = useMemo(() => walk(nodes, edges), [nodes, edges]);

  const problems = useMemo(() => {
    const found = validate(nodes, edges, VALIDATION);
    // The server's verdict on the saved flow stands until the flow changes;
    // anything it found that the rules here did not is added rather than lost.
    if (!dirty) {
      serverProblems.forEach((p) => {
        if (!found.some((q) => q.node === p.node && q.message === p.message)) {
          found.push({ node: p.node, message: p.message });
        }
      });
    }
    return found;
  }, [nodes, edges, serverProblems, dirty]);

  const problemsByNode = useMemo(() => {
    const map = {};
    problems.forEach((p) => {
      if (p.node) (map[p.node] = map[p.node] || []).push(p);
    });
    return map;
  }, [problems]);

  /*
   * Counts are kept by a message's position in the chain, which is the one
   * thing about a step that survives its row being rewritten by an edit. So
   * the first send on the canvas shows the first message's numbers.
   */
  const countsByNode = useMemo(() => {
    const map = {};
    let position = 0;
    chain.chain.forEach((id) => {
      if (chain.byId.get(id).type === NODE_SEND) {
        if (counts[position]) map[id] = counts[position];
        position++;
      }
    });
    return map;
  }, [chain, counts]);

  const tails = useMemo(() => {
    const sources = new Set(edges.map((e) => e.source));
    return new Set(nodes.filter((n) => !sources.has(n.id)).map((n) => n.id));
  }, [nodes, edges]);

  const selectedNodes = nodes.filter((n) => n.selected);
  const selectedNode = selectedNodes.length === 1 ? selectedNodes[0] : null;

  const select = useCallback((id) => {
    setNodes((nds) => nds.map((n) => (n.selected === (n.id === id) ? n : { ...n, selected: n.id === id })));
  }, [setNodes]);

  const pick = useCallback((id) => {
    select(id);
    const node = flow.getNode(id);
    if (node) {
      flow.setCenter(node.position.x + 90, node.position.y + 30, { zoom: 1, duration: 200 });
    }
  }, [flow, select]);

  // ---- editing -----------------------------------------------------------

  const onConnect = useCallback((params) => {
    // One way out of each step: a second connection replaces the first
    // rather than making a branch the engine could not follow.
    setEdges((eds) => eds.filter((e) => e.source !== params.source).concat(makeEdge(params.source, params.target)));
  }, [setEdges]);

  const isValidConnection = useCallback((connection) => {
    const target = flow.getNode(connection.target);
    return connection.source !== connection.target && !!target && target.type !== NODE_TRIGGER;
  }, [flow]);

  const place = useCallback((result) => {
    if (!result) return;
    setNodes(result.nodes.map((n) => ({ ...n, selected: n.id === result.node.id })));
    setEdges(result.edges);
  }, [setNodes, setEdges]);

  const onInsert = useCallback((edgeId, type) => {
    place(insertOnEdge(flow.getNodes(), flow.getEdges(), edgeId, type));
  }, [flow, place]);

  const onAppend = useCallback((nodeId, type) => {
    place(appendAfter(flow.getNodes(), flow.getEdges(), nodeId, type));
  }, [flow, place]);

  /*
   * Removing a step from the middle of a chain joins its neighbours up.
   * Leaving the gap would turn everything after it into unconnected steps,
   * and nobody deleting one wait means to detach the rest of the flow.
   */
  const edgesRef = useRef(edges);
  edgesRef.current = edges;

  const onNodesDelete = useCallback((deleted) => {
    /*
     * Worked out from the connections as last drawn, not from the state the
     * updater below is handed: React Flow removes a deleted step's own
     * connections in the same breath, and by the time an updater runs they
     * may already be gone, with nothing left to say what the step sat between.
     */
    const before = edgesRef.current;
    let after = before;

    deleted.forEach((node) => {
      const incoming = after.filter((e) => e.target === node.id);
      const outgoing = after.filter((e) => e.source === node.id);

      after = after.filter((e) => e.source !== node.id && e.target !== node.id);

      if (incoming.length === 1 && outgoing.length === 1 && incoming[0].source !== outgoing[0].target) {
        after = after.concat(makeEdge(incoming[0].source, outgoing[0].target));
      }
    });

    const gone = new Set(deleted.map((n) => n.id));
    const joined = after.filter((e) => !before.includes(e));

    setEdges((eds) => eds
      .filter((e) => !gone.has(e.source) && !gone.has(e.target))
      .concat(joined.filter((j) => !eds.some((e) => e.id === j.id))));
  }, [setEdges]);

  const onDrop = useCallback((e) => {
    e.preventDefault();
    const type = e.dataTransfer.getData('application/ysr-node-type');
    if (!type || readOnly) return;
    const node = newNode(type, flow.screenToFlowPosition({ x: e.clientX, y: e.clientY }));
    setNodes((nds) => nds.map((n) => (n.selected ? { ...n, selected: false } : n)).concat({ ...node, selected: true }));
  }, [flow, readOnly, setNodes]);

  const tidyUp = useCallback(() => {
    setNodes((nds) => tidy(nds, flow.getEdges()));
    window.setTimeout(() => flow.fitView({ padding: 0.2, maxZoom: 1, duration: 200 }), 0);
  }, [flow, setNodes]);

  // ---- saving ------------------------------------------------------------

  async function save(target) {
    const sent = snapshot;

    setSaving(true);
    setNotice(null);

    try {
      const body = new FormData();
      body.append('form_key', cfg.formKey);
      body.append('id', flowId || '');
      body.append('name', name);
      body.append('status', target);
      body.append('store_id', storeId);
      body.append('graph_json', JSON.stringify(canonical(serialize(nodes, edges))));

      const res = await fetch(cfg.saveUrl, { method: 'POST', body, credentials: 'same-origin' });
      let json;

      try {
        json = await res.json();
      } catch (e) {
        // A login page or an error page, not an answer.
        throw new Error(t('The server did not answer as expected. Your session may have ended; reload the page.'));
      }

      if (!json.success) {
        setServerProblems([]);
        setNotice({ kind: 'error', text: json.message || `HTTP ${res.status}` });
        return;
      }

      drafts.clear(flowId);
      drafts.clear(json.id);
      setPendingDraft(null);

      if (!flowId && cfg.canvasUrl) {
        // This page never navigates, so the address is corrected by hand: a
        // reload of a new flow would otherwise open another empty one.
        window.history.replaceState(null, '', cfg.canvasUrl.replace('__ID__', json.id));
      }

      cfg.flowId = json.id;
      setFlowId(json.id);
      setSaved(sent);
      setStatus(json.status);
      setWanted(json.status);
      setVersion(json.version || 1);
      setPublishedLabel(json.published_label || '');
      setServerProblems(json.problems || []);
      setNotice({ kind: json.demoted ? 'warning' : 'success', text: json.message });
    } catch (e) {
      setNotice({ kind: 'error', text: t('Could not save: %s', e.message) });
    } finally {
      setSaving(false);
    }
  }

  const context = useMemo(() => ({
    readOnly,
    problemsByNode,
    countsByNode,
    tails,
    templates: TEMPLATES,
    events: EVENTS,
    orderStatuses: ORDER_STATUSES,
    onInsert,
    onAppend,
  }), [readOnly, problemsByNode, countsByNode, tails, onInsert, onAppend]);

  return (
    <div className={'ysr-flow-app' + (readOnly ? ' is-live' : '')}>
      <div className="ysr-flow-toolbar">
        <label className="ysr-flow-toolbar__field">
          <span>{t('Name')}</span>
          <input
            type="text"
            className="ysr-flow-toolbar__name"
            value={name}
            maxLength={255}
            disabled={readOnly}
            placeholder={t('Flow name')}
            onChange={(e) => setName(e.target.value)}
          />
        </label>
        <label className="ysr-flow-toolbar__field">
          <span>{t('Store view')}</span>
          <select value={storeId} disabled={readOnly} onChange={(e) => setStoreId(parseInt(e.target.value, 10) || 0)}>
            {renderStoreOptions(STORE_OPTIONS)}
          </select>
        </label>

        <span className="ysr-flow-toolbar__spacer" />

        <span className={'ysr-flow-state ysr-flow-state--' + status}>
          {status === 'active' ? t('Live · version %d', version) : t('Draft')}
        </span>

        <button
          type="button"
          className={'ysr-flow-toolbar__problems' + (problems.length ? ' has-problems' : '')}
          title={t('Show what needs fixing')}
          onClick={() => select(null)}
        >
          {problems.length === 1 ? t('1 problem') : t('%d problems', problems.length)}
        </button>

        {!readOnly && (
          <>
            <button type="button" className="ysr-flow-toolbar__plain" data-action="tidy" onClick={tidyUp}>{t('Tidy up')}</button>
            <label className="ysr-flow-toolbar__field">
              <span>{t('Status')}</span>
              <select data-field="status" value={wanted} onChange={(e) => setWanted(e.target.value)}>
                <option value="draft">{t('Draft')}</option>
                <option value="active">{t('Active')}</option>
              </select>
            </label>
            <button type="button" className="ysr-flow-toolbar__save" data-action="save" disabled={saving} onClick={() => save(wanted)}>
              {saving ? t('Saving…') : wanted === 'active' ? t('Save and Activate') : t('Save Draft')}
            </button>
          </>
        )}
      </div>

      {readOnly && (
        <div className="ysr-flow-bar ysr-flow-bar--live">
          <span>
            <strong>{t('This flow is live. Pause it to make changes.')}</strong>{' '}
            {publishedLabel ? t('Version %d, switched on %s.', version, publishedLabel) : ''}{' '}
            {t('Messages already queued are still sent, as they were when queued.')}
          </span>
          <button type="button" data-action="pause" disabled={saving} onClick={() => save('draft')}>
            {saving ? t('Pausing…') : t('Pause')}
          </button>
        </div>
      )}

      {pendingDraft && !readOnly && (
        <div className="ysr-flow-bar ysr-flow-bar--draft">
          <span>
            {t('This browser has changes to this flow from %s that were never saved.', new Date(pendingDraft.at).toLocaleString())}
          </span>
          <button
            type="button"
            data-action="restore"
            onClick={() => {
              applySnapshot(pendingDraft.snapshot);
              setPendingDraft(null);
            }}
          >
            {t('Restore')}
          </button>
          <button
            type="button"
            className="ysr-flow-toolbar__plain"
            data-action="discard"
            onClick={() => {
              drafts.clear(flowId);
              setPendingDraft(null);
            }}
          >
            {t('Discard')}
          </button>
        </div>
      )}

      {notice && (
        <div className={'ysr-flow-bar ysr-flow-bar--' + notice.kind} role="status">
          <span>{notice.text}</span>
          <button type="button" className="ysr-flow-toolbar__plain" onClick={() => setNotice(null)}>{t('Dismiss')}</button>
        </div>
      )}

      <div className="ysr-flow-body">
        {!readOnly && <Palette />}
        <div
          className="ysr-flow-canvas"
          onDragOver={(e) => {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
          }}
          onDrop={onDrop}
        >
          <FlowContext.Provider value={context}>
            <ReactFlow
              nodes={nodes}
              edges={edges}
              nodeTypes={NODE_TYPES}
              edgeTypes={EDGE_TYPES}
              onNodesChange={onNodesChange}
              onEdgesChange={onEdgesChange}
              onConnect={onConnect}
              onNodesDelete={onNodesDelete}
              isValidConnection={isValidConnection}
              nodesDraggable={!readOnly}
              nodesConnectable={!readOnly}
              edgesFocusable={!readOnly}
              deleteKeyCode={readOnly ? null : ['Backspace', 'Delete']}
              multiSelectionKeyCode={null}
              selectionKeyCode={null}
              fitView
              fitViewOptions={{ padding: 0.2, maxZoom: 1 }}
              minZoom={0.2}
              maxZoom={1.5}
            >
              <Background color="#d5d5d5" gap={20} />
              <Controls showInteractive={false} />
              <MiniMap
                pannable
                zoomable
                nodeStrokeWidth={2}
                nodeColor={(n) => (problemsByNode[n.id] ? '#f4c7c3' : n.type === NODE_TRIGGER ? '#c9d6db' : '#e4e9ec')}
                nodeStrokeColor={(n) => (problemsByNode[n.id] ? '#c0392b' : '#6f8992')}
                maskColor="rgba(111, 137, 146, 0.12)"
              />
            </ReactFlow>
          </FlowContext.Provider>
        </div>
        <NodePanel
          node={selectedNode}
          problems={selectedNode ? problemsByNode[selectedNode.id] || [] : []}
          allProblems={problems}
          templates={TEMPLATES}
          events={EVENTS}
          orderStatuses={ORDER_STATUSES}
          counts={selectedNode ? countsByNode[selectedNode.id] : null}
          readOnly={readOnly}
          onChange={(data) => setNodes((nds) => nds.map((n) => (n.id === selectedNode.id ? { ...n, data } : n)))}
          onDelete={() => flow.deleteElements({ nodes: [{ id: selectedNode.id }] })}
          onPick={pick}
        />
      </div>

      <div className="ysr-flow-foot">
        {dirty ? t('Unsaved changes - kept in this browser until you save.') : flowId ? t('All changes saved.') : t('Not saved yet.')}
      </div>
    </div>
  );
}

createRoot(document.getElementById('ysr-flow-editor-root')).render(
  <ReactFlowProvider>
    <App />
  </ReactFlowProvider>
);
