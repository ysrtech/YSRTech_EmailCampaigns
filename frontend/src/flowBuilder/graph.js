import { t } from './i18n';

/**
 * The drawing as data: reading its chain, checking it, laying it out.
 *
 * Nothing in here touches React, so the rules can be read in one place and
 * compared against Model/Automation/Graph.php, which is the authority. This
 * file mirrors that one so problems show as they are made rather than on
 * save; the server checks again regardless, because a browser can post
 * anything.
 */

export const NODE_TRIGGER = 'trigger';
export const NODE_DELAY = 'delay';
export const NODE_SEND = 'action_send_email';

export const EVENT_ORDER_STATUS = 'order_status';
export const EVENT_PRODUCT = 'product_purchased';

const COLUMN_X = 250;
const FIRST_Y = 40;
export const ROW_HEIGHT = 130;
const PARK_X = 560;

const UNIT_MINUTES = { minutes: 1, hours: 60, days: 1440 };

export function delayMinutes(data) {
  const value = Math.max(0, parseInt(data.value, 10) || 0);
  return value * (UNIT_MINUTES[data.unit] || 1440);
}

/**
 * The chain as the engine will read it: from the first trigger, one edge at
 * a time, stopping at a loop.
 */
export function walk(nodes, edges) {
  const byId = new Map(nodes.map((n) => [n.id, n]));
  const triggers = nodes.filter((n) => n.type === NODE_TRIGGER).map((n) => n.id);
  const next = new Map();
  const branches = new Set();
  const dangling = [];

  edges.forEach((e) => {
    if (!byId.has(e.source) || !byId.has(e.target)) {
      dangling.push(e);
    } else if (next.has(e.source)) {
      if (next.get(e.source) !== e.target) branches.add(e.source);
    } else {
      next.set(e.source, e.target);
    }
  });

  const chain = [];
  let cycle = null;

  if (triggers.length) {
    const seen = new Set([triggers[0]]);
    let at = triggers[0];
    let current = next.get(at);

    while (current !== undefined) {
      if (seen.has(current) || byId.get(current).type === NODE_TRIGGER) {
        cycle = at;
        break;
      }
      seen.add(current);
      chain.push(current);
      at = current;
      current = next.get(current);
    }
  }

  return { byId, triggers, next, chain, cycle, branches: [...branches], dangling };
}

/**
 * The same rules, in the same order and the same words, as
 * YSRTech_EmailCampaigns_Model_Automation_Graph::validate().
 *
 * @returns {{node: string|null, message: string}[]}
 */
export function validate(nodes, edges, context) {
  const problems = [];
  const add = (node, message) => problems.push({ node, message });
  const w = walk(nodes, edges);
  const limits = context.limits;

  w.dangling.forEach((e) => {
    add(w.byId.has(e.source) ? e.source : w.byId.has(e.target) ? e.target : null,
      t('A connection leads to a step that is not in the flow.'));
  });

  if (!w.triggers.length) {
    add(null, t('The flow has no trigger, so nothing would ever start it.'));
  }

  w.triggers.slice(1).forEach((id) => add(id, t('A flow starts from one trigger. Remove this one.')));

  if (w.triggers.length) {
    const id = w.triggers[0];
    const data = w.byId.get(id).data;

    if (!data.trigger_type || !context.events.includes(data.trigger_type)) {
      add(id, t('Choose what starts this flow.'));
    } else if (data.trigger_type === EVENT_ORDER_STATUS && !data.order_status) {
      add(id, t('Choose which order status starts this flow.'));
    } else if (data.trigger_type === EVENT_PRODUCT && !(parseInt(data.product_id, 10) > 0)) {
      add(id, t('Give the id of the product this flow watches for.'));
    }
  }

  w.branches.forEach((id) => add(id, t('Only one connection can leave a step. Flows here do not branch.')));

  if (w.cycle !== null) {
    add(w.cycle, t('The flow loops back on itself here, so it would never end.'));
  }

  const onChain = new Set(w.chain);
  let sends = 0;
  let elapsed = 0;
  let tooLong = false;

  w.chain.forEach((id, position) => {
    const node = w.byId.get(id);

    if (node.type === NODE_SEND && ++sends === limits.maxSends + 1) {
      add(id, t('A flow can send %d messages at most.', limits.maxSends));
    }

    if (node.type === NODE_DELAY) {
      elapsed += delayMinutes(node.data);

      if (elapsed > limits.maxDelayMinutes && !tooLong) {
        tooLong = true;
        add(id, t('The waits add up to more than %d days.', Math.floor(limits.maxDelayMinutes / 1440)));
      }

      if (position === w.chain.length - 1 && w.cycle === null) {
        add(id, t('Nothing follows this wait. Add a message after it or remove it.'));
      }
    }
  });

  nodes.forEach((node) => {
    if (node.type !== NODE_TRIGGER && !onChain.has(node.id)) {
      add(node.id, t('This step is not connected to the chain from the trigger, so it would never run.'));
    }

    if (node.type === NODE_DELAY && !((parseInt(node.data.value, 10) || 0) >= 1)) {
      add(node.id, t('A wait has to be at least 1.'));
    }

    if (node.type === NODE_SEND) {
      const templateId = parseInt(node.data.template_id, 10) || 0;

      if (templateId < 1) {
        add(node.id, t('Choose the template this step sends.'));
      } else if (!context.templateIds.includes(templateId)) {
        add(node.id, t('The template this step used no longer exists. Choose another.'));
      }
    }
  });

  if (w.triggers.length && sends === 0) {
    add(null, t('The flow sends nothing yet. Add a Send Email step after the trigger.'));
  }

  return problems;
}

/**
 * The chain in one column, top to bottom in the order it runs, with whatever
 * is not part of it parked in a second column where it cannot be mistaken
 * for a step that will happen.
 */
export function tidy(nodes, edges) {
  const w = walk(nodes, edges);
  const order = [...w.triggers.slice(0, 1), ...w.chain];
  const placed = new Map(order.map((id, i) => [id, { x: COLUMN_X, y: FIRST_Y + i * ROW_HEIGHT }]));
  let parked = 0;

  return nodes.map((node) => ({
    ...node,
    position: placed.get(node.id) || { x: PARK_X, y: FIRST_Y + parked++ * ROW_HEIGHT },
  }));
}

/** What is posted and what is compared: config and whole-pixel positions. */
export function serialize(nodes, edges) {
  return {
    nodes: nodes.map((n) => ({
      id: n.id,
      type: n.type,
      position: { x: Math.round(n.position.x), y: Math.round(n.position.y) },
      config: { ...n.data },
    })),
    edges: edges.map((e) => ({ source: e.source, target: e.target })),
  };
}

export function hydrate(graph) {
  return {
    nodes: (graph.nodes || []).map((n) => ({
      id: String(n.id),
      type: n.type,
      position: n.position || { x: COLUMN_X, y: FIRST_Y },
      data: { ...(n.config || {}) },
      // A flow with no trigger starts from nothing, so the one it has stays.
      deletable: n.type !== NODE_TRIGGER,
    })),
    edges: (graph.edges || []).map((e) => makeEdge(String(e.source), String(e.target))),
  };
}

export function makeEdge(source, target) {
  return { id: `e-${source}-${target}`, source, target, type: 'insert' };
}

let sequence = 1;

export function newNode(type, position) {
  const data = type === NODE_DELAY ? { value: 1, unit: 'days' } : { template_id: 0 };
  // Letters, digits, dash: the server refuses an id made of anything else.
  const id = `${type === NODE_DELAY ? 'delay' : 'send'}-${Date.now().toString(36)}-${sequence++}`;

  return { id, type, position, data, deletable: true };
}

/**
 * A new step on a connection, between its two ends. Everything from the far
 * end onwards moves down to make room, so the chain keeps its shape and the
 * new step does not land on top of the next one.
 */
export function insertOnEdge(nodes, edges, edgeId, type) {
  const edge = edges.find((e) => e.id === edgeId);
  const source = edge && nodes.find((n) => n.id === edge.source);
  const target = edge && nodes.find((n) => n.id === edge.target);

  if (!source || !target) return null;

  const node = newNode(type, { x: source.position.x, y: source.position.y + ROW_HEIGHT });
  const shortfall = node.position.y + ROW_HEIGHT - target.position.y;
  const downstream = new Set();

  if (shortfall > 0) {
    const next = new Map(edges.map((e) => [e.source, e.target]));
    for (let at = target.id; at !== undefined && !downstream.has(at); at = next.get(at)) {
      downstream.add(at);
    }
  }

  return {
    node,
    nodes: nodes
      .map((n) => (downstream.has(n.id) ? { ...n, position: { x: n.position.x, y: n.position.y + shortfall } } : n))
      .concat(node),
    edges: edges
      .filter((e) => e.id !== edgeId)
      .concat(makeEdge(source.id, node.id), makeEdge(node.id, target.id)),
  };
}

/** A new step after one that has nothing following it. */
export function appendAfter(nodes, edges, nodeId, type) {
  const tail = nodes.find((n) => n.id === nodeId);

  if (!tail || edges.some((e) => e.source === nodeId)) return null;

  const node = newNode(type, { x: tail.position.x, y: tail.position.y + ROW_HEIGHT });

  return { node, nodes: nodes.concat(node), edges: edges.concat(makeEdge(tail.id, node.id)) };
}
