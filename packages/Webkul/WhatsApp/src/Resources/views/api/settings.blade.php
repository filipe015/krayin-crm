<x-admin::layouts>
    <x-slot:title>
        @lang('whatsapp::app.api_settings.title')
    </x-slot>

    <v-api-settings
        :urls="{{ Illuminate\Support\Js::from($urls) }}"
        :labels="{{ Illuminate\Support\Js::from(trans('whatsapp::app.api_settings')) }}"
        :initial-keys="{{ Illuminate\Support\Js::from($keys) }}"
        :initial-webhooks="{{ Illuminate\Support\Js::from($webhooks) }}"
        :events="{{ Illuminate\Support\Js::from($events) }}"
        base-url="{{ $baseUrl }}"
    >
        <div class="p-6 text-sm text-gray-500">@lang('whatsapp::app.agenda.loading')</div>
    </v-api-settings>

    @if ($docs !== '')
        <details class="api-docs" open style="max-width:900px;margin-top:16px">
            <summary>@lang('whatsapp::app.api_settings.docs')</summary>

            {{-- Rendered Markdown (API.md): Vue must not compile it. --}}
            <div class="api-docs-body" v-pre>{!! $docs !!}</div>
        </details>
    @endif

    @pushOnce('scripts')
        <style>
            .api-docs{border:1px solid #e5e7eb;border-radius:8px;background:#fff;padding:0 16px;font-size:14px;color:#111827}
            .dark .api-docs{background:#111827;border-color:#1f2937;color:#e5e7eb}
            .api-docs summary{cursor:pointer;padding:14px 0;font-size:14px;font-weight:600}
            .api-docs-body{padding-bottom:16px;line-height:1.5}
            .api-docs-body h1{font-size:18px;font-weight:600;margin:4px 0 10px}
            .api-docs-body h2{font-size:15px;font-weight:600;margin:22px 0 8px;padding-top:6px;border-top:1px solid #e5e7eb}
            .dark .api-docs-body h2{border-color:#1f2937}
            .api-docs-body p,.api-docs-body ul,.api-docs-body ol{margin:8px 0}
            .api-docs-body ul,.api-docs-body ol{padding-left:22px}
            .api-docs-body ul{list-style:disc}
            .api-docs-body table{display:block;overflow:auto;border-collapse:collapse;font-size:13px;margin:8px 0}
            .api-docs-body th,.api-docs-body td{border:1px solid #e5e7eb;padding:6px 8px;text-align:left;vertical-align:top}
            .dark .api-docs-body th,.dark .api-docs-body td{border-color:#1f2937}
            .api-docs-body th{background:#f3f4f6;font-weight:600}
            .dark .api-docs-body th{background:#1f2937}
            .api-docs-body code{font-size:12px;background:#f3f4f6;padding:1px 5px;border-radius:4px;word-break:break-word}
            .dark .api-docs-body code{background:#1f2937}
            .api-docs-body pre{margin:8px 0;padding:10px 12px;border-radius:6px;background:#f3f4f6;overflow:auto}
            .dark .api-docs-body pre{background:#1f2937}
            .api-docs-body pre code{background:none;padding:0}
            .api-docs-body a{color:#0e90d9}
            .api{--a-bg:#fff;--a-fg:#111827;--a-muted:#6b7280;--a-border:#e5e7eb;--a-soft:#f3f4f6;--a-accent:#0e90d9;display:flex;flex-direction:column;gap:16px;max-width:900px;color:var(--a-fg);font-size:14px}
            .dark .api{--a-bg:#111827;--a-fg:#e5e7eb;--a-muted:#9ca3af;--a-border:#1f2937;--a-soft:#1f2937}
            .api *{box-sizing:border-box}
            .api h1{margin:0;font-size:18px;font-weight:600}
            .api h2{margin:0;font-size:14px;font-weight:600}
            .api-card{display:flex;flex-direction:column;gap:12px;padding:16px;border:1px solid var(--a-border);border-radius:8px;background:var(--a-bg)}
            .api-muted{color:var(--a-muted);font-size:12px;margin:0}
            .api-row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
            .api-row>*{flex:1;min-width:180px}
            .api-field{display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:600;color:var(--a-muted)}
            .api-field input[type=text],.api-field input[type=url],.api-field select{width:100%;padding:8px 10px;border:1px solid var(--a-border);border-radius:6px;background:var(--a-bg);color:var(--a-fg);font:inherit;font-weight:400}
            .api-btn{padding:7px 14px;border:1px solid var(--a-border);border-radius:6px;background:var(--a-bg);font:inherit;font-size:13px;font-weight:500;cursor:pointer;color:inherit}
            .api-btn:hover{background:var(--a-soft)}
            .api-btn.is-primary{background:var(--a-accent);border-color:var(--a-accent);color:#fff}
            .api-btn.is-danger{border-color:#dc2626;color:#dc2626}
            .api-btn:disabled{opacity:.5;cursor:default}
            .api-table{width:100%;border-collapse:collapse;font-size:13px}
            .api-table th{text-align:left;font-size:12px;color:var(--a-muted);font-weight:600;padding:6px 8px;border-bottom:1px solid var(--a-border)}
            .api-table td{padding:8px;border-bottom:1px solid var(--a-border);vertical-align:top}
            .api-pill{display:inline-block;padding:1px 8px;border-radius:999px;background:var(--a-soft);font-size:11px;margin-right:4px}
            .api-pill.is-ok{background:#dcfce7;color:#14532d}
            .api-pill.is-bad{background:#fee2e2;color:#991b1b}
            .api-secret{display:flex;gap:8px;align-items:center;padding:10px 12px;border:1px solid #f59e0b;border-radius:6px;background:#fffbeb;color:#78350f}
            .api-secret code{flex:1;word-break:break-all;font-size:12px}
            .api-events{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:6px}
            .api-events label{display:flex;align-items:center;gap:6px;font-weight:400;color:var(--a-fg)}
            .api pre{margin:0;padding:10px 12px;border-radius:6px;background:var(--a-soft);font-size:12px;overflow:auto}
            .api-alert{padding:10px 12px;border-radius:6px;background:#fee2e2;color:#991b1b;font-size:13px}
        </style>

        <script type="text/x-template" id="v-api-settings-template">
            <div class="api">
                <h1>@{{ labels.title }}</h1>
                <p class="api-muted">@{{ labels.intro }}</p>

                <div class="api-alert" v-if="problem">@{{ problem }}</div>

                <!-- One-time secret -->
                <div class="api-secret" v-if="secret">
                    <div style="flex:1">
                        <strong>@{{ secret.label }}</strong>
                        <div class="api-muted" style="color:inherit">@{{ labels.secret_once }}</div>
                        <code>@{{ secret.value }}</code>
                    </div>
                    <button type="button" class="api-btn" @click="copy(secret.value)">@{{ copied ? labels.copied : labels.copy }}</button>
                    <button type="button" class="api-btn" @click="secret = null">@{{ labels.close }}</button>
                </div>

                <!-- Keys -->
                <div class="api-card">
                    <h2>@{{ labels.keys }}</h2>
                    <p class="api-muted">@{{ labels.keys_hint }}</p>

                    <table class="api-table" v-if="keys.length">
                        <thead><tr><th>@{{ labels.name }}</th><th>@{{ labels.key }}</th><th>@{{ labels.permissions }}</th><th>@{{ labels.last_used }}</th><th></th></tr></thead>
                        <tbody>
                            <tr v-for="item in keys" :key="item.id" :style="item.revoked ? 'opacity:.5' : ''">
                                <td>@{{ item.name }}<div class="api-muted">@{{ item.created_at }}</div></td>
                                <td><code>@{{ item.prefix }}…</code></td>
                                <td><span class="api-pill" v-for="ability in item.abilities" :key="ability">@{{ ability === 'delete' ? labels.can_delete : (ability === 'write' ? labels.can_write : labels.can_read) }}</span></td>
                                <td>@{{ item.last_used_at || '—' }}<div class="api-muted" v-if="item.last_used_ip">@{{ item.last_used_ip }}</div></td>
                                <td style="text-align:right">
                                    <span v-if="item.revoked" class="api-pill is-bad">@{{ labels.revoked }}</span>
                                    <button v-else-if="canEdit" type="button" class="api-btn is-danger" @click="revoke(item)">@{{ labels.revoke }}</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="api-muted" v-else>@{{ labels.no_keys }}</p>

                    <form class="api-row" v-if="canEdit" @submit.prevent="createKey">
                        <label class="api-field">@{{ labels.name }}<input type="text" v-model="newKey.name" maxlength="80" :placeholder="labels.key_name_ph" required></label>
                        <label class="api-field">@{{ labels.permissions }}
                            <select v-model="newKey.mode">
                                <option value="read">@{{ labels.mode_read }}</option>
                                <option value="write">@{{ labels.mode_write }}</option>
                                <option value="delete">@{{ labels.mode_delete }}</option>
                            </select>
                        </label>
                        <div><button type="submit" class="api-btn is-primary" :disabled="busy">@{{ labels.create_key }}</button></div>
                    </form>
                </div>

                <!-- Webhooks -->
                <div class="api-card">
                    <h2>@{{ labels.webhooks }}</h2>
                    <p class="api-muted">@{{ labels.webhooks_hint }}</p>

                    <table class="api-table" v-if="webhooks.length">
                        <thead><tr><th>@{{ labels.name }}</th><th>@{{ labels.events }}</th><th>@{{ labels.status }}</th><th></th></tr></thead>
                        <tbody>
                            <tr v-for="item in webhooks" :key="item.id">
                                <td>@{{ item.name }}<div class="api-muted" style="word-break:break-all">@{{ item.url }}</div></td>
                                <td><span class="api-pill" v-for="event in item.events" :key="event">@{{ event === '*' ? labels.all_events : event }}</span></td>
                                <td>
                                    <span v-if="! item.is_active" class="api-pill">@{{ labels.paused }}</span>
                                    <span v-else-if="item.last_error" class="api-pill is-bad">@{{ item.last_error }}</span>
                                    <span v-else-if="item.last_sent_at" class="api-pill is-ok">OK · @{{ item.last_status }}</span>
                                    <span v-else class="api-pill">@{{ labels.never_sent }}</span>
                                    <div class="api-muted" v-if="item.last_sent_at">@{{ item.last_sent_at }}</div>
                                </td>
                                <td style="text-align:right;white-space:nowrap" v-if="canEdit">
                                    <button type="button" class="api-btn" @click="test(item)">@{{ labels.test }}</button>
                                    <button type="button" class="api-btn" @click="edit(item)">@{{ labels.edit }}</button>
                                    <button type="button" class="api-btn is-danger" @click="removeHook(item)">@{{ labels.delete }}</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="api-muted" v-else>@{{ labels.no_webhooks }}</p>

                    <form v-if="canEdit" class="api-card" style="background:var(--a-soft)" @submit.prevent="saveHook">
                        <h2>@{{ hook.id ? labels.edit_webhook : labels.new_webhook }}</h2>

                        <div class="api-row">
                            <label class="api-field">@{{ labels.name }}<input type="text" v-model="hook.name" maxlength="80" required></label>
                            <label class="api-field">URL<input type="url" v-model="hook.url" maxlength="500" placeholder="https://" required></label>
                        </div>

                        <div class="api-field">
                            @{{ labels.events }}
                            <div class="api-events">
                                <label><input type="checkbox" :checked="hook.events.includes('*')" @change="toggleAll($event.target.checked)"> @{{ labels.all_events }}</label>
                                <label v-for="event in events" :key="event">
                                    <input type="checkbox" :value="event" v-model="hook.events" :disabled="hook.events.includes('*')"> @{{ event }}
                                </label>
                            </div>
                        </div>

                        <label class="api-events" v-if="hook.id"><span><input type="checkbox" v-model="hook.is_active"> @{{ labels.active }}</span><span><input type="checkbox" v-model="hook.rotate_secret"> @{{ labels.rotate }}</span></label>

                        <div class="api-row">
                            <div>
                                <button type="submit" class="api-btn is-primary" :disabled="busy">@{{ hook.id ? labels.save : labels.create_webhook }}</button>
                                <button type="button" class="api-btn" v-if="hook.id" @click="resetHook">@{{ labels.cancel }}</button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- How to use -->
                <div class="api-card">
                    <h2>@{{ labels.how_to }}</h2>
                    <p class="api-muted">@{{ labels.how_to_hint }}</p>
<pre>curl @{{ baseUrl }}/leads?status=open \
  -H "Authorization: Bearer kcrm_…"</pre>
                    <p class="api-muted">@{{ labels.signature_hint }}</p>
                </div>
            </div>
        </script>

        <script type="module">
            app.component('v-api-settings', {
                template: '#v-api-settings-template',

                props: {
                    urls: { type: Object, required: true },
                    labels: { type: Object, required: true },
                    initialKeys: { type: Array, default: () => [] },
                    initialWebhooks: { type: Array, default: () => [] },
                    events: { type: Array, default: () => [] },
                    baseUrl: { type: String, default: '' },
                },

                data() {
                    return {
                        keys: this.initialKeys,
                        webhooks: this.initialWebhooks,
                        newKey: { name: '', mode: 'read' },
                        hook: this.blankHook(),
                        secret: null,
                        copied: false,
                        busy: false,
                        problem: '',
                        canEdit: true,
                    };
                },

                methods: {
                    url(name, id) {
                        return this.urls[name].replace('__ID__', id);
                    },

                    blankHook() {
                        return { id: null, name: '', url: '', events: ['lead.created'], is_active: true, rotate_secret: false };
                    },

                    resetHook() {
                        this.hook = this.blankHook();
                    },

                    toggleAll(on) {
                        this.hook.events = on ? ['*'] : [];
                    },

                    fail(error) {
                        const body = error.response && error.response.data;

                        this.problem = (body && (body.message || Object.values(body.errors || {}).flat()[0])) || this.labels.error;
                    },

                    async refresh() {
                        const { data } = await this.$axios.get(this.urls.list);

                        this.keys = data.keys;
                        this.webhooks = data.webhooks;
                    },

                    async copy(value) {
                        try {
                            await navigator.clipboard.writeText(value);
                            this.copied = true;
                            setTimeout(() => { this.copied = false; }, 2000);
                        } catch (error) {}
                    },

                    async createKey() {
                        this.busy = true;
                        this.problem = '';

                        try {
                            const { data } = await this.$axios.post(this.urls.keys, {
                                name: this.newKey.name,
                                abilities: this.newKey.mode === 'delete' ? ['read', 'write', 'delete'] : (this.newKey.mode === 'write' ? ['read', 'write'] : ['read']),
                            });

                            this.secret = { label: this.labels.new_key, value: data.token };
                            this.newKey = { name: '', mode: 'read' };

                            await this.refresh();
                        } catch (error) {
                            this.fail(error);
                        } finally {
                            this.busy = false;
                        }
                    },

                    async revoke(item) {
                        if (! window.confirm(this.labels.revoke_confirm)) {
                            return;
                        }

                        try {
                            await this.$axios.delete(this.url('revoke', item.id));
                            await this.refresh();
                        } catch (error) {
                            this.fail(error);
                        }
                    },

                    edit(item) {
                        this.hook = { id: item.id, name: item.name, url: item.url, events: [...item.events], is_active: item.is_active, rotate_secret: false };
                        window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
                    },

                    async saveHook() {
                        this.busy = true;
                        this.problem = '';

                        try {
                            const payload = { name: this.hook.name, url: this.hook.url, events: this.hook.events };

                            let data;

                            if (this.hook.id) {
                                ({ data } = await this.$axios.put(this.url('webhook', this.hook.id), Object.assign(payload, {
                                    is_active: this.hook.is_active ? 1 : 0,
                                    rotate_secret: this.hook.rotate_secret ? 1 : 0,
                                })));
                            } else {
                                ({ data } = await this.$axios.post(this.urls.webhooks, payload));
                            }

                            if (data.secret) {
                                this.secret = { label: this.labels.new_secret, value: data.secret };
                            }

                            this.resetHook();
                            await this.refresh();
                        } catch (error) {
                            this.fail(error);
                        } finally {
                            this.busy = false;
                        }
                    },

                    async removeHook(item) {
                        if (! window.confirm(this.labels.delete_confirm)) {
                            return;
                        }

                        try {
                            await this.$axios.delete(this.url('webhook', item.id));
                            await this.refresh();
                        } catch (error) {
                            this.fail(error);
                        }
                    },

                    async test(item) {
                        this.problem = '';

                        try {
                            await this.$axios.post(this.url('test', item.id));
                            await this.refresh();
                        } catch (error) {
                            this.fail(error);
                        }
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
