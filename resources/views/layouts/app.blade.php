<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @include('partials.favicon')
    <title>@yield('title', 'Dashboard') | {{ config('pbp.name') }}</title>
    <script>try { if (localStorage.getItem('sidebarCollapsed') === '1') document.documentElement.classList.add('sb-collapsed'); } catch (e) {}</script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ @filemtime(public_path('css/app.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/forms.css') }}?v={{ @filemtime(public_path('css/forms.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/pbp.css') }}?v={{ @filemtime(public_path('css/pbp.css')) }}">
    @if (\App\Support\Modules::enabled('schedule'))<link rel="stylesheet" href="{{ asset('css/schedule.css') }}?v={{ @filemtime(public_path('css/schedule.css')) }}">@endif
    @if (\App\Support\Modules::enabled('compliance'))<link rel="stylesheet" href="{{ asset('css/compliance.css') }}?v={{ @filemtime(public_path('css/compliance.css')) }}">@endif
    @stack('styles')
    {{-- Theme goes last so it wins: edit public/css/theme.css to retheme. --}}
    <link rel="stylesheet" href="{{ asset('css/theme.css') }}?v={{ @filemtime(public_path('css/theme.css')) }}">
</head>
<body>
@include('partials.preview-banner')
<div class="layout">
    @include('partials.sidebar')
    <div class="main">
        @include('partials.header')
        <main class="content">
            @include('partials.flash')
            @yield('content')
            @include('partials.footer')
        </main>
    </div>
</div>
<script src="{{ asset('js/app.js') }}?v={{ @filemtime(public_path('js/app.js')) }}"></script>
@stack('scripts')
</body>
</html>
