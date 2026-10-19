<x-admin::layouts>
    <x-slot:title>
        @lang('whatsapp::app.agenda.title')
    </x-slot>

    <v-agenda
        :urls="{{ Illuminate\Support\Js::from($urls) }}"
        :labels="{{ Illuminate\Support\Js::from($labels) }}"
        :users="{{ Illuminate\Support\Js::from($users) }}"
        :current-user-id="{{ (int) $currentUserId }}"
        :durations="{{ Illuminate\Support\Js::from($durations) }}"
        :day-start="{{ $dayStart }}"
        :day-end="{{ $dayEnd }}"
        :default-duration="{{ $defaultDuration }}"
        :auto-assign="{{ $autoAssign ? 'true' : 'false' }}"
        :can-create="{{ $canCreate ? 'true' : 'false' }}"
        :can-open-inbox="{{ $canOpenInbox ? 'true' : 'false' }}"
        :stage-colors="{{ Illuminate\Support\Js::from($stageColors) }}"
        locale="{{ strtolower(str_replace('_', '-', app()->getLocale())) }}"
    >
        <div class="p-6 text-sm text-gray-500">
            @lang('whatsapp::app.agenda.loading')
        </div>
    </v-agenda>

    @include('whatsapp::agenda.styles')
    @include('whatsapp::agenda.book')

    @pushOnce('scripts')

        <script type="text/x-template" id="v-agenda-template">
            <div class="ag">
                <div class="ag-bar">
                    <h1>@{{ labels.title }}</h1>

                    <div class="ag-actions">
                        <button type="button" class="ag-btn" @click="goToday">@{{ labels.today }}</button>
                        <button v-if="canCreate" type="button" class="ag-btn" @click="openNew(null, 'followup')">+ @{{ labels.kind_followup }}</button>
                        <button v-if="canCreate" type="button" class="ag-btn is-primary" @click="openNew(null, 'appointment')">+ @{{ labels.new }}</button>
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
                        <input type="checkbox" v-model="includeFollowups" @change="load(); loadToday()">
                        @{{ labels.show_followups }}
                    </label>

                    <label class="ag-check" style="margin-left:0">
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
                            :time-from="dayStart * 60"
                            :time-to="dayEnd * 60"
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
                                    :class="{ 'is-done': event.is_done, 'is-overdue': event.overdue, 'is-followup': event.kind === 'followup' }"
                                    :style="{ background: event.bg, borderLeftColor: event.border }"
                                >
                                    <strong>@{{ event.person || event.lead || event.title }}</strong>
                                    <span><i class="ag-tag" v-if="event.kind === 'followup'">@{{ labels.followup_tag }}</i>@{{ event.title }}</span>
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
                                <span class="ag-muted"><i class="ag-tag" v-if="item.kind === 'followup'">@{{ labels.followup_tag }}</i>@{{ item.title }}<template v-if="item.user_name"> · @{{ item.user_name }}</template></span>
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

                <!-- New appointment / follow-up (shared with the lead's "Agendar" button) -->
                <v-agenda-book
                    :urls="urls"
                    :labels="labels"
                    :users="users"
                    :current-user-id="currentUserId"
                    :durations="durations"
                    :default-duration="defaultDuration"
                    :auto-assign="autoAssign"
                ></v-agenda-book>

                <!-- Appointment details -->
                <div v-if="detail" class="ag-back" @click.self="detail = null">
                    <div class="ag-modal">
                        <h2>@{{ detail.person || detail.lead || detail.title }}</h2>

                        <dl class="ag-detail">
                            <dt>@{{ detail.kind === 'followup' ? labels.followup_tag : labels.service }}</dt><dd>@{{ detail.title }}</dd>
                            <dt>@{{ labels.start }}</dt><dd>@{{ dayLabel(detail.start) }} · @{{ hhmm(detail.start) }}<template v-if="detail.kind !== 'followup'">–@{{ hhmm(detail.end) }}</template></dd>
                            <dt>@{{ labels.professional }}</dt><dd>@{{ detail.user_name || '—' }}</dd>
                            <dt>@{{ labels.stage }}</dt><dd>@{{ detail.stage || labels.no_stage }}</dd>
                            <dt v-if="detail.location">@{{ labels.location }}</dt>
                            <dd v-if="detail.location">
                                <a v-if="/^https?:\/\//i.test(detail.location)" :href="detail.location" target="_blank" rel="noopener" style="color:var(--ag-accent);word-break:break-all">@{{ detail.location }}</a>
                                <span v-else>@{{ detail.location }}</span>
                            </dd>
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
                    dayStart: { type: Number, default: 7 },
                    dayEnd: { type: Number, default: 21 },
                    defaultDuration: { type: Number, default: 30 },
                    autoAssign: { type: Boolean, default: false },
                    canCreate: { type: Boolean, default: false },
                    canOpenInbox: { type: Boolean, default: false },
                    stageColors: { type: Object, default: () => ({}) },
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
                        includeFollowups: true,
                        theme: document.documentElement.classList.contains('dark') ? 'dark' : 'light',
                        palette: ['#93c5fd', '#86efac', '#fde68a', '#fca5a5', '#c4b5fd', '#f9a8d4', '#a5f3fc', '#fdba74'],
                        borders: ['#2563eb', '#16a34a', '#d97706', '#dc2626', '#7c3aed', '#db2777', '#0891b2', '#ea580c'],
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

                    this.$emitter.on('agenda-booked', () => { this.load(); this.loadToday(); });

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

                    // The installation's own color for the stage (Configurações → Agenda): the card gets a light tint of it
                    // and its strong color on the edge. Stages without one use the built-in palette.
                    tint(hex) {
                        const channel = start => parseInt(hex.slice(start, start + 2), 16);
                        const mix = value => Math.round(value + (255 - value) * 0.72);

                        return 'rgb(' + mix(channel(1)) + ', ' + mix(channel(3)) + ', ' + mix(channel(5)) + ')';
                    },

                    colorFor(stageId, strong = false) {
                        if (stageId === null || stageId === undefined) {
                            return strong ? '#6b7280' : '#e5e7eb';
                        }

                        const custom = this.stageColors[stageId];

                        if (custom) {
                            return strong ? custom : this.tint(custom);
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
                        const params = { start, end, include_done: this.includeDone ? 1 : 0, include_followups: this.includeFollowups ? 1 : 0 };

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
                            this.openNew(date instanceof Date ? date : new Date(date), 'appointment');
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

                    openNew(date, kind = 'appointment') {
                        this.$emitter.emit('agenda-open-book', { start: date, kind });
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
