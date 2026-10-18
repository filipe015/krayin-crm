{{--
    The notification center: the bell of the header. It asks the server for the latest notifications every
    minute; a new one also shows up as a pop-up. Rendered by the header of the admin layout.
--}}
<v-notification-bell
    :urls="{{ Illuminate\Support\Js::from([
        'list'    => route('admin.notifications.index'),
        'read'    => route('admin.notifications.read'),
        'readAll' => route('admin.notifications.read_all'),
    ]) }}"
    :labels="{{ Illuminate\Support\Js::from(trans('notifications::app.bell')) }}"
></v-notification-bell>

@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-notification-bell-template"
    >
        <div class="relative" ref="root">
            <button
                type="button"
                class="relative flex rounded-md p-1.5 text-gray-600 transition-all hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-950"
                :title="labels.title"
                @click="toggle"
            >
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9" />
                    <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" />
                </svg>

                <span
                    class="absolute -right-0.5 -top-0.5 flex h-4 min-w-[16px] items-center justify-center rounded-full bg-rose-600 px-1 text-[10px] font-semibold leading-none text-white"
                    v-if="unread > 0"
                >
                    @{{ unread > 99 ? '99+' : unread }}
                </span>
            </button>

            <div
                class="absolute right-0 top-full z-[10002] mt-2 w-[360px] max-w-[92vw] overflow-hidden rounded-lg border border-gray-200 bg-white shadow-xl dark:border-gray-800 dark:bg-gray-900"
                v-if="open"
            >
                <div class="flex items-center justify-between border-b border-gray-200 px-4 py-2.5 dark:border-gray-800">
                    <span class="text-sm font-semibold text-gray-800 dark:text-white">@{{ labels.title }}</span>

                    <button
                        type="button"
                        class="text-xs font-medium text-brandColor hover:underline"
                        v-if="unread > 0"
                        @click="readAll"
                    >
                        @{{ labels.mark_all }}
                    </button>
                </div>

                <div class="max-h-[420px] overflow-y-auto">
                    <p class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400" v-if="! items.length">
                        @{{ labels.empty }}
                    </p>

                    <button
                        type="button"
                        class="flex w-full gap-3 border-b border-gray-100 px-4 py-3 text-left transition-all last:border-b-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-950"
                        :class="{ 'bg-sky-50/60 dark:bg-gray-800/40': ! item.read }"
                        v-for="item in items"
                        :key="item.id"
                        @click="openItem(item)"
                    >
                        <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :class="dot(item.level)"></span>

                        <span class="min-w-0 flex-1">
                            <span class="block text-sm text-gray-800 dark:text-white" :class="{ 'font-semibold': ! item.read }">@{{ item.title }}</span>

                            <span class="mt-0.5 block whitespace-pre-line text-xs text-gray-500 dark:text-gray-400" v-if="item.body">@{{ item.body }}</span>

                            <span class="mt-1 block text-[11px] text-gray-400">@{{ ago(item.created) }}</span>
                        </span>
                    </button>
                </div>
            </div>
        </div>
    </script>

    <script type="module">
        app.component('v-notification-bell', {
            template: '#v-notification-bell-template',

            props: {
                urls: { type: Object, required: true },
                labels: { type: Object, required: true },
            },

            data() {
                return { open: false, unread: 0, items: [], lastId: null, timer: null };
            },

            mounted() {
                this.load();

                // Every minute, while the tab is in front.
                this.timer = setInterval(() => ! document.hidden && this.load(), 60000);

                document.addEventListener('click', this.closeOutside);
                document.addEventListener('visibilitychange', this.refreshWhenBack);
            },

            beforeUnmount() {
                clearInterval(this.timer);
                document.removeEventListener('click', this.closeOutside);
                document.removeEventListener('visibilitychange', this.refreshWhenBack);
            },

            methods: {
                async load() {
                    try {
                        const { data } = await this.$axios.get(this.urls.list);

                        // The first load only learns where things stand; only what comes after pops up.
                        if (this.lastId !== null) {
                            this.popUp(data.items.filter(item => item.id > this.lastId && ! item.read));
                        }

                        this.lastId = Math.max(this.lastId ?? 0, data.latest_id);
                        this.unread = data.unread;
                        this.items = data.items;
                    } catch (error) {
                        // The bell is not worth a message of its own; the next minute tries again.
                    }
                },

                popUp(fresh) {
                    // A pop-up titled "Erro" would say something broke; a goal behind pace is a warning.
                    const types = { info: 'info', success: 'success', warning: 'warning', danger: 'warning' };

                    fresh.slice(0, 3).forEach(item => this.$emitter.emit('add-flash', { type: types[item.level] || 'info', message: item.title }));

                    if (fresh.length > 3) {
                        this.$emitter.emit('add-flash', { type: 'info', message: this.labels.more.replace(':count', fresh.length - 3) });
                    }
                },

                refreshWhenBack() {
                    if (! document.hidden) {
                        this.load();
                    }
                },

                toggle() {
                    this.open = ! this.open;

                    if (this.open) {
                        this.load();
                    }
                },

                closeOutside(event) {
                    if (this.open && ! this.$refs.root.contains(event.target)) {
                        this.open = false;
                    }
                },

                async openItem(item) {
                    if (! item.read) {
                        item.read = true;
                        this.unread = Math.max(0, this.unread - 1);

                        this.$axios.post(this.urls.read, { id: item.id }).catch(() => {});
                    }

                    if (item.url) {
                        window.location.href = item.url;
                    }
                },

                async readAll() {
                    this.items.forEach(item => item.read = true);
                    this.unread = 0;

                    await this.$axios.post(this.urls.readAll).catch(() => {});
                },

                dot(level) {
                    return { info: 'bg-sky-500', success: 'bg-emerald-500', warning: 'bg-amber-500', danger: 'bg-rose-600' }[level] || 'bg-sky-500';
                },

                ago(iso) {
                    if (! iso) {
                        return '';
                    }

                    const locale = document.querySelector('meta[http-equiv="content-language"]').content.replace('_', '-');
                    const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
                    const formatter = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });

                    for (const [unit, size] of [['day', 86400], ['hour', 3600], ['minute', 60]]) {
                        if (Math.abs(seconds) >= size) {
                            return formatter.format(Math.round(seconds / size), unit);
                        }
                    }

                    return formatter.format(0, 'second');
                },
            },
        });
    </script>
@endPushOnce
