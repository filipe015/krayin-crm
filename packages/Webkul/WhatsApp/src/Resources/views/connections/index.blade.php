<x-admin::layouts>
    <x-slot:title>
        @lang('whatsapp::app.connections.title')
    </x-slot>

    <v-whatsapp-connections
        :urls="{{ Illuminate\Support\Js::from($urls) }}"
        :labels="{{ Illuminate\Support\Js::from(trans('whatsapp::app.connections')) }}"
        :cloud-info="{{ Illuminate\Support\Js::from(['webhook' => $cloudWebhook, 'verify' => $cloudVerifyConfigured, 'secret' => $cloudSecretConfigured]) }}"
    >
        <div class="p-6 text-sm text-gray-500">
            @lang('whatsapp::app.connections.loading')
        </div>
    </v-whatsapp-connections>

    @pushOnce('scripts')
        <style>
            .wa-conn{--wa-bg:#fff;--wa-fg:#111827;--wa-muted:#6b7280;--wa-border:#e5e7eb;--wa-soft:#f3f4f6;--wa-accent:#0e90d9;display:flex;flex-direction:column;gap:16px;color:var(--wa-fg);font-size:14px}
            .dark .wa-conn{--wa-bg:#111827;--wa-fg:#e5e7eb;--wa-muted:#9ca3af;--wa-border:#1f2937;--wa-soft:#1f2937}
            .wa-conn *{box-sizing:border-box}
            .wa-conn button{cursor:pointer;font:inherit;color:inherit}
            .wa-conn h1{margin:0;font-size:18px;font-weight:600}
            .wa-conn-card{display:flex;flex-direction:column;gap:14px;padding:16px;border:1px solid var(--wa-border);border-radius:8px;background:var(--wa-bg)}
            .wa-conn-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
            .wa-conn-name{font-size:15px;font-weight:600}
            .wa-conn-muted{color:var(--wa-muted);font-size:12px}
            .wa-conn-pill{display:inline-block;padding:2px 10px;border-radius:10px;font-size:12px;font-weight:600;background:var(--wa-soft);color:var(--wa-muted)}
            .wa-conn-pill.is-ok{background:#dcfce7;color:#14532d}
            .wa-conn-pill.is-wait{background:#fef3c7;color:#92400e}
            .wa-conn-pill.is-bad{background:#fee2e2;color:#991b1b}
            .wa-conn-actions{display:flex;gap:8px;flex-wrap:wrap}
            .wa-conn-btn{padding:6px 12px;border:1px solid var(--wa-border);border-radius:6px;background:var(--wa-bg);font-size:13px}
            .wa-conn-btn:hover{background:var(--wa-soft)}
            .wa-conn-btn:disabled{opacity:.5;cursor:default}
            .wa-conn-btn.is-danger{border-color:#dc2626;color:#dc2626}
            .wa-conn-pair{display:flex;gap:24px;flex-wrap:wrap;padding-top:14px;border-top:1px solid var(--wa-border)}
            .wa-conn-qr{display:flex;flex-direction:column;align-items:center;gap:8px;width:260px}
            .wa-conn-qr img{width:240px;height:240px;border:1px solid var(--wa-border);border-radius:6px;background:#fff}
            .wa-conn-code{display:flex;flex-direction:column;gap:8px;max-width:320px}
            .wa-conn-code input{width:100%;padding:6px 10px;border:1px solid var(--wa-border);border-radius:6px;background:var(--wa-bg);color:var(--wa-fg);font:inherit}
            .wa-conn-code strong{font-size:22px;letter-spacing:.12em}
            .wa-conn-field{display:flex;flex-direction:column;gap:4px;font-size:12px;color:var(--wa-muted)}
            .wa-conn-bad{color:#b91c1c}
            .wa-conn-error{padding:10px 12px;border:1px solid #fecaca;border-radius:6px;background:#fef2f2;color:#991b1b;font-size:13px}
        </style>

        <script type="text/x-template" id="v-whatsapp-connections-template">
            <div class="wa-conn">
                <div class="wa-conn-head">
                    <h1>@{{ labels.title }}</h1>
                    <button type="button" class="wa-conn-btn" :disabled="loading" @click="load()">@{{ labels.refresh }}</button>
                </div>

                <div v-if="error" class="wa-conn-error">@{{ labels.waha_unreachable }}: @{{ error }}</div>
                <div v-if="notice" class="wa-conn-error">@{{ notice }}</div>

                <div v-if="! loading && ! sessions.length && ! error" class="wa-conn-muted">@{{ labels.empty }}</div>

                <div v-for="session in sessions" :key="session.id" class="wa-conn-card">
                    <div class="wa-conn-head">
                        <div>
                            <div class="wa-conn-name">@{{ session.name }}</div>
                            <div class="wa-conn-muted">
                                <span v-if="session.phone">+@{{ session.phone }}</span>
                                <span v-else>@{{ labels.no_phone }}</span>
                            </div>
                        </div>

                        <span class="wa-conn-pill" :class="pillClass(session.status)">@{{ statusLabel(session.status) }}</span>
                    </div>

                    <div class="wa-conn-actions">
                        <button v-if="session.status === 'STOPPED' || session.status === 'FAILED'" type="button" class="wa-conn-btn" :disabled="busy" @click="run(session, 'start')">@{{ labels.start }}</button>
                        <button v-if="session.status !== 'STOPPED'" type="button" class="wa-conn-btn" :disabled="busy" @click="run(session, 'restart')">@{{ labels.restart }}</button>
                        <button v-if="session.status !== 'STOPPED'" type="button" class="wa-conn-btn" :disabled="busy" @click="run(session, 'stop')">@{{ labels.stop }}</button>
                        <button v-if="session.status === 'WORKING'" type="button" class="wa-conn-btn is-danger" :disabled="busy" @click="logout(session)">@{{ labels.logout }}</button>
                    </div>

                    <div v-if="session.status === 'SCAN_QR_CODE'" class="wa-conn-pair">
                        <div class="wa-conn-qr">
                            <img v-if="qr[session.id]" :src="qr[session.id]" :alt="labels.qr">
                            <div v-else class="wa-conn-muted">@{{ labels.qr_loading }}</div>
                            <div class="wa-conn-muted">@{{ labels.qr_help }}</div>
                        </div>

                        <div class="wa-conn-code">
                            <div class="wa-conn-name">@{{ labels.code_title }}</div>
                            <div class="wa-conn-muted">@{{ labels.code_help }}</div>

                            <input type="text" inputmode="tel" v-model="phones[session.id]" :placeholder="labels.code_placeholder" @keyup.enter="requestCode(session)">

                            <button type="button" class="wa-conn-btn" :disabled="busy || ! phones[session.id]" @click="requestCode(session)">@{{ labels.code_button }}</button>

                            <strong v-if="codes[session.id]">@{{ codes[session.id] }}</strong>
                        </div>
                    </div>
                </div>

                <div class="wa-conn-head" style="margin-top:8px">
                    <h1>@{{ labels.cloud_title }}</h1>
                    <button type="button" class="wa-conn-btn is-primary" @click="cloudForm = cloudForm ? null : { name: '', phone_number_id: '', waba_id: '', access_token: '' }">@{{ labels.cloud_add }}</button>
                </div>

                <p class="wa-conn-muted" style="margin:0">@{{ labels.cloud_intro }}</p>

                <div class="wa-conn-card">
                    <div class="wa-conn-name">@{{ labels.cloud_webhook }}</div>
                    <div class="wa-conn-muted">@{{ labels.cloud_webhook_help }}</div>
                    <code style="word-break:break-all">@{{ cloudInfo.webhook }}</code>
                    <div class="wa-conn-muted">
                        <span :class="cloudInfo.verify ? '' : 'wa-conn-bad'">@{{ cloudInfo.verify ? '✓' : '✗' }} WHATSAPP_CLOUD_VERIFY_TOKEN</span> &nbsp;
                        <span :class="cloudInfo.secret ? '' : 'wa-conn-bad'">@{{ cloudInfo.secret ? '✓' : '✗' }} WHATSAPP_CLOUD_APP_SECRET</span>
                    </div>
                </div>

                <form v-if="cloudForm" class="wa-conn-card wa-conn-code" style="max-width:none" @submit.prevent="connectCloud">
                    <label class="wa-conn-field">@{{ labels.cloud_name }}<input type="text" v-model="cloudForm.name" maxlength="80"></label>
                    <label class="wa-conn-field">@{{ labels.cloud_phone_number_id }}<input type="text" v-model="cloudForm.phone_number_id" inputmode="numeric" required></label>
                    <label class="wa-conn-field">@{{ labels.cloud_waba_id }}<input type="text" v-model="cloudForm.waba_id" inputmode="numeric" required></label>
                    <label class="wa-conn-field">@{{ labels.cloud_token }}<input type="password" v-model="cloudForm.access_token" autocomplete="off" required></label>

                    <div class="wa-conn-actions">
                        <button type="submit" class="wa-conn-btn is-primary" :disabled="busy">@{{ labels.cloud_connect }}</button>
                        <button type="button" class="wa-conn-btn" @click="cloudForm = null">@{{ labels.cloud_cancel }}</button>
                    </div>
                </form>

                <div v-if="! cloud.length && ! cloudForm" class="wa-conn-muted">@{{ labels.cloud_empty }}</div>

                <div v-for="number in cloud" :key="'c' + number.id" class="wa-conn-card">
                    <div class="wa-conn-head">
                        <div>
                            <div class="wa-conn-name">@{{ number.name }}</div>
                            <div class="wa-conn-muted">
                                <span v-if="number.phone">+@{{ number.phone }}</span>
                                <span v-if="number.quality"> · @{{ labels.cloud_quality }}: @{{ number.quality }}</span>
                                <span v-if="number.name_status"> · @{{ number.name_status }}</span>
                            </div>
                        </div>

                        <span class="wa-conn-pill" :class="number.connected && number.status === 'WORKING' ? 'is-ok' : 'is-bad'">
                            @{{ number.connected && number.status === 'WORKING' ? labels.statuses.WORKING : labels.cloud_disconnected }}
                        </span>
                    </div>

                    <div class="wa-conn-actions">
                        <button v-if="number.connected" type="button" class="wa-conn-btn" :disabled="busy" @click="refreshCloud(number)">@{{ labels.cloud_refresh }}</button>
                        <button v-if="number.connected" type="button" class="wa-conn-btn is-danger" :disabled="busy" @click="disconnectCloud(number)">@{{ labels.cloud_disconnect }}</button>
                    </div>
                </div>

                <div class="wa-conn-head" style="margin-top:12px">
                    <h1>@{{ labels.rules_title }}</h1>
                </div>

                <p class="wa-conn-muted" style="margin:0">@{{ labels.rules_intro }}</p>

                <div class="wa-conn-card">
                    <div v-if="! rules.length" class="wa-conn-muted">@{{ labels.rules_empty }}</div>

                    <div v-for="rule in rules" :key="'r' + rule.id" class="wa-conn-head">
                        <div>
                            <div class="wa-conn-name">&ldquo;@{{ rule.match_text }}&rdquo;</div>
                            <div class="wa-conn-muted">
                                <span v-if="rule.stage_id">&rarr; @{{ labels.rule_stage }}: @{{ stageName(rule.stage_id) }}</span>
                                <span v-if="rule.tag_id"> &middot; @{{ labels.rule_tag }}: @{{ tagName(rule.tag_id) }}</span>
                            </div>
                        </div>
                        <button type="button" class="wa-conn-btn is-danger" :disabled="busy" @click="deleteRule(rule)">@{{ labels.rule_delete }}</button>
                    </div>
                </div>

                <form class="wa-conn-card wa-conn-code" style="max-width:none" @submit.prevent="addRule">
                    <label class="wa-conn-field">@{{ labels.rule_text }}<input type="text" v-model="ruleForm.match_text" maxlength="100" :placeholder="labels.rule_text_ph" required></label>
                    <label class="wa-conn-field">@{{ labels.rule_stage }}
                        <select v-model="ruleForm.stage_id">
                            <option value="">@{{ labels.rule_none }}</option>
                            <option v-for="stage in ruleOptions.stages" :key="stage.id" :value="stage.id">@{{ stage.name }}</option>
                        </select>
                    </label>
                    <label class="wa-conn-field">@{{ labels.rule_tag }}
                        <select v-model="ruleForm.tag_id">
                            <option value="">@{{ labels.rule_none }}</option>
                            <option v-for="tag in ruleOptions.tags" :key="tag.id" :value="tag.id">@{{ tag.name }}</option>
                        </select>
                    </label>
                    <div class="wa-conn-actions">
                        <button type="submit" class="wa-conn-btn is-primary" :disabled="busy || ! ruleForm.match_text.trim() || (! ruleForm.stage_id && ! ruleForm.tag_id)">@{{ labels.rule_add }}</button>
                    </div>
                </form>

                <div class="wa-conn-head" style="margin-top:12px">
                    <h1>@{{ labels.reminders_title }}</h1>
                </div>

                <p class="wa-conn-muted" style="margin:0">@{{ labels.reminders_intro }}</p>

                <form v-if="reminder" class="wa-conn-card wa-conn-code" style="max-width:none" @submit.prevent="saveReminder">
                    <label class="wa-conn-field" style="flex-direction:row;align-items:center;gap:8px">
                        <input type="checkbox" v-model="reminder.enabled" style="width:auto"> @{{ labels.reminder_enabled }}
                    </label>

                    <label class="wa-conn-field">@{{ labels.reminder_stage }}
                        <select v-model="reminder.stage_id">
                            <option :value="null">—</option>
                            <option v-for="stage in reminderOptions.stages" :key="stage.id" :value="stage.id">@{{ stage.name }}</option>
                        </select>
                    </label>

                    <div class="wa-conn-field">
                        @{{ labels.reminder_keep }}
                        <span class="wa-conn-muted">@{{ labels.reminder_keep_help }}</span>
                        <label v-for="stage in reminderOptions.stages" :key="'k' + stage.id" style="display:flex;gap:6px;align-items:center" v-show="stage.id !== reminder.stage_id">
                            <input type="checkbox" :value="stage.id" v-model="reminder.keep_stage_ids" style="width:auto"> @{{ stage.name }}
                        </label>
                    </div>

                    <label class="wa-conn-field">@{{ labels.reminder_type }}
                        <select v-model="reminder.activity_type">
                            <option v-for="type in reminderOptions.activity_types" :key="type" :value="type">@{{ labels.reminder_types[type] || type }}</option>
                        </select>
                    </label>

                    <label class="wa-conn-field">@{{ labels.reminder_template }}
                        <select :value="reminderTemplateKey" @change="pickReminderTemplate($event.target.value)">
                            <option value="">@{{ labels.reminder_template_pick }}</option>
                            <option v-for="item in reminderTemplates" :key="item.name + '|' + item.language" :value="item.name + '|' + item.language">
                                @{{ item.name }} (@{{ item.language }}, @{{ item.category }})
                            </option>
                        </select>
                    </label>

                    <div v-if="reminderTemplate" class="wa-conn-muted" style="white-space:pre-wrap">@{{ reminderTemplate.body }}</div>

                    <label v-for="n in (reminderTemplate ? reminderTemplate.variables : 0)" :key="'v' + n" class="wa-conn-field">
                        @{{ labels.reminder_variable.replace(':n', n) }}
                        <select v-model="reminder.variables[n - 1]" required>
                            <option v-for="source in reminderOptions.sources" :key="source" :value="source">@{{ labels.reminder_sources[source] || source }}</option>
                        </select>
                    </label>

                    <div class="wa-conn-field">
                        @{{ labels.reminder_offsets }}
                        <label v-for="minutes in reminderOptions.offset_options" :key="'o' + minutes" style="display:flex;gap:6px;align-items:center">
                            <input type="checkbox" :value="minutes" v-model="reminder.offsets" style="width:auto"> @{{ labels.reminder_offset_labels[minutes] || minutes }}
                        </label>
                    </div>

                    <div class="wa-conn-actions">
                        <button type="submit" class="wa-conn-btn is-primary" :disabled="busy">@{{ labels.reminder_save }}</button>
                    </div>
                </form>
            </div>
        </script>

        <script type="module">
            app.component('v-whatsapp-connections', {
                template: '#v-whatsapp-connections-template',

                props: {
                    urls: { type: Object, required: true },
                    labels: { type: Object, required: true },
                    cloudInfo: { type: Object, default: () => ({}) },
                },

                data() {
                    return {
                        sessions: [],
                        cloud: [],
                        cloudForm: null,
                        rules: [],
                        reminder: null,
                        reminderOptions: { stages: [], sources: [], offset_options: [], activity_types: [] },
                        reminderTemplates: [],
                        ruleOptions: { stages: [], tags: [] },
                        ruleForm: { match_text: '', stage_id: '', tag_id: '' },
                        loading: true,
                        busy: false,
                        error: null,
                        notice: null,
                        qr: {},
                        phones: {},
                        codes: {},
                        timer: null,
                        qrTick: 0,
                    };
                },

                mounted() {
                    this.load();
                    this.loadRules();
                    this.loadReminder();

                    this.timer = setInterval(() => {
                        if (document.hidden) {
                            return;
                        }

                        this.load({ quiet: true });
                    }, 4000);
                },

                beforeUnmount() {
                    clearInterval(this.timer);
                },

                computed: {
                    reminderTemplateKey() {
                        return this.reminder && this.reminder.template_name ? this.reminder.template_name + '|' + this.reminder.template_language : '';
                    },

                    reminderTemplate() {
                        return this.reminderTemplates.find(item => item.name === this.reminder?.template_name && item.language === this.reminder?.template_language) || null;
                    },
                },

                methods: {
                    url(name, id, action) {
                        return this.urls[name].replace('__ID__', id).replace('__ACTION__', action);
                    },

                    statusLabel(status) {
                        return this.labels.statuses[status] || status;
                    },

                    pillClass(status) {
                        if (status === 'WORKING') {
                            return 'is-ok';
                        }

                        return ['STARTING', 'SCAN_QR_CODE'].includes(status) ? 'is-wait' : (status === 'FAILED' ? 'is-bad' : '');
                    },

                    async load({ quiet = false } = {}) {
                        if (! quiet) {
                            this.loading = true;
                        }

                        try {
                            const { data } = await this.$axios.get(this.urls.list);

                            this.error = data.error;
                            this.sessions = data.data;
                            this.cloud = data.cloud || [];
                        } catch (error) {
                            this.error = this.labels.generic_error;
                        } finally {
                            this.loading = false;
                        }

                        // The QR code rotates about every 20s, so refresh it on every second poll.
                        this.qrTick += 1;

                        this.sessions
                            .filter(session => session.status === 'SCAN_QR_CODE')
                            .forEach(session => {
                                if (! this.qr[session.id] || this.qrTick % 2 === 0) {
                                    this.loadQr(session);
                                }
                            });
                    },

                    async loadQr(session) {
                        try {
                            const { data } = await this.$axios.get(this.url('qr', session.id));

                            this.qr = { ...this.qr, [session.id]: 'data:' + data.mimetype + ';base64,' + data.data };
                        } catch (error) {
                            // The session may have just left the QR state; the next poll corrects it.
                        }
                    },

                    async send(request) {
                        this.busy = true;
                        this.notice = null;

                        try {
                            return await request();
                        } catch (error) {
                            this.notice = error.response?.data?.message || this.labels.generic_error;

                            return null;
                        } finally {
                            this.busy = false;
                        }
                    },

                    async run(session, action) {
                        const done = await this.send(() => this.$axios.post(this.url('action', session.id, action)));

                        if (done) {
                            this.codes = { ...this.codes, [session.id]: null };
                            this.qr = { ...this.qr, [session.id]: null };

                            // WAHA needs a moment to move to the new state.
                            setTimeout(() => this.load({ quiet: true }), 1500);
                        }
                    },

                    logout(session) {
                        if (window.confirm(this.labels.logout_confirm)) {
                            this.run(session, 'logout');
                        }
                    },

                    async connectCloud() {
                        this.busy = true;
                        this.notice = null;

                        try {
                            const { data } = await this.$axios.post(this.urls.cloudConnect, this.cloudForm);

                            this.cloudForm = null;
                            this.notice = data.subscribed ? null : this.labels.cloud_not_subscribed;
                            await this.load({ quiet: true });
                        } catch (error) {
                            this.notice = (error.response && error.response.data && (error.response.data.message || Object.values(error.response.data.errors || {}).flat()[0])) || this.labels.generic_error;
                        } finally {
                            this.busy = false;
                        }
                    },

                    async loadReminder() {
                        try {
                            const { data } = await this.$axios.get(this.urls.reminders);

                            this.reminderOptions = {
                                stages: data.stages,
                                sources: data.sources,
                                offset_options: data.offset_options,
                                activity_types: data.activity_types,
                            };
                            this.reminder = data.data;

                            if (data.has_number) {
                                const templates = await this.$axios.get(this.urls.reminderTemplates);

                                this.reminderTemplates = templates.data.data;
                            }
                        } catch (error) {
                            // Needs an official number; the section stays hidden otherwise.
                        }
                    },

                    pickReminderTemplate(key) {
                        const [name, language] = key.split('|');
                        const template = this.reminderTemplates.find(item => item.name === name && item.language === language);

                        this.reminder.template_name = template ? template.name : null;
                        this.reminder.template_language = template ? template.language : null;

                        const defaults = ['contact_first_name', 'owner_name', 'appointment_datetime', 'appointment_date', 'appointment_time'];

                        this.reminder.variables = Array.from({ length: template ? template.variables : 0 }, (_, index) => this.reminder.variables[index] || defaults[index] || 'lead_title');
                    },

                    async saveReminder() {
                        const done = await this.send(() => this.$axios.put(this.urls.reminders, this.reminder));

                        if (done) {
                            this.notice = this.labels.reminder_saved;
                        }
                    },

                    async loadRules() {
                        try {
                            const { data } = await this.$axios.get(this.urls.rules);

                            this.rules = data.data;
                            this.ruleOptions = { stages: data.stages, tags: data.tags };
                        } catch (error) {
                            // The list is optional; the section just stays empty.
                        }
                    },

                    stageName(id) {
                        return (this.ruleOptions.stages.find(item => item.id === id) || {}).name || '#' + id;
                    },

                    tagName(id) {
                        return (this.ruleOptions.tags.find(item => item.id === id) || {}).name || '#' + id;
                    },

                    async addRule() {
                        const done = await this.send(() => this.$axios.post(this.urls.ruleStore, {
                            match_text: this.ruleForm.match_text.trim(),
                            stage_id: this.ruleForm.stage_id || null,
                            tag_id: this.ruleForm.tag_id || null,
                        }));

                        if (done) {
                            this.ruleForm = { match_text: '', stage_id: '', tag_id: '' };
                            await this.loadRules();
                        }
                    },

                    async deleteRule(rule) {
                        if (! window.confirm(this.labels.rule_delete_confirm)) {
                            return;
                        }

                        const done = await this.send(() => this.$axios.delete(this.url('ruleDelete', rule.id)));

                        if (done) {
                            await this.loadRules();
                        }
                    },

                    async refreshCloud(number) {
                        await this.send(() => this.$axios.post(this.url('cloudRefresh', number.id)));
                        await this.load({ quiet: true });
                    },

                    async disconnectCloud(number) {
                        if (! window.confirm(this.labels.cloud_disconnect_confirm)) {
                            return;
                        }

                        await this.send(() => this.$axios.post(this.url('cloudDisconnect', number.id)));
                        await this.load({ quiet: true });
                    },

                    async requestCode(session) {
                        const response = await this.send(() => this.$axios.post(this.url('code', session.id), { phone: this.phones[session.id] }));

                        if (response) {
                            this.codes = { ...this.codes, [session.id]: response.data.code };
                        }
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
