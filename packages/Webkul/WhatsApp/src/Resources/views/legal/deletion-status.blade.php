@extends('whatsapp::legal.layout')

@section('title', 'Status da Solicitação')

@section('content')
<section lang="pt-BR">
    <h1>Solicitação recebida</h1>
    <p>Recebemos o pedido de exclusão dos seus dados no {{ $legal['app_name'] }}.</p>
    <p>Código de confirmação:</p>
    <p><span class="code">{{ $code }}</span></p>
    <p>A exclusão é concluída em até {{ $legal['deletion_days'] }} dias. Em caso de dúvidas, escreva para <a href="mailto:{{ $legal['email'] }}">{{ $legal['email'] }}</a> informando este código.</p>
</section>

<hr class="lang-sep">

<section lang="en">
    <h1>Request received</h1>
    <p>We have received the request to delete your data from {{ $legal['app_name'] }}.</p>
    <p>Confirmation code: <span class="code">{{ $code }}</span></p>
    <p>Deletion is completed within {{ $legal['deletion_days'] }} days. If you have questions, write to <a href="mailto:{{ $legal['email'] }}">{{ $legal['email'] }}</a> quoting this code.</p>
</section>
@endsection
