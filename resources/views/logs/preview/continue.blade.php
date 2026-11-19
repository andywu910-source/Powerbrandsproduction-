<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<title>Open the Compliance hub preview</title><link rel="stylesheet" href="{{ asset('css/pbp.css') }}"></head>
<body>
<main style="max-width:480px;margin:48px auto;padding:0 16px">
    <section class="card">
        <h3>Open the Compliance hub</h3>
        <p>This link signs you in once, in this browser, to the Compliance hub only. It ends automatically.
            Entries you save are real records on this site.</p>
        <form method="POST" action="{{ route('logs-preview.go', $token) }}">
            @csrf
            <button type="submit" class="btn btn-primary" style="width:100%;padding:12px;font-size:16px">Continue</button>
        </form>
    </section>
</main>
</body>
</html>
