@extends('whatsapp::legal.layout')

@section('title', 'Termos de Uso')

@section('content')
<div class="lang"><a href="#pt">Português</a> · <a href="#en">English</a></div>

<section id="pt" lang="pt-BR">
    <h1>Termos de Uso</h1>
    <p class="meta">Última atualização: {{ $legal['updated_at'] }}</p>

    <p>Estes Termos regem o uso do {{ $legal['app_name'] }}, oferecido por {{ $legal['trade_name'] }} ({{ $legal['company_name'] }}, CNPJ {{ $legal['cnpj'] }}). Ao usar o sistema, o cliente e seus usuários concordam com estes Termos e com a <a href="{{ route('legal.privacy') }}">Política de Privacidade</a>.</p>

    <h2>1. Descrição do serviço</h2>
    <p>O {{ $legal['app_name'] }} é um CRM em nuvem para gestão de contatos, leads, funis de venda, agenda e atendimento pelo WhatsApp, por meio da WhatsApp Business Platform (API oficial da Meta) ou de outras integrações disponíveis. O serviço é fornecido mediante contratação e pode ter funcionalidades alteradas, incluídas ou removidas ao longo do tempo.</p>

    <h2>2. Responsabilidades do cliente</h2>
    <ul>
        <li>Ser o titular ou estar devidamente autorizado a usar o número de WhatsApp conectado ao sistema;</li>
        <li>Responder integralmente pelo conteúdo das mensagens, mídias e modelos que enviar;</li>
        <li>Obter e manter o consentimento (opt-in) dos seus contatos para receber mensagens, e respeitar pedidos de descadastro (opt-out);</li>
        <li>Tratar os dados pessoais dos seus contatos em conformidade com a LGPD, como controlador desses dados;</li>
        <li>Manter suas credenciais de acesso em sigilo e gerenciar as permissões dos seus usuários.</li>
    </ul>

    <h2>3. Políticas do WhatsApp Business</h2>
    <p>O cliente deve cumprir os Termos de Serviço do WhatsApp Business, a Política Comercial do WhatsApp e a Política de Mensagens do WhatsApp Business, inclusive as regras sobre modelos de mensagem, janela de atendimento de 24 horas e categorias de conversa. O descumprimento pode levar a Meta a restringir ou bloquear o número, sem responsabilidade da {{ $legal['trade_name'] }}.</p>

    <h2>4. Uso proibido</h2>
    <ul>
        <li>Envio de spam, mensagens em massa não solicitadas ou para listas compradas ou obtidas sem consentimento;</li>
        <li>Conteúdo ilegal, enganoso, ofensivo, discriminatório ou que viole direitos de terceiros;</li>
        <li>Tentativas de acessar dados de outros clientes, burlar limites ou comprometer a segurança do sistema.</li>
    </ul>
    <p>Podemos suspender o acesso em caso de uso proibido, mediante aviso quando possível.</p>

    <h2>5. Limitação de responsabilidade</h2>
    <p>O serviço é prestado com empenho para manter alta disponibilidade, mas sem garantia de funcionamento ininterrupto. A {{ $legal['trade_name'] }} não responde por indisponibilidades ou mudanças da Meta/WhatsApp ou de outros terceiros, por bloqueios de número decorrentes da conduta do cliente, pelo conteúdo enviado pelo cliente nem por lucros cessantes ou danos indiretos. Em qualquer caso, a responsabilidade total fica limitada ao valor pago pelo cliente nos 12 meses anteriores ao fato.</p>

    <h2>6. Cancelamento</h2>
    <p>O cliente pode cancelar a qualquer momento, pelo e-mail <a href="mailto:{{ $legal['email'] }}">{{ $legal['email'] }}</a>, observadas as condições comerciais contratadas. Após o cancelamento, o cliente pode solicitar a exportação dos seus dados em até 30 dias; depois disso, os dados são excluídos conforme a Política de Privacidade.</p>

    <h2>7. Alterações</h2>
    <p>Estes Termos podem ser atualizados. Mudanças relevantes serão comunicadas aos clientes, e a versão vigente estará sempre nesta página.</p>

    <h2>8. Lei aplicável e foro</h2>
    <p>Estes Termos são regidos pelas leis do Brasil. Fica eleito o foro da comarca de {{ $legal['forum'] }} para resolver qualquer controvérsia, com renúncia a qualquer outro, por mais privilegiado que seja.</p>

    <h2>9. Contato</h2>
    <p><a href="mailto:{{ $legal['email'] }}">{{ $legal['email'] }}</a> – {{ $legal['address'] }}.</p>
</section>

<hr class="lang-sep">

<section id="en" lang="en">
    <h1>Terms of Use</h1>
    <p class="meta">Last updated: {{ $legal['updated_at_en'] }}</p>

    <p>These Terms govern the use of {{ $legal['app_name'] }}, provided by {{ $legal['trade_name'] }} ({{ $legal['company_name'] }}, CNPJ {{ $legal['cnpj'] }}). By using the system, the customer and its users agree to these Terms and to the <a href="{{ route('legal.privacy') }}#en">Privacy Policy</a>.</p>

    <h2>1. Service description</h2>
    <p>{{ $legal['app_name'] }} is a cloud CRM for managing contacts, leads, sales pipelines, appointments and WhatsApp customer service, through the WhatsApp Business Platform (Meta's official API) or other available integrations. The service is provided under a subscription and its features may be changed, added or removed over time.</p>

    <h2>2. Customer responsibilities</h2>
    <ul>
        <li>Own, or be duly authorized to use, the WhatsApp number connected to the system;</li>
        <li>Be fully responsible for the content of the messages, media and templates it sends;</li>
        <li>Obtain and keep its contacts' consent (opt-in) to receive messages, and honor opt-out requests;</li>
        <li>Process its contacts' personal data in compliance with the LGPD, as the controller of that data;</li>
        <li>Keep its login credentials confidential and manage its users' permissions.</li>
    </ul>

    <h2>3. WhatsApp Business policies</h2>
    <p>The customer must comply with the WhatsApp Business Terms of Service, the WhatsApp Business Commerce Policy and the WhatsApp Business Messaging Policy, including the rules on message templates, the 24-hour customer service window and conversation categories. Non-compliance may lead Meta to restrict or ban the number, with no liability for {{ $legal['trade_name'] }}.</p>

    <h2>4. Prohibited use</h2>
    <ul>
        <li>Spam, unsolicited bulk messages, or messages to purchased lists or lists obtained without consent;</li>
        <li>Illegal, misleading, offensive or discriminatory content, or content that infringes third-party rights;</li>
        <li>Attempts to access other customers' data, bypass limits or compromise the system's security.</li>
    </ul>
    <p>We may suspend access in case of prohibited use, with notice whenever possible.</p>

    <h2>5. Limitation of liability</h2>
    <p>We strive to keep the service highly available, but do not guarantee uninterrupted operation. {{ $legal['trade_name'] }} is not liable for outages or changes by Meta/WhatsApp or other third parties, for number bans caused by the customer's conduct, for content sent by the customer, or for lost profits or indirect damages. In any case, total liability is limited to the amount paid by the customer in the 12 months before the event.</p>

    <h2>6. Cancellation</h2>
    <p>The customer may cancel at any time by e-mail to <a href="mailto:{{ $legal['email'] }}">{{ $legal['email'] }}</a>, subject to the agreed commercial terms. After cancellation, the customer may request an export of its data within 30 days; after that, data is deleted as described in the Privacy Policy.</p>

    <h2>7. Changes</h2>
    <p>These Terms may be updated. Relevant changes will be communicated to customers, and the current version is always available on this page.</p>

    <h2>8. Governing law and venue</h2>
    <p>These Terms are governed by the laws of Brazil. The courts of {{ $legal['forum'] }}, Brazil, have exclusive jurisdiction over any dispute.</p>

    <h2>9. Contact</h2>
    <p><a href="mailto:{{ $legal['email'] }}">{{ $legal['email'] }}</a> – {{ $legal['address'] }}, Brazil.</p>
</section>
@endsection
