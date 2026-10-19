<x-admin::layouts>
    <x-slot:title>
        @lang('whatsapp::app.agenda_settings.title')
    </x-slot>

    <v-agenda-settings
        :urls="{{ Illuminate\Support\Js::from($urls) }}"
        :labels="{{ Illuminate\Support\Js::from(trans('whatsapp::app.agenda_settings')) }}"
        :initial="{{ Illuminate\Support\Js::from($settings) }}"
        :users="{{ Illuminate\Support\Js::from($users) }}"
        :durations="{{ Illuminate\Support\Js::from($durations) }}"
        :pipelines="{{ Illuminate\Support\Js::from($pipelines) }}"
        :color-pipelines="{{ Illuminate\Support\Js::from($colorPipelines) }}"
    >
        <div class="p-6 text-sm text-gray-500">@lang('whatsapp::app.agenda.loading')</div>
    </v-agenda-settings>

    @pushOnce('scripts')
        <style>
            .as{--as-bg:#fff;--as-fg:#111827;--as-muted:#6b7280;--as-border:#e5e7eb;--as-soft:#f3f4f6;--as-accent:#0e90d9;display:flex;flex-direction:column;gap:16px;max-width:760px;color:var(--as-fg);font-size:14px}
            .dark .as{--as-bg:#111827;--as-fg:#e5e7eb;--as-muted:#9ca3af;--as-border:#1f2937;--as-soft:#1f2937}
            .as *{box-sizing:border-box}
            .as h1{margin:0;font-size:18px;font-weight:600}
            .as-card{display:flex;flex-direction:column;gap:14px;padding:16px;border:1px solid var(--as-border);border-radius:8px;background:var(--as-bg)}
            .as-card h2{margin:0;font-size:14px;font-weight:600}
            .as-muted{color:var(--as-muted);font-size:12px;margin:0}
            .as-row{display:flex;gap:12px;flex-wrap:wrap}
            .as-row>*{flex:1;min-width:180px}
            .as-field{display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:600;color:var(--as-muted)}
            .as-field input[type=text],.as-field select{width:100%;padding:8px 10px;border:1px solid var(--as-border);border-radius:6px;background:var(--as-bg);color:var(--as-fg);font:inherit;font-weight:400}
            .as-btn{padding:7px 14px;border:1px solid var(--as-border);border-radius:6px;background:var(--as-bg);font:inherit;font-size:13px;font-weight:500;cursor:pointer;color:inherit}
            .as-btn:hover{background:var(--as-soft)}
            .as-btn.is-primary{background:var(--as-accent);border-color:var(--as-accent);color:#fff}
            .as-btn:disabled{opacity:.5;cursor:default}
            .as-chips{display:flex;gap:8px;flex-wrap:wrap}
            .as-radio{display:flex;gap:16px;flex-wrap:wrap;font-weight:400;color:var(--as-fg)}
            .as-radio label{display:flex;align-items:center;gap:6px}
            .as-pool{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:6px}
            .as-pool label{display:flex;align-items:center;gap:6px;font-weight:400;color:var(--as-fg)}
            .as-preview{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
            .as-pill{padding:6px 14px;border-radius:6px;background:var(--as-accent);color:#fff;font-size:13px;font-weight:500}
            .as-pill.is-soft{background:var(--as-soft);color:var(--as-fg)}
            .as-alert{padding:10px 12px;border-radius:6px;background:#fee2e2;color:#991b1b;font-size:13px}
            .as-ok{padding:10px 12px;border-radius:6px;background:#dcfce7;color:#14532d;font-size:13px}
            .as-colors h3{margin:8px 0 6px;font-size:13px;font-weight:600}
            .as-color{display:flex;align-items:center;gap:10px;padding:4px 0}
            .as-color input[type=color]{width:36px;height:28px;padding:0;border:1px solid var(--as-border);border-radius:6px;background:none;cursor:pointer}
            .as-color span:first-of-type{flex:1}
        </style>

        <script type="text/x-template" id="v-agenda-settings-template">
            <form class="as" @submit.prevent="save">
                <h1>@{{ labels.title }}</h1>
                <p class="as-muted">@{{ labels.intro }}</p>

                <div class="as-card">
                    <h2>@{{ labels.presets }}</h2>

                    <div class="as-chips">
                        <button v-for="preset in presets" :key="preset.key" type="button" class="as-btn" @click="apply(preset)">@{{ preset.label }}</button>
                    </div>
                </div>

                <div class="as-card">
                    <h2>@{{ labels.naming }}</h2>

                    <div class="as-row">
                        <label class="as-field">@{{ labels.singular }}<input type="text" v-model="form.singular" maxlength="40" required></label>
                        <label class="as-field">@{{ labels.plural }}<input type="text" v-model="form.plural" maxlength="40" required></label>
                    </div>

                    <div class="as-field">
                        @{{ labels.gender }}
                        <div class="as-radio">
                            <label><input type="radio" value="m" v-model="form.gender"> @{{ labels.masculine }}</label>
                            <label><input type="radio" value="f" v-model="form.gender"> @{{ labels.feminine }}</label>
                        </div>
                    </div>

                    <div class="as-row">
                        <label class="as-field">@{{ labels.responsible }}<input type="text" v-model="form.responsible" maxlength="40" required></label>
                        <label class="as-field">@{{ labels.responsible_plural }}<input type="text" v-model="form.responsible_plural" maxlength="40" required></label>
                    </div>

                    <div>
                        <p class="as-muted">@{{ labels.preview }}</p>
                        <div class="as-preview">
                            <span class="as-pill">+ @{{ form.gender === 'f' ? 'Nova' : 'Novo' }} @{{ form.singular.toLowerCase() }}</span>
                            <span class="as-pill is-soft">@{{ form.responsible_plural }}: @{{ form.responsible }}</span>
                        </div>
                    </div>
                </div>

                <div class="as-card">
                    <h2>@{{ labels.schedule }}</h2>

                    <div class="as-row">
                        <label class="as-field">
                            @{{ labels.default_duration }}
                            <select v-model.number="form.default_duration">
                                <option v-for="minutes in durations" :key="minutes" :value="minutes">@{{ minutes }} @{{ labels.minutes }}</option>
                            </select>
                        </label>

                        <label class="as-field">
                            @{{ labels.day_start }}
                            <select v-model.number="form.day_start">
                                <option v-for="hour in 24" :key="'s' + hour" :value="hour - 1">@{{ hour - 1 }}@{{ labels.hour_suffix }}</option>
                            </select>
                        </label>

                        <label class="as-field">
                            @{{ labels.day_end }}
                            <select v-model.number="form.day_end">
                                <option v-for="hour in 24" :key="'e' + hour" :value="hour">@{{ hour }}@{{ labels.hour_suffix }}</option>
                            </select>
                        </label>
                    </div>
                </div>

                <div class="as-card">
                    <h2>@{{ labels.distribution }}</h2>

                    <div class="as-radio" style="flex-direction:column;gap:8px">
                        <label><input type="radio" value="manual" v-model="form.distribution_mode"> @{{ labels.mode_manual }}</label>
                        <label><input type="radio" value="round_robin" v-model="form.distribution_mode"> @{{ labels.mode_round_robin }}</label>
                        <label><input type="radio" value="least_busy" v-model="form.distribution_mode"> @{{ labels.mode_least_busy }}</label>
                    </div>

                    <div v-if="form.distribution_mode !== 'manual'" class="as-field">
                        @{{ labels.pool }}
                        <div class="as-pool">
                            <label v-for="user in users" :key="user.id">
                                <input type="checkbox" :value="user.id" v-model="form.distribution_user_ids"> @{{ user.name }}
                            </label>
                        </div>
                        <p class="as-muted">@{{ labels.pool_hint }}</p>
                    </div>
                </div>

                <div class="as-card">
                    <h2>@{{ labels.trigger_title }}</h2>
                    <p class="as-muted">@{{ labels.trigger_hint }}</p>

                    <p class="as-muted" v-if="! form.stage_triggers.length">@{{ labels.trigger_none }}</p>

                    <div class="as-row" v-for="(trigger, index) in form.stage_triggers" :key="index" style="align-items:flex-end">
                        <label class="as-field">
                            @{{ labels.trigger_pipeline }}
                            <select v-model.number="trigger.pipeline_id" @change="trigger.stage_id = stagesOf(trigger.pipeline_id)[0]?.id ?? null">
                                <option v-for="pipeline in pipelines" :key="pipeline.id" :value="pipeline.id">@{{ pipeline.name }}</option>
                            </select>
                        </label>

                        <label class="as-field">
                            @{{ labels.trigger_stage }}
                            <select v-model.number="trigger.stage_id">
                                <option v-for="stage in stagesOf(trigger.pipeline_id)" :key="stage.id" :value="stage.id">@{{ stage.name }}</option>
                            </select>
                        </label>

                        <label class="as-field">
                            @{{ labels.trigger_kind }}
                            <select v-model="trigger.kind">
                                <option value="appointment">@{{ labels.trigger_kind_appointment }}</option>
                                <option value="followup">@{{ labels.trigger_kind_followup }}</option>
                            </select>
                        </label>

                        <div style="flex:none;min-width:0">
                            <button type="button" class="as-btn" @click="form.stage_triggers.splice(index, 1)">@{{ labels.trigger_remove }}</button>
                        </div>
                    </div>

                    <div>
                        <button type="button" class="as-btn" @click="addTrigger" :disabled="! pipelines.length">@{{ labels.trigger_add }}</button>
                    </div>
                </div>

                <div class="as-card">
                    <h2>@{{ labels.colors_title }}</h2>
                    <p class="as-muted">@{{ labels.colors_hint }}</p>

                    <div v-for="pipeline in colorPipelines" :key="pipeline.id" class="as-colors">
                        <h3>@{{ pipeline.name }}</h3>

                        <div class="as-color" v-for="stage in pipeline.stages" :key="stage.id">
                            <input type="color" :value="colorOf(stage.id)" @input="setColor(stage.id, $event.target.value)">
                            <span>@{{ stage.name }}</span>
                            <button type="button" class="as-btn" v-if="form.stage_colors[stage.id]" @click="resetColor(stage.id)">@{{ labels.colors_reset }}</button>
                            <span class="as-muted" v-else>@{{ labels.colors_default }}</span>
                        </div>
                    </div>
                </div>

                <div class="as-ok" v-if="saved">@{{ labels.saved }}</div>
                <div class="as-alert" v-if="problem">@{{ problem }}</div>

                <div>
                    <button type="submit" class="as-btn is-primary" :disabled="busy">@{{ labels.save }}</button>
                </div>
            </form>
        </script>

        <script type="module">
            app.component('v-agenda-settings', {
                template: '#v-agenda-settings-template',

                props: {
                    urls: { type: Object, required: true },
                    labels: { type: Object, required: true },
                    initial: { type: Object, required: true },
                    users: { type: Array, default: () => [] },
                    durations: { type: Array, default: () => [30] },
                    pipelines: { type: Array, default: () => [] },
                    colorPipelines: { type: Array, default: () => [] },
                },

                data() {
                    return {
                        form: JSON.parse(JSON.stringify(this.initial)),
                        busy: false,
                        saved: false,
                        problem: '',
                        presets: [
                            { key: 'health', label: this.labels.preset_health, singular: 'Consulta', plural: 'Consultas', gender: 'f', responsible: 'Profissional', responsible_plural: 'Profissionais' },
                            { key: 'demo', label: this.labels.preset_demo, singular: 'Call de demonstração', plural: 'Calls de demonstração', gender: 'f', responsible: 'Vendedor', responsible_plural: 'Vendedores' },
                            { key: 'meeting', label: this.labels.preset_meeting, singular: 'Reunião', plural: 'Reuniões', gender: 'f', responsible: 'Responsável', responsible_plural: 'Responsáveis' },
                            { key: 'neutral', label: this.labels.preset_neutral, singular: 'Agendamento', plural: 'Agendamentos', gender: 'm', responsible: 'Responsável', responsible_plural: 'Responsáveis' },
                        ],
                    };
                },

                methods: {
                    // What a stage looks like now: the chosen color, or the Agenda's built-in one.
                    colorOf(stageId) {
                        const borders = ['#2563eb', '#16a34a', '#d97706', '#dc2626', '#7c3aed', '#db2777', '#0891b2', '#ea580c'];

                        return this.form.stage_colors[stageId] || borders[Number(stageId) % borders.length];
                    },

                    setColor(stageId, color) {
                        this.form.stage_colors = { ...this.form.stage_colors, [stageId]: color };
                    },

                    resetColor(stageId) {
                        const colors = { ...this.form.stage_colors };

                        delete colors[stageId];

                        this.form.stage_colors = colors;
                    },

                    stagesOf(pipelineId) {
                        return this.pipelines.find(pipeline => pipeline.id === pipelineId)?.stages ?? [];
                    },

                    addTrigger() {
                        const pipeline = this.pipelines[0];

                        this.form.stage_triggers.push({ pipeline_id: pipeline.id, stage_id: pipeline.stages[0]?.id ?? null, kind: 'appointment' });
                    },

                    apply(preset) {
                        ['singular', 'plural', 'gender', 'responsible', 'responsible_plural'].forEach(key => { this.form[key] = preset[key]; });
                        this.saved = false;
                    },

                    async save() {
                        this.busy = true;
                        this.saved = false;
                        this.problem = '';

                        try {
                            await this.$axios.put(this.urls.save, this.form);

                            this.saved = true;
                        } catch (error) {
                            const body = error.response && error.response.data;

                            this.problem = (body && (body.message || Object.values(body.errors || {}).flat()[0])) || this.labels.error;
                        } finally {
                            this.busy = false;
                        }
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
