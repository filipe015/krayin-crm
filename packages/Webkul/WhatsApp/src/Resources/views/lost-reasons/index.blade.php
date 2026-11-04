<x-admin::layouts>
    <x-slot:title>
        @lang('whatsapp::app.lost_reasons.title')
    </x-slot>

    <v-lost-reasons
        :urls="{{ Illuminate\Support\Js::from($urls) }}"
        :labels="{{ Illuminate\Support\Js::from(trans('whatsapp::app.lost_reasons')) }}"
        :initial="{{ Illuminate\Support\Js::from($reasons) }}"
        :pipelines="{{ Illuminate\Support\Js::from($pipelines) }}"
    >
        <div class="p-6 text-sm text-gray-500">...</div>
    </v-lost-reasons>

    @pushOnce('scripts')
        <style>
            .lr{--lr-bg:#fff;--lr-fg:#111827;--lr-muted:#6b7280;--lr-border:#e5e7eb;--lr-soft:#f3f4f6;--lr-accent:#0e90d9;display:flex;flex-direction:column;gap:16px;max-width:640px;color:var(--lr-fg);font-size:14px}
            .dark .lr{--lr-bg:#111827;--lr-fg:#e5e7eb;--lr-muted:#9ca3af;--lr-border:#1f2937;--lr-soft:#1f2937}
            .lr *{box-sizing:border-box}
            .lr h1{margin:0;font-size:18px;font-weight:600}
            .lr-muted{color:var(--lr-muted);font-size:12px;margin:0}
            .lr-card{display:flex;flex-direction:column;gap:10px;padding:16px;border:1px solid var(--lr-border);border-radius:8px;background:var(--lr-bg)}
            .lr-field{display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:600;color:var(--lr-muted)}
            .lr input[type=text],.lr select{width:100%;padding:8px 10px;border:1px solid var(--lr-border);border-radius:6px;background:var(--lr-bg);color:var(--lr-fg);font:inherit;font-weight:400}
            .lr-row{display:flex;gap:8px;align-items:center}
            .lr-row input[type=text]{flex:1}
            .lr-check{display:flex;align-items:center;gap:4px;font-size:12px;white-space:nowrap}
            .lr-btn{padding:7px 12px;border:1px solid var(--lr-border);border-radius:6px;background:var(--lr-bg);font:inherit;font-size:13px;font-weight:500;cursor:pointer;color:inherit;white-space:nowrap}
            .lr-btn:hover{background:var(--lr-soft)}
            .lr-btn.is-primary{background:var(--lr-accent);border-color:var(--lr-accent);color:#fff}
            .lr-btn:disabled{opacity:.5;cursor:default}
            .lr-alert{padding:8px 10px;border-radius:6px;background:#fee2e2;color:#991b1b;font-size:13px}
            .lr-ok{font-size:12px;color:#15803d}
        </style>

        <script type="text/x-template" id="v-lost-reasons-template">
            <div class="lr">
                <h1>@{{ labels.title }}</h1>
                <p class="lr-muted">@{{ labels.intro }}</p>

                <label class="lr-field">
                    @{{ labels.pipeline }}
                    <select v-model.number="pipelineId">
                        <option v-for="pipeline in pipelines" :key="pipeline.id" :value="pipeline.id">@{{ pipeline.name }}</option>
                    </select>
                </label>

                <div class="lr-card">
                    <p class="lr-muted" v-if="! visible.length">@{{ labels.none }}</p>

                    <form class="lr-row" v-for="reason in visible" :key="reason.id" @submit.prevent="save(reason)">
                        <input type="text" v-model="reason.name" maxlength="120" required>
                        <label class="lr-check"><input type="checkbox" v-model="reason.is_active" @change="save(reason)"> @{{ labels.active }}</label>
                        <button class="lr-btn" type="submit" :disabled="reason.busy">@{{ labels.save }}</button>
                        <button class="lr-btn" type="button" :disabled="reason.busy" @click="remove(reason)">@{{ labels.delete }}</button>
                        <span class="lr-ok" v-if="reason.saved">@{{ labels.saved }}</span>
                    </form>

                    <div class="lr-alert" v-if="problem">@{{ problem }}</div>

                    <form class="lr-row" @submit.prevent="add">
                        <input type="text" v-model="draft" maxlength="120" :placeholder="labels.new" required>
                        <button class="lr-btn is-primary" type="submit" :disabled="adding">@{{ labels.add }}</button>
                    </form>
                </div>
            </div>
        </script>

        <script type="module">
            app.component('v-lost-reasons', {
                template: '#v-lost-reasons-template',

                props: {
                    urls: { type: Object, required: true },
                    labels: { type: Object, required: true },
                    initial: { type: Array, default: () => [] },
                    pipelines: { type: Array, default: () => [] },
                },

                data() {
                    return {
                        pipelineId: this.pipelines[0]?.id ?? null,
                        reasons: this.initial.map(reason => ({ ...reason, busy: false, saved: false })),
                        draft: '',
                        adding: false,
                        problem: '',
                    };
                },

                computed: {
                    visible() {
                        return this.reasons.filter(reason => reason.lead_pipeline_id === this.pipelineId);
                    },
                },

                methods: {
                    fail(error) {
                        const body = error.response && error.response.data;

                        this.problem = (body && (body.message || Object.values(body.errors || {}).flat()[0])) || this.labels.error;
                    },

                    async add() {
                        this.adding = true;
                        this.problem = '';

                        try {
                            const response = await this.$axios.post(this.urls.store, { lead_pipeline_id: this.pipelineId, name: this.draft, is_active: true });

                            this.reasons.push({ ...response.data.data, busy: false, saved: false });
                            this.draft = '';
                        } catch (error) {
                            this.fail(error);
                        } finally {
                            this.adding = false;
                        }
                    },

                    async save(reason) {
                        reason.busy = true;
                        reason.saved = false;
                        this.problem = '';

                        try {
                            await this.$axios.put(this.urls.update.replace('__ID__', reason.id), { name: reason.name, is_active: reason.is_active });

                            reason.saved = true;
                        } catch (error) {
                            this.fail(error);
                        } finally {
                            reason.busy = false;
                        }
                    },

                    async remove(reason) {
                        if (! window.confirm(this.labels.confirm_delete)) {
                            return;
                        }

                        reason.busy = true;

                        try {
                            await this.$axios.delete(this.urls.delete.replace('__ID__', reason.id));

                            this.reasons = this.reasons.filter(item => item.id !== reason.id);
                        } catch (error) {
                            reason.busy = false;
                            this.fail(error);
                        }
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
