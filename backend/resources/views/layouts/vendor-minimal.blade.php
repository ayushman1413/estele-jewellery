<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>@yield('meta_title', 'Vendor Bid | Estele')</title>
  <link rel="stylesheet" href="{{ asset('theme/app.css') }}?v={{ @filemtime(public_path('theme/app.css')) }}">
</head>
<body class="bg-white text-heading">
  <header class="border-b border-line px-4 py-4">
    <span class="text-[16px] font-medium uppercase tracking-[0.5px]">Estele</span>
  </header>

  <main class="mx-auto w-full max-w-lg px-4 py-6">
    @yield('content')
  </main>

  <footer class="mt-10 border-t border-line px-4 py-4 text-center text-[11px] text-muted">
    &copy; {{ date('Y') }} Estele
  </footer>

  @stack('scripts')
</body>
</html>
