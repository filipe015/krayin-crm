@extends('whatsapp::legal.layout')

@section('title', 'Política de Privacidade')

@section('content')
<div class="lang"><a href="#pt">Português</a> · <a href="#en">English</a></div>

<section id="pt" lang="pt-BR">
    <h1>Política de Privacidade</h1>
    <p class="meta">Última atualização: {{ $legal['updated_at'] }}</p>

    <p>Esta Política de Privacidade explica como o {{ $legal['app_name'] }} trata dados pessoais, em conformidade com a Lei Geral de Proteção de Dados Pessoais (Lei nº 13.709/2018 – LGPD).</p>

    <h2>1. Quem somos</h2>
    <p>O {{ $legal['app_name'] }} é um sistema de gestão de relacionamento com clientes (CRM) com integração ao WhatsApp, oferecido por {{ $legal['trade_name'] }} ({{ $legal['company_name'] }}), inscrita no CNPJ sob o nº {{ $legal['cnpj'] }}, com endereço em {{ $legal['address'] }}.</p>
    <p>Em relação aos dados dos contatos e leads que nossos clientes cadastram ou recebem no sistema, o cliente é o controlador e a {{ $legal['trade_name'] }} atua como operadora, tratando esses dados apenas conforme as instruções do cliente. Em relação aos dados de cadastro e acesso dos usuários do sistema, a {{ $legal['trade_name'] }} é a controladora.</p>

    <h2>2. Dados que tratamos</h2>
    <ul>
        <li><strong>Dados de identificação e contato:</strong> nome, telefone, e-mail e empresa de contatos e leads.</li>
        <li><strong>Mensagens e mídias do WhatsApp:</strong> textos, imagens, áudios, vídeos e documentos trocados entre a empresa cliente e seus contatos pela WhatsApp Business Platform, além de metadados (data, hora, status de entrega e leitura).</li>
        <li><strong>Dados de leads e negócios:</strong> origem, etapa do funil, valores, anotações, atividades, agendamentos e etiquetas registrados pelo cliente.</li>
        <li><strong>Dados de login e uso:</strong> nome, e-mail e senha (armazenada de forma criptografada) dos usuários, endereço IP, registros de acesso e preferências.</li>
    </ul>

    <h2>3. Finalidade</h2>
    <ul>
        <li>Permitir que o cliente gerencie seus contatos, leads e oportunidades de venda;</li>
        <li>Enviar e receber mensagens do WhatsApp em nome do cliente, a partir do número que ele conectou;</li>
        <li>Autenticar usuários, controlar permissões e manter a segurança do sistema;</li>
        <li>Prestar suporte, cumprir obrigações legais e prevenir fraudes e abusos.</li>
    </ul>
    <p>Não vendemos dados pessoais e não os usamos para publicidade própria.</p>

    <h2>4. Base legal</h2>
    <p>Tratamos dados com base na execução de contrato com o cliente (art. 7º, V, da LGPD), no cumprimento de obrigação legal ou regulatória (art. 7º, II), no legítimo interesse para segurança e melhoria do serviço (art. 7º, IX) e, quando aplicável, no consentimento do titular (art. 7º, I), que o cliente é responsável por obter de seus contatos.</p>

    <h2>5. Compartilhamento</h2>
    <ul>
        <li><strong>Meta Platforms / WhatsApp Business Platform:</strong> as mensagens enviadas e recebidas trafegam pela infraestrutura da Meta, que as trata conforme seus próprios termos e políticas.</li>
        <li><strong>Provedores de hospedagem e infraestrutura:</strong> servidores, banco de dados, armazenamento e envio de e-mails, contratados com obrigações de confidencialidade e segurança.</li>
        <li><strong>Autoridades:</strong> quando houver obrigação legal ou ordem judicial.</li>
    </ul>
    <p>Alguns desses fornecedores podem armazenar dados fora do Brasil; nesses casos, adotamos as salvaguardas previstas na LGPD.</p>

    <h2>6. Retenção</h2>
    <p>Mantemos os dados enquanto durar o contrato com o cliente ou enquanto forem necessários para as finalidades acima. Após o encerramento, os dados são excluídos ou anonimizados em até 90 dias, salvo quando a lei exigir a guarda por prazo maior (por exemplo, registros de acesso por 6 meses, conforme o Marco Civil da Internet).</p>

    <h2>7. Direitos do titular</h2>
    <p>Você pode solicitar, a qualquer momento: confirmação da existência de tratamento; acesso aos dados; correção de dados incompletos ou desatualizados; anonimização, bloqueio ou eliminação de dados desnecessários; portabilidade; informação sobre compartilhamento; revogação do consentimento; e exclusão dos dados. Veja como em <a href="{{ route('legal.deletion') }}">Exclusão de dados</a> ou escreva para <a href="mailto:{{ $legal['email'] }}">{{ $legal['email'] }}</a>. Se o seu dado foi cadastrado por uma empresa cliente, poderemos encaminhar o pedido a ela, que é a controladora.</p>

    <h2>8. Segurança</h2>
    <p>Adotamos medidas técnicas e administrativas para proteger os dados: conexão criptografada (HTTPS), senhas com hash, controle de acesso por perfil e permissão, validação de assinatura dos webhooks, limite de tentativas de acesso e cópias de segurança. Nenhum sistema é totalmente imune a incidentes; caso ocorra algum que gere risco relevante, comunicaremos os afetados e a ANPD, conforme a lei.</p>

    <h2>9. Contato</h2>
    <p>Encarregado pelo tratamento de dados: {{ $legal['trade_name'] }} – <a href="mailto:{{ $legal['email'] }}">{{ $legal['email'] }}</a> – {{ $legal['address'] }}.</p>

    <h2>10. Atualizações desta política</h2>
    <p>Esta política pode ser atualizada. A versão vigente estará sempre nesta página, com a data da última atualização: {{ $legal['updated_at'] }}.</p>
</section>

<hr class="lang-sep">

<section id="en" lang="en">
    <h1>Privacy Policy</h1>
    <p class="meta">Last updated: {{ $legal['updated_at_en'] }}</p>

    <p>This Privacy Policy explains how {{ $legal['app_name'] }} processes personal data, in accordance with the Brazilian General Data Protection Law (Law No. 13,709/2018 – LGPD).</p>

    <h2>1. Who we are</h2>
    <p>{{ $legal['app_name'] }} is a customer relationship management (CRM) system integrated with WhatsApp, provided by {{ $legal['trade_name'] }} ({{ $legal['company_name'] }}), Brazilian company registration (CNPJ) {{ $legal['cnpj'] }}, located at {{ $legal['address'] }}, Brazil.</p>
    <p>For the contacts and leads our customers register or receive in the system, the customer is the data controller and {{ $legal['trade_name'] }} acts as a processor, handling that data only under the customer's instructions. For the account and login data of system users, {{ $legal['trade_name'] }} is the controller.</p>

    <h2>2. Data we process</h2>
    <ul>
        <li><strong>Identification and contact data:</strong> name, phone number, e-mail and company of contacts and leads.</li>
        <li><strong>WhatsApp messages and media:</strong> texts, images, audio, video and documents exchanged between the customer business and its contacts through the WhatsApp Business Platform, plus metadata (date, time, delivery and read status).</li>
        <li><strong>Lead and deal data:</strong> source, pipeline stage, values, notes, activities, appointments and tags recorded by the customer.</li>
        <li><strong>Login and usage data:</strong> users' name, e-mail and password (stored hashed), IP address, access logs and preferences.</li>
    </ul>

    <h2>3. Purpose</h2>
    <ul>
        <li>To let the customer manage its contacts, leads and sales opportunities;</li>
        <li>To send and receive WhatsApp messages on behalf of the customer, from the number it connected;</li>
        <li>To authenticate users, control permissions and keep the system secure;</li>
        <li>To provide support, comply with legal obligations and prevent fraud and abuse.</li>
    </ul>
    <p>We do not sell personal data and do not use it for our own advertising.</p>

    <h2>4. Legal basis</h2>
    <p>We process data based on the performance of a contract with the customer (LGPD art. 7, V), compliance with legal or regulatory obligations (art. 7, II), legitimate interest for security and service improvement (art. 7, IX) and, where applicable, the data subject's consent (art. 7, I), which the customer is responsible for obtaining from its contacts.</p>

    <h2>5. Sharing</h2>
    <ul>
        <li><strong>Meta Platforms / WhatsApp Business Platform:</strong> messages sent and received travel through Meta's infrastructure, which processes them under its own terms and policies.</li>
        <li><strong>Hosting and infrastructure providers:</strong> servers, database, storage and e-mail delivery, bound by confidentiality and security obligations.</li>
        <li><strong>Authorities:</strong> when required by law or court order.</li>
    </ul>
    <p>Some of these providers may store data outside Brazil; in such cases we apply the safeguards required by the LGPD.</p>

    <h2>6. Retention</h2>
    <p>We keep data for as long as the contract with the customer lasts or as needed for the purposes above. After termination, data is deleted or anonymized within 90 days, unless the law requires longer retention (for example, access logs for 6 months under the Brazilian Internet Civil Framework).</p>

    <h2>7. Data subject rights</h2>
    <p>You may request at any time: confirmation of processing; access to your data; correction of incomplete or outdated data; anonymization, blocking or deletion of unnecessary data; portability; information about sharing; withdrawal of consent; and deletion of your data. See <a href="{{ route('legal.deletion') }}">Data deletion</a> or write to <a href="mailto:{{ $legal['email'] }}">{{ $legal['email'] }}</a>. If your data was registered by a customer business, we may forward the request to it, as the controller.</p>

    <h2>8. Security</h2>
    <p>We use technical and organizational measures to protect data: encrypted connections (HTTPS), hashed passwords, role and permission based access control, webhook signature validation, login attempt limits and backups. No system is fully immune to incidents; if one poses a relevant risk, we will notify those affected and the Brazilian Data Protection Authority (ANPD), as required by law.</p>

    <h2>9. Contact</h2>
    <p>Data protection officer: {{ $legal['trade_name'] }} – <a href="mailto:{{ $legal['email'] }}">{{ $legal['email'] }}</a> – {{ $legal['address'] }}, Brazil.</p>

    <h2>10. Changes to this policy</h2>
    <p>This policy may be updated. The current version is always available on this page, with the date of the last update: {{ $legal['updated_at_en'] }}.</p>
</section>
@endsection
