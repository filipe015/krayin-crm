{{--
    Date range picker: ready-made periods on the left, two months to click a range on, the dates typed
    below, Cancel / Update. Used wherever a screen filters by a period.

    @include('admin::components.date-range-picker')   once per page, then in any Vue template:

    <v-date-range-picker
        :start="'2026-10-01'" :end="'2026-10-19'"   Y-m-d
        today="{{ now()->format('Y-m-d') }}"        the installation's today
        @apply="({ start, end, preset }) => ..."
    ></v-date-range-picker>

    Props: align ("left"|"right": which side the panel opens to), modal (opens centered over the page,
    for narrow drawers), allow-future (days after today can be picked), placeholder (button text
    while there is no period).
--}}
@php
    $drpTimezone = config('app.timezone');
    $drpTimezoneLabel = ['America/Sao_Paulo' => 'Horário de São Paulo'][$drpTimezone] ?? $drpTimezone;
@endphp

@pushOnce('scripts')
    <style>
        .drp{position:relative;display:inline-block;--drp-bg:#fff;--drp-fg:#1f2937;--drp-muted:#6b7280;--drp-line:#e5e7eb;--drp-soft:#f3f4f6;--drp-band:#cfe0f5;--drp-accent:#2f6fb5;font-size:14px;color:var(--drp-fg)}
        .dark .drp{--drp-bg:#111827;--drp-fg:#e5e7eb;--drp-muted:#9ca3af;--drp-line:#1f2937;--drp-soft:#1f2937;--drp-band:#1e3a5f}
        .drp *{box-sizing:border-box}
        .drp-trigger{display:flex;align-items:center;gap:8px;min-height:39px;padding:6px 12px;border:1px solid #d1d5db;border-radius:6px;background:var(--drp-bg);color:inherit;font:inherit;cursor:pointer;white-space:nowrap}
        .dark .drp-trigger{border-color:#1f2937}
        .drp-trigger:hover{border-color:#9ca3af}
        .drp-trigger b{font-weight:600}
        .drp-trigger.is-block{width:100%}
        .drp-panel{position:absolute;top:calc(100% + 6px);z-index:10010;display:flex;max-width:calc(100vw - 16px);border:1px solid var(--drp-line);border-radius:8px;background:var(--drp-bg);box-shadow:0 12px 32px rgba(0,0,0,.18);overflow:hidden}
        .drp-panel.is-left{left:0}
        .drp-panel.is-right{right:0}
        .drp-back{position:fixed;inset:0;z-index:10009;background:rgba(0,0,0,.35)}
        .drp-panel.is-modal{position:fixed;top:50%;left:50%;right:auto;transform:translate(-50%,-50%);max-height:calc(100vh - 24px);overflow:auto;z-index:10010}
        .drp-presets{display:flex;flex-direction:column;gap:2px;min-width:190px;padding:12px;border-right:1px solid var(--drp-line);max-height:520px;overflow:auto}
        .drp-presets h4{margin:0 0 6px;font-size:13px;font-weight:700}
        .drp-preset{display:flex;align-items:center;gap:10px;padding:7px 8px;border:0;border-radius:6px;background:none;color:inherit;font:inherit;text-align:left;cursor:pointer}
        .drp-preset:hover{background:var(--drp-soft)}
        .drp-radio{flex:none;width:18px;height:18px;border:1.5px solid #9ca3af;border-radius:50%;background:var(--drp-bg)}
        .drp-preset.is-on .drp-radio{border:5px solid var(--drp-accent)}
        .drp-main{display:flex;flex-direction:column;gap:14px;padding:14px 16px}
        .drp-months{display:flex;gap:28px}
        .drp-month{width:238px}
        .drp-head{display:flex;align-items:center;justify-content:space-between;gap:6px;margin-bottom:8px;min-height:30px}
        .drp-head select{padding:3px 4px;border:0;background:transparent;color:inherit;font:inherit;font-weight:600;cursor:pointer}
        .drp-nav{width:28px;height:28px;border:0;border-radius:50%;background:none;color:inherit;font-size:18px;line-height:1;cursor:pointer}
        .drp-nav:hover{background:var(--drp-soft)}
        .drp-nav[hidden]{visibility:hidden;display:block}
        .drp-next-m{visibility:hidden}
        .drp-grid{display:grid;grid-template-columns:repeat(7,1fr);row-gap:2px;text-align:center}
        .drp-dow{padding:6px 0;color:var(--drp-muted);font-size:12px}
        .drp-day{position:relative;height:34px;border:0;background:none;color:inherit;font:inherit;font-size:13px;cursor:pointer}
        .drp-day:disabled{color:#9ca3af;cursor:default;opacity:.6}
        .drp-day.in-range{background:var(--drp-band)}
        .drp-day.is-edge{background:var(--drp-accent);color:#fff;border-radius:6px}
        .drp-day.in-range:not(.is-edge):hover,.drp-day:not(:disabled):not(.is-edge):hover{outline:1px solid var(--drp-accent);outline-offset:-1px}
        .drp-fields{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
        .drp-fields select,.drp-fields input{padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;background:var(--drp-bg);color:inherit;font:inherit}
        .dark .drp-fields select,.dark .drp-fields input{border-color:#374151}
        .drp-fields input{width:128px}
        .drp-fields select{min-width:170px}
        .drp-fields input.is-bad{border-color:#dc2626}
        .drp-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:6px}
        .drp-btn{padding:9px 22px;border:1px solid #d1d5db;border-radius:6px;background:var(--drp-bg);color:inherit;font:inherit;font-weight:600;cursor:pointer}
        .drp-btn.is-primary{background:var(--drp-accent);border-color:var(--drp-accent);color:#fff}
        .drp-note{margin:0;color:var(--drp-muted);font-size:12px}
        @media (max-width:820px){
            .drp-panel{flex-direction:column;overflow:auto;max-height:calc(100vh - 24px)}
            .drp-panel:not(.is-modal){position:fixed;top:12px;left:8px;right:8px;max-width:none}
            .drp-presets{flex-direction:row;flex-wrap:wrap;min-width:0;max-height:none;border-right:0;border-bottom:1px solid var(--drp-line)}
            .drp-presets h4{width:100%}
            .drp-preset{padding:5px 10px;border:1px solid var(--drp-line);border-radius:999px;font-size:13px}
            .drp-preset .drp-radio{display:none}
            .drp-preset.is-on{background:var(--drp-accent);border-color:var(--drp-accent);color:#fff}
            .drp-months{justify-content:center}
            .drp-month{width:100%;max-width:320px}
            .drp-second{display:none}
            .drp-next-m{visibility:visible}
        }
    </style>

    <script type="text/x-template" id="v-date-range-picker-template">
        <div class="drp" ref="root">
            <button type="button" class="drp-trigger" :class="{ 'is-block': block }" @click="toggle">
                <span class="icon-calendar text-xl"></span>

                <span>
                    <b v-if="triggerPreset">@{{ triggerPreset }}: </b>@{{ triggerDates }}
                </span>
            </button>

            <div v-if="open && modal" class="drp-back" @click="cancel"></div>

            <div
                v-if="open"
                class="drp-panel"
                :class="[modal ? 'is-modal' : (align === 'right' ? 'is-right' : 'is-left')]"
                @click.stop
            >
                <div class="drp-presets">
                    <h4>@{{ labels.recent_title }}</h4>

                    <button
                        v-for="item in presets"
                        :key="item.key"
                        type="button"
                        class="drp-preset"
                        :class="{ 'is-on': item.key === activeKey }"
                        @click="pick(item)"
                    >
                        <span class="drp-radio"></span>@{{ item.label }}
                    </button>
                </div>

                <div class="drp-main">
                    <div class="drp-months">
                        <div class="drp-month" v-for="offset in [0, 1]" :key="offset" :class="{ 'drp-second': offset === 1 }">
                            <div class="drp-head">
                                <button type="button" class="drp-nav" v-if="offset === 0" @click="shift(-1)" aria-label="‹">‹</button>
                                <button type="button" class="drp-nav" v-else hidden></button>

                                <span>
                                    <select :value="monthOf(offset).m" @change="jump(offset, 'm', $event.target.value)">
                                        <option v-for="(name, index) in labels.months" :key="index" :value="index">@{{ name }}</option>
                                    </select>

                                    <select :value="monthOf(offset).y" @change="jump(offset, 'y', $event.target.value)">
                                        <option v-for="year in years" :key="year" :value="year">@{{ year }}</option>
                                    </select>
                                </span>

                                <button type="button" class="drp-nav" v-if="offset === 1" @click="shift(1)" aria-label="›">›</button>
                                <!-- Only one month shows on a phone: its own "next" arrow -->
                                <button type="button" class="drp-nav drp-next-m" v-else @click="shift(1)" aria-label="›">›</button>
                            </div>

                            <div class="drp-grid">
                                <span class="drp-dow" v-for="name in labels.weekdays" :key="name">@{{ name }}</span>

                                <template v-for="(cell, index) in cells(offset)" :key="offset + '-' + index">
                                    <span v-if="cell === null"></span>

                                    <button
                                        v-else
                                        type="button"
                                        class="drp-day"
                                        :class="{ 'in-range': inRange(cell), 'is-edge': isEdge(cell) }"
                                        :disabled="isDisabled(cell)"
                                        @click="choose(cell)"
                                        @mouseenter="hover = cell"
                                    >@{{ Number(cell.slice(8)) }}</button>
                                </template>
                            </div>
                        </div>
                    </div>

                    <div class="drp-fields">
                        <select :value="activeKey" @change="pickKey($event.target.value)">
                            <option v-for="item in presets" :key="item.key" :value="item.key">@{{ item.label }}</option>
                            <option value="custom">@{{ labels.custom }}</option>
                        </select>

                        <input type="text" inputmode="numeric" placeholder="dd/mm/aaaa" :value="typed.start" :class="{ 'is-bad': badStart }" @input="typed.start = $event.target.value" @change="typedChange('start')">
                        <span>–</span>
                        <input type="text" inputmode="numeric" placeholder="dd/mm/aaaa" :value="typed.end" :class="{ 'is-bad': badEnd }" @input="typed.end = $event.target.value" @change="typedChange('end')">
                    </div>

                    <div class="drp-actions">
                        <button type="button" class="drp-btn" @click="cancel">@{{ labels.cancel }}</button>
                        <button type="button" class="drp-btn is-primary" :disabled="! valid" @click="apply">@{{ labels.update }}</button>
                    </div>

                    <p class="drp-note" v-if="timezoneLabel">@{{ labels.timezone }} @{{ timezoneLabel }}</p>
                </div>
            </div>
        </div>
    </script>

    <script type="module">
        app.component('v-date-range-picker', {
            template: '#v-date-range-picker-template',

            props: {
                start: { type: String, default: '' },
                end: { type: String, default: '' },
                today: { type: String, default: @json(now()->format('Y-m-d')) },
                align: { type: String, default: 'left' },
                modal: { type: Boolean, default: false },
                block: { type: Boolean, default: false },
                allowFuture: { type: Boolean, default: false },
                timezoneLabel: { type: String, default: @json($drpTimezoneLabel) },
            },

            emits: ['apply'],

            data() {
                return {
                    open: false,
                    draft: { start: this.start, end: this.end },
                    picking: false,
                    hover: null,
                    view: { y: 0, m: 0 },
                    typed: { start: '', end: '' },
                    badStart: false,
                    badEnd: false,
                    labels: @json(trans('admin::app.components.date-range-picker')),
                };
            },

            computed: {
                now() {
                    return this.today || this.ymd(new Date());
                },

                presets() {
                    const t = this.now;
                    const monday = this.addDays(t, -((this.weekday(t) + 6) % 7));
                    const monthStart = t.slice(0, 8) + '01';
                    const prevMonthEnd = this.addDays(monthStart, -1);

                    return [
                        { key: 'today', label: this.labels.today, start: t, end: t },
                        { key: 'yesterday', label: this.labels.yesterday, start: this.addDays(t, -1), end: this.addDays(t, -1) },
                        { key: 'today_yesterday', label: this.labels.today_yesterday, start: this.addDays(t, -1), end: t },
                        { key: 'last_7', label: this.labels.last_7, start: this.addDays(t, -6), end: t },
                        { key: 'last_14', label: this.labels.last_14, start: this.addDays(t, -13), end: t },
                        { key: 'last_28', label: this.labels.last_28, start: this.addDays(t, -27), end: t },
                        { key: 'last_30', label: this.labels.last_30, start: this.addDays(t, -29), end: t },
                        { key: 'this_week', label: this.labels.this_week, start: monday, end: t },
                        { key: 'last_week', label: this.labels.last_week, start: this.addDays(monday, -7), end: this.addDays(monday, -1) },
                        { key: 'this_month', label: this.labels.this_month, start: monthStart, end: t },
                        { key: 'last_month', label: this.labels.last_month, start: prevMonthEnd.slice(0, 8) + '01', end: prevMonthEnd },
                    ];
                },

                // The preset the draft period equals, if any.
                activeKey() {
                    const found = this.presets.find(item => item.start === this.draft.start && item.end === this.draft.end);

                    return found ? found.key : 'custom';
                },

                // Same for the period actually applied (the button text).
                triggerPreset() {
                    const found = this.presets.find(item => item.start === this.start && item.end === this.end);

                    return found ? found.label : '';
                },

                triggerDates() {
                    return this.start && this.end ? this.dmy(this.start) + ' – ' + this.dmy(this.end) : this.labels.select;
                },

                years() {
                    const current = Number(this.now.slice(0, 4));
                    const list = [];

                    for (let year = current - 8; year <= current + (this.allowFuture ? 3 : 0); year++) {
                        list.push(year);
                    }

                    return list;
                },

                valid() {
                    return !! (this.draft.start && this.draft.end && this.draft.start <= this.draft.end);
                },

                // The band under the mouse while the second day is still to be chosen.
                shown() {
                    if (this.picking && this.hover) {
                        return this.hover < this.draft.start
                            ? { start: this.hover, end: this.draft.start }
                            : { start: this.draft.start, end: this.hover };
                    }

                    return this.draft;
                },
            },

            mounted() {
                document.addEventListener('click', this.outside);
                document.addEventListener('keydown', this.escape);
            },

            beforeUnmount() {
                document.removeEventListener('click', this.outside);
                document.removeEventListener('keydown', this.escape);
            },

            methods: {
                // Dates travel as "Y-m-d" text and are handled in UTC, so the browser's own zone never moves a day.
                // The browser's own date, as Y-m-d (only used when the page gave no "today").
                ymd(date) {
                    return [date.getFullYear(), String(date.getMonth() + 1).padStart(2, '0'), String(date.getDate()).padStart(2, '0')].join('-');
                },

                utc(text) {
                    const [y, m, d] = text.split('-').map(Number);

                    return new Date(Date.UTC(y, m - 1, d));
                },

                fromUtc(date) {
                    return date.getUTCFullYear() + '-' + String(date.getUTCMonth() + 1).padStart(2, '0') + '-' + String(date.getUTCDate()).padStart(2, '0');
                },

                addDays(text, days) {
                    const date = this.utc(text);

                    date.setUTCDate(date.getUTCDate() + days);

                    return this.fromUtc(date);
                },

                weekday(text) {
                    return this.utc(text).getUTCDay();
                },

                dmy(text) {
                    const [y, m, d] = text.split('-');

                    return d + '/' + m + '/' + y;
                },

                parseDmy(text) {
                    const match = /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/.exec(String(text).trim());

                    if (! match) {
                        return null;
                    }

                    const iso = match[3] + '-' + match[2].padStart(2, '0') + '-' + match[1].padStart(2, '0');

                    return this.fromUtc(this.utc(iso)) === iso ? iso : null;
                },

                monthOf(offset) {
                    const index = this.view.y * 12 + this.view.m + offset;

                    return { y: Math.floor(index / 12), m: index % 12 };
                },

                cells(offset) {
                    const { y, m } = this.monthOf(offset);
                    const lead = (new Date(Date.UTC(y, m, 1)).getUTCDay() + 6) % 7;
                    const days = new Date(Date.UTC(y, m + 1, 0)).getUTCDate();
                    const list = Array(lead).fill(null);

                    for (let day = 1; day <= days; day++) {
                        list.push(y + '-' + String(m + 1).padStart(2, '0') + '-' + String(day).padStart(2, '0'));
                    }

                    return list;
                },

                isDisabled(day) {
                    return ! this.allowFuture && day > this.now;
                },

                inRange(day) {
                    return !! this.shown.start && day >= this.shown.start && day <= this.shown.end;
                },

                isEdge(day) {
                    return day === this.shown.start || day === this.shown.end;
                },

                toggle() {
                    this.open ? this.cancel() : this.show();
                },

                show() {
                    this.draft = { start: this.start || this.now, end: this.end || this.now };
                    this.picking = false;
                    this.hover = null;
                    this.syncTyped();
                    this.viewStart();
                    this.open = true;
                },

                // The left month is the one the period starts in; the next one sits on the right.
                viewStart() {
                    this.view = { y: Number(this.draft.start.slice(0, 4)), m: Number(this.draft.start.slice(5, 7)) - 1 };
                },

                cancel() {
                    this.open = false;
                    this.picking = false;
                },

                outside(event) {
                    if (this.open && ! this.modal && this.$refs.root && ! this.$refs.root.contains(event.target)) {
                        this.cancel();
                    }
                },

                escape(event) {
                    if (this.open && event.key === 'Escape') {
                        this.cancel();
                    }
                },

                shift(step) {
                    const index = this.view.y * 12 + this.view.m + step;

                    this.view = { y: Math.floor(index / 12), m: index % 12 };
                },

                jump(offset, part, value) {
                    const target = { ...this.monthOf(offset), [part]: Number(value) };

                    // The second month is always the one after the first.
                    const index = target.y * 12 + target.m - offset;

                    this.view = { y: Math.floor(index / 12), m: index % 12 };
                },

                choose(day) {
                    if (! this.picking) {
                        this.draft = { start: day, end: day };
                        this.picking = true;
                    } else {
                        this.draft = day < this.draft.start ? { start: day, end: this.draft.start } : { start: this.draft.start, end: day };
                        this.picking = false;
                    }

                    this.syncTyped();
                },

                pick(item) {
                    this.draft = { start: item.start, end: item.end };
                    this.picking = false;
                    this.syncTyped();
                    this.viewStart();
                },

                pickKey(key) {
                    const item = this.presets.find(preset => preset.key === key);

                    if (item) {
                        this.pick(item);
                    }
                },

                syncTyped() {
                    this.typed = { start: this.draft.start ? this.dmy(this.draft.start) : '', end: this.draft.end ? this.dmy(this.draft.end) : '' };
                    this.badStart = false;
                    this.badEnd = false;
                },

                typedChange(which) {
                    const iso = this.parseDmy(this.typed[which]);
                    const bad = iso === null || (! this.allowFuture && iso > this.now);

                    which === 'start' ? this.badStart = bad : this.badEnd = bad;

                    if (bad) {
                        return;
                    }

                    this.draft = { ...this.draft, [which]: iso };

                    // A start after the end (or an end before the start) makes a one-day period on that day.
                    if (this.draft.start > this.draft.end) {
                        this.draft = { start: iso, end: iso };
                    }

                    this.syncTyped();
                    this.viewStart();
                },

                apply() {
                    if (! this.valid) {
                        return;
                    }

                    this.open = false;
                    this.picking = false;

                    this.$emit('apply', { start: this.draft.start, end: this.draft.end, preset: this.activeKey });
                },
            },
        });
    </script>
@endPushOnce
