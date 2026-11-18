<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @include('partials.favicon')
    <title>Sign in | {{ config('pbp.name') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/login.css') }}?v={{ @filemtime(public_path('css/login.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/theme.css') }}?v={{ @filemtime(public_path('css/theme.css')) }}">
</head>
<body>
@include('partials.preview-banner')
<div class="page">
    <aside class="brand">

        <div class="logo-block">
            <div class="logo-box">
                <img src="{{ asset('img/logo.svg') }}" alt="PowerBrands Productions logo" class="logo-img">
            </div>
            <div class="logo-text">
                <span class="logo-title">POWERBRANDS</span>
                <span class="logo-tagline">Productions</span>
            </div>
        </div>

        <div class="brand-copy">
            <h1>Production, logged right.</h1>
            <p>Sign in to manage products, batch logs, and SOP &amp; compliance records for the PowerBrands NY processing team.</p>
        </div>

        <div class="brand-foot">&copy; {{ date('Y') }} PowerBrands Productions. Internal use only.</div>
    </aside>

    <main class="panel">
        <div class="form-card">
            <h2>Welcome back</h2>
            <p class="subtitle">Sign in with your email and password.</p>

            @if (session('status'))
                <div class="alert alert-success" role="status">{{ session('status') }}</div>
            @endif
            @if ($errors->any())
                <div class="alert alert-error" role="alert">{{ $errors->first() }}</div>
            @endif

            <form id="loginForm" method="POST" action="{{ route('login.submit') }}">
                @csrf
                <div class="field">
                    <label for="email">Email address</label>
                    <div class="input-wrap">
                        <input type="email" id="email" name="email" value="{{ old('email') }}" placeholder="you@powerbrandsny.com" autocomplete="email" required autofocus>
                    </div>
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <div class="input-wrap has-toggle">
                        <input type="password" id="password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
                        <button type="button" class="toggle-pass" id="togglePass" aria-label="Show password">
                            <svg class="icon-show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg class="icon-hide" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.9 17.9A10.1 10.1 0 0112 19c-6.4 0-10-7-10-7a18.5 18.5 0 015.1-5.9M9.9 5.2A9.7 9.7 0 0112 5c6.4 0 10 7 10 7a18.6 18.6 0 01-2.2 3.2M14.1 14.1a3 3 0 01-4.2-4.2"/><path d="M2 2l20 20"/></svg>
                        </button>
                    </div>
                </div>

                <div class="row">
                    <label class="check">
                        <input type="checkbox" name="remember" value="1">
                        Keep me signed in
                    </label>
                    <span class="pb-forgot">Forgot password? Ask an admin to reset it.</span>
                </div>

                <button type="submit" id="loginBtn" class="btn btn-primary">Sign in</button>

                {{-- GOOGLE SIGN-IN (future): add a "Sign in with Google" button here linking to route('google.redirect'). --}}
            </form>
        </div>
    </main>
</div>
<script>
(function () {
    var btn = document.getElementById('togglePass'), input = document.getElementById('password');
    btn.addEventListener('click', function () {
        var hidden = input.type === 'password';
        input.type = hidden ? 'text' : 'password';
        btn.classList.toggle('is-visible', hidden);
        btn.setAttribute('aria-label', hidden ? 'Hide password' : 'Show password');
    });
    document.getElementById('loginForm').addEventListener('submit', function () {
        var b = document.getElementById('loginBtn'); b.disabled = true; b.textContent = 'Signing in...';
    });
})();
</script>
</body>
</html>
