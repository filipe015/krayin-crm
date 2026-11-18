<template>
    <div class="afl">
        <!-- Topo: nome, status, botões -->
        <div class="afl-top">
            <label class="afl-field afl-name">{{ old.name }}
                <input type="text" v-model="name" maxlength="120" :disabled="readOnly">
            </label>
            <label class="afl-check">
                <input type="checkbox" v-model="runOnce" :disabled="readOnly"> {{ old.run_once }}
            </label>
            <span class="afl-pill" :class="status">{{ labels.statuses[status] }}</span>
            <span class="afl-muted">{{ ui.nodes_count.replace(':count', nodes.length).replace(':max', maxNodes) }}</span>
            <span class="afl-pill waiting" v-if="pendingWaits">{{ ui.pending_waits_count.replace(':count', pendingWaits) }}</span>

            <div class="afl-top-actions">
                <button type="button" class="afl-btn" @click="close()">{{ ui.back }}</button>
                <template v-if="! readOnly">
                    <button type="button" class="afl-btn" :disabled="busy" @click="save('off')">{{ ui.save_draft }}</button>
                    <button type="button" class="afl-btn is-test" :disabled="busy" :title="ui.test_hint" @click="save('test')">{{ ui.test }}</button>
                    <button type="button" class="afl-btn is-primary" :disabled="busy" @click="save('on')">{{ ui.activate }}</button>
                </template>
            </div>
        </div>

        <div class="afl-legacy" v-if="legacy">{{ ui.legacy }}</div>
        <div class="afl-ok" v-if="message">{{ message }}</div>
        <div class="afl-alert" v-if="problem">{{ problem }}</div>
        <div class="afl-warn" v-if="transfersWon">{{ old.won_warning }}</div>

        <div class="afl-problems" v-if="showProblems && allGeneral.length">
            <strong>{{ ui.problems }}</strong>
            <div v-for="(item, i) in allGeneral" :key="i">{{ item }}</div>
        </div>

        <div class="afl-main">
            <!-- Paleta -->
            <aside class="afl-palette" v-if="! readOnly">
                <h3>{{ ui.palette }}</h3>
                <p class="afl-muted">{{ ui.palette_hint }}</p>

                <div class="afl-group" v-for="group in palette" :key="group.kind">
                    <div class="afl-group-title" :class="'is-' + group.kind">{{ labels.flow.blocks[group.kind] }}</div>
                    <button
                        v-for="item in group.items"
                        :key="item.key"
                        type="button"
                        class="afl-palette-item"
                        :class="'is-' + group.kind"
                        draggable="true"
                        @dragstart="onDragStart($event, group.kind, item.value)"
                        @click="addAtCenter(group.kind, item.value)"
                    >{{ item.label }}</button>
                </div>
            </aside>

            <!-- Tela -->
            <div class="afl-canvas" ref="canvas" @dragover.prevent="onDragOver" @drop.prevent="onDrop">
                <VueFlow
                    :id="flowId"
                    v-model:nodes="nodes"
                    v-model:edges="edges"
                    :node-types="nodeTypes"
                    :default-edge-options="{ type: 'smoothstep', animated: true }"
                    :delete-key-code="readOnly ? null : ['Delete', 'Backspace']"
                    :nodes-draggable="! readOnly"
                    :nodes-connectable="! readOnly"
                    :is-valid-connection="isValidConnection"
                    :min-zoom="0.2"
                    :max-zoom="2"
                    :snap-to-grid="true"
                    :snap-grid="[10, 10]"
                    @nodes-initialized="fit()"
                    @connect="onConnect"
                    @node-click="select($event.node.id, 'node')"
                    @edge-click="select($event.edge.id, 'edge')"
                    @pane-click="select(null)"
                >
                    <Background :gap="20" />
                    <Controls :show-interactive="false" />
                    <MiniMap v-if="minimap" pannable zoomable :node-color="minimapColor" />
                </VueFlow>

                <div class="afl-canvas-tools">
                    <button type="button" class="afl-btn is-small" v-if="! readOnly" :disabled="history.length < 2" @click="undo()">{{ ui.undo }}</button>
                    <button type="button" class="afl-btn is-small" @click="fit()">{{ ui.fit }}</button>
                    <label class="afl-check is-small"><input type="checkbox" v-model="minimap"> {{ ui.minimap }}</label>
                </div>
                <p class="afl-canvas-hint afl-muted">{{ ui.canvas_hint }}</p>
            </div>

            <!-- Painel -->
            <aside class="afl-panel">
                <h3>{{ ui.panel }}</h3>

                <NodePanel v-if="selectedNode" :key="selectedNode.id" :node="selectedNode" :read-only="readOnly" />

                <div v-else-if="selectedEdge" class="afl-panel-body">
                    <div class="afl-panel-kind">{{ ui.link }}</div>
                    <p class="afl-muted">{{ edgeText(selectedEdge) }}</p>
                    <button type="button" class="afl-btn is-danger" v-if="! readOnly" @click="removeEdge(selectedEdge.id)">{{ ui.delete_link }}</button>
                </div>

                <p v-else class="afl-muted">{{ ui.no_selection }}</p>

                <!-- Simular -->
                <div class="afl-simulate" v-if="canSimulate">
                    <h3>{{ old.simulate }}</h3>
                    <p class="afl-muted">{{ old.simulate_hint }}</p>
                    <p class="afl-muted" v-if="timeTriggered">{{ ui.simulate_time_hint }}</p>
                    <p class="afl-muted" v-if="! ruleId">{{ ui.simulate_save_first }}</p>
                    <div class="afl-inline" v-else>
                        <input type="number" min="1" v-model.number="simulation.leadId" :placeholder="old.simulate_lead">
                        <button type="button" class="afl-btn" :disabled="simulation.busy || ! simulation.leadId" @click="simulate()">{{ old.simulate_run }}</button>
                    </div>
                    <div class="afl-alert" v-if="simulation.problem">{{ simulation.problem }}</div>
                    <div v-if="simulation.result">
                        <span class="afl-pill" :class="simulation.result.status">{{ labels.run_statuses[simulation.result.status] }}</span>
                        <strong class="afl-path-title">{{ ui.path }}</strong>
                        <ol class="afl-steps">
                            <li v-for="(step, i) in simulation.result.steps" :key="i" :class="step.status">{{ stepLabel(step) }}: {{ step.detail }}</li>
                        </ol>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</template>

<script setup>
import { computed, markRaw, nextTick, provide, ref, watch } from "vue";
import { VueFlow, useVueFlow } from "@vue-flow/core";
import { Background } from "@vue-flow/background";
import { Controls } from "@vue-flow/controls";
import { MiniMap } from "@vue-flow/minimap";
import FlowNode from "./FlowNode.vue";
import NodePanel from "./NodePanel.vue";
import { ACTIONS, CONDITIONS, TIME_TRIGGERS, TRIGGERS, actionConfig, conditionRule, edgeFor, newId, semanticKey, toServer, toVueFlow, triggerConfig, validate, waitConfig } from "../graph.js";

const props = defineProps({
    rule: { type: Object, required: true },
    options: { type: Object, required: true },
    labels: { type: Object, required: true },
    urls: { type: Object, required: true },
    can: { type: Object, required: true },
    whatsapp: { type: Boolean, default: false },
    maxNodes: { type: Number, default: 30 },
    maxWaitDays: { type: Number, default: 30 },
    http: { type: [Object, Function], required: true },
    highlight: { type: Array, default: () => [] },
    onSaved: { type: Function, default: () => {} },
    onClose: { type: Function, default: () => {} },
});

const labels = props.labels;
const ui = labels.flow.ui;
const old = labels.ui;
const flowId = "automation-flow-" + newId("");

const ruleId = ref(props.rule.id || null);
const name = ref(props.rule.name || "");
const status = ref(props.rule.status || "off");
const runOnce = ref(props.rule.run_once_per_lead !== false);
const legacy = ref(props.rule.id && ! props.rule.is_flow);

const initial = toVueFlow(props.rule.flow || { nodes: [], edges: [] }, labels);
const nodes = ref(initial.nodes);
const edges = ref(initial.edges);
const selected = ref(null);
const busy = ref(false);
const message = ref("");
const problem = ref("");
const serverErrors = ref({ general: [], byNode: {} });
const showProblems = ref(false);
const minimap = ref(window.innerWidth >= 1440);
const path = ref([...props.highlight]);
const simulation = ref({ leadId: null, busy: false, result: null, problem: "" });
const canvas = ref(null);
const pendingWaits = ref(Number(props.rule.pending_waits) || 0);
let savedKey = semanticKey(nodes.value, edges.value);

const readOnly = computed(() => (ruleId.value ? ! props.can.edit : ! props.can.create));
const canSimulate = computed(() => props.can.edit);
const nodeTypes = { automation: markRaw(FlowNode) };

const { screenToFlowCoordinate, fitView, removeNodes, removeEdges } = useVueFlow(flowId);

// ---------------------------------------------------------------- helpers for blocks and panel

const stagesOf = (pipelineId) => props.options.pipelines.find((p) => p.id === pipelineId)?.stages ?? [];
const pipelineName = (pipelineId) => props.options.pipelines.find((p) => p.id === pipelineId)?.name ?? "";
const findName = (list, id) => (list || []).find((item) => item.id === id)?.name;

function title(data) {
    const c = data.config || {};

    if (data.kind === "trigger") return labels.triggers[c.trigger_type] || c.trigger_type;
    if (data.kind === "action") return labels.actions[c.type] || c.type;
    if (data.kind === "wait") return labels.flow.blocks.wait;

    const rules = c.rules || [];

    return rules.length === 1 ? labels.conditions[rules[0].type] : labels.flow.blocks.condition + " (" + rules.length + ")";
}

function stageName(id) {
    for (const p of props.options.pipelines) {
        const stage = p.stages.find((s) => s.id === id);

        if (stage) return p.name + " › " + stage.name;
    }

    return null;
}

function ruleSummary(rule) {
    const o = props.options;

    return {
        pipeline: () => findName(o.pipelines, rule.pipeline_id),
        stage: () => stageName(rule.stage_id),
        source: () => findName(o.sources, rule.source_id),
        tag: () => findName(o.tags, rule.tag_id),
        owner: () => findName(o.users, rule.user_id),
        lead_value: () => (rule.value !== null && rule.value !== "" ? ui.operators[rule.operator] + " " + rule.value : null),
        field: () => (rule.attribute_code ? (o.fields.find((f) => f.code === rule.attribute_code)?.name || rule.attribute_code) + " " + ui.operators[rule.operator || "equals"] + " \"" + (rule.value || "") + "\"" : null),
    }[rule.type]?.() || null;
}

const duration = (c) => (Number(c.amount) > 0 && ui.unit_labels[c.unit] ? c.amount + " " + (Number(c.amount) === 1 ? ui.unit_labels_one : ui.unit_labels)[c.unit] : "");

function summary(data) {
    const c = data.config || {};
    const o = props.options;

    if (data.kind === "wait") {
        return [duration(c), c.cancel_on_stage_change ? ui.wait_cancel_on_stage : ""].filter(Boolean).join(" · ");
    }

    if (data.kind === "trigger" && TIME_TRIGGERS.includes(c.trigger_type)) {
        return [duration(c), c.stage_id ? stageName(c.stage_id) : findName(o.pipelines, c.pipeline_id)].filter(Boolean).join(" · ");
    }

    if (data.kind === "trigger") {
        return {
            stage_entered: () => stageName(c.stage_id),
            lead_created: () => [findName(o.sources, c.source_id), findName(o.types, c.type_id)].filter(Boolean).join(" · "),
            tag_added: () => findName(o.tags, c.tag_id),
            activity_created: () => o.activity_types.find((t) => t.value === c.activity_type)?.label,
        }[c.trigger_type]?.() || "";
    }

    if (data.kind === "condition") {
        return (c.rules || []).map(ruleSummary).filter(Boolean).join(c.match === "any" ? " OU " : " E ");
    }

    return {
        move_stage: () => stageName(c.stage_id),
        transfer_pipeline: () => stageName(c.stage_id),
        assign_owner: () => findName(o.users, c.user_id),
        add_tag: () => findName(o.tags, c.tag_id),
        create_task: () => c.title,
        notify_whatsapp: () => c.to,
        webhook: () => { try { return new URL(c.url).host; } catch (e) { return ""; } },
        mark_lost: () => findName(o.lost_reasons, c.reason_id),
    }[c.type]?.() || "";
}

// ---------------------------------------------------------------- validation

const client = computed(() => validate(nodes.value, edges.value, labels, props.maxNodes, props.maxWaitDays));

const timeTriggered = computed(() => TIME_TRIGGERS.includes(nodes.value.find((n) => n.data.kind === "trigger")?.data.config.trigger_type));

function problemsOf(id) {
    return [...new Set([...(client.value.byNode[id] || []), ...(serverErrors.value.byNode[id] || [])])];
}

const allGeneral = computed(() => {
    // The blocks with problems are marked on the canvas; here only the problems of the flow as a whole.
    return [...new Set([...client.value.general, ...serverErrors.value.general])];
});

const isValid = computed(() => ! client.value.general.length && ! Object.keys(client.value.byNode).length);

const transfersWon = computed(() => {
    const trigger = nodes.value.find((n) => n.data.kind === "trigger");
    const c = trigger?.data.config || {};

    if (c.trigger_type !== "stage_entered") return false;

    const stage = stagesOf(c.pipeline_id).find((s) => s.id === c.stage_id);

    return !! stage && stage.code === "won" && nodes.value.some((n) => n.data.kind === "action" && n.data.config.type === "transfer_pipeline");
});

// ---------------------------------------------------------------- palette, drag and drop

const palette = [
    { kind: "trigger", items: TRIGGERS.map((t) => ({ key: t, value: t, label: labels.triggers[t] })) },
    { kind: "condition", items: CONDITIONS.map((t) => ({ key: t, value: t, label: labels.conditions[t] })) },
    { kind: "action", items: ACTIONS.map((t) => ({ key: t, value: t, label: labels.actions[t] })) },
    { kind: "wait", items: [{ key: "wait", value: "wait", label: labels.flow.blocks.wait }] },
];

function makeNode(kind, value, position) {
    const config = kind === "trigger" ? triggerConfig(value) : kind === "condition" ? { match: "all", rules: [conditionRule(value)] } : kind === "wait" ? waitConfig() : actionConfig(value);

    return { id: newId(), type: "automation", position, data: { kind, config, label: "", end: [] } };
}

function addNode(kind, value, position) {
    if (nodes.value.length >= props.maxNodes) {
        problem.value = labels.flow.errors.too_many_nodes.replace(":max", props.maxNodes);

        return;
    }

    const node = makeNode(kind, value, position);

    nodes.value = [...nodes.value, node];
    select(node.id, "node");
}

function onDragStart(event, kind, value) {
    event.dataTransfer.setData("application/x-automation-block", JSON.stringify({ kind, value }));
    event.dataTransfer.effectAllowed = "move";
}

function onDragOver(event) {
    if (event.dataTransfer) event.dataTransfer.dropEffect = "move";
}

function onDrop(event) {
    if (readOnly.value) return;

    let block;

    try {
        block = JSON.parse(event.dataTransfer.getData("application/x-automation-block") || "null");
    } catch (e) {
        block = null;
    }

    if (! block) return;

    const position = screenToFlowCoordinate({ x: event.clientX, y: event.clientY });

    addNode(block.kind, block.value, { x: position.x - 100, y: position.y - 30 });
}

function addAtCenter(kind, value) {
    const rect = canvas.value.getBoundingClientRect();
    const position = screenToFlowCoordinate({ x: rect.left + rect.width / 2, y: rect.top + rect.height / 2 });
    const offset = (nodes.value.length % 5) * 20;

    addNode(kind, value, { x: position.x - 100 + offset, y: position.y - 30 + offset });

    // On a phone the canvas is below the palette: bring it into view.
    if (window.innerWidth < 1024) canvas.value.scrollIntoView({ behavior: "smooth", block: "center" });
}

// ---------------------------------------------------------------- links

function isValidConnection(connection) {
    const target = nodes.value.find((n) => n.id === connection.target);

    return connection.source !== connection.target && !! target && target.data.kind !== "trigger";
}

function onConnect(connection) {
    if (readOnly.value) return;

    const branch = connection.sourceHandle === "yes" || connection.sourceHandle === "no" ? connection.sourceHandle : null;

    // One link per exit: a new link replaces the old one.
    const kept = edges.value.filter((e) => ! (e.source === connection.source && (e.data?.branch || null) === branch));

    edges.value = [...kept, edgeFor(connection.source, connection.target, branch, labels)];

    // A linked branch is no longer an "end".
    const source = nodes.value.find((n) => n.id === connection.source);

    if (branch && source) source.data.end = (source.data.end || []).filter((b) => b !== branch);
}

function hasBranch(nodeId, branch) {
    return edges.value.some((e) => e.source === nodeId && (e.data?.branch || null) === branch);
}

function edgeText(edge) {
    const from = nodes.value.find((n) => n.id === edge.source);
    const to = nodes.value.find((n) => n.id === edge.target);
    const branch = edge.data?.branch ? " (" + ui[edge.data.branch] + ")" : "";

    return (from ? from.data.label || title(from.data) : "?") + branch + " → " + (to ? to.data.label || title(to.data) : "?");
}

// ---------------------------------------------------------------- selection and removal

function select(id, type = "node") {
    selected.value = id ? { id, type } : null;
}

const selectedNode = computed(() => (selected.value?.type === "node" ? nodes.value.find((n) => n.id === selected.value.id) : null));
const selectedEdge = computed(() => (selected.value?.type === "edge" ? edges.value.find((e) => e.id === selected.value.id) : null));

function removeNode(id) {
    removeNodes([id]);
    nodes.value = nodes.value.filter((n) => n.id !== id);
    edges.value = edges.value.filter((e) => e.source !== id && e.target !== id);
    select(null);
}

function removeEdge(id) {
    removeEdges([id]);
    edges.value = edges.value.filter((e) => e.id !== id);
    select(null);
}

// ---------------------------------------------------------------- undo (snapshots of the graph)

const history = ref([]);
let restoring = false;
let timer = null;

function snapshot() {
    return JSON.stringify({
        nodes: nodes.value.map((n) => ({ id: n.id, position: { x: Math.round(n.position.x), y: Math.round(n.position.y) }, data: n.data })),
        edges: edges.value.map((e) => ({ id: e.id, source: e.source, target: e.target, branch: e.data?.branch || null })),
    });
}

let dirty = false;

watch(snapshot, (value) => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        if (restoring) {
            restoring = false;

            return;
        }

        if (history.value[history.value.length - 1] !== value) {
            if (history.value.length) dirty = true;

            history.value = [...history.value.slice(-49), value];
        }
    }, 300);
}, { immediate: true });

function undo() {
    if (history.value.length < 2) return;

    const list = history.value.slice(0, -1);
    const state = JSON.parse(list[list.length - 1]);

    history.value = list;
    restoring = true;

    nodes.value = state.nodes.map((n) => ({ id: n.id, type: "automation", position: n.position, data: n.data }));
    edges.value = state.edges.map((e) => ({ ...edgeFor(e.source, e.target, e.branch, labels), id: e.id }));
    select(null);
}

function onKey(event) {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === "z" && ! /input|textarea|select/i.test(event.target.tagName)) {
        event.preventDefault();
        undo();
    }
}

window.addEventListener("keydown", onKey);

// ---------------------------------------------------------------- save, simulate, close

function fit() {
    fitView({ padding: 0.2, maxZoom: 1 });
}

function errorOf(error) {
    const body = error?.response?.data;

    return (body && (Object.values(body.errors || {}).flat()[0] || body.message)) || old.error;
}

async function save(target) {
    message.value = "";
    problem.value = "";
    showProblems.value = true;

    if (target !== "off" && ! isValid.value) {
        problem.value = labels.flow.errors.invalid;

        return;
    }

    // Fase 2: saving a changed flow (or a draft) cancels the flows paused by it.
    const changes = semanticKey(nodes.value, edges.value) !== savedKey;

    if (ruleId.value && pendingWaits.value > 0 && (changes || target === "off") && ! window.confirm(ui.save_cancels_waits.replace(":count", pendingWaits.value))) {
        return;
    }

    busy.value = true;
    serverErrors.value = { general: [], byNode: {} };

    const payload = { name: name.value, status: target, run_once_per_lead: runOnce.value, flow: toServer(nodes.value, edges.value) };

    try {
        const response = ruleId.value
            ? await props.http.put(props.urls.update.replace("__ID__", ruleId.value), payload)
            : await props.http.post(props.urls.store, payload);

        const rule = response.data.data;

        ruleId.value = rule.id;
        status.value = rule.status;
        pendingWaits.value = Number(rule.pending_waits) || 0;
        savedKey = semanticKey(nodes.value, edges.value);

        if (response.data.cancelled_waits) message.value = ui.waits_cancelled.replace(":count", response.data.cancelled_waits) + " ";
        legacy.value = false;
        dirty = false;
        message.value = (message.value || "") + { off: ui.draft_saved, test: ui.tested, on: ui.activated }[rule.status];

        // Secrets come back masked ("__keep__"): keep the blocks in step with what was stored.
        const fresh = toVueFlow(rule.flow, labels);

        nodes.value.forEach((node) => {
            const stored = fresh.nodes.find((n) => n.id === node.id);

            if (stored && node.data.config.type === "webhook") node.data.config.secret = stored.data.config.secret;
        });

        props.onSaved(rule);
    } catch (error) {
        const errors = error?.response?.data?.errors || {};
        const byNode = {};
        const general = [];

        Object.entries(errors).forEach(([key, list]) => {
            const match = key.match(/^flow\.nodes\.(.+)$/);

            match ? (byNode[match[1]] = list) : general.push(...list);
        });

        serverErrors.value = { general, byNode };
        problem.value = general[0] || (Object.keys(byNode).length ? labels.flow.errors.invalid : errorOf(error));
    } finally {
        busy.value = false;
    }
}

async function simulate() {
    simulation.value.busy = true;
    simulation.value.problem = "";
    simulation.value.result = null;

    try {
        const response = await props.http.post(props.urls.simulate.replace("__ID__", ruleId.value), { lead_id: simulation.value.leadId });

        simulation.value.result = response.data.data;
        path.value = [...new Set((response.data.data.steps || []).map((s) => s.node).filter(Boolean))];
    } catch (error) {
        simulation.value.problem = errorOf(error);
    } finally {
        simulation.value.busy = false;
    }
}

function stepLabel(step) {
    const action = String(step.action || "");

    if (action.startsWith("condition:")) return labels.conditions[action.slice(10)] || action;
    if (action === "trigger") return labels.flow.blocks.trigger;
    if (action === "branch" || action === "end") return labels.flow.blocks.condition;
    if (action === "time_check") return labels.flow.blocks.trigger;
    if (action.startsWith("wait")) return labels.flow.blocks.wait;

    return labels.actions[action] || action;
}

function close() {
    if (dirty && ! readOnly.value && ! window.confirm(ui.unsaved)) return;

    window.removeEventListener("keydown", onKey);
    props.onClose();
}

function minimapColor(node) {
    return { trigger: "#6366f1", condition: "#f59e0b", action: "#10b981", wait: "#0891b2" }[node.data?.kind] || "#9ca3af";
}

provide("automationEditor", {
    labels,
    options: props.options,
    whatsapp: props.whatsapp,
    maxWaitDays: props.maxWaitDays,
    path,
    title,
    summary,
    problemsOf,
    stagesOf,
    pipelineName,
    hasBranch,
    removeNode,
});

nextTick(() => setTimeout(fit, 50));
</script>
