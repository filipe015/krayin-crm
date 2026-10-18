{{--
    Money field (type="price" of the form control). It shows the currency symbol and the amount the way the
    locale writes it (1.500,00); on focus the amount turns into plain text selected for typing (1500,5 or
    1.500,50 are both fine) and on leaving it is formatted again. What goes to the form is a plain number
    ("1500.5"), so nothing changes for the server.
--}}
@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-money-input-template"
    >
        <div
            class="flex w-full items-center overflow-hidden rounded-md border text-sm text-gray-600 transition-all focus-within:border-gray-400 hover:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400"
            :class="{ 'border !border-red-600 hover:border-red-600': error }"
        >
            <span class="py-2.5 text-gray-500 ltr:pl-4 rtl:pr-4">@{{ symbol }}</span>

            {{-- The plain number is what a form that is posted as it is (FormData) receives; the visible text is only for the eyes. --}}
            <input type="hidden" :name="name" :value="plain">

            <input
                type="text"
                inputmode="decimal"
                autocomplete="off"
                class="w-full p-2.5 text-sm text-gray-600 dark:bg-gray-900 dark:text-gray-300"
                :placeholder="placeholder"
                :value="text"
                @focus="onFocus"
                @input="onInput"
                @blur="onBlur"
            />
        </div>
    </script>

    <script type="module">
        app.component('v-money-input', {
            template: '#v-money-input-template',

            props: {
                name: String,

                modelValue: { type: [String, Number], default: '' },

                placeholder: { type: String, default: '0,00' },

                error: { type: Boolean, default: false },
            },

            emits: ['update:modelValue', 'blur'],

            data() {
                return { text: '', editing: false };
            },

            computed: {
                /**
                 * The amount as a plain number ("1500.5"), or empty when there is none.
                 */
                plain() {
                    const value = this.modelValue;

                    return value === '' || value === null || value === undefined ? '' : String(this.toNumber(value));
                },

                symbol() {
                    const currency = JSON.parse(document.querySelector('meta[name="currency"]').content);

                    return currency.symbol !== '' ? currency.symbol : currency.code;
                },

                locale() {
                    return document.querySelector('meta[http-equiv="content-language"]').content.replace('_', '-');
                },
            },

            watch: {
                // The value can change from outside (the form is filled after it opens); not while typing.
                modelValue: {
                    immediate: true,

                    handler(value) {
                        if (! this.editing) {
                            this.text = this.format(this.toNumber(value));
                        }
                    },
                },
            },

            methods: {
                /**
                 * "1500", "1500,50", "1.500,50" and "1500.50" all read as numbers; anything else is nothing.
                 */
                parse(text) {
                    let value = String(text ?? '').replace(/[^\d.,]/g, '');

                    if (value === '') {
                        return null;
                    }

                    if (value.includes(',')) {
                        value = value.replace(/\./g, '').replace(',', '.');
                    } else if (/^\d{1,3}(\.\d{3})+$/.test(value)) {
                        value = value.replace(/\./g, '');
                    }

                    const number = Number(value);

                    return Number.isFinite(number) ? Math.round(number * 100) / 100 : null;
                },

                toNumber(value) {
                    const number = Number(value);

                    return value === '' || value === null || value === undefined || ! Number.isFinite(number) ? 0 : number;
                },

                format(number) {
                    return new Intl.NumberFormat(this.locale, { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(number);
                },

                onFocus(event) {
                    this.editing = true;

                    const number = this.toNumber(this.modelValue);

                    // Zero is not worth erasing: show it empty, ready for the amount.
                    this.text = number === 0 ? '' : String(number).replace('.', ',');

                    this.$nextTick(() => event.target.select());
                },

                onInput(event) {
                    this.text = event.target.value;

                    const number = this.parse(this.text);

                    this.$emit('update:modelValue', number === null ? '' : String(number));
                },

                onBlur() {
                    this.editing = false;

                    const number = this.parse(this.text) ?? 0;

                    this.text = this.format(number);

                    this.$emit('update:modelValue', String(number));
                    this.$emit('blur');
                },
            },
        });
    </script>
@endPushOnce
