<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
<title>Preview link</title><link rel="stylesheet" href="{{ asset('css/pbp.css') }}"></head>
<body>
@include('partials.preview-banner')
<main style="max-width:480px;margin:48px auto;padding:0 16px">
    <section class="card">
        <h3>{{ $state === 'used' ? 'Link already used' : 'Link expired' }}</h3>
        <p>{{ $state === 'used' ? 'This preview link has already been used. Each link signs in once.' : 'This preview link has expired.' }}
            Ask for a new preview link.</p>
    </section>
</main>
</body>
</html>
