<x-admin::layouts>
    <x-slot:title>
        @lang('whatsapp::app.connections.title')
    </x-slot>

    <v-whatsapp-connections
        :urls="{{ Illuminate\Support\Js::from($urls) }}"
        :labels="{{ Illuminate\Support\Js::from(trans('whatsapp::app.connections')) }}"
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
            </div>
        </script>

        <script type="module">
            app.component('v-whatsapp-connections', {
                template: '#v-whatsapp-connections-template',

                props: {
                    urls: { type: Object, required: true },
                    labels: { type: Object, required: true },
                },

                data() {
                    return {
                        sessions: [],
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
