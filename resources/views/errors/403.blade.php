<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Доступ запрещён</title>

    <script>
        (function () {
            try {
                var storedTheme = localStorage.getItem('theme')
                    || localStorage.getItem('filament.theme')
                    || localStorage.getItem('color-theme');
                var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                var theme = storedTheme === 'dark' || storedTheme === 'light'
                    ? storedTheme
                    : (prefersDark ? 'dark' : 'light');

                document.documentElement.dataset.theme = theme;
            } catch (error) {
                document.documentElement.dataset.theme = 'dark';
            }
        })();
    </script>

    <style>
        :root {
            color-scheme: light;
            --ac-bg: #e2e8f0;
            --ac-card: #ffffff;
            --ac-border: #cbd5e1;
            --ac-text: #0f172a;
            --ac-muted: #475569;
            --ac-primary: #f97316;
            --ac-primary-hover: #fb923c;
            --ac-primary-text: #111827;
            --ac-body-bg:
                radial-gradient(circle at 20% 20%, rgba(14, 165, 233, 0.14), transparent 32rem),
                linear-gradient(135deg, #f8fafc 0%, var(--ac-bg) 100%);
            --ac-shadow: 0 24px 70px rgba(15, 23, 42, 0.18);
        }

        :root[data-theme="dark"] {
            color-scheme: dark;
            --ac-bg: #0f172a;
            --ac-card: #111827;
            --ac-border: #334155;
            --ac-text: #f8fafc;
            --ac-muted: #cbd5e1;
            --ac-body-bg:
                radial-gradient(circle at 20% 20%, rgba(14, 165, 233, 0.18), transparent 32rem),
                linear-gradient(135deg, #020617 0%, var(--ac-bg) 100%);
            --ac-shadow: 0 24px 70px rgba(2, 6, 23, 0.38);
        }

        @media (prefers-color-scheme: dark) {
            :root:not([data-theme="light"]) {
                color-scheme: dark;
                --ac-bg: #0f172a;
                --ac-card: #111827;
                --ac-border: #334155;
                --ac-text: #f8fafc;
                --ac-muted: #cbd5e1;
                --ac-body-bg:
                    radial-gradient(circle at 20% 20%, rgba(14, 165, 233, 0.18), transparent 32rem),
                    linear-gradient(135deg, #020617 0%, var(--ac-bg) 100%);
                --ac-shadow: 0 24px 70px rgba(2, 6, 23, 0.38);
            }
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            display: grid;
            place-items: center;
            padding: 24px;
            background: var(--ac-body-bg);
            color: var(--ac-text);
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        .error-page {
            width: min(100%, 520px);
            padding: 32px;
            border: 1px solid var(--ac-border);
            border-radius: 12px;
            background: color-mix(in srgb, var(--ac-card) 92%, transparent);
            box-shadow: var(--ac-shadow);
        }

        .error-code {
            margin: 0 0 12px;
            color: var(--ac-primary-hover);
            font-size: 14px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        h1 {
            margin: 0;
            font-size: 30px;
            line-height: 1.2;
        }

        p {
            margin: 16px 0 0;
            color: var(--ac-muted);
            font-size: 16px;
            line-height: 1.6;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 28px;
        }

        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            padding: 0 18px;
            border-radius: 10px;
            background: var(--ac-primary);
            color: var(--ac-primary-text);
            font-weight: 700;
            text-decoration: none;
            transition: background 0.16s ease, transform 0.16s ease;
        }

        .button:hover,
        .button:focus-visible {
            background: var(--ac-primary-hover);
            transform: translateY(-1px);
        }
    </style>
</head>
<body>
    <main class="error-page">
        <p class="error-code">Ошибка 403</p>
        <h1>Доступ запрещён</h1>
        <p>
            У вас нет доступа к этому разделу.
            Перейдите на главную страницу панели и выберите доступный раздел.
        </p>

        <div class="actions">
            <a class="button" href="{{ url('/admin') }}">Перейти на главную</a>
        </div>
    </main>
</body>
</html>
