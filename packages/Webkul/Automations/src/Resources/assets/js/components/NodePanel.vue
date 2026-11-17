<template>
    <div class="afl-panel-body">
        <div class="afl-panel-kind" :class="'is-' + node.data.kind">{{ e.labels.flow.blocks[node.data.kind] }}</div>

        <div class="afl-problems" v-if="problems.length">
            <div v-for="(problem, i) in problems" :key="i">{{ problem }}</div>
        </div>

        <label class="afl-field">{{ ui.block_label }}
            <input type="text" v-model="node.data.label" maxlength="60" :disabled="readOnly">
        </label>

        <!-- Gatilho -->
        <template v-if="node.data.kind === 'trigger'">
            <label class="afl-field">{{ ui.trigger_type }}
                <select :value="c.trigger_type" @change="setTrigger($event.target.value)" :disabled="readOnly">
                    <option v-for="type in TRIGGERS" :key="type" :value="type">{{ e.labels.triggers[type] }}</option>
                </select>
            </label>

            <template v-if="c.trigger_type === 'stage_entered'">
                <label class="afl-field">{{ old.pipeline }}
                    <select v-model.number="c.pipeline_id" @change="c.stage_id = null" :disabled="readOnly">
                        <option :value="null">{{ old.choose }}</option>
                        <option v-for="p in o.pipelines" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </select>
                </label>
                <label class="afl-field">{{ old.stage }}
                    <select v-model.number="c.stage_id" :disabled="readOnly">
                        <option :value="null">{{ old.choose }}</option>
                        <option v-for="s in e.stagesOf(c.pipeline_id)" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                </label>
                <label class="afl-field">{{ old.from_stage }}
                    <select v-model.number="c.from_stage_id" :disabled="readOnly">
                        <option :value="null">{{ old.any }}</option>
                        <optgroup v-for="p in o.pipelines" :key="p.id" :label="p.name">
                            <option v-for="s in p.stages" :key="s.id" :value="s.id">{{ s.name }}</option>
                        </optgroup>
                    </select>
                </label>
            </template>

            <template v-if="c.trigger_type === 'lead_created'">
                <label class="afl-field">{{ old.source }}
                    <select v-model.number="c.source_id" :disabled="readOnly">
                        <option :value="null">{{ old.any }}</option>
                        <option v-for="s in o.sources" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                </label>
                <label class="afl-field">{{ old.channel }}
                    <select v-model.number="c.type_id" :disabled="readOnly">
                        <option :value="null">{{ old.any }}</option>
                        <option v-for="t in o.types" :key="t.id" :value="t.id">{{ t.name }}</option>
                    </select>
                </label>
            </template>

            <label class="afl-field" v-if="c.trigger_type === 'tag_added'">{{ old.tag }}
                <select v-model.number="c.tag_id" :disabled="readOnly">
                    <option :value="null">{{ old.choose }}</option>
                    <option v-for="t in o.tags" :key="t.id" :value="t.id">{{ t.name }}</option>
                </select>
            </label>

            <label class="afl-field" v-if="c.trigger_type === 'activity_created'">{{ old.activity_type }}
                <select v-model="c.activity_type" :disabled="readOnly">
                    <option :value="null">{{ old.any }}</option>
                    <option v-for="t in o.activity_types" :key="t.value" :value="t.value">{{ t.label }}</option>
                </select>
            </label>
        </template>

        <!-- Condição -->
        <template v-if="node.data.kind === 'condition'">
            <label class="afl-field">{{ ui.match }}
                <select v-model="c.match" :disabled="readOnly">
                    <option value="all">{{ ui.match_all }}</option>
                    <option value="any">{{ ui.match_any }}</option>
                </select>
            </label>

            <div class="afl-rule" v-for="(rule, index) in c.rules" :key="index">
                <div class="afl-rule-head">
                    <select :value="rule.type" @change="c.rules.splice(index, 1, conditionRule($event.target.value))" :disabled="readOnly">
                        <option v-for="type in CONDITIONS" :key="type" :value="type">{{ e.labels.conditions[type] }}</option>
                    </select>
                    <button type="button" class="afl-btn is-small" v-if="! readOnly" @click="c.rules.splice(index, 1)">{{ old.remove }}</button>
                </div>

                <select v-if="rule.type === 'pipeline'" v-model.number="rule.pipeline_id" :disabled="readOnly">
                    <option :value="null">{{ old.choose }}</option>
                    <option v-for="p in o.pipelines" :key="p.id" :value="p.id">{{ p.name }}</option>
                </select>

                <select v-if="rule.type === 'stage'" v-model.number="rule.stage_id" :disabled="readOnly">
                    <option :value="null">{{ old.choose }}</option>
                    <optgroup v-for="p in o.pipelines" :key="p.id" :label="p.name">
                        <option v-for="s in p.stages" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </optgroup>
                </select>

                <select v-if="rule.type === 'source'" v-model.number="rule.source_id" :disabled="readOnly">
                    <option :value="null">{{ old.choose }}</option>
                    <option v-for="s in o.sources" :key="s.id" :value="s.id">{{ s.name }}</option>
                </select>

                <select v-if="rule.type === 'tag'" v-model.number="rule.tag_id" :disabled="readOnly">
                    <option :value="null">{{ old.choose }}</option>
                    <option v-for="t in o.tags" :key="t.id" :value="t.id">{{ t.name }}</option>
                </select>

                <select v-if="rule.type === 'owner'" v-model.number="rule.user_id" :disabled="readOnly">
                    <option :value="null">{{ old.choose }}</option>
                    <option v-for="u in o.users" :key="u.id" :value="u.id">{{ u.name }}</option>
                </select>

                <div class="afl-inline" v-if="rule.type === 'lead_value'">
                    <select v-model="rule.operator" :disabled="readOnly">
                        <option v-for="op in ['gt', 'gte', 'lt', 'lte', 'eq']" :key="op" :value="op">{{ ui.operators[op] }}</option>
                    </select>
                    <input type="number" step="0.01" v-model.number="rule.value" :placeholder="ui.amount" :disabled="readOnly">
                </div>

                <template v-if="rule.type === 'field'">
                    <select v-model="rule.attribute_code" :disabled="readOnly">
                        <option value="">{{ old.choose }}</option>
                        <option v-for="f in o.fields" :key="f.code" :value="f.code">{{ f.name }}</option>
                    </select>
                    <div class="afl-inline">
                        <select v-model="rule.operator" :disabled="readOnly">
                            <option value="equals">{{ ui.operators.equals }}</option>
                            <option value="contains">{{ ui.operators.contains }}</option>
                        </select>
                        <input type="text" v-model="rule.value" maxlength="255" :placeholder="old.value" :disabled="readOnly">
                    </div>
                    <p class="afl-muted">{{ ui.utm_hint }}</p>
                </template>
            </div>

            <button type="button" class="afl-btn is-small" v-if="! readOnly && c.rules.length < 10" @click="c.rules.push(conditionRule('tag'))">{{ ui.add_rule }}</button>

            <label class="afl-check" v-for="branch in ['yes', 'no']" :key="branch">
                <input type="checkbox" :checked="(node.data.end || []).includes(branch)" @change="toggleEnd(branch, $event.target.checked)" :disabled="readOnly || e.hasBranch(node.id, branch)">
                {{ ui.end_branch.replace(':branch', ui[branch]) }}
            </label>
        </template>

        <!-- Ação -->
        <template v-if="node.data.kind === 'action'">
            <label class="afl-field">{{ ui.action_type }}
                <select :value="c.type" @change="setAction($event.target.value)" :disabled="readOnly">
                    <option v-for="type in ACTIONS" :key="type" :value="type">{{ e.labels.actions[type] }}</option>
                </select>
            </label>

            <label class="afl-field" v-if="c.type === 'move_stage'">{{ old.stage }}
                <select v-model.number="c.stage_id" :disabled="readOnly">
                    <option :value="null">{{ old.choose }}</option>
                    <optgroup v-for="p in o.pipelines" :key="p.id" :label="p.name">
                        <option v-for="s in p.stages.filter(s => s.code !== 'lost')" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </optgroup>
                </select>
            </label>

            <template v-if="c.type === 'transfer_pipeline'">
                <label class="afl-field">{{ old.pipeline }}
                    <select v-model.number="c.pipeline_id" @change="c.stage_id = (e.stagesOf(c.pipeline_id).find(s => ! ['won', 'lost'].includes(s.code)) || {}).id || null" :disabled="readOnly">
                        <option :value="null">{{ old.choose }}</option>
                        <option v-for="p in o.pipelines" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </select>
                </label>
                <label class="afl-field">{{ old.stage }}
                    <select v-model.number="c.stage_id" :disabled="readOnly">
                        <option :value="null">{{ old.choose }}</option>
                        <option v-for="s in e.stagesOf(c.pipeline_id)" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                </label>
            </template>

            <label class="afl-field" v-if="c.type === 'assign_owner'">{{ old.user }}
                <select v-model.number="c.user_id" :disabled="readOnly">
                    <option :value="null">{{ old.choose }}</option>
                    <option v-for="u in o.users" :key="u.id" :value="u.id">{{ u.name }}</option>
                </select>
            </label>

            <label class="afl-field" v-if="c.type === 'add_tag'">{{ old.tag }}
                <select v-model.number="c.tag_id" :disabled="readOnly">
                    <option :value="null">{{ old.choose }}</option>
                    <option v-for="t in o.tags" :key="t.id" :value="t.id">{{ t.name }}</option>
                </select>
            </label>

            <template v-if="c.type === 'create_task'">
                <label class="afl-field">{{ old.task_title }}<input type="text" v-model="c.title" maxlength="200" :disabled="readOnly"></label>
                <label class="afl-field">{{ old.due_in_days }}<input type="number" min="0" max="365" v-model.number="c.due_in_days" :disabled="readOnly"></label>
                <label class="afl-field">{{ old.assignee }}
                    <select v-model="c.assignee" :disabled="readOnly">
                        <option value="owner">{{ old.assignee_owner }}</option>
                        <option value="fixed">{{ old.assignee_fixed }}</option>
                    </select>
                </label>
                <label class="afl-field" v-if="c.assignee === 'fixed'">{{ old.user }}
                    <select v-model.number="c.user_id" :disabled="readOnly">
                        <option :value="null">{{ old.choose }}</option>
                        <option v-for="u in o.users" :key="u.id" :value="u.id">{{ u.name }}</option>
                    </select>
                </label>
                <p class="afl-muted">{{ old.placeholders }}</p>
            </template>

            <template v-if="c.type === 'notify_whatsapp'">
                <div class="afl-warn" v-if="! e.whatsapp">{{ old.whatsapp_off }}</div>
                <label class="afl-field">{{ old.whatsapp_to }}<input type="text" v-model="c.to" maxlength="300" :disabled="readOnly"></label>
                <label class="afl-field">{{ old.message }}<textarea v-model="c.message" maxlength="1000" :disabled="readOnly"></textarea></label>
                <p class="afl-muted">{{ old.placeholders }}</p>
            </template>

            <template v-if="c.type === 'webhook'">
                <label class="afl-field">{{ old.url }}<input type="url" v-model="c.url" maxlength="500" placeholder="https://" :disabled="readOnly"></label>
                <label class="afl-field">{{ old.secret }}<input type="password" v-model="c.secret" maxlength="200" autocomplete="new-password" :disabled="readOnly"></label>
                <p class="afl-muted" v-if="c.secret === '__keep__'">{{ old.secret_kept }}</p>
            </template>

            <label class="afl-field" v-if="c.type === 'mark_lost'">{{ old.reason }}
                <select v-model.number="c.reason_id" :disabled="readOnly">
                    <option :value="null">—</option>
                    <option v-for="r in o.lost_reasons" :key="r.id" :value="r.id">{{ r.name }} ({{ e.pipelineName(r.lead_pipeline_id) }})</option>
                </select>
            </label>
        </template>

        <button type="button" class="afl-btn is-danger" v-if="! readOnly" @click="e.removeNode(node.id)">{{ ui.delete_block }}</button>
    </div>
</template>

<script setup>
import { computed, inject } from "vue";
import { ACTIONS, CONDITIONS, TRIGGERS, actionConfig, conditionRule, triggerConfig } from "../graph.js";

const props = defineProps({
    node: { type: Object, required: true },
    readOnly: { type: Boolean, default: false },
});

const e = inject("automationEditor");
const ui = e.labels.flow.ui;
const old = e.labels.ui;
const o = e.options;

const c = computed(() => props.node.data.config);
const problems = computed(() => e.problemsOf(props.node.id));

function setTrigger(type) {
    props.node.data.config = triggerConfig(type);
}

function setAction(type) {
    props.node.data.config = actionConfig(type);
}

function toggleEnd(branch, on) {
    const end = new Set(props.node.data.end || []);

    on ? end.add(branch) : end.delete(branch);
    props.node.data.end = [...end];
}
</script>
