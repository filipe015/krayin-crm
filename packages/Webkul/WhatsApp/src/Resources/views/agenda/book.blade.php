{{--
    The booking card, shared by the Agenda page ("Nova consulta") and the lead's "Agendar" button.
    It is opened with $emitter.emit('agenda-open-book', { lead?, start?, kind? }) and announces
    'agenda-booked' once saved. Two kinds: a scheduled appointment (professional, duration, online or
    in person, conflict check) and a follow-up ("retomar contato") that only needs a date and a note.
--}}
@pushOnce('scripts')
    <script type="text/x-template" id="v-agenda-book-template">
        <Teleport to="body">
        <div v-if="form" class="ag-back" @click.self="form = null">
            <form class="ag ag-modal" @submit.prevent="submit(false)">
                <h2>@{{ form.kind === 'followup' ? labels.kind_followup : labels.new }}</h2>

                <div class="ag-kind" v-if="! form.lockKind">
                    <button type="button" class="ag-chip" :class="{ 'is-on': form.kind === 'appointment' }" @click="setKind('appointment')">@{{ labels.kind_appointment }}</button>
                    <button type="button" class="ag-chip" :class="{ 'is-on': form.kind === 'followup' }" @click="setKind('followup')">@{{ labels.kind_followup }}</button>
                </div>

                <p v-if="form.kind === 'followup'" class="ag-hint">@{{ labels.followup_hint }}</p>

                <div class="ag-field">
                    @{{ labels.lead }}

                    <div v-if="form.lead" class="ag-picked">
                        <span>
                            <strong>@{{ form.lead.person || form.lead.title }}</strong>
                            <span class="ag-muted" v-if="form.lead.stage"> · @{{ form.lead.stage }}</span>
                        </span>
                        <button v-if="! form.lockLead" type="button" class="ag-btn" @click="form.lead = null">×</button>
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
                    @{{ form.kind === 'followup' ? labels.followup_subject : labels.service }}
                    <input
                        type="text"
                        v-model="form.title"
                        maxlength="120"
                        :placeholder="form.kind === 'followup' ? labels.followup_title : labels.default_title"
                    >
                </label>

                <div class="ag-row">
                    <label class="ag-field">
                        @{{ form.kind === 'followup' ? labels.followup_when : labels.start }}
                        <input type="datetime-local" v-model="form.start" required @input="form.edited = true">
                    </label>

                    <label class="ag-field" v-if="form.kind === 'appointment'">
                        @{{ labels.duration }}
                        <select v-model="form.duration">
                            <option v-for="minutes in durations" :key="minutes" :value="minutes">@{{ minutes }} @{{ labels.minutes }}</option>
                        </select>
                    </label>
                </div>

                <label class="ag-field">
                    @{{ form.kind === 'followup' ? labels.followup_who : labels.professional }}
                    <select v-model="form.user_id">
                        <option v-if="autoAssign && form.kind === 'appointment'" value="auto">@{{ labels.auto }}</option>
                        <option v-for="user in users" :key="user.id" :value="user.id">@{{ user.name }}</option>
                    </select>
                </label>

                <div class="ag-field" v-if="form.kind === 'appointment'">
                    @{{ labels.mode }}

                    <div class="ag-row">
                        <button type="button" class="ag-chip" :class="{ 'is-on': form.online }" @click="form.online = true">@{{ labels.online }}</button>
                        <button type="button" class="ag-chip" :class="{ 'is-on': ! form.online }" @click="form.online = false">@{{ labels.in_person }}</button>
                    </div>

                    <input
                        type="text"
                        v-model="form.location"
                        maxlength="255"
                        :placeholder="form.online ? labels.link_ph : labels.address_ph"
                        :aria-label="form.online ? labels.link_label : labels.address_label"
                    >
                </div>

                <label class="ag-field">
                    @{{ form.kind === 'followup' ? labels.followup_note : labels.notes }}
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
                    <button type="submit" class="ag-btn is-primary" :disabled="busy">@{{ form.kind === 'followup' ? labels.followup_save : labels.save }}</button>
                </div>
            </form>
        </div>
        </Teleport>
    </script>

    <script type="module">
        app.component('v-agenda-book', {
            template: '#v-agenda-book-template',

            props: {
                urls: { type: Object, required: true },
                labels: { type: Object, required: true },
                users: { type: Array, default: () => [] },
                currentUserId: { type: Number, default: 0 },
                durations: { type: Array, default: () => [30, 60] },
                defaultDuration: { type: Number, default: 30 },
                autoAssign: { type: Boolean, default: false },
            },

            data() {
                return {
                    form: null,
                    search: '',
                    results: [],
                    searched: false,
                    searchTimer: null,
                    problem: '',
                    conflicts: [],
                    busy: false,
                };
            },

            mounted() {
                this.$emitter.on('agenda-open-book', payload => this.open(payload || {}));
            },

            methods: {
                pad(value) {
                    return String(value).padStart(2, '0');
                },

                localInput(date) {
                    return date.getFullYear() + '-' + this.pad(date.getMonth() + 1) + '-' + this.pad(date.getDate())
                        + 'T' + this.pad(date.getHours()) + ':' + this.pad(date.getMinutes());
                },

                open({ lead = null, start = null, kind = 'appointment', lockKind = false } = {}) {
                    const followup = kind === 'followup';
                    let when = start ? new Date(start) : null;

                    if (! when) {
                        when = new Date();

                        if (followup) {
                            // A follow-up defaults to tomorrow morning.
                            when.setDate(when.getDate() + 1);
                            when.setHours(9, 0, 0, 0);
                        } else {
                            when.setMinutes(0, 0, 0);
                            when.setHours(when.getHours() + 1);
                        }
                    }

                    this.problem = '';
                    this.conflicts = [];
                    this.search = '';
                    this.results = [];
                    this.searched = false;

                    const mine = this.users.some(user => user.id === this.currentUserId)
                        ? this.currentUserId
                        : (this.users[0] ? this.users[0].id : null);

                    this.form = {
                        kind,
                        lockKind,
                        lead,
                        lockLead: !! lead,
                        title: '',
                        comment: '',
                        start: this.localInput(when),
                        duration: this.durations.includes(this.defaultDuration) ? this.defaultDuration : this.durations[0],
                        online: true,
                        location: '',
                        user_id: this.autoAssign ? 'auto' : mine,
                        mine,
                        edited: !! start,
                    };
                },

                // Switching kind moves the suggested time too, unless the operator already typed one.
                setKind(kind) {
                    if (this.form.kind === kind) {
                        return;
                    }

                    this.form.kind = kind;

                    if (this.form.edited) {
                        return;
                    }

                    const when = new Date();

                    if (kind === 'followup') {
                        when.setDate(when.getDate() + 1);
                        when.setHours(9, 0, 0, 0);
                    } else {
                        when.setMinutes(0, 0, 0);
                        when.setHours(when.getHours() + 1);
                    }

                    this.form.start = this.localInput(when);
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

                    const followup = this.form.kind === 'followup';

                    // "Automático" only exists for appointments; a follow-up belongs to a person.
                    const userId = followup && this.form.user_id === 'auto' ? this.form.mine : this.form.user_id;

                    this.busy = true;
                    this.problem = '';

                    try {
                        await this.$axios.post(this.urls.store, {
                            kind: this.form.kind,
                            lead_id: this.form.lead.id,
                            title: this.form.title,
                            comment: this.form.comment,
                            location: followup ? '' : this.form.location,
                            start: this.form.start,
                            duration: followup ? this.durations[0] : this.form.duration,
                            user_id: userId,
                            ignore_conflict: force ? 1 : 0,
                        });

                        this.form = null;
                        this.$emitter.emit('add-flash', { type: 'success', message: followup ? this.labels.followup_saved : this.labels.saved });
                        this.$emitter.emit('agenda-booked');
                    } catch (error) {
                        const body = error.response && error.response.data;

                        this.conflicts = (body && body.conflicts) || [];
                        this.problem = (body && (body.message || Object.values(body.errors || {}).flat()[0])) || this.labels.error;
                    } finally {
                        this.busy = false;
                    }
                },
            },
        });
    </script>
@endPushOnce
