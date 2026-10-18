{{--
    Histórico: who changed what and when (the plans, the working days and the settings), newest first.
--}}
@pushOnce('scripts')
    <script type="text/x-template" id="v-goals-history-template">
        <section class="gl-box">
            <header class="gl-row" style="justify-content:space-between;align-items:center">
                <h2>@{{ labels.tabs.history }}</h2>

                <button type="button" class="gl-link" @click="onlyMonth = ! onlyMonth">
                    @{{ onlyMonth ? labels.all_months : labels.only_month }}
                </button>
            </header>

            <div class="gl-wrap" v-if="items.length">
                <table>
                    <thead>
                        <tr>
                            <th>@{{ labels.when }}</th>
                            <th class="is-left">@{{ labels.who }}</th>
                            <th class="is-left">@{{ labels.month }}</th>
                            <th class="is-left">@{{ labels.what }}</th>
                            <th class="is-left">@{{ labels.before }}</th>
                            <th class="is-left">@{{ labels.after }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in items" :key="item.id">
                            <td>@{{ when(item.at) }}</td>
                            <td class="is-left">@{{ item.user || labels.system }}</td>
                            <td class="is-left">@{{ item.month ? monthName(item.month) : '—' }}<template v-if="item.subject === 'plan'"> · @{{ item.funnel || labels.general }}</template></td>
                            <td class="is-left">@{{ item.field }}</td>
                            <td class="is-left">@{{ item.old }}</td>
                            <td class="is-left">@{{ item.new }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="gl-empty" v-else-if="loaded">@{{ labels.history_empty }}</p>
            <p class="gl-empty" v-else>@{{ labels.loading }}</p>

            <div style="margin-top:12px" v-if="hasMore">
                <button type="button" class="gl-btn" :disabled="busy" @click="more">@{{ labels.load_more }}</button>
            </div>
        </section>
    </script>

    <script type="module">
        app.component('v-goals-history', {
            template: '#v-goals-history-template',

            props: {
                urls: { type: Object, required: true },
                labels: { type: Object, required: true },
                month: { type: String, required: true },
            },

            data() {
                return { items: [], page: 1, hasMore: false, loaded: false, busy: false, onlyMonth: false };
            },

            watch: {
                month() {
                    this.reload();
                },

                onlyMonth() {
                    this.reload();
                },
            },

            mounted() {
                this.reload();
            },

            methods: {
                reload() {
                    this.items = [];
                    this.page = 1;
                    this.loaded = false;

                    return this.fetch();
                },

                more() {
                    this.page++;

                    return this.fetch();
                },

                async fetch() {
                    this.busy = true;

                    try {
                        const { data } = await this.$axios.get(this.urls.history, { params: { month: this.onlyMonth ? this.month : null, page: this.page } });

                        this.items = [...this.items, ...data.items];
                        this.hasMore = data.has_more;
                    } finally {
                        this.loaded = true;
                        this.busy = false;
                    }
                },

                when(iso) {
                    return new Date(iso).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
                },

                monthName(month) {
                    return new Date(month + '-01T12:00:00').toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' });
                },
            },
        });
    </script>
@endPushOnce
