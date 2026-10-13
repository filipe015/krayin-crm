{{--
    "Agendar" on the lead card: opens the same booking card as the Agenda, with this lead already
    chosen. The operator picks an appointment or a follow-up ("retomar contato").
--}}
@php
    $book = \Webkul\WhatsApp\Http\Controllers\AgendaController::bookingProps();
    $bookLead = [
        'id'     => $lead->id,
        'title'  => $lead->title,
        'person' => $lead->person?->name,
        'stage'  => $lead->stage?->name,
    ];
@endphp

@include('whatsapp::agenda.styles')
@include('whatsapp::agenda.book')

<button
    type="button"
    class="flex h-[74px] w-[84px] flex-col items-center justify-center gap-1 rounded-lg border border-transparent font-medium transition-all"
    style="background:#e9d5ff;color:#581c87"
    onmouseover="this.style.borderColor='#c084fc'"
    onmouseout="this.style.borderColor='transparent'"
    onclick="window.emitter && window.emitter.emit('agenda-open-book', { lead: {{ Illuminate\Support\Js::from($bookLead) }} })"
>
    <span class="icon-calendar text-2xl"></span>

    {{ $book['labels']['book'] }}
</button>

<v-agenda-book
    :urls="{{ Illuminate\Support\Js::from($book['bookUrls']) }}"
    :labels="{{ Illuminate\Support\Js::from($book['labels']) }}"
    :users="{{ Illuminate\Support\Js::from($book['users']) }}"
    :current-user-id="{{ $book['currentUserId'] }}"
    :durations="{{ Illuminate\Support\Js::from($book['durations']) }}"
    :default-duration="{{ $book['defaultDuration'] }}"
    :auto-assign="{{ $book['autoAssign'] ? 'true' : 'false' }}"
></v-agenda-book>
