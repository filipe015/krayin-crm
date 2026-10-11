<?php

use Webkul\WhatsApp\Support\OutboundMedia;

it('classifies the files the Inbox can send', function (string $mime, string $type, string $kind) {
    expect(OutboundMedia::classify($mime))->toMatchArray(['type' => $type, 'kind' => $kind]);
})->with([
    'jpeg'     => ['image/jpeg', 'image', 'image'],
    'pdf'      => ['application/pdf', 'document', 'file'],
    'docx'     => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'document', 'file'],
    'mp3'      => ['audio/mpeg', 'audio', 'voice'],
    'video'    => ['video/mp4', 'video', 'video'],
    'ogg+opus' => ['audio/ogg; codecs=opus', 'audio', 'voice'],
]);

it('refuses types that are not safe or supported', function (string $mime) {
    expect(OutboundMedia::classify($mime))->toBeNull();
})->with(['text/html', 'application/x-msdownload', 'image/svg+xml', 'application/javascript', '']);

it('accepts a browser recording as a voice note even when sniffed as video', function () {
    expect(OutboundMedia::classify('video/webm', true))->toMatchArray(['type' => 'audio', 'kind' => 'voice'])
        ->and(OutboundMedia::classify('audio/webm;codecs=opus', true))->toMatchArray(['type' => 'audio', 'kind' => 'voice']);

    // Without the voice flag a webm is still just a video.
    expect(OutboundMedia::classify('video/webm'))->toMatchArray(['type' => 'video', 'kind' => 'video']);
});
