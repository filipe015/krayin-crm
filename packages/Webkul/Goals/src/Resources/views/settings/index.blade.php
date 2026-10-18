<x-admin::layouts>
    <x-slot:title>
        @lang('goals::app.ui.title')
    </x-slot>

    <v-goals-settings
        :urls="{{ Illuminate\Support\Js::from($urls) }}"
        :labels="{{ Illuminate\Support\Js::from(trans('goals::app.ui')) }}"
        :step-names="{{ Illuminate\Support\Js::from(trans('goals::app.steps')) }}"
        :weekday-names="{{ Illuminate\Support\Js::from(trans('goals::app.weekdays_short')) }}"
        initial-month="{{ $month }}"
        :can-edit="{{ $canEdit ? 'true' : 'false' }}"
    >
        <div class="p-6 text-sm text-gray-500">@lang('goals::app.ui.loading')</div>
    </v-goals-settings>

    @include('goals::settings._plan')
    @include('goals::settings._steps')
    @include('goals::settings._history')

    @pushOnce('scripts')
        <style>
            .gl{--g-bg:#fff;--g-fg:#111827;--g-muted:#6b7280;--g-border:#e5e7eb;--g-soft:#f3f4f6;--g-accent:#0e90d9;--g-good:#10b981;--g-warn:#f59e0b;--g-bad:#e11d48;display:flex;flex-direction:column;gap:16px;color:var(--g-fg);font-size:14px}
            .dark .gl{--g-bg:#111827;--g-fg:#e5e7eb;--g-muted:#9ca3af;--g-border:#1f2937;--g-soft:#1f2937}
            .gl *{box-sizing:border-box}
            .gl h1{margin:0;font-size:20px;font-weight:700}
            .gl h2{margin:0;font-size:15px;font-weight:600}
            .gl h3{margin:0 0 8px;font-size:12px;letter-spacing:.04em;text-transform:uppercase;color:var(--g-muted);font-weight:600}
            .gl-sub{margin:2px 0 0;font-size:12px;color:var(--g-muted)}
            .gl-bar{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;justify-content:space-between}
            .gl-tabs{display:flex;border:1px solid var(--g-border);border-radius:8px;overflow:hidden}
            .gl-tabs button{padding:8px 14px;border:0;background:var(--g-bg);color:inherit;font:inherit;cursor:pointer}
            .gl-tabs button.is-on{background:var(--g-accent);color:#fff}
            .gl-box{padding:16px 18px;border:1px solid var(--g-border);border-radius:10px;background:var(--g-bg)}
            .gl-box > header{margin-bottom:12px}
            .gl-row{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end}
            .gl-field{display:flex;flex-direction:column;gap:3px;font-size:12px;font-weight:600;color:var(--g-muted);min-width:150px;flex:1}
            .gl-field input,.gl-field select{padding:8px 10px;border:1px solid var(--g-border);border-radius:6px;background:var(--g-bg);color:var(--g-fg);font:inherit;font-weight:400;width:100%}
            .gl-field input:disabled,.gl-field select:disabled{opacity:.6}
            .gl-unit{display:flex;align-items:center;border:1px solid var(--g-border);border-radius:6px;background:var(--g-bg);overflow:hidden}
            .gl-unit span{padding:0 0 0 10px;color:var(--g-muted);font-weight:400}
            .gl-unit span.is-after{padding:0 10px 0 0}
            .gl-unit input{border:0;border-radius:0;outline:0;flex:1;min-width:0}
            .gl-btn{padding:8px 14px;border:1px solid var(--g-border);border-radius:6px;background:var(--g-bg);color:inherit;font:inherit;cursor:pointer}
            .gl-btn.is-primary{background:var(--g-accent);border-color:var(--g-accent);color:#fff}
            .gl-btn.is-danger{color:var(--g-bad);border-color:var(--g-bad)}
            .gl-btn:disabled{opacity:.5;cursor:default}
            .gl-link{background:none;border:0;padding:0;color:var(--g-accent);font:inherit;font-size:12px;cursor:pointer}
            .gl-chips{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
            .gl-chip{padding:6px 12px;border:1px solid var(--g-border);border-radius:999px;background:var(--g-bg);color:inherit;font:inherit;cursor:pointer}
            .gl-chip.is-on{background:var(--g-accent);border-color:var(--g-accent);color:#fff}
            .gl-note{padding:10px 12px;border:1px solid var(--g-border);border-radius:8px;font-size:12px;color:var(--g-muted)}
            .gl-note.is-error{border-color:var(--g-bad);color:var(--g-bad)}
            .gl-note.is-warn{border-color:var(--g-warn)}
            .gl-two{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px}
            .gl-chain{display:flex;flex-direction:column;gap:8px}
            .gl-step{display:grid;grid-template-columns:150px 1fr auto;gap:10px;align-items:center}
            .gl-step input{padding:8px 10px;border:1px solid var(--g-border);border-radius:6px;background:var(--g-bg);color:var(--g-fg);font:inherit;width:100%}
            .gl-step input.is-fixed{border-color:var(--g-accent)}
            .gl-badge{font-size:11px;color:var(--g-accent);font-weight:600}
            .gl-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-top:14px}
            .gl-card{padding:12px 14px;border:1px solid var(--g-border);border-radius:8px;background:var(--g-soft)}
            .gl-card span{display:block;font-size:11px;color:var(--g-muted)}
            .gl-card strong{display:block;font-size:18px;margin-top:2px}
            .gl-card small{color:var(--g-muted)}
            .gl-days{display:grid;grid-template-columns:repeat(auto-fill,minmax(54px,1fr));gap:6px}
            .gl-day{padding:6px 0;border:1px solid var(--g-border);border-radius:6px;background:var(--g-bg);color:inherit;font:inherit;text-align:center;cursor:pointer;line-height:1.2}
            .gl-day small{display:block;font-size:10px;color:var(--g-muted)}
            .gl-day.is-on{background:var(--g-accent);border-color:var(--g-accent);color:#fff}
            .gl-day.is-on small{color:#e0f2fe}
            .gl-wrap{overflow:auto}
            .gl table{width:100%;border-collapse:collapse;font-size:13px;white-space:nowrap}
            .gl th{text-align:right;padding:8px 10px;font-size:11px;letter-spacing:.03em;text-transform:uppercase;color:var(--g-muted);font-weight:600;border-bottom:1px solid var(--g-border)}
            .gl td{padding:8px 10px;text-align:right;border-bottom:1px solid var(--g-border)}
            .gl th:first-child,.gl td:first-child{text-align:left}
            .gl td.is-left,.gl th.is-left{text-align:left;white-space:normal}
            .gl tr.is-off td{color:var(--g-muted)}
            .gl-rule{padding:12px;border:1px solid var(--g-border);border-radius:8px;display:flex;flex-direction:column;gap:10px}
            .gl-checks{display:flex;gap:6px 14px;flex-wrap:wrap;max-height:160px;overflow:auto}
            .gl-checks label{display:flex;align-items:center;gap:6px;font-weight:400;color:var(--g-fg)}
            .gl-empty{padding:20px;text-align:center;color:var(--g-muted)}
        </style>

        <script type="text/x-template" id="v-goals-settings-template">
            <div class="gl">
                <div class="gl-bar">
                    <div>
                        <h1>@{{ labels.title }}</h1>
                        <p class="gl-sub">@{{ labels.subtitle }}</p>
                    </div>

                    <div class="gl-row">
                        <label class="gl-field" style="min-width:160px;flex:none" v-if="tab !== 'steps'">
                            @{{ labels.month }}
                            <input type="month" v-model="month" @change="load">
                        </label>

                        <div class="gl-tabs">
                            <button type="button" v-for="(name, key) in labels.tabs" :key="key" :class="{ 'is-on': tab === key }" @click="tab = key">@{{ name }}</button>
                        </div>
                    </div>
                </div>

                <p class="gl-note" v-if="! canEdit">@{{ labels.read_only }}</p>
                <p class="gl-note is-error" v-if="problem">@{{ problem }}</p>

                <template v-if="data">
                    <v-goals-plan v-if="tab === 'plan'" :data="data" :urls="urls" :labels="labels" :step-names="stepNames" :weekday-names="weekdayNames" :can-edit="canEdit" @update="data = $event"></v-goals-plan>

                    <v-goals-steps v-else-if="tab === 'steps'" :data="data" :urls="urls" :labels="labels" :weekday-names="weekdayNames" :can-edit="canEdit" @saved="load"></v-goals-steps>

                    <v-goals-history v-else :urls="urls" :labels="labels" :month="month"></v-goals-history>
                </template>

                <p class="gl-empty" v-else-if="! problem">@{{ labels.loading }}</p>
            </div>
        </script>

        <script type="module">
            app.component('v-goals-settings', {
                template: '#v-goals-settings-template',

                props: {
                    urls: { type: Object, required: true },
                    labels: { type: Object, required: true },
                    stepNames: { type: Object, required: true },
                    weekdayNames: { type: Object, required: true },
                    initialMonth: { type: String, required: true },
                    canEdit: { type: Boolean, default: false },
                },

                data() {
                    return { tab: 'plan', month: this.initialMonth, data: null, problem: '' };
                },

                mounted() {
                    this.load();
                },

                methods: {
                    async load() {
                        this.problem = '';

                        if (! /^\d{4}-\d{2}$/.test(this.month)) {
                            return;
                        }

                        try {
                            const { data } = await this.$axios.get(this.urls.data, { params: { month: this.month } });

                            this.data = data;
                        } catch (error) {
                            this.problem = this.labels.error;
                        }
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
