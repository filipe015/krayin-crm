{{--
    Opens the booking card when a deal reaches a stage the installation chose (Configurações → Agenda).
    Pages announce it with $emitter.emit('agenda-stage-changed', { lead_id }); this asks the server
    whether the card should open (the stage is chosen, nothing waiting yet) and opens it. Closing the
    card without booking only shows a warning.

    @include('whatsapp::agenda.stage-prompt', ['withBook' => true])  the page has no booking card yet
    @include('whatsapp::agenda.stage-prompt')                        the page already has one (lead page)
--}}
@if (bouncer()->hasPermission('agenda.create'))
    @php
        $book = \Webkul\WhatsApp\Http\Controllers\AgendaController::bookingProps();
    @endphp

    @if ($withBook ?? false)
        @include('whatsapp::agenda.styles')
        @include('whatsapp::agenda.book')

        <v-agenda-book
            :urls="{{ Illuminate\Support\Js::from($book['bookUrls']) }}"
            :labels="{{ Illuminate\Support\Js::from($book['labels']) }}"
            :users="{{ Illuminate\Support\Js::from($book['users']) }}"
            :current-user-id="{{ $book['currentUserId'] }}"
            :durations="{{ Illuminate\Support\Js::from($book['durations']) }}"
            :default-duration="{{ $book['defaultDuration'] }}"
            :auto-assign="{{ $book['autoAssign'] ? 'true' : 'false' }}"
        ></v-agenda-book>
    @endif

    <v-agenda-stage-prompt
        url="{{ route('admin.agenda.stage-prompt') }}"
        skipped-message="{{ $book['labels']['stage_skipped'] }}"
        :pending-lead-id="{{ (int) session('agenda_prompt_lead_id', 0) }}"
    ></v-agenda-stage-prompt>

    @pushOnce('scripts')
        <script type="module">
            app.component('v-agenda-stage-prompt', {
                props: {
                    url: { type: String, required: true },
                    skippedMessage: { type: String, default: '' },
                    // A deal created on a page that reloaded (the full form, the table): asked once the next page is up.
                    pendingLeadId: { type: Number, default: 0 },
                },

                data() {
                    return { waiting: false };
                },

                mounted() {
                    this.$emitter.on('agenda-stage-changed', payload => this.check(payload && payload.lead_id));

                    this.$emitter.on('agenda-booked', () => { this.waiting = false; });

                    this.$emitter.on('agenda-book-closed', () => {
                        if (this.waiting) {
                            this.waiting = false;

                            this.$emitter.emit('add-flash', { type: 'warning', message: this.skippedMessage });
                        }
                    });

                    let leadId = this.pendingLeadId;

                    try {
                        leadId = leadId || Number(sessionStorage.getItem('agendaPromptLead') || 0);
                        sessionStorage.removeItem('agendaPromptLead');
                    } catch (error) {}

                    if (leadId) {
                        this.check(leadId);
                    }
                },

                methods: {
                    check(leadId) {
                        if (! leadId) {
                            return;
                        }

                        this.$axios.post(this.url, { lead_id: leadId })
                            .then(response => {
                                if (! response.data.open) {
                                    return;
                                }

                                this.waiting = true;

                                this.$emitter.emit('agenda-open-book', { lead: response.data.lead, kind: response.data.kind, lockKind: true });
                            })
                            .catch(() => {});
                    },
                },

                render() {
                    return null;
                },
            });
        </script>
    @endPushOnce
@endif
