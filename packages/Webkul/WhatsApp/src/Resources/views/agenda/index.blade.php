<x-admin::layouts>
    <x-slot:title>
        @lang('whatsapp::app.agenda.title')
    </x-slot>

    <v-agenda
        :urls="{{ Illuminate\Support\Js::from($urls) }}"
        :labels="{{ Illuminate\Support\Js::from(trans('whatsapp::app.agenda')) }}"
        :users="{{ Illuminate\Support\Js::from($users) }}"
        :current-user-id="{{ (int) $currentUserId }}"
        :durations="{{ Illuminate\Support\Js::from($durations) }}"
        :can-create="{{ $canCreate ? 'true' : 'false' }}"
        :can-open-inbox="{{ $canOpenInbox ? 'true' : 'false' }}"
        locale="{{ strtolower(str_replace('_', '-', app()->getLocale())) }}"
    >
        <div class="p-6 text-sm text-gray-500">
            @lang('whatsapp::app.agenda.loading')
        </div>
    </v-agenda>

    @pushOnce('scripts')
        <style>
            .ag{--ag-bg:#fff;--ag-fg:#111827;--ag-muted:#6b7280;--ag-border:#e5e7eb;--ag-soft:#f3f4f6;--ag-accent:#0e90d9;display:flex;flex-direction:column;gap:12px;color:var(--ag-fg);font-size:14px}
            .dark .ag{--ag-bg:#111827;--ag-fg:#e5e7eb;--ag-muted:#9ca3af;--ag-border:#1f2937;--ag-soft:#1f2937}
            .ag *{box-sizing:border-box}
            .ag button{cursor:pointer;font:inherit;color:inherit}
            .ag-bar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:12px 16px;border:1px solid var(--ag-border);border-radius:8px;background:var(--ag-bg)}
            .ag-bar h1{margin:0;font-size:18px;font-weight:600}
            .ag-actions{display:flex;gap:8px;flex-wrap:wrap}
            .ag-btn{padding:7px 14px;border:1px solid var(--ag-border);border-radius:6px;background:var(--ag-bg);font-size:13px;font-weight:500}
            .ag-btn:hover{background:var(--ag-soft)}
            .ag-btn.is-primary{background:var(--ag-accent);border-color:var(--ag-accent);color:#fff}
            .ag-btn.is-primary:hover{filter:brightness(.95)}
            .ag-btn.is-danger{border-color:#dc2626;color:#dc2626}
            .ag-btn:disabled{opacity:.5;cursor:default}
            .ag-filters{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:10px 16px;border:1px solid var(--ag-border);border-radius:8px;background:var(--ag-bg)}
            .ag-label{font-size:12px;color:var(--ag-muted);font-weight:600}
            .ag-chips{display:flex;gap:6px;flex-wrap:wrap}
            .ag-chip{padding:4px 12px;border:1px solid var(--ag-border);border-radius:999px;background:var(--ag-bg);font-size:12px}
            .ag-chip.is-on{background:var(--ag-accent);border-color:var(--ag-accent);color:#fff}
            .ag-check{display:flex;align-items:center;gap:6px;font-size:13px;margin-left:auto}
            .ag-body{display:flex;gap:12px;align-items:flex-start}
            .ag-cal{flex:1;min-width:0;border:1px solid var(--ag-border);border-radius:8px;background:var(--ag-bg);padding:8px}
            .ag-side{width:280px;flex:none;display:flex;flex-direction:column;gap:12px}
            @media (max-width:1100px){.ag-body{flex-direction:column;align-items:stretch}.ag-side{width:100%}}
            .ag-card{border:1px solid var(--ag-border);border-radius:8px;background:var(--ag-bg);padding:12px;display:flex;flex-direction:column;gap:8px}
            .ag-card h2{margin:0;font-size:13px;font-weight:600}
            .ag-muted{color:var(--ag-muted);font-size:12px}
            .ag-item{display:flex;flex-direction:column;gap:2px;width:100%;padding:8px 10px;border:1px solid var(--ag-border);border-left-width:4px;border-radius:6px;background:var(--ag-bg);text-align:left}
            .ag-item:hover{background:var(--ag-soft)}
            .ag-item.is-done{opacity:.6}
            .ag-legend{display:flex;flex-direction:column;gap:6px}
            .ag-legend span{display:flex;align-items:center;gap:8px;font-size:12px}
            .ag-dot{width:10px;height:10px;border-radius:50%;flex:none}
            .ag-event{height:100%;padding:3px 6px;overflow:hidden;border-left:4px solid;border-radius:4px;color:#111827;font-size:12px;line-height:1.25;text-align:left}
            .ag-event strong{display:block;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
            .ag-event span,.ag-event small{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
            .ag-event small{opacity:.8}
            .ag-event.is-done{opacity:.55;text-decoration:line-through}
            .ag-event.is-overdue{box-shadow:inset 0 0 0 1px #dc2626}
            .ag-back{position:fixed;inset:0;z-index:10005;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(0,0,0,.45)}
            .ag-modal{width:100%;max-width:480px;max-height:90vh;overflow:auto;display:flex;flex-direction:column;gap:12px;padding:20px;border-radius:10px;background:var(--ag-bg);color:var(--ag-fg);box-shadow:0 20px 40px rgba(0,0,0,.25)}
            .ag-modal h2{margin:0;font-size:16px;font-weight:600}
            .ag-field{display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:600;color:var(--ag-muted)}
            .ag-field input,.ag-field select,.ag-field textarea{width:100%;padding:8px 10px;border:1px solid var(--ag-border);border-radius:6px;background:var(--ag-bg);color:var(--ag-fg);font:inherit;font-weight:400}
            .ag-row{display:flex;gap:10px}
            .ag-row>*{flex:1;min-width:0}
            .ag-results{border:1px solid var(--ag-border);border-radius:6px;overflow:hidden}
            .ag-results button{display:flex;flex-direction:column;width:100%;padding:8px 10px;border:0;border-bottom:1px solid var(--ag-border);background:var(--ag-bg);text-align:left}
            .ag-results button:last-child{border-bottom:0}
            .ag-results button:hover{background:var(--ag-soft)}
            .ag-picked{display:flex;justify-content:space-between;align-items:center;gap:8px;padding:8px 10px;border:1px solid var(--ag-accent);border-radius:6px}
            .ag-alert{padding:10px 12px;border-radius:6px;background:#fef3c7;color:#92400e;font-size:13px}
            .ag-detail{display:grid;grid-template-columns:110px 1fr;gap:6px 10px;font-size:13px}
            .ag-detail dt{color:var(--ag-muted)}
            .ag-detail dd{margin:0}
            .ag-footer{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
            .ag .vuecal{background:transparent}
            .ag .vuecal__event{background:transparent!important;border:0!important}
            .ag .vuecal__cell--today{background:rgba(14,144,217,.06)}
            .ag .vuecal__heading{font-size:12px;line-height:1.15}
            .ag .vuecal__heading .weekday-label{display:flex;flex-direction:column;align-items:center;gap:0}
            .ag .vuecal__heading .weekday-label span:nth-child(2){font-size:11px;opacity:.7}
        </style>

        <script type="text/x-template" id="v-agenda-template">
            <div class="ag">
                <div class="ag-bar">
                    <h1>@{{ labels.title }}</h1>

                    <div class="ag-actions">
                        <button type="button" class="ag-btn" @click="goToday">@{{ labels.today }}</button>
                        <button v-if="canCreate" type="button" class="ag-btn is-primary" @click="openNew(null)">+ @{{ labels.new }}</button>
                    </div>
                </div>

                <div class="ag-filters">
                    <span class="ag-label">@{{ labels.professionals }}</span>

                    <div class="ag-chips">
                        <button type="button" class="ag-chip" :class="{ 'is-on': ! selectedUsers.length }" @click="selectedUsers = []; load()">@{{ labels.all }}</button>
                        <button
                            v-for="user in users"
                            :key="user.id"
                            type="button"
                            class="ag-chip"
                            :class="{ 'is-on': selectedUsers.includes(user.id) }"
                            @click="toggleUser(user.id)"
                        >@{{ user.name }}</button>
                    </div>

                    <label class="ag-check">
                        <input type="checkbox" v-model="includeDone" @change="load(); loadToday()">
                        @{{ labels.show_done }}
                    </label>
                </div>

                <div class="ag-body">
                    <div class="ag-cal">
                        <v-vue-cal
                            ref="calendar"
                            active-view="week"
                            :selected-date="selectedDate"
                            :disable-views="['years', 'year', 'month']"
                            :events="calendarEvents"
                            :time-from="7 * 60"
                            :time-to="21 * 60"
                            :time-step="30"
                            :time-cell-height="52"
                            :time-format="'H:mm'"
                            :editable-events="false"
                            :click-to-navigate="false"
                            :dblclick-to-navigate="false"
                            :hide-weekends="false"
                            :class="{ 'vuecal--dark': theme === 'dark' }"
                            style="height: calc(100vh - 290px); min-height: 520px;"
                            :locale="locale"
                            @ready="onViewChange"
                            @view-change="onViewChange"
                            @cell-click="onCellClick"
                            @event-click="onEventClick"
                        >
                            <template #event="{ event }">
                                <div
                                    class="ag-event"
                                    :class="{ 'is-done': event.is_done, 'is-overdue': event.overdue }"
                                    :style="{ background: event.bg, borderLeftColor: event.border }"
                                >
                                    <strong>@{{ event.person || event.lead || event.title }}</strong>
                                    <span>@{{ event.title }}</span>
                                    <small>@{{ hhmm(event.start) }}–@{{ hhmm(event.end) }} · @{{ event.user_name }}</small>
                                </div>
                            </template>
                        </v-vue-cal>
                    </div>

                    <aside class="ag-side">
                        <div class="ag-card">
                            <h2>@{{ labels.today_list }}</h2>

                            <span v-if="! todayEvents.length" class="ag-muted">@{{ labels.none_today }}</span>

                            <button
                                v-for="item in todayEvents"
                                :key="'t' + item.id"
                                type="button"
                                class="ag-item"
                                :class="{ 'is-done': item.is_done }"
                                :style="{ borderLeftColor: item.border }"
                                @click="openDetail(item)"
                            >
                                <strong>@{{ hhmm(item.start) }} · @{{ item.person || item.lead || item.title }}</strong>
                                <span class="ag-muted">@{{ item.title }}<template v-if="item.user_name"> · @{{ item.user_name }}</template></span>
                            </button>
                        </div>

                        <div class="ag-card" v-if="legend.length">
                            <h2>@{{ labels.stage }}</h2>

                            <div class="ag-legend">
                                <span v-for="stage in legend" :key="stage.key">
                                    <i class="ag-dot" :style="{ background: stage.border }"></i>@{{ stage.name }}
                                </span>
                            </div>
                        </div>
                    </aside>
                </div>

                <!-- New appointment -->
                <div v-if="form" class="ag-back" @click.self="form = null">
                    <form class="ag-modal" @submit.prevent="submit(false)">
                        <h2>@{{ labels.new }}</h2>

                        <div class="ag-field">
                            @{{ labels.lead }}

                            <div v-if="form.lead" class="ag-picked">
                                <span>
                                    <strong>@{{ form.lead.person || form.lead.title }}</strong>
                                    <span class="ag-muted" v-if="form.lead.stage"> · @{{ form.lead.stage }}</span>
                                </span>
                                <button type="button" class="ag-btn" @click="form.lead = null">×</button>
                            </div>

                            <template v-else>
                                <input type="text" v-model="search" :placeholder="labels.search_ph" @input="searchLeads" autocomplete="off">

                                <div class="ag-results" v-if="results.length">
                                    <button v-for="lead in results" :key="lead.id" type="button" @click="pick(lead)">
                                        <strong>@{{ lead.person || lead.title }}</strong>
                                        <span class="ag-muted">@{{ lead.title }}<template v-if="lead.stage"> · @{{ lead.stage }}</template></span>
                                    </button>
                                </div>

                                <span class="ag-muted" v-else-if="searched">@{{ labels.no_results }}</span>
                            </template>
                        </div>

                        <label class="ag-field">
                            @{{ labels.service }}
                            <input type="text" v-model="form.title" maxlength="120" :placeholder="labels.default_title">
                        </label>

                        <div class="ag-row">
                            <label class="ag-field">
                                @{{ labels.start }}
                                <input type="datetime-local" v-model="form.start" required>
                            </label>

                            <label class="ag-field">
                                @{{ labels.duration }}
                                <select v-model="form.duration">
                                    <option v-for="minutes in durations" :key="minutes" :value="minutes">@{{ minutes }} @{{ labels.minutes }}</option>
                                </select>
                            </label>
                        </div>

                        <label class="ag-field">
                            @{{ labels.professional }}
                            <select v-model="form.user_id">
                                <option v-for="user in users" :key="user.id" :value="user.id">@{{ user.name }}</option>
                            </select>
                        </label>

                        <label class="ag-field">
                            @{{ labels.notes }}
                            <textarea v-model="form.comment" rows="2" maxlength="1000"></textarea>
                        </label>

                        <div class="ag-alert" v-if="problem">
                            @{{ problem }}
                            <template v-if="conflicts.length">
                                <br>
                                <span v-for="item in conflicts" :key="item.id">@{{ item.start }}–@{{ item.end }} · @{{ item.title }}<br></span>
                            </template>
                        </div>

                        <div class="ag-footer">
                            <button type="button" class="ag-btn" @click="form = null">@{{ labels.cancel }}</button>
                            <button v-if="conflicts.length" type="button" class="ag-btn is-danger" :disabled="busy" @click="submit(true)">@{{ labels.conflict_force }}</button>
                            <button type="submit" class="ag-btn is-primary" :disabled="busy">@{{ labels.save }}</button>
                        </div>
                    </form>
                </div>

                <!-- Appointment details -->
                <div v-if="detail" class="ag-back" @click.self="detail = null">
                    <div class="ag-modal">
                        <h2>@{{ detail.person || detail.lead || detail.title }}</h2>

                        <dl class="ag-detail">
                            <dt>@{{ labels.service }}</dt><dd>@{{ detail.title }}</dd>
                            <dt>@{{ labels.start }}</dt><dd>@{{ dayLabel(detail.start) }} · @{{ hhmm(detail.start) }}–@{{ hhmm(detail.end) }}</dd>
                            <dt>@{{ labels.professional }}</dt><dd>@{{ detail.user_name || '—' }}</dd>
                            <dt>@{{ labels.stage }}</dt><dd>@{{ detail.stage || labels.no_stage }}</dd>
                            <dt v-if="detail.comment">@{{ labels.notes }}</dt><dd v-if="detail.comment">@{{ detail.comment }}</dd>
                            <dt v-if="detail.is_done || detail.overdue">&nbsp;</dt>
                            <dd v-if="detail.is_done"><strong>@{{ labels.done }}</strong></dd>
                            <dd v-else-if="detail.overdue" style="color:#dc2626"><strong>@{{ labels.overdue }}</strong></dd>
                        </dl>

                        <div class="ag-footer">
                            <a v-if="detail.lead_id" class="ag-btn" :href="url('lead', detail.lead_id)">@{{ labels.open_lead }}</a>
                            <a v-if="detail.lead_id && canOpenInbox" class="ag-btn" :href="url('inbox', detail.lead_id)">@{{ labels.open_chat }}</a>
                            <a class="ag-btn" :href="url('activity', detail.id)">@{{ labels.edit }}</a>
                            <button v-if="canCreate && ! detail.is_done" type="button" class="ag-btn is-primary" :disabled="busy" @click="markDone(detail)">@{{ labels.mark_done }}</button>
                            <button type="button" class="ag-btn" @click="detail = null">@{{ labels.close }}</button>
                        </div>
                    </div>
                </div>
            </div>
        </script>

        <script type="module">
            app.component('v-agenda', {
                template: '#v-agenda-template',

                props: {
                    urls: { type: Object, required: true },
                    labels: { type: Object, required: true },
                    users: { type: Array, default: () => [] },
                    currentUserId: { type: Number, default: 0 },
                    durations: { type: Array, default: () => [30, 60] },
                    canCreate: { type: Boolean, default: false },
                    canOpenInbox: { type: Boolean, default: false },
                    locale: { type: String, default: 'pt-br' },
                },

                data() {
                    return {
                        selectedDate: new Date(),
                        range: null,
                        events: [],
                        todayEvents: [],
                        selectedUsers: [],
                        includeDone: false,
                        theme: document.documentElement.classList.contains('dark') ? 'dark' : 'light',
                        palette: ['#93c5fd', '#86efac', '#fde68a', '#fca5a5', '#c4b5fd', '#f9a8d4', '#a5f3fc', '#fdba74'],
                        borders: ['#2563eb', '#16a34a', '#d97706', '#dc2626', '#7c3aed', '#db2777', '#0891b2', '#ea580c'],
                        form: null,
                        search: '',
                        results: [],
                        searched: false,
                        searchTimer: null,
                        problem: '',
                        conflicts: [],
                        detail: null,
                        busy: false,
                    };
                },

                computed: {
                    calendarEvents() {
                        return this.events.map(item => this.decorate(item));
                    },

                    legend() {
                        const seen = new Map();

                        [...this.events, ...this.todayEvents].forEach(item => {
                            const key = item.stage_id ?? 'none';

                            if (! seen.has(key)) {
                                seen.set(key, { key, name: item.stage || this.labels.no_stage, border: this.colorFor(item.stage_id, true) });
                            }
                        });

                        return [...seen.values()];
                    },
                },

                mounted() {
                    this.loadToday();

                    if (this.$emitter) {
                        this.$emitter.on('change-theme', theme => { this.theme = theme; });
                    }
                },

                methods: {
                    url(name, id) {
                        return this.urls[name].replace('__ID__', id);
                    },

                    pad(value) {
                        return String(value).padStart(2, '0');
                    },

                    ymd(date) {
                        return date.getFullYear() + '-' + this.pad(date.getMonth() + 1) + '-' + this.pad(date.getDate());
                    },

                    // The calendar hands events back with Date objects; the API sends "Y-m-d H:i" text.
                    hhmm(value) {
                        if (value instanceof Date) {
                            return this.pad(value.getHours()) + ':' + this.pad(value.getMinutes());
                        }

                        return String(value).slice(11, 16);
                    },

                    dayLabel(value) {
                        if (value instanceof Date) {
                            return this.pad(value.getDate()) + '/' + this.pad(value.getMonth() + 1) + '/' + value.getFullYear();
                        }

                        const [year, month, day] = String(value).slice(0, 10).split('-');

                        return day + '/' + month + '/' + year;
                    },

                    colorFor(stageId, strong = false) {
                        if (stageId === null || stageId === undefined) {
                            return strong ? '#6b7280' : '#e5e7eb';
                        }

                        const index = Number(stageId) % this.palette.length;

                        return strong ? this.borders[index] : this.palette[index];
                    },

                    decorate(item) {
                        const now = new Date();
                        const end = new Date(String(item.end).replace(' ', 'T'));

                        return Object.assign({}, item, {
                            class: item.is_done ? 'is-done' : '',
                            bg: this.colorFor(item.stage_id),
                            border: this.colorFor(item.stage_id, true),
                            overdue: ! item.is_done && end < now,
                        });
                    },

                    params(start, end) {
                        const params = { start, end, include_done: this.includeDone ? 1 : 0 };

                        if (this.selectedUsers.length) {
                            params.user_ids = this.selectedUsers;
                        }

                        return params;
                    },

                    async load() {
                        if (! this.range) {
                            return;
                        }

                        try {
                            const { data } = await this.$axios.get(this.urls.events, { params: this.params(this.range.start, this.range.end) });

                            this.events = data.data;
                        } catch (error) {
                            this.events = [];
                        }
                    },

                    async loadToday() {
                        const today = this.ymd(new Date());

                        try {
                            const { data } = await this.$axios.get(this.urls.events, { params: this.params(today, today) });

                            this.todayEvents = data.data.map(item => this.decorate(item));
                        } catch (error) {
                            this.todayEvents = [];
                        }
                    },

                    onViewChange({ startDate, endDate }) {
                        this.range = { start: this.ymd(new Date(startDate)), end: this.ymd(new Date(endDate)) };
                        this.load();
                    },

                    goToday() {
                        const today = new Date();

                        this.selectedDate = today;

                        if (this.$refs.calendar && typeof this.$refs.calendar.switchView === 'function') {
                            this.$refs.calendar.switchView(this.$refs.calendar.view?.id || 'week', today);
                        }
                    },

                    toggleUser(id) {
                        this.selectedUsers = this.selectedUsers.includes(id)
                            ? this.selectedUsers.filter(item => item !== id)
                            : [...this.selectedUsers, id];

                        this.load();
                        this.loadToday();
                    },

                    localInput(date) {
                        return this.ymd(date) + 'T' + this.pad(date.getHours()) + ':' + this.pad(date.getMinutes());
                    },

                    onCellClick(date) {
                        if (this.canCreate) {
                            this.openNew(date instanceof Date ? date : new Date(date));
                        }
                    },

                    onEventClick(event, e) {
                        if (e && e.stopPropagation) {
                            e.stopPropagation();
                        }

                        this.openDetail(event);
                    },

                    openDetail(item) {
                        this.detail = Object.assign({}, item);
                    },

                    openNew(date) {
                        const start = date || (() => { const d = new Date(); d.setMinutes(0, 0, 0); d.setHours(d.getHours() + 1); return d; })();

                        this.problem = '';
                        this.conflicts = [];
                        this.search = '';
                        this.results = [];
                        this.searched = false;
                        this.form = {
                            lead: null,
                            title: '',
                            comment: '',
                            start: this.localInput(start),
                            duration: this.durations.includes(30) ? 30 : this.durations[0],
                            user_id: this.users.some(user => user.id === this.currentUserId) ? this.currentUserId : (this.users[0] ? this.users[0].id : null),
                        };
                    },

                    searchLeads() {
                        clearTimeout(this.searchTimer);

                        if (this.search.trim().length < 2) {
                            this.results = [];
                            this.searched = false;

                            return;
                        }

                        this.searchTimer = setTimeout(async () => {
                            try {
                                const { data } = await this.$axios.get(this.urls.leads, { params: { q: this.search.trim() } });

                                this.results = data.data;
                                this.searched = true;
                            } catch (error) {
                                this.results = [];
                            }
                        }, 250);
                    },

                    pick(lead) {
                        this.form.lead = lead;
                        this.results = [];
                        this.search = '';
                    },

                    async submit(force) {
                        if (! this.form.lead) {
                            this.problem = this.labels.lead_required;
                            this.conflicts = [];

                            return;
                        }

                        this.busy = true;
                        this.problem = '';

                        try {
                            await this.$axios.post(this.urls.store, {
                                lead_id: this.form.lead.id,
                                title: this.form.title,
                                comment: this.form.comment,
                                start: this.form.start,
                                duration: this.form.duration,
                                user_id: this.form.user_id,
                                ignore_conflict: force ? 1 : 0,
                            });

                            this.form = null;
                            this.$emitter.emit('add-flash', { type: 'success', message: this.labels.saved });
                            this.load();
                            this.loadToday();
                        } catch (error) {
                            const body = error.response && error.response.data;

                            this.conflicts = (body && body.conflicts) || [];
                            this.problem = (body && (body.message || Object.values(body.errors || {}).flat()[0])) || this.labels.error;
                        } finally {
                            this.busy = false;
                        }
                    },

                    async markDone(item) {
                        this.busy = true;

                        try {
                            await this.$axios.post(this.url('done', item.id));

                            this.detail = null;
                            this.$emitter.emit('add-flash', { type: 'success', message: this.labels.marked_done });
                            this.load();
                            this.loadToday();
                        } catch (error) {
                            this.$emitter.emit('add-flash', { type: 'error', message: this.labels.error });
                        } finally {
                            this.busy = false;
                        }
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
