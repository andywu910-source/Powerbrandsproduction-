{{-- Void (never delete): $action (url), $what --}}
@if (auth()->user()->isSupervisorOrAdmin())
<details class="pb-details pb-details-danger">
    <summary>Void this {{ $what ?? 'record' }}</summary>
    <form method="POST" action="{{ $action }}" class="pb-form">
        @csrf
        <div class="f-field"><label>Reason (required)</label><input name="reason" class="pb-input" required></div>
        <p class="pb-muted">Voided records are kept and shown as VOID. Nothing is ever deleted.</p>
        <div><button class="btn-save pb-danger" type="submit">Void</button></div>
    </form>
</details>
@endif
