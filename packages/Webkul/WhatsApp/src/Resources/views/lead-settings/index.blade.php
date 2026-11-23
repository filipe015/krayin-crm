<x-admin::layouts>
    <x-slot:title>
        @lang('whatsapp::app.lead_settings.title')
    </x-slot>

    <v-whatsapp-lead-settings
        :urls="{{ Illuminate\Support\Js::from($urls) }}"
        :labels="{{ Illuminate\Support\Js::from(trans('whatsapp::app.lead_settings')) }}"
        :initial="{{ Illuminate\Support\Js::from($settings) }}"
        :initially-saved="{{ $saved ? 'true' : 'false' }}"
        :can-edit="{{ $canEdit ? 'true' : 'false' }}"
        :users="{{ Illuminate\Support\Js::from($users) }}"
        :pipelines="{{ Illuminate\Support\Js::from($pipelines) }}"
    >
        <div class="p-6 text-sm text-gray-500">@lang('whatsapp::app.agenda.loading')</div>
    </v-whatsapp-lead-settings>

    @pushOnce('scripts')
        <style>
            .wls{--wls-bg:#fff;--wls-fg:#111827;--wls-muted:#6b7280;--wls-border:#e5e7eb;--wls-soft:#f3f4f6;--wls-accent:#0e90d9;display:flex;flex-direction:column;gap:16px;max-width:760px;color:var(--wls-fg);font-size:14px}
            .dark .wls{--wls-bg:#111827;--wls-fg:#e5e7eb;--wls-muted:#9ca3af;--wls-border:#1f2937;--wls-soft:#1f2937}
            .wls *{box-sizing:border-box}
            .wls h1{margin:0;font-size:18px;font-weight:600}
            .wls-card{display:flex;flex-direction:column;gap:14px;padding:16px;border:1px solid var(--wls-border);border-radius:8px;background:var(--wls-bg)}
            .wls-card h2{margin:0;font-size:14px;font-weight:600}
            .wls-card.is-off{opacity:.6}
            .wls-muted{color:var(--wls-muted);font-size:12px;margin:0;font-weight:400}
            .wls-row{display:flex;gap:12px;flex-wrap:wrap}
            .wls-row>*{flex:1;min-width:200px}
            .wls-field{display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:600;color:var(--wls-muted)}
            .wls-field input[type=text],.wls-field select{width:100%;padding:8px 10px;border:1px solid var(--wls-border);border-radius:6px;background:var(--wls-bg);color:var(--wls-fg);font:inherit;font-weight:400}
            .wls-radio{display:flex;flex-direction:column;gap:8px;font-weight:400;color:var(--wls-fg)}
            .wls-radio label{display:flex;align-items:flex-start;gap:8px}
            .wls-radio input{margin-top:3px}
            .wls-btn{padding:7px 14px;border:1px solid var(--wls-border);border-radius:6px;background:var(--wls-bg);font:inherit;font-size:13px;font-weight:500;cursor:pointer;color:inherit}
            .wls-btn.is-primary{background:var(--wls-accent);border-color:var(--wls-accent);color:#fff}
            .wls-btn:disabled{opacity:.5;cursor:default}
            .wls-note{padding:10px 12px;border-radius:6px;background:var(--wls-soft);font-size:13px}
            .wls-alert{padding:10px 12px;border-radius:6px;background:#fee2e2;color:#991b1b;font-size:13px}
            .wls-ok{padding:10px 12px;border-radius:6px;background:#dcfce7;color:#14532d;font-size:13px}
        </style>

        <script type="text/x-template" id="v-whatsapp-lead-settings-template">
            <form class="wls" @submit.prevent="save">
                <h1>@{{ labels.title }}</h1>
                <p class="wls-muted">@{{ labels.intro }}</p>

                <div class="wls-note" v-if="! savedOnce">@{{ labels.env_notice }}</div>
                <div class="wls-note" v-if="! canEdit">@{{ labels.read_only }}</div>

                <div class="wls-card">
                    <h2>@{{ labels.mode }}</h2>

                    <div class="wls-radio">
                        <label><input type="radio" value="manual" v-model="form.mode" :disabled="! canEdit"> @{{ labels.mode_manual }}</label>
                        <label><input type="radio" value="ads" v-model="form.mode" :disabled="! canEdit"> @{{ labels.mode_ads }}</label>
                        <label><input type="radio" value="all" v-model="form.mode" :disabled="! canEdit"> @{{ labels.mode_all }}</label>
                    </div>
                </div>

                <div class="wls-card" :class="{ 'is-off': form.mode === 'manual' }">
                    <h2>@{{ labels.defaults }}</h2>
                    <p class="wls-muted">@{{ labels.defaults_hint }}</p>

                    <div class="wls-row">
                        <label class="wls-field">
                            @{{ labels.pipeline }}
                            <select v-model="form.pipeline_id" :disabled="locked" @change="form.stage_id = null">
                                <option :value="null">@{{ labels.pipeline_default }}</option>
                                <option v-for="pipeline in pipelines" :key="pipeline.id" :value="pipeline.id">@{{ pipeline.name }}</option>
                            </select>
                        </label>

                        <label class="wls-field">
                            @{{ labels.stage }}
                            <select v-model="form.stage_id" :disabled="locked || form.pipeline_id === null">
                                <option :value="null">@{{ labels.stage_first }}</option>
                                <option v-for="stage in stages" :key="stage.id" :value="stage.id">@{{ stage.name }}</option>
                            </select>
                        </label>
                    </div>

                    <div class="wls-row">
                        <label class="wls-field">
                            @{{ labels.source }}
                            <input type="text" v-model="form.source" maxlength="100" :disabled="locked">
                            <span class="wls-muted">@{{ labels.source_hint }}</span>
                        </label>

                        <label class="wls-field">
                            @{{ labels.tags }}
                            <input type="text" v-model="form.tags" maxlength="500" :disabled="locked">
                            <span class="wls-muted">@{{ labels.tags_hint }}</span>
                        </label>
                    </div>

                    <label class="wls-field">
                        @{{ labels.user }}
                        <select v-model="form.user_id" :disabled="locked">
                            <option :value="null">@{{ labels.user_none }}</option>
                            <option v-for="user in users" :key="user.id" :value="user.id">@{{ user.name }}</option>
                        </select>
                        <span class="wls-muted">@{{ labels.user_hint }}</span>
                    </label>
                </div>

                <div class="wls-ok" v-if="saved">@{{ labels.saved }}</div>
                <div class="wls-alert" v-if="problem">@{{ problem }}</div>

                <div v-if="canEdit">
                    <button type="submit" class="wls-btn is-primary" :disabled="busy">@{{ labels.save }}</button>
                </div>
            </form>
        </script>

        <script type="module">
            app.component('v-whatsapp-lead-settings', {
                template: '#v-whatsapp-lead-settings-template',

                props: {
                    urls: { type: Object, required: true },
                    labels: { type: Object, required: true },
                    initial: { type: Object, required: true },
                    initiallySaved: { type: Boolean, default: false },
                    canEdit: { type: Boolean, default: false },
                    users: { type: Array, default: () => [] },
                    pipelines: { type: Array, default: () => [] },
                },

                data() {
                    return {
                        form: JSON.parse(JSON.stringify(this.initial)),
                        savedOnce: this.initiallySaved,
                        busy: false,
                        saved: false,
                        problem: '',
                    };
                },

                computed: {
                    // The defaults only matter when leads are created by themselves.
                    locked() {
                        return ! this.canEdit || this.form.mode === 'manual';
                    },

                    stages() {
                        return this.pipelines.find(pipeline => pipeline.id === this.form.pipeline_id)?.stages ?? [];
                    },
                },

                methods: {
                    async save() {
                        this.busy = true;
                        this.saved = false;
                        this.problem = '';

                        try {
                            await this.$axios.put(this.urls.save, this.form);

                            this.saved = true;
                            this.savedOnce = true;
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
