<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('app.name'))</title>
    @vite('resources/css/public-verification.css')
    @stack('head')
</head>
<body>
    <div class="site-shell">
        <header class="site-header">
            <a class="brand" href="{{ route('public.validate') }}" aria-label="Inicio">
                <span class="brand-mark" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18" />
                        <path d="M6 12H4a2 2 0 0 0-2 2v8h20v-8a2 2 0 0 0-2-2h-2" />
                        <path d="M10 6h4" />
                        <path d="M10 10h4" />
                        <path d="M10 14h4" />
                    </svg>
                </span>
                <span class="brand-copy">
                    <strong>AAUD</strong>
                    <span>Paz y Salvo</span>
                </span>
            </a>
            <nav class="site-nav" aria-label="Navegación principal">
                <a href="{{ route('public.validate') }}">Consulta pública</a>
                <a href="{{ route('public.validate') }}">Validar</a>
            </nav>
        </header>

        <main class="site-main">
            @yield('content')
        </main>

        <footer class="site-footer">
            <span>Autoridad de Aseo Urbano y Domiciliario</span>
        </footer>
    </div>

    @stack('scripts')
</body>
</html>
