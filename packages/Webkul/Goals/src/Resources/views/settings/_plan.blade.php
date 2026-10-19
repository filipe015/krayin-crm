{{--
    Plano do mês: the numbers of a month (general or one funnel), the funnel they need worked out as the
    person types (the server does the arithmetic: preview), what that means per week, and the
    month's working days.
--}}
@pushOnce('scripts')
    <script type="text/x-template" id="v-goals-plan-template">
        <div class="gl">
            <!-- Which plan: general or a funnel -->
            <div class="gl-chips">
                <button type="button" class="gl-chip" :class="{ 'is-on': scope === funnel }" v-for="funnel in funnels" :key="'f' + funnel" @click="scope = funnel">
                    @{{ funnel === '' ? labels.scope_general : funnel }}
                </button>

                <template v-if="canEdit">
                    <button type="button" class="gl-chip" v-if="! adding" @click="adding = true">@{{ labels.add_funnel }}</button>

                    <template v-else>
                        <select class="gl-chip" v-model="newFunnel" v-if="available.length">
                            <option value="">@{{ labels.funnel_pick }}</option>
                            <option v-for="name in available" :key="name" :value="name">@{{ name }}</option>
                        </select>

                        <input class="gl-chip" type="text" maxlength="120" v-model="typedFunnel" :placeholder="labels.funnel_name" @keydown.enter.prevent="addFunnel">

                        <button type="button" class="gl-btn is-primary" @click="addFunnel">@{{ labels.add }}</button>
                        <button type="button" class="gl-btn" @click="adding = false">@{{ labels.cancel }}</button>
                    </template>
                </template>

                <button type="button" class="gl-link" v-if="canEdit" style="margin-left:auto" @click="copyPrevious">@{{ labels.copy_previous }}</button>
            </div>

            <p class="gl-note" v-if="! plan">@{{ labels.no_plan }}</p>

            <div class="gl-two">
                <!-- What the person wants -->
                <section class="gl-box">
                    <header><h2>@{{ labels.goals }}</h2></header>

                    <div class="gl-row">
                        <label class="gl-field">
                            @{{ labels.revenue }}
                            <div class="gl-unit"><span>R$</span><input type="number" min="0" step="1" v-model="form.revenue_target" :disabled="! canEdit" @input="changed"></div>
                        </label>

                        <label class="gl-field">
                            @{{ labels.ticket }}
                            <div class="gl-unit"><span>R$</span><input type="number" min="1" step="1" v-model="form.ticket" :disabled="! canEdit" @input="changed"></div>
                        </label>
                    </div>

                    <h3 style="margin-top:16px">@{{ labels.rates }}</h3>

                    <div class="gl-row">
                        <label class="gl-field" v-for="rate in rates" :key="rate">
                            @{{ labels[rate] }}
                            <div class="gl-unit"><input type="number" min="1" max="100" step="1" v-model="form[rate]" :disabled="! canEdit" @input="changed"><span class="is-after">%</span></div>
                        </label>
                    </div>

                    <p class="gl-sub">@{{ labels.hint_rates }}</p>

                    <h3 style="margin-top:16px">@{{ labels.costs }}</h3>

                    <div class="gl-row">
                        <label class="gl-field">
                            @{{ labels.cpl }}
                            <div class="gl-unit"><span>R$</span><input type="number" min="0" step="1" v-model="form.cpl_target" :disabled="! canEdit" @input="changed"></div>
                        </label>

                        <label class="gl-field">
                            @{{ labels.budget }}
                            <div class="gl-unit"><span>R$</span><input type="number" min="0" step="1" v-model="form.budget" :disabled="! canEdit" @input="changed"></div>
                        </label>
                    </div>
                </section>

                <!-- What the funnel then needs -->
                <section class="gl-box">
                    <header>
                        <h2>@{{ labels.chain }}</h2>
                        <p class="gl-sub">@{{ labels.chain_hint }}</p>
                    </header>

                    <div class="gl-chain">
                        <div class="gl-step" v-for="step in steps" :key="step">
                            <strong>@{{ stepNames[step] }}</strong>

                            <input type="number" min="0" step="1" :class="{ 'is-fixed': isFixed(step) }" :value="stepValue(step)" :disabled="! canEdit" @input="fixStep(step, $event.target.value)">

                            <span>
                                <span class="gl-badge" v-if="isFixed(step)">@{{ labels.fixed }}</span>
                                <button type="button" class="gl-link" v-if="isFixed(step) && canEdit" @click="unfix(step)">@{{ labels.undo_fixed }}</button>
                            </span>
                        </div>
                    </div>

                    <div class="gl-cards">
                        <div class="gl-card">
                            <span>@{{ labels.sales_worth }}</span>
                            <strong>@{{ money(preview.revenue_implied) }}</strong>
                            <small>@{{ labels.of_goal }}: @{{ money(form.revenue_target) }}</small>
                        </div>

                        <div class="gl-card">
                            <span>@{{ labels.investment_needed }}</span>
                            <strong>@{{ money(preview.budget_needed) }}</strong>
                            <small>@{{ labels.planned }}: @{{ money(form.budget) }}</small>
                        </div>
                    </div>
                </section>
            </div>

            <div class="gl-row" v-if="canEdit">
                <button type="button" class="gl-btn is-primary" :disabled="saving" @click="save">@{{ labels.save }}</button>
                <button type="button" class="gl-btn is-danger" v-if="plan" :disabled="saving" @click="remove">@{{ labels.remove }}</button>
            </div>

            <!-- Funnel plans against the general plan -->
            <div class="gl-note is-warn" v-if="gapLines.length">
                <strong>@{{ labels.gap_title }}</strong>
                <div v-for="line in gapLines" :key="line">@{{ line }}</div>
            </div>

            <!-- What it means per week -->
            <section class="gl-box" v-if="plan">
                <header><h2>@{{ labels.schedule }}</h2></header>

                <h3>@{{ labels.by_week }}</h3>

                <div class="gl-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>@{{ labels.week }}</th>
                                <th>@{{ labels.working_days_short }}</th>
                                <th v-for="column in columns" :key="column.key">@{{ column.label }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(week, index) in plan.weeks" :key="week.week" :class="{ 'is-off': week.working === 0 }">
                                <td>@{{ index + 1 }} · @{{ shortDate(week.start, data.month) }} – @{{ shortDate(week.end, data.month) }}</td>
                                <td>@{{ week.working }}</td>
                                <td v-for="column in columns" :key="column.key">@{{ cell(column, week.targets) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Working days of the month -->
            <section class="gl-box">
                <header>
                    <h2>@{{ labels.working_days }} · @{{ labels.working_count.replace(':count', picked.length) }}</h2>
                    <p class="gl-sub">@{{ labels.working_hint }}</p>
                </header>

                <div class="gl-days">
                    <button type="button" class="gl-day" v-for="day in data.days" :key="day.date" :class="{ 'is-on': picked.includes(day.date) }" :disabled="! canEdit" @click="toggleDay(day.date)">
                        @{{ day.date.slice(8, 10) }}
                        <small>@{{ weekdayNames[day.weekday] }}</small>
                    </button>
                </div>

                <div class="gl-row" style="margin-top:12px" v-if="canEdit">
                    <button type="button" class="gl-btn is-primary" :disabled="! daysChanged || ! picked.length" @click="saveDays">@{{ labels.working_save }}</button>
                    <button type="button" class="gl-btn" @click="useDefaultDays">@{{ labels.working_default }}</button>
                </div>
            </section>
        </div>
    </script>

    <script type="module">
        const BLANK = { revenue_target: '', ticket: '', qualification_rate: '', scheduling_rate: '', attendance_rate: '', closing_rate: '', cpl_target: '', budget: '' };

        app.component('v-goals-plan', {
            template: '#v-goals-plan-template',

            props: {
                data: { type: Object, required: true },
                urls: { type: Object, required: true },
                labels: { type: Object, required: true },
                stepNames: { type: Object, required: true },
                weekdayNames: { type: Object, required: true },
                canEdit: { type: Boolean, default: false },
            },

            emits: ['update'],

            data() {
                return {
                    scope: '',
                    extraFunnels: [],
                    adding: false,
                    newFunnel: '',
                    typedFunnel: '',
                    form: { ...BLANK },
                    fixed: {},
                    preview: {},
                    picked: [],
                    saving: false,
                    timer: null,
                    rates: ['qualification_rate', 'scheduling_rate', 'attendance_rate', 'closing_rate'],
                    // From the top of the funnel to the sale.
                    steps: ['leads', 'qualified', 'scheduled', 'attended', 'sales'],
                };
            },

            computed: {
                plan() {
                    return this.data.plans.find(plan => plan.funnel === this.scope) || null;
                },

                funnels() {
                    const names = this.data.plans.map(plan => plan.funnel).filter(name => name !== '');

                    return ['', ...new Set([...names, ...this.extraFunnels])];
                },

                available() {
                    return this.data.suggested_funnels.filter(name => ! this.funnels.includes(name));
                },

                columns() {
                    return [
                        { key: 'leads_target', label: this.stepNames.leads },
                        { key: 'qualified_target', label: this.stepNames.qualified },
                        { key: 'scheduled_target', label: this.stepNames.scheduled },
                        { key: 'attended_target', label: this.stepNames.attended },
                        { key: 'sales_target', label: this.stepNames.sales },
                        { key: 'revenue_target', label: this.labels.revenue_short, money: true },
                        { key: 'budget', label: this.labels.budget_short, money: true },
                    ];
                },

                daysChanged() {
                    return JSON.stringify([...this.picked].sort()) !== JSON.stringify([...this.data.working_days].sort());
                },

                gapLines() {
                    const names = { revenue_target: this.labels.revenue, leads_target: this.stepNames.leads, sales_target: this.stepNames.sales };

                    return Object.entries(this.data.gap)
                        .filter(([metric, gap]) => names[metric] && gap.gap !== 0)
                        .map(([metric, gap]) => (gap.gap > 0 ? this.labels.gap_less : this.labels.gap_more)
                            .replace(':metric', names[metric])
                            .replace(':funnels', this.number(gap.funnels, metric))
                            .replace(':general', this.number(gap.general, metric))
                            .replace(':gap', this.number(Math.abs(gap.gap), metric)));
                },
            },

            watch: {
                data: { immediate: true, handler() { this.fill(); } },

                scope() {
                    this.fill();
                },
            },

            beforeUnmount() {
                clearTimeout(this.timer);
            },

            methods: {
                /**
                 * The form follows the saved plan of the chosen funnel (or starts blank).
                 */
                fill() {
                    this.picked = [...this.data.working_days];

                    const plan = this.plan;

                    this.form = { ...BLANK };
                    this.fixed = {};
                    this.preview = {};

                    if (! plan) {
                        return;
                    }

                    Object.keys(BLANK).forEach(key => this.form[key] = plan.values[key] ?? '');

                    plan.fixed.forEach(step => this.fixed[step] = String(plan.values[step + '_target']));

                    this.preview = { ...plan.values, revenue_implied: plan.revenue_implied, budget_needed: plan.budget_needed };
                },

                payload() {
                    const payload = { month: this.data.month, funnel: this.scope, fixed: Object.keys(this.fixed), ...this.form };

                    Object.entries(this.fixed).forEach(([step, value]) => payload[step + '_target'] = value);

                    return payload;
                },

                // Every change asks the server what the funnel needs (the arithmetic lives there, once).
                changed() {
                    clearTimeout(this.timer);

                    this.timer = setTimeout(async () => {
                        try {
                            const { data } = await this.$axios.post(this.urls.preview, this.payload());

                            this.preview = data;
                        } catch (error) {
                            // Half-typed numbers are refused; the last good preview stays until they are valid.
                        }
                    }, 350);
                },

                isFixed(step) {
                    return this.fixed[step] !== undefined;
                },

                stepValue(step) {
                    return this.isFixed(step) ? this.fixed[step] : (this.preview[step + '_target'] ?? '');
                },

                fixStep(step, value) {
                    if (value === '') {
                        delete this.fixed[step];
                    } else {
                        this.fixed[step] = value;
                    }

                    this.changed();
                },

                unfix(step) {
                    delete this.fixed[step];

                    this.changed();
                },

                async save() {
                    this.saving = true;

                    try {
                        const { data } = await this.$axios.post(this.urls.plan, this.payload());

                        this.$emit('update', data);
                        this.$emitter.emit('add-flash', { type: 'success', message: this.labels.saved });
                    } catch (error) {
                        this.$emitter.emit('add-flash', { type: 'error', message: error.response?.data?.message || this.labels.save_error });
                    } finally {
                        this.saving = false;
                    }
                },

                async remove() {
                    if (! window.confirm(this.labels.remove_confirm)) {
                        return;
                    }

                    this.saving = true;

                    try {
                        const { data } = await this.$axios.delete(this.urls.plan, { data: { month: this.data.month, funnel: this.scope } });

                        this.$emit('update', data);
                        this.$emitter.emit('add-flash', { type: 'success', message: this.labels.removed });
                    } catch (error) {
                        this.$emitter.emit('add-flash', { type: 'error', message: this.labels.save_error });
                    } finally {
                        this.saving = false;
                    }
                },

                addFunnel() {
                    const name = (this.typedFunnel || this.newFunnel).trim();

                    if (name === '') {
                        return;
                    }

                    if (! this.funnels.includes(name)) {
                        this.extraFunnels.push(name);
                    }

                    this.scope = name;
                    this.adding = false;
                    this.newFunnel = '';
                    this.typedFunnel = '';
                },

                async copyPrevious() {
                    const [year, month] = this.data.month.split('-').map(Number);
                    const previous = new Date(year, month - 2, 1);
                    const from = previous.getFullYear() + '-' + String(previous.getMonth() + 1).padStart(2, '0');

                    if (! window.confirm(this.labels.copy_confirm)) {
                        return;
                    }

                    try {
                        const { data } = await this.$axios.post(this.urls.copy, { from, to: this.data.month });

                        this.$emit('update', data);
                        this.$emitter.emit('add-flash', { type: data.copied ? 'success' : 'warning', message: data.copied ? this.labels.copied : this.labels.copy_empty });
                    } catch (error) {
                        this.$emitter.emit('add-flash', { type: 'error', message: this.labels.save_error });
                    }
                },

                toggleDay(date) {
                    this.picked = this.picked.includes(date) ? this.picked.filter(day => day !== date) : [...this.picked, date];
                },

                useDefaultDays() {
                    this.picked = this.data.days.filter(day => this.data.settings.default_weekdays.includes(day.weekday)).map(day => day.date);
                },

                async saveDays() {
                    try {
                        const { data } = await this.$axios.post(this.urls.workingDays, { month: this.data.month, days: this.picked });

                        this.$emit('update', data);
                        this.$emitter.emit('add-flash', { type: 'success', message: this.labels.working_saved });
                    } catch (error) {
                        this.$emitter.emit('add-flash', { type: 'error', message: error.response?.data?.message || this.labels.save_error });
                    }
                },

                isWorking(date) {
                    return this.data.working_days.includes(date);
                },

                weekdayName(date) {
                    return this.weekdayNames[this.data.days.find(day => day.date === date)?.weekday] || '';
                },

                shortDate(date, month) {
                    // Days of the neighbouring months show their month too.
                    return date.slice(0, 7) === month ? date.slice(8, 10) : date.slice(8, 10) + '/' + date.slice(5, 7);
                },

                number(value, metric) {
                    return ['revenue_target', 'budget'].includes(metric) ? this.money(value) : Number(value).toLocaleString('pt-BR');
                },

                money(value) {
                    return value === null || value === undefined || value === '' ? '—' : 'R$ ' + Number(value).toLocaleString('pt-BR');
                },

                cell(column, targets) {
                    const value = targets?.[column.key];

                    if (value === undefined) {
                        return '—';
                    }

                    return column.money ? this.money(value) : Number(value).toLocaleString('pt-BR');
                },
            },
        });
    </script>
@endPushOnce
