<x-admin::layouts>
    <x-slot:title>
        @lang('whatsapp::app.inbox.title')
    </x-slot>

    @if (bouncer()->hasPermission('whatsapp.connections') || bouncer()->hasPermission('whatsapp.quick-replies'))
        <div class="mb-2 flex justify-end gap-4 text-sm">
            @if (bouncer()->hasPermission('whatsapp.quick-replies'))
                <a href="{{ route('admin.whatsapp.quick-replies.index') }}" class="text-brandColor hover:underline">
                    @lang('whatsapp::app.quick_replies.menu')
                </a>
            @endif

            @if (bouncer()->hasPermission('whatsapp.connections'))
                <a href="{{ route('admin.whatsapp.connections.index') }}" class="text-brandColor hover:underline">
                    @lang('whatsapp::app.connections.menu')
                </a>
            @endif
        </div>
    @endif

    <v-whatsapp-inbox
        :urls="{{ Illuminate\Support\Js::from($urls) }}"
        :labels="{{ Illuminate\Support\Js::from(trans('whatsapp::app.inbox')) }}"
        :sessions="{{ Illuminate\Support\Js::from($sessions) }}"
    >
        <div class="p-6 text-sm text-gray-500">
            @lang('whatsapp::app.inbox.loading')
        </div>
    </v-whatsapp-inbox>

    @pushOnce('scripts')
        <style>
            .wa-inbox{position:relative;--wa-bg:#fff;--wa-fg:#111827;--wa-muted:#6b7280;--wa-border:#e5e7eb;--wa-soft:#f3f4f6;--wa-accent:#0e90d9;--wa-in:#f3f4f6;--wa-out:#dcfce7;--wa-out-fg:#14532d;display:flex;height:calc(100vh - 130px);min-height:420px;overflow:hidden;border:1px solid var(--wa-border);border-radius:8px;background:var(--wa-bg);color:var(--wa-fg);font-size:14px}
            .dark .wa-inbox{--wa-bg:#111827;--wa-fg:#e5e7eb;--wa-muted:#9ca3af;--wa-border:#1f2937;--wa-soft:#1f2937;--wa-in:#1f2937;--wa-out:#14532d;--wa-out-fg:#dcfce7}
            .wa-inbox *{box-sizing:border-box}
            .wa-inbox button{cursor:pointer;font:inherit;color:inherit}
            .wa-inbox input[type=text],.wa-inbox select{width:100%;padding:6px 10px;border:1px solid var(--wa-border);border-radius:6px;background:var(--wa-bg);color:var(--wa-fg);font:inherit}
            .wa-col{display:flex;flex-direction:column;min-width:0}
            .wa-list{width:320px;flex:none;border-right:1px solid var(--wa-border)}
            .wa-thread{flex:1}
            .wa-panel{display:none;width:300px;flex:none;overflow-y:auto;padding:16px;border-left:1px solid var(--wa-border);background:var(--wa-bg);gap:14px}
            .wa-panel.is-open{display:flex;position:absolute;top:0;right:0;bottom:0;z-index:5;width:min(320px,100%);box-shadow:-6px 0 16px rgba(0,0,0,.12)}
            @media (min-width:1280px){.wa-panel,.wa-panel.is-open{display:flex;position:static;width:300px;box-shadow:none}.wa-details,.wa-panel-close{display:none!important}}
            @media (max-width:767px){.wa-list{width:100%;border-right:0}.wa-inbox[data-selected="true"] .wa-list{display:none}.wa-inbox[data-selected="false"] .wa-thread{display:none}}
            @media (min-width:768px){.wa-back{display:none!important}}
            .wa-head{display:flex;flex-direction:column;gap:8px;padding:12px;border-bottom:1px solid var(--wa-border)}
            .wa-tabs{display:flex;gap:4px}
            .wa-tab{flex:1;padding:6px 4px;border:0;border-radius:6px;background:var(--wa-soft);font-size:12px;font-weight:500}
            .wa-tab.is-active{background:var(--wa-accent);color:#fff}
            .wa-row{display:flex;align-items:center;gap:8px;font-size:12px}
            .wa-scroll{flex:1;overflow-y:auto}
            .wa-item{display:flex;width:100%;gap:10px;padding:10px 12px;border:0;border-bottom:1px solid var(--wa-border);background:transparent;text-align:left}
            .wa-item:hover{background:var(--wa-soft)}
            .wa-item.is-active{background:var(--wa-soft);box-shadow:inset 3px 0 0 var(--wa-accent)}
            .wa-avatar{display:flex;flex:none;align-items:center;justify-content:center;width:38px;height:38px;border-radius:50%;background:var(--wa-accent);color:#fff;font-size:13px;font-weight:600}
            .wa-item-body{display:flex;flex:1;flex-direction:column;gap:2px;min-width:0}
            .wa-item-line{display:flex;align-items:center;justify-content:space-between;gap:8px}
            .wa-ellipsis{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
            .wa-muted{color:var(--wa-muted);font-size:12px}
            .wa-badge{flex:none;min-width:20px;padding:1px 6px;border-radius:10px;background:#16a34a;color:#fff;font-size:11px;font-weight:600;text-align:center}
            .wa-tag{align-self:flex-start;padding:0 6px;border-radius:4px;background:var(--wa-soft);color:var(--wa-muted);font-size:11px}
            .wa-more{width:100%;padding:10px;border:0;background:transparent;color:var(--wa-accent);font-size:12px}
            .wa-state{padding:24px 16px;color:var(--wa-muted);text-align:center}
            .wa-bar{display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid var(--wa-border)}
            .wa-back{border:0;background:transparent;font-size:13px}
            .wa-title{flex:1;min-width:0}
            .wa-messages{display:flex;flex:1;flex-direction:column;gap:6px;overflow-y:auto;padding:14px}
            .wa-day{align-self:center;margin:6px 0;padding:2px 10px;border-radius:10px;background:var(--wa-soft);color:var(--wa-muted);font-size:11px}
            .wa-msg{display:flex}
            .wa-msg.is-out{justify-content:flex-end}
            .wa-bubble{max-width:78%;padding:7px 10px;border-radius:10px;background:var(--wa-in)}
            .is-out .wa-bubble{background:var(--wa-out);color:var(--wa-out-fg)}
            .wa-bubble p{margin:0;white-space:pre-wrap;word-break:break-word}
            .wa-bubble img,.wa-bubble video{display:block;max-width:100%;max-height:300px;border-radius:6px}
            .wa-bubble audio{max-width:100%}
            .wa-bubble a{text-decoration:underline}
            .wa-meta{display:flex;justify-content:flex-end;gap:6px;margin-top:2px;color:var(--wa-muted);font-size:11px}
            .is-out .wa-meta{color:inherit;opacity:.7}
            .wa-tick.is-read{color:#0ea5e9;opacity:1}
            .wa-tick.is-failed{color:#dc2626}
            .wa-italic{font-style:italic;opacity:.75}
            .wa-composer{display:flex;flex-direction:column;gap:6px;padding:10px 14px;border-top:1px solid var(--wa-border);background:var(--wa-soft)}
            .wa-compose-row{display:flex;align-items:flex-end;gap:8px}
            .wa-composer textarea{flex:1;min-height:38px;max-height:140px;padding:8px 10px;border:1px solid var(--wa-border);border-radius:6px;background:var(--wa-bg);color:var(--wa-fg);font:inherit;resize:none}
            .wa-composer textarea:disabled{opacity:.6}
            .wa-note{color:var(--wa-muted);font-size:12px}
            .wa-attach{display:flex;align-items:center;gap:10px;padding:6px 8px;border:1px solid var(--wa-border);border-radius:6px;background:var(--wa-bg)}
            .wa-attach img{width:44px;height:44px;object-fit:cover;border-radius:4px}
            .wa-attach audio{height:32px;max-width:240px}
            .wa-attach .wa-link{margin-left:auto;align-self:center}
            .wa-recording{display:flex;align-items:center;gap:10px}
            .wa-dot{width:10px;height:10px;border-radius:50%;background:#dc2626;animation:wa-pulse 1s infinite}
            @keyframes wa-pulse{50%{opacity:.3}}
            .wa-icon-btn{padding:6px 9px;font-size:15px;line-height:1}
            .wa-compose-wrap{position:relative;flex:1;display:flex}
            .wa-compose-wrap textarea{width:100%}
            .wa-qr-pop{position:absolute;left:0;right:0;bottom:calc(100% + 6px);z-index:6;max-height:260px;overflow-y:auto;border:1px solid var(--wa-border);border-radius:8px;background:var(--wa-bg);box-shadow:0 6px 18px rgba(0,0,0,.14)}
            .wa-qr-item{display:flex;flex-direction:column;gap:2px;width:100%;padding:8px 10px;border:0;border-bottom:1px solid var(--wa-border);background:transparent;text-align:left}
            .wa-qr-item:last-child{border-bottom:0}
            .wa-qr-item.is-active,.wa-qr-item:hover{background:var(--wa-soft)}
            .wa-qr-item small{color:var(--wa-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
            .wa-link{align-self:flex-end;padding:0;border:0;background:transparent;color:#dc2626;font-size:11px;text-decoration:underline}
            .wa-btn{padding:6px 10px;border:1px solid var(--wa-border);border-radius:6px;background:var(--wa-bg);font-size:12px}
            .wa-btn:hover{background:var(--wa-soft)}
            .wa-btn:disabled{opacity:.5;cursor:default}
            .wa-btn.is-primary{border-color:var(--wa-accent);background:var(--wa-accent);color:#fff}
            .wa-label{color:var(--wa-muted);font-size:11px;text-transform:uppercase;letter-spacing:.04em}
            .wa-block{display:flex;flex-direction:column;gap:6px}
            .wa-result{padding:6px 8px;border:1px solid var(--wa-border);border-radius:6px;background:transparent;text-align:left;font-size:13px}
            .wa-result:hover{background:var(--wa-soft)}
        </style>

        <script type="text/x-template" id="v-whatsapp-inbox-template">
            <div class="wa-inbox" :data-selected="selectedId ? 'true' : 'false'">
                <aside class="wa-col wa-list">
                    <div class="wa-head">
                        <div class="wa-tabs">
                            <button
                                v-for="name in tabs"
                                :key="name"
                                type="button"
                                class="wa-tab"
                                :class="{ 'is-active': tab === name }"
                                @click="tab = name"
                            >
                                @{{ labels.tabs[name] }}
                            </button>
                        </div>

                        <input type="text" v-model="search" :placeholder="labels.search_placeholder">

                        <div class="wa-row">
                            <label class="wa-row">
                                <input type="checkbox" v-model="unreadOnly">
                                @{{ labels.unread_only }}
                            </label>

                            <select v-if="sessions.length > 1" v-model="sessionId" style="margin-left:auto;width:auto">
                                <option value="">@{{ labels.all_sessions }}</option>
                                <option v-for="session in sessions" :key="session.id" :value="session.id">@{{ session.name }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="wa-scroll">
                        <div v-if="loadingList" class="wa-state">@{{ labels.loading }}</div>
                        <div v-else-if="listError" class="wa-state">@{{ labels.error }}</div>
                        <div v-else-if="! conversations.length" class="wa-state">@{{ labels.empty_list }}</div>

                        <template v-else>
                            <button
                                v-for="conversation in conversations"
                                :key="conversation.id"
                                type="button"
                                class="wa-item"
                                :class="{ 'is-active': conversation.id === selectedId }"
                                @click="select(conversation)"
                            >
                                <span class="wa-avatar">@{{ initials(conversation) }}</span>

                                <span class="wa-item-body">
                                    <span class="wa-item-line">
                                        <strong class="wa-ellipsis">@{{ displayName(conversation) }}</strong>
                                        <span class="wa-muted">@{{ listTime(conversation.sort_at) }}</span>
                                    </span>

                                    <span class="wa-item-line">
                                        <span class="wa-ellipsis wa-muted">@{{ conversation.preview }}</span>
                                        <span v-if="conversation.unread" class="wa-badge">@{{ conversation.unread }}</span>
                                    </span>

                                    <span v-if="! conversation.lead" class="wa-tag">@{{ labels.no_lead }}</span>
                                </span>
                            </button>

                            <button v-if="hasMore" type="button" class="wa-more" @click="loadConversations({ more: true })">
                                @{{ labels.load_more }}
                            </button>
                        </template>
                    </div>
                </aside>

                <section class="wa-col wa-thread">
                    <div v-if="! current" class="wa-state" style="margin:auto">@{{ labels.empty_thread }}</div>

                    <template v-else>
                        <div class="wa-bar">
                            <button type="button" class="wa-back" @click="selectedId = null">&larr; @{{ labels.back }}</button>

                            <div class="wa-title">
                                <strong class="wa-ellipsis" style="display:block">@{{ displayName(current) }}</strong>
                                <span v-if="current.phone" class="wa-muted">+@{{ current.phone }}</span>
                            </div>

                            <a v-if="current.lead" :href="current.lead.url" class="wa-btn">@{{ labels.open_lead }}</a>

                            <button type="button" class="wa-btn wa-details" @click="panelOpen = ! panelOpen">@{{ labels.details }}</button>
                        </div>

                        <div class="wa-messages" ref="thread">
                            <button v-if="hasMoreMessages" type="button" class="wa-more" @click="loadOlder">
                                @{{ labels.load_older }}
                            </button>

                            <div v-if="loadingMessages" class="wa-state">@{{ labels.loading }}</div>
                            <div v-else-if="threadError" class="wa-state">@{{ labels.error }}</div>

                            <template v-for="item in threadItems" :key="item.key">
                                <div v-if="item.day" class="wa-day">@{{ item.day }}</div>

                                <div v-else class="wa-msg" :class="{ 'is-out': item.message.direction === 'outbound' }">
                                    <div class="wa-bubble">
                                        <template v-if="item.message.media && ! item.message.revoked">
                                            <img v-if="['image', 'sticker'].includes(item.message.type)" :src="item.message.media.url" loading="lazy" alt="">
                                            <audio v-else-if="item.message.type === 'audio'" :src="item.message.media.url" controls preload="none"></audio>
                                            <video v-else-if="item.message.type === 'video'" :src="item.message.media.url" controls preload="none"></video>
                                            <a v-else :href="item.message.media.url" target="_blank" rel="noopener">
                                                <i class="icon-attachment"></i> @{{ item.message.media.filename || labels.attachment }}
                                            </a>
                                        </template>

                                        <p v-if="item.message.revoked" class="wa-italic">@{{ labels.deleted }}</p>
                                        <p v-else-if="item.message.content">@{{ item.message.content }}</p>
                                        <p v-else-if="! item.message.media" class="wa-italic">@{{ typeLabel(item.message) }}</p>

                                        <div class="wa-meta">
                                            <span v-if="item.message.edited && ! item.message.revoked">@{{ labels.edited }}</span>
                                            <span>@{{ clock(item.message.created_at) }}</span>
                                            <span
                                                v-if="item.message.direction === 'outbound'"
                                                class="wa-tick"
                                                :class="{ 'is-read': item.message.status === 'read', 'is-failed': item.message.status === 'failed' }"
                                                :title="item.message.status === 'failed' ? labels.failed : ''"
                                            >@{{ tick(item.message) }}</span>
                                        </div>

                                        <button
                                            v-if="item.message.status === 'failed' && item.message.direction === 'outbound'"
                                            type="button"
                                            class="wa-link"
                                            :title="item.message.error || ''"
                                            @click="retry(item.message)"
                                        >@{{ labels.failed }} — @{{ labels.retry }}</button>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <form class="wa-composer" @submit.prevent="send">
                            <span v-if="sessionOffline" class="wa-note">@{{ labels.session_offline }}</span>

                            <div v-if="attachment" class="wa-attach">
                                <img v-if="attachmentUrl && attachment.type.startsWith('image/')" :src="attachmentUrl" alt="">
                                <audio v-else-if="attachmentUrl && attachment.type.startsWith('audio/')" :src="attachmentUrl" controls></audio>
                                <span v-else class="wa-ellipsis">@{{ attachment.name }}</span>

                                <button type="button" class="wa-link" @click="clearAttachment">@{{ labels.remove_attachment }}</button>
                            </div>

                            <div v-if="recording" class="wa-recording">
                                <span class="wa-dot"></span>
                                <span>@{{ labels.recording }} @{{ recordClock }}</span>
                                <button type="button" class="wa-btn" @click="cancelRecording">@{{ labels.cancel_recording }}</button>
                                <button type="button" class="wa-btn is-primary" @click="stopRecording">@{{ labels.stop_recording }}</button>
                            </div>

                            <div v-else class="wa-compose-row">
                                <input
                                    ref="file"
                                    type="file"
                                    hidden
                                    accept="image/*,audio/*,video/mp4,video/3gpp,video/quicktime,video/webm,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip,.rtf"
                                    @change="pickFile"
                                >

                                <button type="button" class="wa-btn wa-icon-btn" :title="labels.attach" :disabled="sessionOffline || sending" @click="$refs.file.click()">&#128206;</button>

                                <button type="button" class="wa-btn wa-icon-btn" :title="labels.quick_reply" :disabled="sessionOffline" @click="openQuickReplies">&#9889;</button>

                                <div class="wa-compose-wrap">
                                    <div v-if="quickMatches.length" class="wa-qr-pop">
                                        <button
                                            v-for="(reply, index) in quickMatches"
                                            :key="reply.id"
                                            type="button"
                                            class="wa-qr-item"
                                            :class="{ 'is-active': index === quickIndex }"
                                            @mousedown.prevent="insertQuickReply(reply)"
                                        >
                                            <span><strong>/@{{ reply.shortcut }}</strong> &middot; @{{ reply.title }}</span>
                                            <small>@{{ reply.body }}</small>
                                        </button>
                                    </div>

                                    <textarea
                                        ref="composer"
                                        v-model="draft"
                                        rows="1"
                                        maxlength="4096"
                                        :placeholder="attachment ? labels.caption_placeholder : labels.write_message"
                                        :disabled="sessionOffline"
                                        @keydown="composerKey"
                                        @input="autosize"
                                    ></textarea>
                                </div>

                                <button
                                    v-if="! draft.trim() && ! attachment"
                                    type="button"
                                    class="wa-btn wa-icon-btn"
                                    :title="labels.record_voice"
                                    :disabled="sessionOffline || sending"
                                    @click="startRecording"
                                >&#127897;</button>

                                <button v-else type="submit" class="wa-btn is-primary" :disabled="! canSend">@{{ labels.send }}</button>
                            </div>
                        </form>
                    </template>
                </section>

                <aside v-if="current" class="wa-col wa-panel" :class="{ 'is-open': panelOpen }">
                    <button type="button" class="wa-btn wa-panel-close" @click="panelOpen = false">@{{ labels.close_panel }}</button>

                    <div class="wa-block">
                        <span class="wa-label">@{{ labels.contact }}</span>
                        <strong>@{{ displayName(current) }}</strong>
                        <span v-if="current.phone" class="wa-muted">@{{ labels.phone }}: +@{{ current.phone }}</span>
                    </div>

                    <div class="wa-block">
                        <span class="wa-label">@{{ labels.responsible }}</span>
                        <span>@{{ current.assigned ? current.assigned.name : labels.nobody }}</span>

                        <div class="wa-row">
                            <button
                                v-if="! current.assigned || current.assigned.id !== currentUserId"
                                type="button" class="wa-btn is-primary" :disabled="busy" @click="claim(false)"
                            >@{{ labels.claim }}</button>

                            <button v-else type="button" class="wa-btn" :disabled="busy" @click="claim(true)">@{{ labels.release }}</button>

                            <button
                                type="button" class="wa-btn" :disabled="busy"
                                @click="setStatus(current.status === 'closed' ? 'open' : 'closed')"
                            >@{{ current.status === 'closed' ? labels.reopen : labels.close }}</button>
                        </div>
                    </div>

                    <div class="wa-block">
                        <span class="wa-label">@{{ labels.lead }}</span>

                        <template v-if="current.lead">
                            <a :href="current.lead.url" style="color:var(--wa-accent)">@{{ current.lead.title }}</a>
                            <div class="wa-row">
                                <a :href="current.lead.url" class="wa-btn">@{{ labels.open_lead }}</a>
                                <button type="button" class="wa-btn" :disabled="busy" @click="linkLead(null)">@{{ labels.unlink }}</button>
                            </div>
                        </template>

                        <template v-else>
                            <span class="wa-muted">@{{ labels.no_lead }}</span>
                            <button v-if="! linkOpen" type="button" class="wa-btn" @click="linkOpen = true">@{{ labels.link_lead }}</button>

                            <template v-else>
                                <input type="text" v-model="linkQuery" :placeholder="labels.search_lead">
                                <button
                                    v-for="lead in linkResults"
                                    :key="lead.id"
                                    type="button"
                                    class="wa-result"
                                    @click="linkLead(lead)"
                                >
                                    @{{ lead.title }}<span v-if="lead.person" class="wa-muted"> — @{{ lead.person }}</span>
                                </button>
                                <span v-if="linkQuery.trim().length >= 2 && linkSearched && ! linkResults.length" class="wa-muted">@{{ labels.no_results }}</span>
                            </template>
                        </template>
                    </div>
                </aside>
            </div>
        </script>

        <script type="module">
            app.component('v-whatsapp-inbox', {
                template: '#v-whatsapp-inbox-template',

                props: {
                    urls: { type: Object, required: true },
                    labels: { type: Object, required: true },
                    sessions: { type: Array, default: () => [] },
                },

                data() {
                    return {
                        tabs: ['queue', 'mine', 'all', 'closed'],
                        tab: 'queue',
                        search: '',
                        unreadOnly: false,
                        sessionId: '',

                        conversations: [],
                        hasMore: false,
                        page: 1,
                        loadingList: true,
                        listError: false,
                        listSeq: 0,

                        selectedId: null,
                        selectedConversation: null,
                        messages: [],
                        hasMoreMessages: false,
                        loadingMessages: false,
                        threadError: false,

                        busy: false,
                        draft: '',
                        drafts: {},
                        quickReplies: [],
                        quickIndex: 0,
                        quickDismissed: false,
                        attachment: null,
                        attachmentUrl: null,
                        attachmentIsVoice: false,
                        recording: false,
                        recordSeconds: 0,
                        recorder: null,
                        recordTimer: null,
                        sending: false,
                        panelOpen: false,
                        linkOpen: false,
                        linkQuery: '',
                        linkResults: [],
                        linkSearched: false,

                        listTimer: null,
                        threadTimer: null,
                        searchTimer: null,
                        linkTimer: null,
                    };
                },

                computed: {
                    current() {
                        return this.conversations.find(conversation => conversation.id === this.selectedId) || this.selectedConversation;
                    },

                    currentUserId() {
                        return {{ (int) auth()->guard('user')->id() }};
                    },

                    currentUserName() {
                        return {{ Illuminate\Support\Js::from((string) auth()->guard('user')->user()?->name) }};
                    },

                    sessionOffline() {
                        return !! this.current && this.current.session_status !== 'WORKING';
                    },

                    canSend() {
                        return ! this.sending && ! this.sessionOffline && (this.draft.trim().length > 0 || this.attachment !== null);
                    },

                    quickMatches() {
                        if (this.quickDismissed || ! this.draft.startsWith('/') || /\s/.test(this.draft) || ! this.quickReplies.length) {
                            return [];
                        }

                        const query = this.draft.slice(1).toLowerCase();

                        return this.quickReplies
                            .filter(reply => ! query || reply.shortcut.startsWith(query) || reply.title.toLowerCase().includes(query))
                            .slice(0, 8);
                    },

                    recordClock() {
                        const minutes = Math.floor(this.recordSeconds / 60);

                        return minutes + ':' + String(this.recordSeconds % 60).padStart(2, '0');
                    },

                    threadItems() {
                        const items = [];
                        let day = null;

                        for (const message of this.messages) {
                            const key = new Date(message.created_at).toDateString();

                            if (key !== day) {
                                day = key;
                                items.push({ key: 'd' + key, day: this.dayLabel(message.created_at) });
                            }

                            items.push({ key: 'm' + message.id, message });
                        }

                        return items;
                    },
                },

                watch: {
                    tab() { this.loadConversations(); },
                    unreadOnly() { this.loadConversations(); },
                    sessionId() { this.loadConversations(); },

                    draft() {
                        this.quickIndex = 0;

                        if (! this.draft.startsWith('/')) {
                            this.quickDismissed = false;
                        }
                    },

                    search() {
                        clearTimeout(this.searchTimer);
                        this.searchTimer = setTimeout(() => this.loadConversations(), 250);
                    },

                    linkQuery() {
                        clearTimeout(this.linkTimer);
                        this.linkTimer = setTimeout(() => this.searchLeads(), 300);
                    },
                },

                mounted() {
                    this.loadConversations();
                    this.loadQuickReplies();

                    this.listTimer = setInterval(() => {
                        if (! document.hidden) {
                            this.loadConversations({ poll: true });
                        }
                    }, 5000);

                    this.threadTimer = setInterval(() => {
                        if (! document.hidden && this.selectedId) {
                            this.refreshThread();
                        }
                    }, 3000);
                },

                beforeUnmount() {
                    [this.listTimer, this.threadTimer].forEach(clearInterval);
                    [this.searchTimer, this.linkTimer].forEach(clearTimeout);
                    this.cancelRecording();
                    this.clearAttachment();
                },

                methods: {
                    url(name, id) {
                        return this.urls[name].replace('__ID__', id);
                    },

                    listParams(page) {
                        return {
                            tab: this.tab,
                            search: this.search.trim() || undefined,
                            unread: this.unreadOnly ? 1 : undefined,
                            session_id: this.sessionId || undefined,
                            page,
                        };
                    },

                    async loadConversations({ poll = false, more = false } = {}) {
                        const seq = ++this.listSeq;
                        const page = more ? this.page + 1 : 1;

                        if (! poll && ! more) {
                            this.loadingList = true;
                        }

                        try {
                            const { data } = await this.$axios.get(this.urls.conversations, { params: this.listParams(page) });

                            if (seq !== this.listSeq) {
                                return;
                            }

                            this.listError = false;

                            if (more) {
                                this.conversations = [...this.conversations, ...data.data];
                                this.page = page;
                                this.hasMore = data.has_more;
                            } else {
                                // On a refresh, keep the older pages the user has already scrolled through.
                                const oldest = data.data.length ? data.data[data.data.length - 1].sort_at : null;
                                const tail = poll && data.has_more && oldest
                                    ? this.conversations.filter(conversation => conversation.sort_at < oldest)
                                    : [];

                                this.conversations = [...data.data, ...tail];
                                this.hasMore = tail.length ? this.hasMore : data.has_more;

                                if (! tail.length) {
                                    this.page = 1;
                                }
                            }

                            const open = this.conversations.find(conversation => conversation.id === this.selectedId);

                            if (open) {
                                this.selectedConversation = open;
                            }
                        } catch (error) {
                            if (! poll) {
                                this.listError = true;
                            }
                        } finally {
                            if (seq === this.listSeq) {
                                this.loadingList = false;
                            }
                        }
                    },

                    async select(conversation) {
                        if (this.selectedId) {
                            this.drafts[this.selectedId] = this.draft;
                        }

                        this.draft = this.drafts[conversation.id] || '';
                        this.cancelRecording();
                        this.clearAttachment();
                        this.selectedId = conversation.id;
                        this.selectedConversation = conversation;
                        this.panelOpen = false;
                        this.messages = [];
                        this.hasMoreMessages = false;
                        this.linkOpen = false;
                        this.linkQuery = '';
                        this.linkResults = [];

                        await this.refreshThread(true);
                        this.markRead();
                    },

                    mergeMessages(fresh) {
                        const byId = new Map(this.messages.map(message => [message.id, message]));

                        fresh.forEach(message => byId.set(message.id, message));
                        this.messages = [...byId.values()].sort((a, b) => a.id - b.id);
                    },

                    async refreshThread(initial = false) {
                        const id = this.selectedId;

                        if (! id) {
                            return;
                        }

                        const element = this.$refs.thread;
                        const stick = initial || ! element || element.scrollHeight - element.scrollTop - element.clientHeight < 120;
                        const lastId = this.messages.length ? this.messages[this.messages.length - 1].id : 0;

                        if (initial) {
                            this.loadingMessages = true;
                            this.threadError = false;
                        }

                        try {
                            const { data } = await this.$axios.get(this.url('messages', id));

                            if (id !== this.selectedId) {
                                return;
                            }

                            this.mergeMessages(data.data);

                            if (initial) {
                                this.hasMoreMessages = data.has_more;
                            }

                            const arrived = data.data.some(message => message.id > lastId && message.direction === 'inbound');

                            if (arrived && ! initial && ! document.hidden) {
                                this.markRead();
                            }

                            if (stick) {
                                this.$nextTick(() => this.scrollToBottom());
                            }
                        } catch (error) {
                            if (initial) {
                                this.threadError = true;
                            }
                        } finally {
                            if (initial) {
                                this.loadingMessages = false;
                            }
                        }
                    },

                    async loadOlder() {
                        const first = this.messages[0];
                        const element = this.$refs.thread;

                        if (! first || ! element) {
                            return;
                        }

                        const previousHeight = element.scrollHeight;

                        try {
                            const { data } = await this.$axios.get(this.url('messages', this.selectedId), { params: { before_id: first.id } });

                            this.mergeMessages(data.data);
                            this.hasMoreMessages = data.has_more;

                            await this.$nextTick();
                            element.scrollTop = element.scrollHeight - previousHeight;
                        } catch (error) {
                            this.actionFailed();
                        }
                    },

                    scrollToBottom() {
                        const element = this.$refs.thread;

                        if (element) {
                            element.scrollTop = element.scrollHeight;
                        }
                    },

                    markRead() {
                        const conversation = this.current;

                        if (! conversation) {
                            return;
                        }

                        this.$axios.post(this.url('read', conversation.id))
                            .then(() => this.patch(conversation.id, { unread: 0 }))
                            .catch(() => {});
                    },

                    patch(id, fields) {
                        const item = this.conversations.find(conversation => conversation.id === id);

                        if (item) {
                            Object.assign(item, fields);
                        }

                        if (this.selectedConversation && this.selectedConversation.id === id) {
                            Object.assign(this.selectedConversation, fields);
                        }
                    },

                    async act(request) {
                        this.busy = true;

                        try {
                            return await request();
                        } catch (error) {
                            this.actionFailed();
                        } finally {
                            this.busy = false;
                        }
                    },

                    actionFailed() {
                        this.$emitter.emit('add-flash', { type: 'error', message: this.labels.action_error });
                    },

                    async claim(release) {
                        const id = this.selectedId;
                        const data = await this.act(() => this.$axios.post(this.url('claim', id), { release: release ? 1 : 0 }).then(response => response.data));

                        if (data) {
                            this.patch(id, { assigned: data.assigned });
                            this.loadConversations({ poll: true });
                        }
                    },

                    async setStatus(status) {
                        const id = this.selectedId;
                        const data = await this.act(() => this.$axios.post(this.url('status', id), { status }).then(response => response.data));

                        if (data) {
                            this.patch(id, { status: data.status });
                            this.loadConversations({ poll: true });
                        }
                    },

                    async searchLeads() {
                        const term = this.linkQuery.trim();

                        this.linkSearched = false;

                        if (term.length < 2) {
                            this.linkResults = [];
                            return;
                        }

                        try {
                            const { data } = await this.$axios.get(this.urls.leadSearch, { params: { q: term } });

                            if (term === this.linkQuery.trim()) {
                                this.linkResults = data.data;
                                this.linkSearched = true;
                            }
                        } catch (error) {
                            this.actionFailed();
                        }
                    },

                    async linkLead(lead) {
                        const id = this.selectedId;
                        const data = await this.act(() => this.$axios.post(this.url('link', id), { lead_id: lead ? lead.id : null }).then(response => response.data));

                        if (data) {
                            this.patch(id, { lead: data.lead });
                            this.linkOpen = false;
                            this.linkQuery = '';
                            this.linkResults = [];
                        }
                    },

                    autosize(event) {
                        const element = event.target;

                        element.style.height = 'auto';
                        element.style.height = Math.min(element.scrollHeight, 140) + 'px';
                    },

                    async send() {
                        const text = this.draft.trim();
                        const id = this.selectedId;

                        if (! this.canSend || (! text && ! this.attachment)) {
                            return;
                        }

                        this.sending = true;

                        try {
                            let payload = { text };

                            if (this.attachment) {
                                payload = new FormData();
                                payload.append('file', this.attachment, this.attachment.name);

                                if (text) {
                                    payload.append('text', text);
                                }

                                if (this.attachmentIsVoice) {
                                    payload.append('voice', '1');
                                }
                            }

                            const { data } = await this.$axios.post(this.url('send', id), payload);

                            this.draft = '';
                            delete this.drafts[id];
                            this.clearAttachment();
                            this.mergeMessages([data.data]);
                            this.patch(id, { preview: text || this.typeLabel(data.data), status: 'open' });

                            await this.$nextTick();

                            if (this.$refs.composer) {
                                this.$refs.composer.style.height = 'auto';
                                this.$refs.composer.focus();
                            }

                            this.scrollToBottom();
                            this.loadConversations({ poll: true });
                        } catch (error) {
                            this.$emitter.emit('add-flash', {
                                type: 'error',
                                message: this.errorMessage(error),
                            });
                        } finally {
                            this.sending = false;
                        }
                    },

                    async loadQuickReplies() {
                        try {
                            const { data } = await this.$axios.get(this.urls.quickReplies);

                            this.quickReplies = data.data;
                        } catch (error) {
                            // The shortcut just stays unavailable; sending still works.
                        }
                    },

                    openQuickReplies() {
                        this.quickDismissed = false;
                        this.draft = '/';

                        this.$nextTick(() => this.$refs.composer && this.$refs.composer.focus());
                    },

                    renderQuickReply(body) {
                        const conversation = this.current || {};
                        const full = (conversation.name || '').trim();
                        const values = {
                            nome: full.split(/\s+/)[0] || '',
                            nome_completo: full,
                            telefone: conversation.phone ? '+' + conversation.phone : '',
                            atendente: this.currentUserName,
                        };

                        return body
                            .replace(/\{\{\s*(\w+)\s*\}\}/g, (match, key) => (key in values ? values[key] : match))
                            .replace(/[ \t]+([,.!?;:])/g, '$1')
                            .replace(/[ \t]{2,}/g, ' ');
                    },

                    insertQuickReply(reply) {
                        this.draft = this.renderQuickReply(reply.body);
                        this.quickDismissed = true;

                        this.$nextTick(() => {
                            const element = this.$refs.composer;

                            if (element) {
                                element.focus();
                                element.style.height = 'auto';
                                element.style.height = Math.min(element.scrollHeight, 140) + 'px';
                            }
                        });
                    },

                    composerKey(event) {
                        const matches = this.quickMatches;

                        if (matches.length) {
                            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                                event.preventDefault();
                                const step = event.key === 'ArrowDown' ? 1 : -1;

                                this.quickIndex = (this.quickIndex + step + matches.length) % matches.length;

                                return;
                            }

                            if (event.key === 'Enter' || event.key === 'Tab') {
                                event.preventDefault();
                                this.insertQuickReply(matches[Math.min(this.quickIndex, matches.length - 1)]);

                                return;
                            }

                            if (event.key === 'Escape') {
                                event.preventDefault();
                                this.quickDismissed = true;

                                return;
                            }
                        }

                        if (event.key === 'Enter' && ! event.shiftKey && ! event.ctrlKey && ! event.altKey && ! event.metaKey) {
                            event.preventDefault();
                            this.send();
                        }
                    },

                    errorMessage(error) {
                        const response = error.response;

                        if (response && response.status === 413) {
                            return this.labels.file_too_large;
                        }

                        const errors = response && response.data && response.data.errors;

                        if (errors) {
                            return Object.values(errors).flat()[0];
                        }

                        return (response && response.data && response.data.message) || this.labels.action_error;
                    },

                    flash(message) {
                        this.$emitter.emit('add-flash', { type: 'error', message });
                    },

                    setAttachment(file, isVoice = false) {
                        if (file.size > 16 * 1024 * 1024) {
                            this.flash(this.labels.file_too_large);

                            return;
                        }

                        this.clearAttachment();
                        this.attachment = file;
                        this.attachmentIsVoice = isVoice;

                        if (file.type.startsWith('image/') || file.type.startsWith('audio/')) {
                            this.attachmentUrl = URL.createObjectURL(file);
                        }
                    },

                    clearAttachment() {
                        if (this.attachmentUrl) {
                            URL.revokeObjectURL(this.attachmentUrl);
                        }

                        this.attachment = null;
                        this.attachmentUrl = null;
                        this.attachmentIsVoice = false;

                        if (this.$refs.file) {
                            this.$refs.file.value = '';
                        }
                    },

                    pickFile(event) {
                        const file = event.target.files && event.target.files[0];

                        if (file) {
                            this.setAttachment(file);
                        }
                    },

                    async startRecording() {
                        if (this.recording) {
                            return;
                        }

                        if (! navigator.mediaDevices || ! window.MediaRecorder) {
                            this.flash(this.labels.mic_unsupported);

                            return;
                        }

                        let stream;

                        try {
                            stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                        } catch (error) {
                            this.flash(this.labels.mic_denied);

                            return;
                        }

                        const type = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4'].find(candidate => MediaRecorder.isTypeSupported(candidate));
                        const chunks = [];
                        const recorder = new MediaRecorder(stream, type ? { mimeType: type } : undefined);

                        recorder.ondataavailable = event => {
                            if (event.data && event.data.size) {
                                chunks.push(event.data);
                            }
                        };

                        recorder.onstop = () => {
                            stream.getTracks().forEach(track => track.stop());

                            if (recorder.discard || ! chunks.length) {
                                return;
                            }

                            const mime = (recorder.mimeType || type || 'audio/webm').split(';')[0];
                            const extension = mime.includes('mp4') ? 'm4a' : (mime.includes('ogg') ? 'ogg' : 'webm');

                            this.setAttachment(new File(chunks, 'audio-' + Date.now() + '.' + extension, { type: mime }), true);
                        };

                        this.recorder = recorder;
                        this.recordSeconds = 0;
                        this.recording = true;
                        this.recordTimer = setInterval(() => { this.recordSeconds += 1; }, 1000);

                        recorder.start();
                    },

                    finishRecording(discard) {
                        clearInterval(this.recordTimer);

                        if (this.recorder) {
                            this.recorder.discard = discard;

                            if (this.recorder.state !== 'inactive') {
                                this.recorder.stop();
                            }
                        }

                        this.recorder = null;
                        this.recording = false;
                    },

                    stopRecording() {
                        this.finishRecording(false);
                    },

                    cancelRecording() {
                        if (this.recording) {
                            this.finishRecording(true);
                        }
                    },

                    async retry(message) {
                        const data = await this.act(() => this.$axios.post(this.url('retry', message.id)).then(response => response.data));

                        if (data) {
                            this.mergeMessages([data.data]);
                            this.$nextTick(() => this.scrollToBottom());
                        }
                    },

                    displayName(conversation) {
                        return conversation.name || (conversation.phone ? '+' + conversation.phone : this.labels.unknown);
                    },

                    initials(conversation) {
                        const name = (conversation.name || '').trim();

                        return name ? name.split(/\s+/).slice(0, 2).map(part => part[0]).join('').toUpperCase() : '#';
                    },

                    listTime(iso) {
                        if (! iso) {
                            return '';
                        }

                        const date = new Date(iso);

                        return date.toDateString() === new Date().toDateString()
                            ? this.clock(iso)
                            : date.toLocaleDateString([], { day: '2-digit', month: '2-digit' });
                    },

                    clock(iso) {
                        return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                    },

                    dayLabel(iso) {
                        const date = new Date(iso);
                        const yesterday = new Date();

                        yesterday.setDate(yesterday.getDate() - 1);

                        if (date.toDateString() === new Date().toDateString()) {
                            return this.labels.today;
                        }

                        return date.toDateString() === yesterday.toDateString()
                            ? this.labels.yesterday
                            : date.toLocaleDateString([], { day: '2-digit', month: 'long', year: 'numeric' });
                    },

                    tick(message) {
                        return { queued: '…', sending: '…', sent: '✓', delivered: '✓✓', read: '✓✓', failed: '!' }[message.status] || '';
                    },

                    typeLabel(message) {
                        return this.labels.types[message.type] || this.labels.types.text;
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
