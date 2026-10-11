<x-admin::layouts>
    <x-slot:title>
        @lang('whatsapp::app.quick_replies.title')
    </x-slot>

    <v-whatsapp-quick-replies
        :urls="{{ Illuminate\Support\Js::from($urls) }}"
        :labels="{{ Illuminate\Support\Js::from(trans('whatsapp::app.quick_replies')) }}"
    >
        <div class="p-6 text-sm text-gray-500">
            @lang('whatsapp::app.quick_replies.loading')
        </div>
    </v-whatsapp-quick-replies>

    @pushOnce('scripts')
        <style>
            .wa-qr{--wa-bg:#fff;--wa-fg:#111827;--wa-muted:#6b7280;--wa-border:#e5e7eb;--wa-soft:#f3f4f6;--wa-accent:#0e90d9;display:flex;flex-direction:column;gap:16px;color:var(--wa-fg);font-size:14px}
            .dark .wa-qr{--wa-bg:#111827;--wa-fg:#e5e7eb;--wa-muted:#9ca3af;--wa-border:#1f2937;--wa-soft:#1f2937}
            .wa-qr *{box-sizing:border-box}
            .wa-qr button{cursor:pointer;font:inherit;color:inherit}
            .wa-qr h1{margin:0;font-size:18px;font-weight:600}
            .wa-qr-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
            .wa-qr-muted{color:var(--wa-muted);font-size:12px}
            .wa-qr-card{display:flex;flex-direction:column;gap:10px;padding:14px;border:1px solid var(--wa-border);border-radius:8px;background:var(--wa-bg)}
            .wa-qr-row{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
            .wa-qr-code{padding:1px 8px;border-radius:10px;background:var(--wa-soft);font-family:monospace;font-size:12px}
            .wa-qr-body{margin:4px 0 0;white-space:pre-wrap;word-break:break-word;color:var(--wa-muted)}
            .wa-qr-btn{padding:6px 12px;border:1px solid var(--wa-border);border-radius:6px;background:var(--wa-bg);font-size:13px}
            .wa-qr-btn:hover{background:var(--wa-soft)}
            .wa-qr-btn:disabled{opacity:.5;cursor:default}
            .wa-qr-btn.is-primary{border-color:var(--wa-accent);background:var(--wa-accent);color:#fff}
            .wa-qr-btn.is-danger{border-color:#dc2626;color:#dc2626}
            .wa-qr-form{display:flex;flex-direction:column;gap:10px}
            .wa-qr-form label{display:flex;flex-direction:column;gap:4px;font-size:12px;color:var(--wa-muted)}
            .wa-qr-form input,.wa-qr-form textarea{padding:7px 10px;border:1px solid var(--wa-border);border-radius:6px;background:var(--wa-bg);color:var(--wa-fg);font:inherit}
            .wa-qr-form textarea{min-height:110px;resize:vertical}
            .wa-qr-error{padding:8px 10px;border:1px solid #fecaca;border-radius:6px;background:#fef2f2;color:#991b1b;font-size:13px}
        </style>

        <script type="text/x-template" id="v-whatsapp-quick-replies-template">
            <div class="wa-qr">
                <div class="wa-qr-head">
                    <h1>@{{ labels.title }}</h1>
                    <div style="display:flex;gap:8px">
                        <a :href="urls.inbox" class="wa-qr-btn">@{{ labels.back }}</a>
                        <button type="button" class="wa-qr-btn is-primary" @click="startNew">@{{ labels.new }}</button>
                    </div>
                </div>

                <p class="wa-qr-muted" style="margin:0">@{{ labels.intro }}</p>

                <div v-if="error" class="wa-qr-error">@{{ error }}</div>

                <form v-if="form" class="wa-qr-card wa-qr-form" @submit.prevent="save">
                    <label>
                        @{{ labels.shortcut }} <span>(@{{ labels.shortcut_hint }})</span>
                        <input type="text" v-model="form.shortcut" maxlength="30" placeholder="ola" required>
                    </label>

                    <label>
                        @{{ labels.name }}
                        <input type="text" v-model="form.title" maxlength="80" required>
                    </label>

                    <label>
                        @{{ labels.text }}
                        <textarea v-model="form.body" maxlength="4096" required></textarea>
                    </label>

                    <div style="display:flex;gap:8px">
                        <button type="submit" class="wa-qr-btn is-primary" :disabled="busy">@{{ labels.save }}</button>
                        <button type="button" class="wa-qr-btn" @click="form = null">@{{ labels.cancel }}</button>
                    </div>
                </form>

                <div v-if="loading" class="wa-qr-muted">@{{ labels.loading }}</div>
                <div v-else-if="! replies.length" class="wa-qr-muted">@{{ labels.empty }}</div>

                <div v-for="reply in replies" :key="reply.id" class="wa-qr-card">
                    <div class="wa-qr-row">
                        <div>
                            <span class="wa-qr-code">/@{{ reply.shortcut }}</span>
                            <strong style="margin-left:8px">@{{ reply.title }}</strong>
                            <p class="wa-qr-body">@{{ reply.body }}</p>
                        </div>

                        <div style="display:flex;gap:8px;flex:none">
                            <button type="button" class="wa-qr-btn" :disabled="busy" @click="startEdit(reply)">@{{ labels.edit }}</button>
                            <button type="button" class="wa-qr-btn is-danger" :disabled="busy" @click="remove(reply)">@{{ labels.delete }}</button>
                        </div>
                    </div>
                </div>
            </div>
        </script>

        <script type="module">
            app.component('v-whatsapp-quick-replies', {
                template: '#v-whatsapp-quick-replies-template',

                props: {
                    urls: { type: Object, required: true },
                    labels: { type: Object, required: true },
                },

                data() {
                    return { replies: [], loading: true, busy: false, error: null, form: null };
                },

                mounted() {
                    this.load();
                },

                methods: {
                    async load() {
                        this.loading = true;

                        try {
                            const { data } = await this.$axios.get(this.urls.list);

                            this.replies = data.data;
                        } catch (error) {
                            this.error = this.labels.error;
                        } finally {
                            this.loading = false;
                        }
                    },

                    startNew() {
                        this.error = null;
                        this.form = { id: null, shortcut: '', title: '', body: '' };
                    },

                    startEdit(reply) {
                        this.error = null;
                        this.form = { ...reply };
                    },

                    async save() {
                        this.busy = true;
                        this.error = null;

                        const payload = { shortcut: this.form.shortcut, title: this.form.title, body: this.form.body };

                        try {
                            if (this.form.id) {
                                await this.$axios.put(this.urls.update.replace('__ID__', this.form.id), payload);
                            } else {
                                await this.$axios.post(this.urls.store, payload);
                            }

                            this.form = null;
                            await this.load();
                        } catch (error) {
                            const errors = error.response && error.response.data && error.response.data.errors;

                            this.error = errors ? Object.values(errors).flat()[0] : this.labels.error;
                        } finally {
                            this.busy = false;
                        }
                    },

                    async remove(reply) {
                        if (! window.confirm(this.labels.delete_confirm)) {
                            return;
                        }

                        this.busy = true;

                        try {
                            await this.$axios.delete(this.urls.delete.replace('__ID__', reply.id));
                            await this.load();
                        } catch (error) {
                            this.error = this.labels.error;
                        } finally {
                            this.busy = false;
                        }
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
