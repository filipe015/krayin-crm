<x-admin::layouts>
    <x-slot:title>
        @lang('whatsapp::app.templates.title')
    </x-slot>

    <v-whatsapp-templates
        :urls="{{ Illuminate\Support\Js::from($urls) }}"
        :labels="{{ Illuminate\Support\Js::from(trans('whatsapp::app.templates')) }}"
        :categories="{{ Illuminate\Support\Js::from($categories) }}"
        :languages="{{ Illuminate\Support\Js::from($languages) }}"
    >
        <div class="p-6 text-sm text-gray-500">
            @lang('whatsapp::app.templates.loading')
        </div>
    </v-whatsapp-templates>

    @pushOnce('scripts')
        <style>
            .wa-tpl{--wa-bg:#fff;--wa-fg:#111827;--wa-muted:#6b7280;--wa-border:#e5e7eb;--wa-soft:#f3f4f6;--wa-accent:#0e90d9;display:flex;flex-direction:column;gap:16px;color:var(--wa-fg);font-size:14px}
            .dark .wa-tpl{--wa-bg:#111827;--wa-fg:#e5e7eb;--wa-muted:#9ca3af;--wa-border:#1f2937;--wa-soft:#1f2937}
            .wa-tpl *{box-sizing:border-box}
            .wa-tpl button{cursor:pointer;font:inherit;color:inherit}
            .wa-tpl h1{margin:0;font-size:18px;font-weight:600}
            .wa-tpl-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
            .wa-tpl-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
            .wa-tpl-muted{color:var(--wa-muted);font-size:12px}
            .wa-tpl-card{display:flex;flex-direction:column;gap:10px;padding:14px;border:1px solid var(--wa-border);border-radius:8px;background:var(--wa-bg)}
            .wa-tpl-name{font-size:15px;font-weight:600;word-break:break-all}
            .wa-tpl-body{margin:0;padding:10px;border-radius:8px;background:var(--wa-soft);white-space:pre-wrap;word-break:break-word;font-size:13px}
            .wa-tpl-btn{padding:6px 12px;border:1px solid var(--wa-border);border-radius:6px;background:var(--wa-bg);font-size:13px;text-decoration:none;color:inherit}
            .wa-tpl-btn:hover{background:var(--wa-soft)}
            .wa-tpl-btn:disabled{opacity:.5;cursor:default}
            .wa-tpl-btn.is-primary{border-color:var(--wa-accent);background:var(--wa-accent);color:#fff}
            .wa-tpl-form{display:flex;flex-direction:column;gap:10px}
            .wa-tpl-form label{display:flex;flex-direction:column;gap:4px;font-size:12px;color:var(--wa-muted)}
            .wa-tpl-form input,.wa-tpl-form textarea,.wa-tpl select{padding:7px 10px;border:1px solid var(--wa-border);border-radius:6px;background:var(--wa-bg);color:var(--wa-fg);font:inherit}
            .wa-tpl-form textarea{min-height:120px;resize:vertical}
            .wa-tpl-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px}
            .wa-tpl-pill{display:inline-block;padding:2px 10px;border-radius:10px;font-size:12px;font-weight:600;background:var(--wa-soft);color:var(--wa-muted);white-space:nowrap}
            .wa-tpl-pill.is-ok{background:#dcfce7;color:#14532d}
            .wa-tpl-pill.is-wait{background:#fef3c7;color:#92400e}
            .wa-tpl-pill.is-bad{background:#fee2e2;color:#991b1b}
            .wa-tpl-error{padding:10px 12px;border:1px solid #fecaca;border-radius:6px;background:#fef2f2;color:#991b1b;font-size:13px}
            .wa-tpl-ok{padding:10px 12px;border:1px solid #bbf7d0;border-radius:6px;background:#f0fdf4;color:#14532d;font-size:13px}
        </style>

        <script type="text/x-template" id="v-whatsapp-templates-template">
            <div class="wa-tpl">
                <div class="wa-tpl-head">
                    <h1>@{{ labels.title }}</h1>

                    <div class="wa-tpl-actions">
                        <a :href="urls.inbox" class="wa-tpl-btn">@{{ labels.back }}</a>
                        <button type="button" class="wa-tpl-btn" :disabled="loading" @click="load()">@{{ labels.refresh }}</button>
                        <button type="button" class="wa-tpl-btn is-primary" :disabled="! sessionId" @click="startNew">@{{ labels.new }}</button>
                    </div>
                </div>

                <p class="wa-tpl-muted" style="margin:0">@{{ labels.intro }}</p>

                <div v-if="error" class="wa-tpl-error">@{{ error }}</div>
                <div v-if="success" class="wa-tpl-ok">@{{ success }}</div>

                <div v-if="! loading && ! numbers.length" class="wa-tpl-card">
                    <span>@{{ labels.no_number }}</span>
                    <div><a :href="urls.connections" class="wa-tpl-btn">@{{ labels.connections }}</a></div>
                </div>

                <label v-if="numbers.length > 1" class="wa-tpl-actions wa-tpl-muted">
                    @{{ labels.number }}
                    <select v-model="sessionId" @change="load()">
                        <option v-for="number in numbers" :key="number.id" :value="number.id">@{{ number.name }}@{{ number.phone ? ' (+' + number.phone + ')' : '' }}</option>
                    </select>
                </label>

                <form v-if="form" class="wa-tpl-card wa-tpl-form" @submit.prevent="save">
                    <div class="wa-tpl-grid">
                        <label>
                            @{{ labels.name }} <span>(@{{ labels.name_hint }})</span>
                            <input type="text" v-model="form.name" maxlength="512" pattern="[a-z0-9_]+" required>
                        </label>

                        <label>
                            @{{ labels.category }}
                            <select v-model="form.category" required>
                                <option v-for="category in categories" :key="category" :value="category">@{{ labels.categories[category] || category }}</option>
                            </select>
                        </label>

                        <label>
                            @{{ labels.language }}
                            <select v-model="form.language" required>
                                <option v-for="language in languages" :key="language" :value="language">@{{ language }}</option>
                            </select>
                        </label>
                    </div>

                    <label>
                        @{{ labels.header }}
                        <input type="text" v-model="form.header" maxlength="60">
                    </label>

                    <label>
                        @{{ labels.body }} <span>@{{ labels.body_hint }}</span>
                        <textarea v-model="form.body" maxlength="1024" required></textarea>
                    </label>

                    <template v-if="variableCount">
                        <span class="wa-tpl-muted">@{{ labels.examples_help }}</span>

                        <div class="wa-tpl-grid">
                            <label v-for="n in variableCount" :key="n">
                                @{{ labels.example.replace(':n', n) }}
                                <input type="text" v-model="form.examples[n - 1]" maxlength="200" required>
                            </label>
                        </div>
                    </template>

                    <label>
                        @{{ labels.footer }}
                        <input type="text" v-model="form.footer" maxlength="60">
                    </label>

                    <template v-if="form.body.trim()">
                        <span class="wa-tpl-muted">@{{ labels.preview }}</span>
                        <pre class="wa-tpl-body">@{{ preview }}</pre>
                    </template>

                    <div class="wa-tpl-actions">
                        <button type="submit" class="wa-tpl-btn is-primary" :disabled="busy">@{{ labels.create }}</button>
                        <button type="button" class="wa-tpl-btn" @click="form = null">@{{ labels.cancel }}</button>
                    </div>
                </form>

                <div v-if="loading" class="wa-tpl-muted">@{{ labels.loading }}</div>
                <div v-else-if="numbers.length && ! templates.length && ! error" class="wa-tpl-muted">@{{ labels.empty }}</div>

                <div v-for="template in templates" :key="template.id || (template.name + template.language)" class="wa-tpl-card">
                    <div class="wa-tpl-head">
                        <div>
                            <div class="wa-tpl-name">@{{ template.name }}</div>
                            <div class="wa-tpl-muted">
                                @{{ template.language }} · @{{ labels.categories[template.category] || template.category }}
                                <span v-if="template.variables"> · @{{ template.variables }} @{{ labels.variables }}</span>
                            </div>
                        </div>

                        <span class="wa-tpl-pill" :class="pillClass(template.status)">@{{ labels.statuses[template.status] || template.status }}</span>
                    </div>

                    <div v-if="template.header" style="font-weight:600">@{{ template.header }}</div>
                    <pre class="wa-tpl-body">@{{ template.body }}</pre>
                    <div v-if="template.footer" class="wa-tpl-muted">@{{ template.footer }}</div>
                    <div v-if="template.reason" class="wa-tpl-muted" style="color:#b91c1c">@{{ labels.reason }}: @{{ template.reason }}</div>
                </div>
            </div>
        </script>

        <script type="module">
            app.component('v-whatsapp-templates', {
                template: '#v-whatsapp-templates-template',

                props: {
                    urls: { type: Object, required: true },
                    labels: { type: Object, required: true },
                    categories: { type: Array, default: () => [] },
                    languages: { type: Array, default: () => [] },
                },

                data() {
                    return {
                        numbers: [],
                        sessionId: null,
                        templates: [],
                        form: null,
                        loading: true,
                        busy: false,
                        error: null,
                        success: null,
                    };
                },

                mounted() {
                    this.load();
                },

                computed: {
                    variableCount() {
                        const found = [...(this.form ? this.form.body : '').matchAll(/\{\{\s*(\d+)\s*\}\}/g)].map(match => Number(match[1]));

                        return found.length ? Math.min(Math.max(...found), 20) : 0;
                    },

                    preview() {
                        const text = this.form.body.replace(/\{\{\s*(\d+)\s*\}\}/g, (match, n) => this.form.examples[n - 1] || match);

                        return [this.form.header, text, this.form.footer].filter(part => part && part.trim()).join('\n\n');
                    },
                },

                methods: {
                    pillClass(status) {
                        if (status === 'APPROVED') {
                            return 'is-ok';
                        }

                        return ['PENDING', 'IN_APPEAL'].includes(status) ? 'is-wait' : 'is-bad';
                    },

                    message(error) {
                        const data = error.response && error.response.data;

                        return (data && (Object.values(data.errors || {}).flat()[0] || data.message)) || this.labels.generic_error;
                    },

                    async load() {
                        this.loading = true;
                        this.error = null;

                        try {
                            const { data } = await this.$axios.get(this.urls.list, { params: { session_id: this.sessionId } });

                            this.apply(data);
                        } catch (error) {
                            if (error.response && error.response.data && error.response.data.numbers) {
                                this.apply(error.response.data);
                            }

                            this.error = this.message(error);
                        } finally {
                            this.loading = false;
                        }
                    },

                    apply(data) {
                        this.numbers = data.numbers || [];
                        this.sessionId = data.session_id;
                        this.templates = data.data || [];
                    },

                    startNew() {
                        this.success = null;
                        this.error = null;
                        this.form = { name: '', category: this.categories[0], language: this.languages[0], header: '', body: '', footer: '', examples: [] };
                    },

                    async save() {
                        this.busy = true;
                        this.error = null;
                        this.success = null;

                        try {
                            const { data } = await this.$axios.post(this.urls.store, {
                                ...this.form,
                                session_id: this.sessionId,
                                examples: this.form.examples.slice(0, this.variableCount),
                            });

                            this.form = null;
                            this.success = this.labels.created
                                .replace(':name', data.data.name)
                                .replace(':status', this.labels.statuses[data.data.status] || data.data.status);

                            await this.load();
                        } catch (error) {
                            this.error = this.message(error);
                        } finally {
                            this.busy = false;
                        }
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
