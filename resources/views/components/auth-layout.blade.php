@props(['title', 'split' => false])
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="no-referrer">
    <title>{{ $title }} - Nusalogi</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="auth-page">
    <main @class(['min-h-dvh', 'lg:grid lg:grid-cols-2' => $split, 'flex items-center justify-center px-5 py-12' => ! $split])>
        @if ($split)
            <section class="hidden flex-col justify-between bg-muted/40 p-12 lg:flex xl:p-16" aria-label="Nusalogi Maintenance System">
                <div><p class="text-2xl font-semibold tracking-tight">Nusalogi</p><p class="mt-1 text-sm text-muted-foreground">Maintenance System</p></div>
                <div class="max-w-md py-12"><p class="text-xs font-medium tracking-wider text-muted-foreground uppercase">Ruang kerja internal</p><h2 class="mt-4 text-3xl leading-tight font-semibold tracking-tight">Pemeliharaan yang terkoordinasi.</h2><p class="mt-5 text-[15px] leading-7 text-muted-foreground">Ruang kerja internal untuk pencatatan dan koordinasi pemeliharaan Nusalogi.</p></div>
                <p class="text-xs text-muted-foreground">Akses khusus pengguna internal.</p>
            </section>
        @endif
        <section @class(['w-full', 'flex min-h-dvh items-center justify-center px-5 py-12 sm:px-10' => $split, 'max-w-md' => ! $split])>
            <div class="mx-auto flex w-full max-w-sm flex-col gap-7">
                <div @class(['flex flex-col gap-1', 'lg:hidden' => $split])><p class="text-xl font-semibold tracking-tight">Nusalogi</p><p class="text-xs text-muted-foreground">Maintenance System</p></div>
                @if (session('status'))
                    <p role="status" class="rounded-md bg-accent px-4 py-3 text-sm leading-6 text-accent-foreground">{{ session('status') }}</p>
                @endif
                {{ $slot }}
            </div>
        </section>
    </main>
</body>
</html>
