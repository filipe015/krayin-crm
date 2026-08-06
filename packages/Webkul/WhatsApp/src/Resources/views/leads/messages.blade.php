<v-whatsapp-messages endpoint="{{ route('admin.leads.whatsapp.messages.index', $lead->id) }}">
    <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
        @lang('whatsapp::app.messages.loading')
    </div>
</v-whatsapp-messages>

@pushOnce('scripts')
    <script type="text/x-template" id="v-whatsapp-messages-template">
        <div class="rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            <div v-if="loading" class="p-6 text-sm text-gray-500 dark:text-gray-400">
                @lang('whatsapp::app.messages.loading')
            </div>

            <div v-else-if="error" class="p-6 text-sm text-red-600">
                @lang('whatsapp::app.messages.error')
            </div>

            <div v-else-if="! messages.length" class="p-6 text-sm text-gray-500 dark:text-gray-400">
                @lang('whatsapp::app.messages.empty')
            </div>

            <div v-else class="flex flex-col gap-3 p-4">
                <div
                    v-for="message in messages"
                    :key="message.id"
                    class="flex"
                    :class="message.direction === 'outbound' ? 'justify-end' : 'justify-start'"
                >
                    <div
                        class="max-w-[80%] rounded-lg px-4 py-3 text-sm"
                        :class="message.direction === 'outbound'
                            ? 'bg-green-100 text-green-950 dark:bg-green-900 dark:text-green-100'
                            : 'bg-gray-100 text-gray-900 dark:bg-gray-800 dark:text-gray-100'"
                    >
                        <div class="mb-1 flex flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                            <span v-if="message.sender_name" class="font-semibold">@{{ message.sender_name }}</span>
                            <span>@{{ message.canal_origem }}</span>
                            <span>@{{ formatDate(message.occurred_at) }}</span>
                        </div>

                        <p class="whitespace-pre-wrap break-words">@{{ message.content }}</p>
                    </div>
                </div>
            </div>
        </div>
    </script>

    <script type="module">
        app.component('v-whatsapp-messages', {
            template: '#v-whatsapp-messages-template',

            props: {
                endpoint: {
                    type: String,
                    required: true,
                },
            },

            data() {
                return {
                    messages: [],
                    loading: true,
                    error: false,
                };
            },

            mounted() {
                this.getMessages();
            },

            methods: {
                getMessages() {
                    this.loading = true;
                    this.error = false;

                    this.$axios.get(this.endpoint)
                        .then(response => {
                            this.messages = response.data.data;
                        })
                        .catch(() => {
                            this.error = true;
                        })
                        .finally(() => {
                            this.loading = false;
                        });
                },

                formatDate(value) {
                    if (! value) {
                        return '';
                    }

                    return new Intl.DateTimeFormat(document.documentElement.lang || 'pt-BR', {
                        dateStyle: 'short',
                        timeStyle: 'short',
                    }).format(new Date(value));
                },
            },
        });
    </script>
@endPushOnce
