{{--
    Alertas: the rules of the automatic warnings (pace, conversion rate, CPL, investment). They are
    sent to every user through the notification center once a day while the rule holds.
--}}
@pushOnce('scripts')
    <script type="text/x-template" id="v-goals-alerts-template">
        <div class="gl">
            <section class="gl-box">
                <header>
                    <h2>@{{ labels.al_title }}</h2>
                    <p class="gl-sub">@{{ labels.al_hint }}</p>
                </header>

                <label style="display:flex;gap:8px;align-items:center;margin-bottom:12px">
                    <input type="checkbox" v-model="enabled" :disabled="! canEdit"> @{{ labels.al_enabled }}
                </label>

                <p class="gl-sub" v-if="! rules.length">@{{ labels.al_none }}</p>

                <div style="display:flex;flex-direction:column;gap:10px">
                    <div class="gl-rule" v-for="(rule, index) in rules" :key="index">
                        <div class="gl-row">
                            <label class="gl-field">
                                @{{ labels.al_kind }}
                                <select v-model="rule.kind" :disabled="! canEdit" @change="kindChanged(rule)">
                                    <option v-for="kind in kinds" :key="kind" :value="kind">@{{ labels['al_' + kind] }}</option>
                                </select>
                            </label>

                            <label class="gl-field" v-if="rule.kind === 'pace' || rule.kind === 'rate'">
                                @{{ labels.al_metric }}
                                <select v-model="rule.metric" :disabled="! canEdit">
                                    <option v-for="metric in metricsOf(rule.kind)" :key="metric" :value="metric">@{{ metricName(metric) }}</option>
                                </select>
                            </label>

                            <label class="gl-field" style="max-width:160px">
                                @{{ labels.al_percent }}
                                <div class="gl-unit">
                                    <input type="number" min="1" max="500" step="1" v-model.number="rule.percent" :disabled="! canEdit">
                                    <span class="is-after">%</span>
                                </div>
                            </label>

                            <button type="button" class="gl-btn is-danger" v-if="canEdit" @click="rules.splice(index, 1)">@{{ labels.remove_rule }}</button>
                        </div>

                        <p class="gl-sub" style="margin-top:6px">@{{ labels['al_' + rule.kind + '_hint'] }}</p>
                    </div>
                </div>

                <div style="margin-top:10px" v-if="canEdit">
                    <button type="button" class="gl-btn" @click="add">@{{ labels.al_add }}</button>
                </div>
            </section>

            <div v-if="canEdit">
                <button type="button" class="gl-btn is-primary" :disabled="saving" @click="save">@{{ labels.al_save }}</button>
            </div>
        </div>
    </script>

    <script type="module">
        app.component('v-goals-alerts', {
            template: '#v-goals-alerts-template',

            props: {
                data: { type: Object, required: true },
                urls: { type: Object, required: true },
                labels: { type: Object, required: true },
                stepNames: { type: Object, required: true },
                canEdit: { type: Boolean, default: false },
            },

            emits: ['saved'],

            data() {
                const alerts = JSON.parse(JSON.stringify(this.data.alerts));

                return {
                    kinds: ['pace', 'rate', 'cpl', 'budget'],
                    enabled: alerts.enabled,
                    rules: alerts.rules,
                    saving: false,
                };
            },

            methods: {
                metricsOf(kind) {
                    return kind === 'pace' ? ['leads', 'qualified', 'scheduled', 'attended', 'sales', 'revenue'] : ['qualified', 'scheduled', 'attended', 'sales'];
                },

                metricName(metric) {
                    return metric === 'revenue' ? this.labels.al_revenue : this.stepNames[metric];
                },

                add() {
                    this.rules.push({ kind: 'pace', metric: 'leads', percent: 80 });
                },

                kindChanged(rule) {
                    const defaults = { pace: 80, rate: 80, cpl: 120, budget: 90 };

                    rule.percent = defaults[rule.kind];

                    if (rule.kind === 'pace' || rule.kind === 'rate') {
                        rule.metric = this.metricsOf(rule.kind).includes(rule.metric) ? rule.metric : 'sales';
                    } else {
                        delete rule.metric;
                    }
                },

                async save() {
                    this.saving = true;

                    try {
                        await this.$axios.post(this.urls.alerts, { enabled: this.enabled, rules: this.rules });

                        this.$emitter.emit('add-flash', { type: 'success', message: this.labels.al_saved });
                        this.$emit('saved');
                    } catch (error) {
                        const errors = error.response?.data?.errors;

                        this.$emitter.emit('add-flash', { type: 'error', message: (errors && Object.values(errors).flat()[0]) || error.response?.data?.message || this.labels.save_error });
                    } finally {
                        this.saving = false;
                    }
                },
            },
        });
    </script>
@endPushOnce
