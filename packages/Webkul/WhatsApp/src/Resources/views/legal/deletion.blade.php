@extends('whatsapp::legal.layout')

@section('title', 'Exclusão de Dados')

@section('content')
<div class="lang"><a href="#pt">Português</a> · <a href="#en">English</a></div>

<section id="pt" lang="pt-BR">
    <h1>Exclusão de Dados</h1>
    <p class="meta">Última atualização: {{ $legal['updated_at'] }}</p>

    <p>Você pode pedir a exclusão dos seus dados pessoais tratados pelo {{ $legal['app_name'] }} a qualquer momento, conforme a LGPD.</p>

    <h2>Como solicitar</h2>
    <ul>
        <li>Envie um e-mail para <a href="mailto:{{ $legal['email'] }}?subject=Exclus%C3%A3o%20de%20dados">{{ $legal['email'] }}</a> com o assunto “Exclusão de dados”, informando seu nome, e-mail e o telefone de WhatsApp usado; ou</li>
        <li>Preencha o formulário abaixo.</li>
    </ul>
    <p>Podemos pedir uma confirmação de identidade antes de concluir a exclusão. O pedido é atendido em até {{ $legal['deletion_days'] }} dias.</p>

    <h2>O que é apagado</h2>
    <ul>
        <li>Seus dados de contato e de lead (nome, telefone, e-mail e demais campos cadastrados);</li>
        <li>As conversas, mensagens e mídias do WhatsApp vinculadas ao seu número;</li>
        <li>Anotações, atividades, agendamentos e etiquetas associados a você;</li>
        <li>Se você for usuário do sistema, sua conta e seus dados de login.</li>
    </ul>
    <p>Mantemos apenas o que a lei obriga a guardar (por exemplo, registros de acesso por 6 meses e documentos fiscais), pelo prazo legal. Se o seu dado foi cadastrado por uma empresa cliente, ela também será informada do pedido.</p>

    <h2>Formulário de solicitação</h2>

    @if (session('deletion_requested'))
        <div class="notice ok" role="status">Solicitação recebida. Responderemos pelo e-mail informado em até {{ $legal['deletion_days'] }} dias.</div>
    @endif

    @if ($errors->any())
        <div class="notice error" role="alert">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('legal.deletion.request') }}">
        @csrf
        <label for="name">Nome completo *</label>
        <input id="name" name="name" type="text" maxlength="120" required value="{{ old('name') }}" autocomplete="name">

        <label for="email">E-mail *</label>
        <input id="email" name="email" type="email" maxlength="190" required value="{{ old('email') }}" autocomplete="email">

        <label for="phone">Telefone de WhatsApp</label>
        <input id="phone" name="phone" type="tel" maxlength="40" value="{{ old('phone') }}" autocomplete="tel" placeholder="+55 61 90000-0000">

        <label for="details">Detalhes (opcional)</label>
        <textarea id="details" name="details" rows="4" maxlength="2000">{{ old('details') }}</textarea>

        <button type="submit">Enviar solicitação</button>
    </form>
</section>

<hr class="lang-sep">

<section id="en" lang="en">
    <h1>Data Deletion</h1>
    <p class="meta">Last updated: {{ $legal['updated_at_en'] }}</p>

    <p>You may request the deletion of your personal data processed by {{ $legal['app_name'] }} at any time, under the Brazilian LGPD.</p>

    <h2>How to request</h2>
    <ul>
        <li>Send an e-mail to <a href="mailto:{{ $legal['email'] }}?subject=Data%20deletion">{{ $legal['email'] }}</a> with the subject “Data deletion”, stating your name, e-mail and the WhatsApp phone number used; or</li>
        <li>Fill in the form above.</li>
    </ul>
    <p>We may ask you to confirm your identity before completing the deletion. Requests are handled within {{ $legal['deletion_days'] }} days.</p>

    <h2>What is deleted</h2>
    <ul>
        <li>Your contact and lead data (name, phone, e-mail and other registered fields);</li>
        <li>WhatsApp conversations, messages and media linked to your number;</li>
        <li>Notes, activities, appointments and tags associated with you;</li>
        <li>If you are a system user, your account and login data.</li>
    </ul>
    <p>We keep only what the law requires (for example, access logs for 6 months and tax records), for the legal period. If your data was registered by a customer business, it will also be informed of the request.</p>

    <h2>Facebook / Meta login</h2>
    <p>If you removed {{ $legal['app_name'] }} from your Facebook or Meta account settings, Meta sends us your deletion request automatically and gives you a confirmation code. You can check it at <code>{{ rtrim(config('app.url'), '/') }}/exclusao-de-dados/status/&lt;code&gt;</code>.</p>
</section>
@endsection
