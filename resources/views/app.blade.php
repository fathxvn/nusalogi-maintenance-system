<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Nusalogi Maintenance System</title>

    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])

    <x-inertia::head />
</head>
<body class="inertia-app">
    <x-inertia::app />

    @auth
        <form id="nusalogi-logout-form" method="POST" action="{{ route('logout') }}" hidden>
            @csrf
        </form>
    @endauth
</body>
</html>
