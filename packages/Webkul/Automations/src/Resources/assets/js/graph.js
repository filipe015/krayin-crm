/**
 * Conversões entre o formato salvo no servidor (FlowGraph.php) e o do Vue Flow, e a validação
 * "ao vivo" na tela. O servidor valida tudo de novo (FlowValidator.php): aqui é só para mostrar os
 * problemas nos blocos enquanto a pessoa monta o fluxo.
 */

export const TRIGGERS = ["stage_entered", "lead_created", "tag_added", "activity_created"];

export const CONDITIONS = ["pipeline", "stage", "source", "tag", "owner", "lead_value", "field"];

export const ACTIONS = ["move_stage", "transfer_pipeline", "assign_owner", "add_tag", "create_task", "notify_whatsapp", "webhook", "mark_lost"];

export function newId(prefix = "n") {
    return prefix + Date.now().toString(36).slice(-5) + Math.random().toString(36).slice(2, 7);
}

export function triggerConfig(type) {
    return {
        stage_entered: { trigger_type: type, pipeline_id: null, stage_id: null, from_stage_id: null },
        lead_created: { trigger_type: type, source_id: null, type_id: null },
        tag_added: { trigger_type: type, tag_id: null },
        activity_created: { trigger_type: type, activity_type: null },
    }[type] || { trigger_type: type };
}

export function conditionRule(type) {
    return {
        pipeline: { type, pipeline_id: null },
        stage: { type, stage_id: null },
        source: { type, source_id: null },
        tag: { type, tag_id: null },
        owner: { type, user_id: null },
        lead_value: { type, operator: "gte", value: null },
        field: { type, attribute_code: "", value: "", operator: "equals" },
    }[type] || { type };
}

export function actionConfig(type) {
    return {
        move_stage: { type, stage_id: null },
        transfer_pipeline: { type, pipeline_id: null, stage_id: null },
        assign_owner: { type, user_id: null },
        add_tag: { type, tag_id: null },
        create_task: { type, title: "", due_in_days: 1, assignee: "owner", user_id: null },
        notify_whatsapp: { type, to: "", message: "" },
        webhook: { type, url: "", secret: "" },
        mark_lost: { type, reason_id: null },
    }[type] || { type };
}

export function edgeFor(source, target, branch, labels) {
    const colors = { yes: "#16a34a", no: "#dc2626" };

    return {
        id: newId("e"),
        source,
        target,
        sourceHandle: branch || null,
        type: "smoothstep",
        animated: true,
        markerEnd: "arrowclosed",
        label: branch ? labels.flow.ui[branch] : undefined,
        labelBgStyle: branch ? { fill: colors[branch], fillOpacity: 0.12 } : undefined,
        labelStyle: branch ? { fill: colors[branch], fontWeight: 600 } : undefined,
        style: branch ? { stroke: colors[branch], strokeWidth: 2 } : { strokeWidth: 2 },
        data: { branch: branch || null },
    };
}

/** Server flow → Vue Flow nodes and edges. */
export function toVueFlow(flow, labels) {
    const nodes = (flow?.nodes || []).map((node) => ({
        id: String(node.id),
        type: "automation",
        position: { x: Number(node.position?.x) || 0, y: Number(node.position?.y) || 0 },
        data: {
            kind: node.type,
            config: JSON.parse(JSON.stringify(node.config || {})),
            label: node.label || "",
            end: Array.isArray(node.end) ? [...node.end] : [],
        },
    }));

    const edges = (flow?.edges || []).map((edge) => ({ ...edgeFor(String(edge.source), String(edge.target), edge.branch || null, labels), id: String(edge.id) }));

    return { nodes, edges };
}

/** Vue Flow → the payload the server expects. */
export function toServer(nodes, edges) {
    return {
        version: 1,
        nodes: nodes.map((node) => {
            const out = {
                id: node.id,
                type: node.data.kind,
                config: JSON.parse(JSON.stringify(node.data.config || {})),
                position: { x: Math.round(node.position.x), y: Math.round(node.position.y) },
            };

            if (node.data.label) out.label = node.data.label;
            if (node.data.kind === "condition") out.end = [...(node.data.end || [])];

            return out;
        }),
        edges: edges.map((edge) => ({ id: edge.id, source: edge.source, target: edge.target, branch: edge.data?.branch ?? edge.sourceHandle ?? null })),
    };
}

const filled = (value) => value !== null && value !== undefined && String(value).trim() !== "";

function configProblems(node, labels) {
    const v = labels.validation;
    const c = node.data.config || {};
    const problems = [];

    if (node.data.kind === "trigger") {
        if (c.trigger_type === "stage_entered" && (! filled(c.pipeline_id) || ! filled(c.stage_id))) problems.push(v.stage);
        if (c.trigger_type === "tag_added" && ! filled(c.tag_id)) problems.push(v.tag);
    }

    if (node.data.kind === "condition") {
        if (! (c.rules || []).length) problems.push(labels.flow.errors.no_rules);

        (c.rules || []).forEach((rule) => {
            const need = { pipeline: ["pipeline_id", v.pipeline], stage: ["stage_id", v.stage], source: ["source_id", v.source], tag: ["tag_id", v.tag], owner: ["user_id", v.user] }[rule.type];

            if (need && ! filled(rule[need[0]])) problems.push(need[1]);
            if (rule.type === "field" && ! filled(rule.attribute_code)) problems.push(v.field);
            if (rule.type === "field" && rule.operator === "contains" && ! filled(rule.value)) problems.push(v.value);
            if (rule.type === "lead_value" && (! filled(rule.value) || isNaN(Number(rule.value)))) problems.push(v.value);
        });
    }

    if (node.data.kind === "action") {
        const need = {
            move_stage: [["stage_id", v.move_stage]],
            transfer_pipeline: [["pipeline_id", v.pipeline], ["stage_id", v.stage]],
            assign_owner: [["user_id", v.user]],
            add_tag: [["tag_id", v.tag]],
            create_task: [["title", v.task_title], ["due_in_days", v.days]],
            notify_whatsapp: [["to", v.whatsapp_to], ["message", v.message]],
            webhook: [["url", v.url]],
        }[c.type] || [];

        need.forEach(([key, message]) => { if (! filled(c[key])) problems.push(message); });

        if (c.type === "create_task" && c.assignee === "fixed" && ! filled(c.user_id)) problems.push(v.user);
        if (c.type === "webhook" && filled(c.url) && ! /^https?:\/\/[^\s/]+/i.test(c.url)) problems.push(v.url);
    }

    return [...new Set(problems)];
}

/**
 * The same soft checks as the server: { general: [..], byNode: { id: [..] } }.
 */
export function validate(nodes, edges, labels, maxNodes) {
    const e = labels.flow.errors;
    const general = [];
    const byNode = {};
    const add = (id, message) => { (byNode[id] ||= []).includes(message) || byNode[id].push(message); };

    const triggers = nodes.filter((node) => node.data.kind === "trigger");

    if (triggers.length !== 1) general.push(e.one_trigger);
    if (! nodes.some((node) => node.data.kind === "action")) general.push(e.no_action);
    if (nodes.length > maxNodes) general.push(e.too_many_nodes.replace(":max", maxNodes));

    const out = {};
    const exits = {};

    edges.forEach((edge) => {
        (out[edge.source] ||= []).push(edge.target);

        const exit = edge.source + "|" + (edge.data?.branch || "");

        if (exits[exit]) add(edge.source, e.one_exit);
        exits[exit] = true;

        if (nodes.find((node) => node.id === edge.target)?.data.kind === "trigger") add(edge.target, e.trigger_incoming);
    });

    nodes.forEach((node) => {
        configProblems(node, labels).forEach((message) => add(node.id, message));

        if (node.data.kind === "condition") {
            ["yes", "no"].forEach((branch) => {
                const linked = edges.some((edge) => edge.source === node.id && (edge.data?.branch || null) === branch);

                if (! linked && ! (node.data.end || []).includes(branch)) add(node.id, e["branch_" + branch]);
            });
        }
    });

    if (triggers.length) {
        const reached = new Set();
        const stack = [triggers[0].id];

        while (stack.length) {
            const id = stack.pop();

            if (reached.has(id)) continue;
            reached.add(id);
            (out[id] || []).forEach((target) => stack.push(target));
        }

        nodes.forEach((node) => { if (! reached.has(node.id)) add(node.id, e.unreachable); });
    }

    const color = {};
    let cycle = false;
    const visit = (id) => {
        color[id] = 1;
        (out[id] || []).forEach((target) => {
            if (color[target] === 1) cycle = true;
            else if (! color[target]) visit(target);
        });
        color[id] = 2;
    };

    nodes.forEach((node) => { if (! color[node.id]) visit(node.id); });

    if (cycle) general.push(e.cycle);

    return { general, byNode };
}
