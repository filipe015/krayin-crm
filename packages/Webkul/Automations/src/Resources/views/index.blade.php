<x-admin::layouts>
    <x-slot:title>
        @lang('automations::app.ui.title')
    </x-slot>

    <v-automations
        :urls="{{ Illuminate\Support\Js::from($urls) }}"
        :labels="{{ Illuminate\Support\Js::from(trans('automations::app')) }}"
        :initial="{{ Illuminate\Support\Js::from($rules) }}"
        :recipes="{{ Illuminate\Support\Js::from($recipes) }}"
        :options="{{ Illuminate\Support\Js::from($options) }}"
        :can="{{ Illuminate\Support\Js::from($can) }}"
        :whatsapp="{{ Illuminate\Support\Js::from($whatsapp) }}"
        :max-rules="{{ (int) $maxRules }}"
    >
        <div class="p-6 text-sm text-gray-500">...</div>
    </v-automations>

    @pushOnce('scripts')
        <style>
            .au{--au-bg:#fff;--au-fg:#111827;--au-muted:#6b7280;--au-border:#e5e7eb;--au-soft:#f3f4f6;--au-accent:#0e90d9;display:flex;flex-direction:column;gap:16px;max-width:1100px;color:var(--au-fg);font-size:14px}
            .dark .au{--au-bg:#111827;--au-fg:#e5e7eb;--au-muted:#9ca3af;--au-border:#1f2937;--au-soft:#1f2937}
            .au *{box-sizing:border-box}
            .au h1{margin:0;font-size:18px;font-weight:600}
            .au h2{margin:0;font-size:15px;font-weight:600}
            .au-card{display:flex;flex-direction:column;gap:12px;padding:16px;border:1px solid var(--au-border);border-radius:8px;background:var(--au-bg)}
            .au-muted{color:var(--au-muted);font-size:12px;margin:0}
            .au-row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
            .au-row>.au-field{flex:1;min-width:170px}
            .au-field{display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:600;color:var(--au-muted)}
            .au-field input,.au-field select,.au-field textarea{width:100%;padding:7px 9px;border:1px solid var(--au-border);border-radius:6px;background:var(--au-bg);color:var(--au-fg);font:inherit;font-weight:400}
            .au-field textarea{min-height:70px}
            .au-check{display:flex;align-items:center;gap:8px;font-weight:400;color:var(--au-fg)}
            .au-btn{padding:6px 12px;border:1px solid var(--au-border);border-radius:6px;background:var(--au-bg);font:inherit;font-size:13px;font-weight:500;cursor:pointer;color:inherit;white-space:nowrap}
            .au-btn:hover{background:var(--au-soft)}
            .au-btn.is-primary{background:var(--au-accent);border-color:var(--au-accent);color:#fff}
            .au-btn.is-active{border-color:var(--au-accent);color:var(--au-accent)}
            .au-btn.is-small{padding:3px 8px;font-size:12px}
            .au-btn:disabled{opacity:.5;cursor:default}
            .au-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
            .au-tabs{display:flex;gap:4px;border-bottom:1px solid var(--au-border)}
            .au-tab{padding:8px 14px;border:0;border-bottom:2px solid transparent;background:none;font:inherit;cursor:pointer;color:var(--au-muted)}
            .au-tab.is-active{border-color:var(--au-accent);color:var(--au-fg);font-weight:600}
            .au-table{width:100%;border-collapse:collapse;font-size:13px}
            .au-table th,.au-table td{padding:8px;border-bottom:1px solid var(--au-border);text-align:left;vertical-align:top}
            .au-table th{font-size:12px;color:var(--au-muted);font-weight:600}
            .au-pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;background:var(--au-soft)}
            .au-pill.on,.au-pill.ok{background:#dcfce7;color:#14532d}
            .au-pill.test,.au-pill.dry_run{background:#fef9c3;color:#713f12}
            .au-pill.failed{background:#fee2e2;color:#991b1b}
            .au-block{display:flex;flex-direction:column;gap:8px;padding:10px;border:1px dashed var(--au-border);border-radius:6px}
            .au-alert{padding:10px 12px;border-radius:6px;background:#fee2e2;color:#991b1b;font-size:13px}
            .au-warn{padding:10px 12px;border-radius:6px;background:#fef9c3;color:#713f12;font-size:13px}
            .au-ok{padding:10px 12px;border-radius:6px;background:#dcfce7;color:#14532d;font-size:13px}
            .au-steps{margin:0;padding-left:16px;font-size:12px}
            .au-recipes{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:10px}
            .au-recipe{display:flex;flex-direction:column;gap:6px;padding:12px;border:1px solid var(--au-border);border-radius:8px;cursor:pointer;background:var(--au-bg);text-align:left;font:inherit;color:inherit}
            .au-recipe:hover{border-color:var(--au-accent)}
            .au-scroll{overflow-x:auto}
        </style>

        <script type="text/x-template" id="v-automations-template">
            <div class="au">
                <div>
                    <h1>@{{ ui.title }}</h1>
                    <p class="au-muted">@{{ ui.intro }}</p>
                    <p class="au-muted" v-if="! canWrite">@{{ ui.read_only }}</p>
                </div>

                <div class="au-tabs">
                    <button type="button" class="au-tab" :class="{ 'is-active': tab === 'rules' }" @click="tab = 'rules'">@{{ ui.tab_rules }}</button>
                    <button type="button" class="au-tab" :class="{ 'is-active': tab === 'history' }" @click="openHistory()">@{{ ui.tab_history }}</button>
                </div>

                <!-- Rules -->
                <template v-if="tab === 'rules' && ! editing">
                    <div class="au-actions" v-if="can.create">
                        <button type="button" class="au-btn is-primary" :disabled="rules.length >= maxRules" @click="startNew()">@{{ ui.new }}</button>
                        <button type="button" class="au-btn" :disabled="rules.length >= maxRules" @click="showRecipes = ! showRecipes">@{{ ui.use_recipe }}</button>
                        <span class="au-muted">@{{ ui.limit.replace(':max', maxRules) }}</span>
                    </div>

                    <div class="au-card" v-if="showRecipes">
                        <h2>@{{ ui.recipes_title }}</h2>
                        <p class="au-muted">@{{ ui.recipes_hint }}</p>
                        <div class="au-recipes">
                            <button type="button" class="au-recipe" v-for="recipe in recipes" :key="recipe.key" @click="useRecipe(recipe)">
                                <strong>@{{ labels.recipes[recipe.key].name }}</strong>
                                <span class="au-muted">@{{ labels.recipes[recipe.key].description }}</span>
                            </button>
                        </div>
                    </div>

                    <div class="au-card au-scroll">
                        <p class="au-muted" v-if="! rules.length">@{{ ui.none }}</p>
                        <table class="au-table" v-else>
                            <thead>
                                <tr>
                                    <th>@{{ ui.name }}</th>
                                    <th>@{{ ui.trigger }}</th>
                                    <th>@{{ ui.status }}</th>
                                    <th>@{{ ui.runs }}</th>
                                    <th>@{{ ui.last_run }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="rule in rules" :key="rule.id">
                                    <td><strong>@{{ rule.name }}</strong></td>
                                    <td>@{{ labels.triggers[rule.trigger_type] }}</td>
                                    <td>
                                        <div class="au-actions">
                                            <button type="button" class="au-btn is-small" v-for="status in ['on', 'test', 'off']" :key="status"
                                                :class="{ 'is-active': rule.status === status }" :disabled="! can.edit || rule.busy" @click="setStatus(rule, status)">
                                                @{{ labels.statuses[status] }}
                                            </button>
                                        </div>
                                    </td>
                                    <td>@{{ rule.runs_count }}</td>
                                    <td>
                                        <span v-if="rule.last_run_at">@{{ formatDate(rule.last_run_at) }} <span class="au-pill" :class="rule.last_status">@{{ labels.run_statuses[rule.last_status] || rule.last_status }}</span></span>
                                        <span class="au-muted" v-else>@{{ ui.never }}</span>
                                    </td>
                                    <td>
                                        <div class="au-actions">
                                            <button type="button" class="au-btn is-small" @click="edit(rule)">@{{ ui.edit }}</button>
                                            <button type="button" class="au-btn is-small" @click="historyOf(rule)">@{{ ui.tab_history }}</button>
                                            <button type="button" class="au-btn is-small" v-if="can.delete" :disabled="rule.busy" @click="remove(rule)">@{{ ui.delete }}</button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <div class="au-alert" v-if="listProblem">@{{ listProblem }}</div>
                    </div>
                </template>

                <!-- Builder -->
                <form class="au-card" v-if="tab === 'rules' && editing" @submit.prevent="save()">
                    <div class="au-row">
                        <label class="au-field">@{{ ui.name }}<input type="text" v-model="editing.name" maxlength="120" required></label>
                        <label class="au-field" style="max-width:200px">@{{ ui.status }}
                            <select v-model="editing.status">
                                <option v-for="status in ['on', 'test', 'off']" :key="status" :value="status">@{{ labels.statuses[status] }}</option>
                            </select>
                        </label>
                    </div>

                    <h2>@{{ ui.trigger }}</h2>
                    <div class="au-block">
                        <label class="au-field">
                            <select v-model="editing.trigger_type" @change="resetTrigger()">
                                <option v-for="(label, key) in triggerOptions" :key="key" :value="key">@{{ label }}</option>
                            </select>
                        </label>

                        <div class="au-row" v-if="editing.trigger_type === 'stage_entered'">
                            <label class="au-field">@{{ ui.pipeline }}
                                <select v-model.number="editing.trigger_config.pipeline_id" @change="editing.trigger_config.stage_id = null">
                                    <option :value="null">@{{ ui.choose }}</option>
                                    <option v-for="pipeline in options.pipelines" :key="pipeline.id" :value="pipeline.id">@{{ pipeline.name }}</option>
                                </select>
                            </label>
                            <label class="au-field">@{{ ui.stage }}
                                <select v-model.number="editing.trigger_config.stage_id">
                                    <option :value="null">@{{ ui.choose }}</option>
                                    <option v-for="stage in stagesOf(editing.trigger_config.pipeline_id)" :key="stage.id" :value="stage.id">@{{ stage.name }}</option>
                                </select>
                            </label>
                            <label class="au-field">@{{ ui.from_stage }}
                                <select v-model.number="editing.trigger_config.from_stage_id">
                                    <option :value="null">@{{ ui.any }}</option>
                                    <optgroup v-for="pipeline in options.pipelines" :key="pipeline.id" :label="pipeline.name">
                                        <option v-for="stage in pipeline.stages" :key="stage.id" :value="stage.id">@{{ stage.name }}</option>
                                    </optgroup>
                                </select>
                            </label>
                        </div>

                        <div class="au-row" v-if="editing.trigger_type === 'lead_created'">
                            <label class="au-field">@{{ ui.source }}
                                <select v-model.number="editing.trigger_config.source_id">
                                    <option :value="null">@{{ ui.any }}</option>
                                    <option v-for="source in options.sources" :key="source.id" :value="source.id">@{{ source.name }}</option>
                                </select>
                            </label>
                            <label class="au-field">@{{ ui.channel }}
                                <select v-model.number="editing.trigger_config.type_id">
                                    <option :value="null">@{{ ui.any }}</option>
                                    <option v-for="type in options.types" :key="type.id" :value="type.id">@{{ type.name }}</option>
                                </select>
                            </label>
                        </div>

                        <div class="au-row" v-if="editing.trigger_type === 'tag_added'">
                            <label class="au-field">@{{ ui.tag }}
                                <select v-model.number="editing.trigger_config.tag_id">
                                    <option :value="null">@{{ ui.choose }}</option>
                                    <option v-for="tag in options.tags" :key="tag.id" :value="tag.id">@{{ tag.name }}</option>
                                </select>
                            </label>
                        </div>

                        <div class="au-row" v-if="editing.trigger_type === 'activity_created'">
                            <label class="au-field">@{{ ui.activity_type }}
                                <select v-model="editing.trigger_config.activity_type">
                                    <option :value="null">@{{ ui.any }}</option>
                                    <option v-for="type in options.activity_types" :key="type.value" :value="type.value">@{{ type.label }}</option>
                                </select>
                            </label>
                        </div>
                    </div>

                    <h2>@{{ ui.conditions }}</h2>
                    <div class="au-block" v-for="(condition, index) in editing.conditions" :key="'c' + index">
                        <div class="au-row">
                            <label class="au-field">
                                <select v-model="condition.type">
                                    <option v-for="(label, key) in labels.conditions" :key="key" :value="key">@{{ label }}</option>
                                </select>
                            </label>

                            <label class="au-field" v-if="condition.type === 'pipeline'">@{{ ui.pipeline }}
                                <select v-model.number="condition.pipeline_id">
                                    <option :value="null">@{{ ui.choose }}</option>
                                    <option v-for="pipeline in options.pipelines" :key="pipeline.id" :value="pipeline.id">@{{ pipeline.name }}</option>
                                </select>
                            </label>

                            <label class="au-field" v-if="condition.type === 'stage'">@{{ ui.stage }}
                                <select v-model.number="condition.stage_id">
                                    <option :value="null">@{{ ui.choose }}</option>
                                    <optgroup v-for="pipeline in options.pipelines" :key="pipeline.id" :label="pipeline.name">
                                        <option v-for="stage in pipeline.stages" :key="stage.id" :value="stage.id">@{{ stage.name }}</option>
                                    </optgroup>
                                </select>
                            </label>

                            <label class="au-field" v-if="condition.type === 'source'">@{{ labels.conditions.source }}
                                <select v-model.number="condition.source_id">
                                    <option :value="null">@{{ ui.choose }}</option>
                                    <option v-for="source in options.sources" :key="source.id" :value="source.id">@{{ source.name }}</option>
                                </select>
                            </label>

                            <label class="au-field" v-if="condition.type === 'tag'">@{{ ui.tag }}
                                <select v-model.number="condition.tag_id">
                                    <option :value="null">@{{ ui.choose }}</option>
                                    <option v-for="tag in options.tags" :key="tag.id" :value="tag.id">@{{ tag.name }}</option>
                                </select>
                            </label>

                            <label class="au-field" v-if="condition.type === 'owner'">@{{ ui.user }}
                                <select v-model.number="condition.user_id">
                                    <option :value="null">@{{ ui.choose }}</option>
                                    <option v-for="user in options.users" :key="user.id" :value="user.id">@{{ user.name }}</option>
                                </select>
                            </label>

                            <template v-if="condition.type === 'field'">
                                <label class="au-field">@{{ ui.field }}
                                    <select v-model="condition.attribute_code">
                                        <option value="">@{{ ui.choose }}</option>
                                        <option v-for="field in options.fields" :key="field.code" :value="field.code">@{{ field.name }}</option>
                                    </select>
                                </label>
                                <label class="au-field">@{{ ui.value }}<input type="text" v-model="condition.value" maxlength="255"></label>
                            </template>

                            <button type="button" class="au-btn is-small" @click="editing.conditions.splice(index, 1)">@{{ ui.remove }}</button>
                        </div>
                    </div>
                    <div><button type="button" class="au-btn is-small" :disabled="editing.conditions.length >= 10" @click="editing.conditions.push({ type: 'pipeline', pipeline_id: null })">@{{ ui.add_condition }}</button></div>

                    <h2>@{{ ui.actions }}</h2>
                    <div class="au-block" v-for="(action, index) in editing.actions" :key="'a' + index">
                        <div class="au-row">
                            <label class="au-field">@{{ (index + 1) + '.' }}
                                <select v-model="action.type" @change="resetAction(action)">
                                    <option v-for="(label, key) in labels.actions" :key="key" :value="key">@{{ label }}</option>
                                </select>
                            </label>
                            <button type="button" class="au-btn is-small" :disabled="index === 0" @click="moveUp(index)">@{{ ui.up }}</button>
                            <button type="button" class="au-btn is-small" :disabled="editing.actions.length === 1" @click="editing.actions.splice(index, 1)">@{{ ui.remove }}</button>
                        </div>

                        <div class="au-row" v-if="action.type === 'move_stage'">
                            <label class="au-field">@{{ ui.stage }}
                                <select v-model.number="action.stage_id">
                                    <option :value="null">@{{ ui.choose }}</option>
                                    <optgroup v-for="pipeline in options.pipelines" :key="pipeline.id" :label="pipeline.name">
                                        <option v-for="stage in pipeline.stages.filter(s => s.code !== 'lost')" :key="stage.id" :value="stage.id">@{{ stage.name }}</option>
                                    </optgroup>
                                </select>
                            </label>
                        </div>

                        <div class="au-row" v-if="action.type === 'transfer_pipeline'">
                            <label class="au-field">@{{ ui.pipeline }}
                                <select v-model.number="action.pipeline_id" @change="action.stage_id = (stagesOf(action.pipeline_id).find(s => ! ['won', 'lost'].includes(s.code)) || {}).id || null">
                                    <option :value="null">@{{ ui.choose }}</option>
                                    <option v-for="pipeline in options.pipelines" :key="pipeline.id" :value="pipeline.id">@{{ pipeline.name }}</option>
                                </select>
                            </label>
                            <label class="au-field">@{{ ui.stage }}
                                <select v-model.number="action.stage_id">
                                    <option :value="null">@{{ ui.choose }}</option>
                                    <option v-for="stage in stagesOf(action.pipeline_id)" :key="stage.id" :value="stage.id">@{{ stage.name }}</option>
                                </select>
                            </label>
                        </div>

                        <div class="au-row" v-if="action.type === 'assign_owner'">
                            <label class="au-field">@{{ ui.user }}
                                <select v-model.number="action.user_id">
                                    <option :value="null">@{{ ui.choose }}</option>
                                    <option v-for="user in options.users" :key="user.id" :value="user.id">@{{ user.name }}</option>
                                </select>
                            </label>
                        </div>

                        <div class="au-row" v-if="action.type === 'add_tag'">
                            <label class="au-field">@{{ ui.tag }}
                                <select v-model.number="action.tag_id">
                                    <option :value="null">@{{ ui.choose }}</option>
                                    <option v-for="tag in options.tags" :key="tag.id" :value="tag.id">@{{ tag.name }}</option>
                                </select>
                            </label>
                        </div>

                        <template v-if="action.type === 'create_task'">
                            <div class="au-row">
                                <label class="au-field">@{{ ui.task_title }}<input type="text" v-model="action.title" maxlength="200"></label>
                                <label class="au-field" style="max-width:160px">@{{ ui.due_in_days }}<input type="number" min="0" max="365" v-model.number="action.due_in_days"></label>
                            </div>
                            <div class="au-row">
                                <label class="au-field">@{{ ui.assignee }}
                                    <select v-model="action.assignee">
                                        <option value="owner">@{{ ui.assignee_owner }}</option>
                                        <option value="fixed">@{{ ui.assignee_fixed }}</option>
                                    </select>
                                </label>
                                <label class="au-field" v-if="action.assignee === 'fixed'">@{{ ui.user }}
                                    <select v-model.number="action.user_id">
                                        <option :value="null">@{{ ui.choose }}</option>
                                        <option v-for="user in options.users" :key="user.id" :value="user.id">@{{ user.name }}</option>
                                    </select>
                                </label>
                            </div>
                            <p class="au-muted">@{{ ui.placeholders }}</p>
                        </template>

                        <template v-if="action.type === 'notify_whatsapp'">
                            <div class="au-warn" v-if="! whatsapp">@{{ ui.whatsapp_off }}</div>
                            <label class="au-field">@{{ ui.whatsapp_to }}<input type="text" v-model="action.to" maxlength="300"></label>
                            <label class="au-field">@{{ ui.message }}<textarea v-model="action.message" maxlength="1000"></textarea></label>
                            <p class="au-muted">@{{ ui.placeholders }}</p>
                        </template>

                        <template v-if="action.type === 'webhook'">
                            <label class="au-field">@{{ ui.url }}<input type="url" v-model="action.url" maxlength="500" placeholder="https://"></label>
                            <label class="au-field">@{{ ui.secret }}<input type="password" v-model="action.secret" maxlength="200" autocomplete="new-password"></label>
                            <p class="au-muted" v-if="action.secret === '__keep__'">@{{ ui.secret_kept }}</p>
                        </template>

                        <div class="au-row" v-if="action.type === 'mark_lost'">
                            <label class="au-field">@{{ ui.reason }}
                                <select v-model.number="action.reason_id">
                                    <option :value="null">—</option>
                                    <option v-for="reason in options.lost_reasons" :key="reason.id" :value="reason.id">@{{ reason.name }} (@{{ pipelineName(reason.lead_pipeline_id) }})</option>
                                </select>
                            </label>
                        </div>
                    </div>
                    <div><button type="button" class="au-btn is-small" :disabled="editing.actions.length >= 10" @click="editing.actions.push(newAction('move_stage'))">@{{ ui.add_action }}</button></div>

                    <div class="au-warn" v-if="transfersWon">@{{ ui.won_warning }}</div>

                    <div>
                        <label class="au-check"><input type="checkbox" v-model="editing.run_once_per_lead"> @{{ ui.run_once }}</label>
                        <p class="au-muted">@{{ ui.run_once_hint }}</p>
                    </div>

                    <div class="au-ok" v-if="saved">@{{ ui.saved }}</div>
                    <div class="au-alert" v-if="problem">@{{ problem }}</div>

                    <div class="au-actions">
                        <button type="submit" class="au-btn is-primary" v-if="canWrite" :disabled="busy">@{{ ui.save }}</button>
                        <button type="button" class="au-btn" @click="closeEditor()">@{{ ui.cancel }}</button>
                    </div>

                    <div class="au-block" v-if="editing.id && can.edit">
                        <h2>@{{ ui.simulate }}</h2>
                        <p class="au-muted">@{{ ui.simulate_hint }}</p>
                        <div class="au-row">
                            <label class="au-field" style="max-width:200px">@{{ ui.simulate_lead }}<input type="number" min="1" v-model.number="simulation.lead_id"></label>
                            <button type="button" class="au-btn" :disabled="simulation.busy || ! simulation.lead_id" @click="simulate()">@{{ ui.simulate_run }}</button>
                        </div>
                        <div class="au-alert" v-if="simulation.problem">@{{ simulation.problem }}</div>
                        <div v-if="simulation.result">
                            <span class="au-pill" :class="simulation.result.status">@{{ labels.run_statuses[simulation.result.status] }}</span>
                            <ul class="au-steps">
                                <li v-for="(step, i) in simulation.result.steps" :key="i">@{{ stepLabel(step) }}: @{{ step.detail }}</li>
                            </ul>
                        </div>
                    </div>
                </form>

                <!-- History -->
                <template v-if="tab === 'history'">
                    <div class="au-card">
                        <div class="au-row">
                            <label class="au-field">@{{ ui.filter_rule }}
                                <select v-model="filters.rule_id">
                                    <option value="">@{{ ui.all }}</option>
                                    <option v-for="rule in rules" :key="rule.id" :value="rule.id">@{{ rule.name }}</option>
                                </select>
                            </label>
                            <label class="au-field">@{{ ui.filter_status }}
                                <select v-model="filters.status">
                                    <option value="">@{{ ui.all }}</option>
                                    <option v-for="(label, key) in labels.run_statuses" :key="key" :value="key">@{{ label }}</option>
                                </select>
                            </label>
                            <label class="au-field">@{{ ui.filter_lead }}<input type="number" min="1" v-model="filters.lead_id"></label>
                            <button type="button" class="au-btn is-primary" @click="loadRuns(1)">@{{ ui.filter }}</button>
                        </div>
                    </div>

                    <div class="au-card au-scroll">
                        <p class="au-muted" v-if="! runs.length">@{{ ui.empty_history }}</p>
                        <table class="au-table" v-else>
                            <thead>
                                <tr>
                                    <th>@{{ ui.when }}</th>
                                    <th>@{{ ui.filter_rule }}</th>
                                    <th>@{{ ui.lead }}</th>
                                    <th>@{{ ui.trigger }}</th>
                                    <th>@{{ ui.status }}</th>
                                    <th>@{{ ui.steps }}</th>
                                    <th>@{{ ui.duration }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="run in runs" :key="run.id">
                                    <td>@{{ run.created_at }}</td>
                                    <td>@{{ run.rule_name }}</td>
                                    <td><a v-if="run.lead_id" :href="urls.lead.replace('__ID__', run.lead_id)" target="_blank" class="text-brandColor">#@{{ run.lead_id }} @{{ run.lead_title }}</a></td>
                                    <td>@{{ labels.triggers[run.trigger] || run.trigger }}</td>
                                    <td><span class="au-pill" :class="run.status">@{{ labels.run_statuses[run.status] || run.status }}</span></td>
                                    <td>
                                        <ul class="au-steps">
                                            <li v-for="(step, i) in run.steps" :key="i">@{{ stepLabel(step) }}: @{{ step.detail }}</li>
                                        </ul>
                                        <div class="au-muted" v-if="run.error">@{{ run.error }}</div>
                                    </td>
                                    <td>@{{ run.duration_ms }} ms</td>
                                </tr>
                            </tbody>
                        </table>
                        <div class="au-actions" v-if="meta.last_page > 1">
                            <button type="button" class="au-btn is-small" :disabled="meta.page <= 1" @click="loadRuns(meta.page - 1)">@{{ ui.previous }}</button>
                            <span class="au-muted">@{{ meta.page }} / @{{ meta.last_page }}</span>
                            <button type="button" class="au-btn is-small" :disabled="meta.page >= meta.last_page" @click="loadRuns(meta.page + 1)">@{{ ui.next }}</button>
                        </div>
                    </div>
                </template>
            </div>
        </script>

        <script type="module">
            app.component('v-automations', {
                template: '#v-automations-template',

                props: {
                    urls: { type: Object, required: true },
                    labels: { type: Object, required: true },
                    initial: { type: Array, default: () => [] },
                    recipes: { type: Array, default: () => [] },
                    options: { type: Object, required: true },
                    can: { type: Object, required: true },
                    whatsapp: { type: Boolean, default: false },
                    maxRules: { type: Number, default: 100 },
                },

                data() {
                    return {
                        tab: 'rules',
                        rules: this.initial.map(rule => ({ ...rule, busy: false })),
                        editing: null,
                        showRecipes: false,
                        busy: false,
                        saved: false,
                        problem: '',
                        listProblem: '',
                        simulation: { lead_id: null, busy: false, result: null, problem: '' },
                        filters: { rule_id: '', status: '', lead_id: '' },
                        runs: [],
                        meta: { page: 1, last_page: 1, total: 0 },
                    };
                },

                computed: {
                    ui() {
                        return this.labels.ui;
                    },

                    canWrite() {
                        return this.editing && this.editing.id ? this.can.edit : this.can.create;
                    },

                    triggerOptions() {
                        const { simulation, ...triggers } = this.labels.triggers;

                        return triggers;
                    },

                    transfersWon() {
                        if (! this.editing || this.editing.trigger_type !== 'stage_entered') {
                            return false;
                        }

                        const stage = this.stagesOf(this.editing.trigger_config.pipeline_id).find(s => s.id === this.editing.trigger_config.stage_id);

                        return !! stage && stage.code === 'won' && this.editing.actions.some(a => a.type === 'transfer_pipeline');
                    },
                },

                methods: {
                    clone(value) {
                        return JSON.parse(JSON.stringify(value));
                    },

                    stagesOf(pipelineId) {
                        return this.options.pipelines.find(pipeline => pipeline.id === pipelineId)?.stages ?? [];
                    },

                    pipelineName(pipelineId) {
                        return this.options.pipelines.find(pipeline => pipeline.id === pipelineId)?.name ?? '';
                    },

                    stepLabel(step) {
                        const action = String(step.action || '');

                        if (action.startsWith('condition:')) {
                            return this.labels.conditions[action.slice(10)] || action;
                        }

                        return this.labels.actions[action] || action;
                    },

                    formatDate(value) {
                        const date = new Date(String(value).replace(' ', 'T'));

                        return isNaN(date) ? value : date.toLocaleString();
                    },

                    newAction(type) {
                        const defaults = {
                            move_stage: { stage_id: null },
                            transfer_pipeline: { pipeline_id: null, stage_id: null },
                            assign_owner: { user_id: null },
                            add_tag: { tag_id: null },
                            create_task: { title: '', due_in_days: 1, assignee: 'owner', user_id: null },
                            notify_whatsapp: { to: '', message: '' },
                            webhook: { url: '', secret: '' },
                            mark_lost: { reason_id: null },
                        };

                        return { type, ...(defaults[type] || {}) };
                    },

                    resetAction(action) {
                        const fresh = this.newAction(action.type);

                        Object.keys(action).forEach(key => { if (key !== 'type') delete action[key]; });
                        Object.assign(action, fresh);
                    },

                    resetTrigger() {
                        this.editing.trigger_config = {
                            stage_entered: { pipeline_id: null, stage_id: null, from_stage_id: null },
                            lead_created: { source_id: null, type_id: null },
                            tag_added: { tag_id: null },
                            activity_created: { activity_type: null },
                        }[this.editing.trigger_type] || {};
                    },

                    startNew() {
                        this.open({
                            id: null, name: '', status: 'test', trigger_type: 'stage_entered',
                            trigger_config: { pipeline_id: null, stage_id: null, from_stage_id: null },
                            conditions: [], actions: [this.newAction('create_task')], run_once_per_lead: true,
                        });
                    },

                    useRecipe(recipe) {
                        this.showRecipes = false;
                        this.open(this.clone(recipe.rule));
                    },

                    edit(rule) {
                        const { busy, runs_count, last_run_at, last_status, ...data } = this.clone(rule);

                        this.open(data);
                    },

                    open(data) {
                        data.trigger_config = { ...(data.trigger_config || {}) };
                        data.conditions = data.conditions || [];
                        this.editing = data;
                        this.saved = false;
                        this.problem = '';
                        this.simulation = { lead_id: null, busy: false, result: null, problem: '' };
                    },

                    closeEditor() {
                        this.editing = null;
                    },

                    moveUp(index) {
                        const [action] = this.editing.actions.splice(index, 1);

                        this.editing.actions.splice(index - 1, 0, action);
                    },

                    errorOf(error) {
                        const body = error.response && error.response.data;

                        return (body && (Object.values(body.errors || {}).flat()[0] || body.message)) || this.ui.error;
                    },

                    async save() {
                        this.busy = true;
                        this.saved = false;
                        this.problem = '';

                        const { id, ...payload } = this.clone(this.editing);

                        try {
                            const response = id
                                ? await this.$axios.put(this.urls.update.replace('__ID__', id), payload)
                                : await this.$axios.post(this.urls.store, payload);

                            const rule = { ...response.data.data, busy: false };
                            const index = this.rules.findIndex(item => item.id === rule.id);

                            index >= 0 ? this.rules.splice(index, 1, rule) : this.rules.push(rule);

                            this.editing.id = rule.id;
                            this.editing.actions = this.clone(rule.actions);
                            this.saved = true;
                        } catch (error) {
                            this.problem = this.errorOf(error);
                        } finally {
                            this.busy = false;
                        }
                    },

                    async setStatus(rule, status) {
                        if (rule.status === status) {
                            return;
                        }

                        rule.busy = true;
                        this.listProblem = '';

                        try {
                            const response = await this.$axios.patch(this.urls.status.replace('__ID__', rule.id), { status });

                            rule.status = response.data.data.status;
                        } catch (error) {
                            this.listProblem = this.errorOf(error);
                        } finally {
                            rule.busy = false;
                        }
                    },

                    async remove(rule) {
                        if (! window.confirm(this.ui.confirm_delete)) {
                            return;
                        }

                        rule.busy = true;

                        try {
                            await this.$axios.delete(this.urls.delete.replace('__ID__', rule.id));
                            this.rules = this.rules.filter(item => item.id !== rule.id);
                        } catch (error) {
                            rule.busy = false;
                            this.listProblem = this.errorOf(error);
                        }
                    },

                    async simulate() {
                        this.simulation.busy = true;
                        this.simulation.problem = '';
                        this.simulation.result = null;

                        try {
                            const response = await this.$axios.post(this.urls.simulate.replace('__ID__', this.editing.id), { lead_id: this.simulation.lead_id });

                            this.simulation.result = response.data.data;
                        } catch (error) {
                            this.simulation.problem = this.errorOf(error);
                        } finally {
                            this.simulation.busy = false;
                        }
                    },

                    openHistory() {
                        this.tab = 'history';
                        this.loadRuns(1);
                    },

                    historyOf(rule) {
                        this.filters = { rule_id: rule.id, status: '', lead_id: '' };
                        this.openHistory();
                    },

                    async loadRuns(page) {
                        const params = { page };

                        Object.entries(this.filters).forEach(([key, value]) => { if (value !== '' && value !== null) params[key] = value; });

                        try {
                            const response = await this.$axios.get(this.urls.runs, { params });

                            this.runs = response.data.data;
                            this.meta = response.data.meta;
                        } catch (error) {
                            this.runs = [];
                        }
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
