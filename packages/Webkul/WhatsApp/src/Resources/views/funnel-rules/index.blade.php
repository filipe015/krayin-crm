<x-admin::layouts>
    <x-slot:title>
        @lang('whatsapp::app.funnel_rules.title')
    </x-slot>

    <v-funnel-rules
        :urls="{{ Illuminate\Support\Js::from($urls) }}"
        :labels="{{ Illuminate\Support\Js::from(trans('whatsapp::app.funnel_rules')) }}"
        :initial="{{ Illuminate\Support\Js::from($rules) }}"
        :users="{{ Illuminate\Support\Js::from($users) }}"
        :pipelines="{{ Illuminate\Support\Js::from($pipelines) }}"
    >
        <div class="p-6 text-sm text-gray-500">...</div>
    </v-funnel-rules>

    @pushOnce('scripts')
        <style>
            .fr{--fr-bg:#fff;--fr-fg:#111827;--fr-muted:#6b7280;--fr-border:#e5e7eb;--fr-soft:#f3f4f6;--fr-accent:#0e90d9;display:flex;flex-direction:column;gap:16px;max-width:760px;color:var(--fr-fg);font-size:14px}
            .dark .fr{--fr-bg:#111827;--fr-fg:#e5e7eb;--fr-muted:#9ca3af;--fr-border:#1f2937;--fr-soft:#1f2937}
            .fr *{box-sizing:border-box}
            .fr h1{margin:0;font-size:18px;font-weight:600}
            .fr-card{display:flex;flex-direction:column;gap:14px;padding:16px;border:1px solid var(--fr-border);border-radius:8px;background:var(--fr-bg)}
            .fr-muted{color:var(--fr-muted);font-size:12px;margin:0}
            .fr-row{display:flex;gap:12px;flex-wrap:wrap}
            .fr-row>*{flex:1;min-width:180px}
            .fr-field{display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:600;color:var(--fr-muted)}
            .fr-field input[type=text],.fr-field select{width:100%;padding:8px 10px;border:1px solid var(--fr-border);border-radius:6px;background:var(--fr-bg);color:var(--fr-fg);font:inherit;font-weight:400}
            .fr-check{display:flex;align-items:flex-start;gap:8px;font-weight:400;color:var(--fr-fg)}
            .fr-radio{display:flex;flex-direction:column;gap:6px;font-weight:400;color:var(--fr-fg)}
            .fr-radio label{display:flex;align-items:center;gap:6px}
            .fr-pool{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:6px}
            .fr-pool label{display:flex;align-items:center;gap:6px;font-weight:400;color:var(--fr-fg)}
            .fr-btn{padding:7px 14px;border:1px solid var(--fr-border);border-radius:6px;background:var(--fr-bg);font:inherit;font-size:13px;font-weight:500;cursor:pointer;color:inherit}
            .fr-btn:hover{background:var(--fr-soft)}
            .fr-btn.is-primary{background:var(--fr-accent);border-color:var(--fr-accent);color:#fff}
            .fr-btn:disabled{opacity:.5;cursor:default}
            .fr-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
            .fr-alert{padding:10px 12px;border-radius:6px;background:#fee2e2;color:#991b1b;font-size:13px}
            .fr-ok{padding:10px 12px;border-radius:6px;background:#dcfce7;color:#14532d;font-size:13px}
        </style>

        <script type="text/x-template" id="v-funnel-rules-template">
            <div class="fr">
                <h1>@{{ labels.title }}</h1>
                <p class="fr-muted">@{{ labels.intro }}</p>

                <p class="fr-muted" v-if="! rules.length">@{{ labels.none }}</p>

                <form class="fr-card" v-for="(rule, index) in rules" :key="rule.id || 'new-' + index" @submit.prevent="save(rule)">
                    <div class="fr-row">
                        <label class="fr-field">@{{ labels.name }}<input type="text" v-model="rule.name" maxlength="120"></label>
                        <label class="fr-check" style="align-self:flex-end"><input type="checkbox" v-model="rule.is_active"> @{{ labels.active }}</label>
                    </div>

                    <div class="fr-row">
                        <label class="fr-field">
                            @{{ labels.source }}
                            <select v-model.number="rule.source_pipeline_id">
                                <option v-for="pipeline in pipelines" :key="pipeline.id" :value="pipeline.id">@{{ pipeline.name }}</option>
                            </select>
                        </label>

                        <label class="fr-field">
                            @{{ labels.target }}
                            <select v-model.number="rule.target_pipeline_id" @change="rule.target_stage_id = stagesOf(rule.target_pipeline_id)[0]?.id ?? null">
                                <option v-for="pipeline in pipelines" :key="pipeline.id" :value="pipeline.id">@{{ pipeline.name }}</option>
                            </select>
                        </label>

                        <label class="fr-field">
                            @{{ labels.stage }}
                            <select v-model.number="rule.target_stage_id">
                                <option v-for="stage in stagesOf(rule.target_pipeline_id)" :key="stage.id" :value="stage.id">@{{ stage.name }}</option>
                            </select>
                        </label>
                    </div>

                    <div class="fr-field">
                        @{{ labels.responsible }}
                        <div class="fr-radio">
                            <label><input type="radio" value="owner" v-model="rule.assignment_mode"> @{{ labels.mode_owner }}</label>
                            <label><input type="radio" value="fixed" v-model="rule.assignment_mode"> @{{ labels.mode_fixed }}</label>
                            <label><input type="radio" value="round_robin" v-model="rule.assignment_mode"> @{{ labels.mode_round_robin }}</label>
                        </div>
                    </div>

                    <label class="fr-field" v-if="rule.assignment_mode === 'fixed'">
                        @{{ labels.pick_user }}
                        <select v-model.number="rule.assigned_user_id">
                            <option :value="null"></option>
                            <option v-for="user in users" :key="user.id" :value="user.id">@{{ user.name }}</option>
                        </select>
                    </label>

                    <div class="fr-field" v-if="rule.assignment_mode === 'round_robin'">
                        @{{ labels.pool }}
                        <div class="fr-pool">
                            <label v-for="user in users" :key="user.id"><input type="checkbox" :value="user.id" v-model="rule.pool_user_ids"> @{{ user.name }}</label>
                        </div>
                    </div>

                    <div>
                        <label class="fr-check"><input type="checkbox" v-model="rule.copy_value"> @{{ labels.copy_value }}</label>
                        <p class="fr-muted">@{{ labels.copy_value_hint }}</p>
                    </div>

                    <div>
                        <label class="fr-check"><input type="checkbox" v-model="rule.move_conversation"> @{{ labels.move_conversation }}</label>
                        <p class="fr-muted">@{{ labels.move_conversation_hint }}</p>
                    </div>

                    <div class="fr-ok" v-if="rule.saved">@{{ labels.saved }}</div>
                    <div class="fr-alert" v-if="rule.problem">@{{ rule.problem }}</div>

                    <div class="fr-actions">
                        <button type="submit" class="fr-btn is-primary" :disabled="rule.busy">@{{ labels.save }}</button>
                        <button type="button" class="fr-btn" :disabled="rule.busy" @click="remove(rule, index)">@{{ labels.delete }}</button>
                    </div>
                </form>

                <div>
                    <button type="button" class="fr-btn" :disabled="pipelines.length < 2" @click="add">@{{ labels.add }}</button>
                </div>
            </div>
        </script>

        <script type="module">
            app.component('v-funnel-rules', {
                template: '#v-funnel-rules-template',

                props: {
                    urls: { type: Object, required: true },
                    labels: { type: Object, required: true },
                    initial: { type: Array, default: () => [] },
                    users: { type: Array, default: () => [] },
                    pipelines: { type: Array, default: () => [] },
                },

                data() {
                    return {
                        rules: this.initial.map(rule => ({ ...rule, busy: false, saved: false, problem: '' })),
                    };
                },

                methods: {
                    stagesOf(pipelineId) {
                        return this.pipelines.find(pipeline => pipeline.id === pipelineId)?.stages ?? [];
                    },

                    add() {
                        const source = this.pipelines[0];
                        const target = this.pipelines[1];

                        this.rules.push({
                            id: null, name: '', is_active: true,
                            source_pipeline_id: source.id, target_pipeline_id: target.id, target_stage_id: target.stages[0]?.id ?? null,
                            assignment_mode: 'owner', assigned_user_id: null, pool_user_ids: [],
                            copy_value: false, move_conversation: true,
                            busy: false, saved: false, problem: '',
                        });
                    },

                    async save(rule) {
                        rule.busy = true;
                        rule.saved = false;
                        rule.problem = '';

                        const payload = { ...rule };
                        ['busy', 'saved', 'problem', 'id'].forEach(key => delete payload[key]);

                        try {
                            const response = rule.id
                                ? await this.$axios.put(this.urls.update.replace('__ID__', rule.id), payload)
                                : await this.$axios.post(this.urls.store, payload);

                            rule.id = response.data.data.id;
                            rule.saved = true;
                        } catch (error) {
                            const body = error.response && error.response.data;

                            rule.problem = (body && (body.message || Object.values(body.errors || {}).flat()[0])) || this.labels.error;
                        } finally {
                            rule.busy = false;
                        }
                    },

                    async remove(rule, index) {
                        if (rule.id) {
                            if (! window.confirm(this.labels.confirm_delete)) {
                                return;
                            }

                            rule.busy = true;

                            try {
                                await this.$axios.delete(this.urls.delete.replace('__ID__', rule.id));
                            } catch (error) {
                                rule.busy = false;
                                rule.problem = this.labels.error;

                                return;
                            }
                        }

                        this.rules.splice(index, 1);
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
