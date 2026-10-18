{{--
    Etapas do funil: how a deal "reaches" each step of the funnel (a stage it enters, a tag it gets, an
    appointment made), and the default working weekdays. Lead and sale are fixed.
--}}
@pushOnce('scripts')
    <script type="text/x-template" id="v-goals-steps-template">
        <div class="gl">
            <section class="gl-box">
                <header>
                    <h2>@{{ labels.steps_title }}</h2>
                    <p class="gl-sub">@{{ labels.steps_hint }}</p>
                </header>

                <div class="gl-chain">
                    <div class="gl-step" style="grid-template-columns:150px 1fr">
                        <strong>@{{ labels.step_leads }}</strong><span class="gl-sub">@{{ labels.step_leads_rule }}</span>
                    </div>

                    <div class="gl-step" style="grid-template-columns:150px 1fr">
                        <strong>@{{ labels.step_sales }}</strong><span class="gl-sub">@{{ labels.step_sales_rule }}</span>
                    </div>
                </div>
            </section>

            <section class="gl-box" v-for="step in steps" :key="step">
                <header><h2>@{{ labels['step_' + step] }}</h2></header>

                <p class="gl-sub" v-if="! rules[step].length">@{{ labels.no_rules }}</p>

                <div style="display:flex;flex-direction:column;gap:10px">
                    <div class="gl-rule" v-for="(rule, index) in rules[step]" :key="step + index">
                        <div class="gl-row">
                            <label class="gl-field">
                                @{{ labels.rule_type }}
                                <select v-model="rule.type" :disabled="! canEdit" @change="typeChanged(rule)">
                                    <option value="stage">@{{ labels.rule_stage }}</option>
                                    <option value="tag">@{{ labels.rule_tag }}</option>
                                    <option value="appointment">@{{ labels.rule_appointment }}</option>
                                </select>
                            </label>

                            <label class="gl-field" v-if="rule.type === 'stage'">
                                @{{ labels.pipeline }}
                                <select v-model.number="rule.pipeline_id" :disabled="! canEdit" @change="rule.stage_ids = []">
                                    <option v-for="pipeline in data.options.pipelines" :key="pipeline.id" :value="pipeline.id">@{{ pipeline.name }}</option>
                                </select>
                            </label>

                            <button type="button" class="gl-btn is-danger" v-if="canEdit" @click="rules[step].splice(index, 1)">@{{ labels.remove_rule }}</button>
                        </div>

                        <div v-if="rule.type === 'stage'">
                            <h3>@{{ labels.stages }}</h3>

                            <div class="gl-checks">
                                <label v-for="stage in stagesOf(rule.pipeline_id)" :key="stage.id">
                                    <input type="checkbox" :value="stage.id" v-model="rule.stage_ids" :disabled="! canEdit"> @{{ stage.name }}
                                </label>
                            </div>
                        </div>

                        <div v-else-if="rule.type === 'tag'">
                            <h3>@{{ labels.tags }}</h3>

                            <div class="gl-checks">
                                <label v-for="tag in data.options.tags" :key="tag.id">
                                    <input type="checkbox" :value="tag.id" v-model="rule.tag_ids" :disabled="! canEdit"> @{{ tag.name }}
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <div style="margin-top:10px" v-if="canEdit">
                    <button type="button" class="gl-btn" @click="addRule(step)">@{{ labels.add_rule }}</button>
                </div>
            </section>

            <section class="gl-box">
                <header>
                    <h2>@{{ labels.weekdays }}</h2>
                    <p class="gl-sub">@{{ labels.weekdays_hint }}</p>
                </header>

                <div class="gl-chips">
                    <button type="button" class="gl-chip" v-for="day in 7" :key="day" :class="{ 'is-on': weekdays.includes(day) }" :disabled="! canEdit" @click="toggleWeekday(day)">@{{ weekdayNames[day] }}</button>
                </div>
            </section>

            <div v-if="canEdit">
                <button type="button" class="gl-btn is-primary" :disabled="saving" @click="save">@{{ labels.settings_save }}</button>
            </div>
        </div>
    </script>

    <script type="module">
        app.component('v-goals-steps', {
            template: '#v-goals-steps-template',

            props: {
                data: { type: Object, required: true },
                urls: { type: Object, required: true },
                labels: { type: Object, required: true },
                weekdayNames: { type: Object, required: true },
                canEdit: { type: Boolean, default: false },
            },

            emits: ['saved'],

            data() {
                const settings = JSON.parse(JSON.stringify(this.data.settings));

                return {
                    steps: ['qualified', 'scheduled', 'attended'],
                    rules: { qualified: [], scheduled: [], attended: [], ...settings.step_rules },
                    weekdays: [...settings.default_weekdays],
                    saving: false,
                };
            },

            methods: {
                stagesOf(pipelineId) {
                    return this.data.options.pipelines.find(pipeline => pipeline.id === pipelineId)?.stages || [];
                },

                addRule(step) {
                    this.rules[step].push({ type: 'stage', pipeline_id: this.data.options.pipelines[0]?.id, stage_ids: [] });
                },

                typeChanged(rule) {
                    if (rule.type === 'stage') {
                        rule.pipeline_id = rule.pipeline_id || this.data.options.pipelines[0]?.id;
                        rule.stage_ids = [];
                    } else if (rule.type === 'tag') {
                        rule.tag_ids = [];
                    }
                },

                toggleWeekday(day) {
                    this.weekdays = this.weekdays.includes(day) ? this.weekdays.filter(item => item !== day) : [...this.weekdays, day].sort();
                },

                async save() {
                    this.saving = true;

                    try {
                        await this.$axios.post(this.urls.settings, { step_rules: this.rules, weekdays: this.weekdays });

                        this.$emitter.emit('add-flash', { type: 'success', message: this.labels.settings_saved });
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
