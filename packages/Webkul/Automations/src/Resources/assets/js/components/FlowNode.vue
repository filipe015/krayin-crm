<template>
    <div
        class="afl-node"
        :class="['is-' + data.kind, { 'is-selected': selected, 'has-error': problems.length, 'is-path': onPath }]"
    >
        <Handle v-if="data.kind !== 'trigger'" type="target" :position="Position.Top" class="afl-handle" />

        <div class="afl-node-head">
            <span class="afl-icon" aria-hidden="true">
                <svg v-if="data.kind === 'trigger'" viewBox="0 0 24 24"><path d="M13 2 4 14h7l-1 8 9-12h-7z" /></svg>
                <svg v-else-if="data.kind === 'condition'" viewBox="0 0 24 24"><path d="M12 3v6m0 0-6 6v6m6-12 6 6v6" fill="none" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" /></svg>
                <svg v-else-if="data.kind === 'wait'" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5" fill="none" stroke-width="2.4" /><path d="M12 7.5V12l3 2" fill="none" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" /></svg>
                <svg v-else viewBox="0 0 24 24"><path d="M7 4v16l13-8z" /></svg>
            </span>
            <span class="afl-kind">{{ editor.labels.flow.blocks[data.kind] }}</span>
            <span v-if="problems.length" class="afl-badge" :title="problems.join('\n')">!</span>
        </div>

        <div class="afl-node-title">{{ data.label || editor.title(data) }}</div>
        <div class="afl-node-summary" v-if="summary">{{ summary }}</div>

        <template v-if="data.kind === 'condition'">
            <div class="afl-branches">
                <span class="afl-yes">{{ editor.labels.flow.ui.yes }}<em v-if="(data.end || []).includes('yes')"> · {{ editor.labels.flow.ui.end_short }}</em></span>
                <span class="afl-no">{{ editor.labels.flow.ui.no }}<em v-if="(data.end || []).includes('no')"> · {{ editor.labels.flow.ui.end_short }}</em></span>
            </div>
            <Handle id="yes" type="source" :position="Position.Bottom" class="afl-handle afl-handle-yes" style="left: 25%" />
            <Handle id="no" type="source" :position="Position.Bottom" class="afl-handle afl-handle-no" style="left: 75%" />
        </template>

        <Handle v-else type="source" :position="Position.Bottom" class="afl-handle" />
    </div>
</template>

<script setup>
import { computed, inject } from "vue";
import { Handle, Position } from "@vue-flow/core";

const props = defineProps({
    id: { type: String, required: true },
    data: { type: Object, required: true },
    selected: { type: Boolean, default: false },
});

const editor = inject("automationEditor");

const problems = computed(() => editor.problemsOf(props.id));

const summary = computed(() => editor.summary(props.data));

const onPath = computed(() => editor.path.value.includes(props.id));
</script>
