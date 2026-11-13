<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="index, follow">
    <title>@yield('title') | {{ $legal['app_name'] }}</title>
    <style>
        :root { --text: #1f2937; --muted: #6b7280; --border: #e5e7eb; --bg: #f9fafb; --accent: #0E90D9; --ok: #047857; --error: #b91c1c; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: var(--text); background: var(--bg); line-height: 1.65; font-size: 16px; }
        a { color: var(--accent); }
        header { background: #fff; border-bottom: 1px solid var(--border); }
        .wrap { max-width: 860px; margin: 0 auto; padding: 0 16px; }
        header .wrap { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; padding-top: 16px; padding-bottom: 16px; }
        .brand { font-size: 20px; font-weight: 700; color: var(--text); text-decoration: none; }
        nav a { margin-left: 16px; font-size: 14px; text-decoration: none; }
        nav a:first-child { margin-left: 0; }
        main .wrap { background: #fff; border: 1px solid var(--border); border-radius: 8px; margin-top: 24px; margin-bottom: 24px; padding: 24px 24px 32px; }
        h1 { font-size: 28px; line-height: 1.25; margin: 0 0 8px; }
        h2 { font-size: 20px; margin: 28px 0 8px; }
        h3 { font-size: 17px; margin: 20px 0 6px; }
        .meta { color: var(--muted); font-size: 14px; margin: 0 0 16px; }
        .lang { font-size: 14px; margin-bottom: 16px; }
        .lang a { text-decoration: none; }
        hr.lang-sep { border: 0; border-top: 2px solid var(--border); margin: 40px 0 24px; }
        ul { padding-left: 22px; }
        .notice { border-radius: 6px; padding: 12px 16px; margin: 16px 0; }
        .notice.ok { background: #ecfdf5; color: var(--ok); border: 1px solid #a7f3d0; }
        .notice.error { background: #fef2f2; color: var(--error); border: 1px solid #fecaca; }
        form label { display: block; font-weight: 600; font-size: 14px; margin: 14px 0 4px; }
        form input, form textarea { width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }
        form button { margin-top: 16px; background: var(--accent); color: #fff; border: 0; border-radius: 6px; padding: 10px 20px; font: inherit; font-weight: 600; cursor: pointer; }
        .code { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 18px; background: var(--bg); border: 1px solid var(--border); border-radius: 6px; padding: 8px 12px; display: inline-block; word-break: break-all; }
        footer { color: var(--muted); font-size: 14px; padding: 8px 0 32px; }
        footer p { margin: 2px 0; }
        @media (max-width: 600px) {
            main .wrap { border-radius: 0; border-left: 0; border-right: 0; margin-top: 0; padding: 20px 16px 28px; }
            h1 { font-size: 24px; }
            nav a { margin-left: 12px; }
        }
    </style>
</head>
<body>
    <header>
        <div class="wrap">
            <a class="brand" href="{{ route('legal.privacy') }}">{{ $legal['app_name'] }}</a>
            <nav>
                <a href="{{ route('legal.privacy') }}">Privacidade</a>
                <a href="{{ route('legal.terms') }}">Termos</a>
                <a href="{{ route('legal.deletion') }}">Exclusão de dados</a>
            </nav>
        </div>
    </header>

    <main>
        <div class="wrap">
            @yield('content')
        </div>
    </main>

    <footer>
        <div class="wrap">
            <p><strong>{{ $legal['trade_name'] }}</strong> · {{ $legal['company_name'] }} · CNPJ {{ $legal['cnpj'] }}</p>
            <p>{{ $legal['address'] }}</p>
            <p><a href="mailto:{{ $legal['email'] }}">{{ $legal['email'] }}</a> · <a href="{{ $legal['website'] }}" rel="noopener">{{ preg_replace('#^https?://#', '', $legal['website']) }}</a></p>
        </div>
    </footer>
</body>
</html>
