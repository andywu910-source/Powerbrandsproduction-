<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
<title>Open the preview</title><link rel="stylesheet" href="{{ asset('css/pbp.css') }}"></head>
<body>
@include('partials.preview-banner')
<main style="max-width:480px;margin:48px auto;padding:0 16px">
    <section class="card">
        <h3>Open the PowerBrands preview</h3>
        <p>This signs you in to the test copy of the site (test data only). The link works once, in this browser.</p>
        <form method="POST" action="{{ url('/guest/'.$token) }}">
            @csrf
            <button type="submit" class="btn btn-primary" style="width:100%;padding:12px;font-size:16px">Continue to the preview</button>
        </form>
    </section>
</main>
</body>
</html>
