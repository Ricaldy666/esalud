<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'REM Explorer') — {{ config('app.name') }}</title>
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css'])
    @endif
</head>
<body class="bg-gray-50 text-gray-900 font-sans antialiased">
    <div class="min-h-screen flex flex-col">
        <header class="bg-white border-b border-gray-200 shadow-sm">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between h-14">
                    <div class="flex items-center gap-6">
                        <a href="/" class="text-sm font-semibold text-gray-500 hover:text-gray-700">Inicio</a>
                        <span class="text-gray-300">/</span>
                        <a href="/admin/rem-explorer" class="text-sm font-semibold text-indigo-600 hover:text-indigo-800">REM Explorer</a>
                    </div>
                    <div class="text-xs text-gray-400">
                        {{ config('app.name') }}
                    </div>
                </div>
            </div>
        </header>
        <main class="flex-1">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
                @yield('content')
            </div>
        </main>
        <footer class="border-t border-gray-200 py-3 text-center text-xs text-gray-400">
            REM Parser v1 — {{ date('Y') }}
        </footer>
    </div>
</body>
</html>
