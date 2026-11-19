{{-- $sigs: signatures collection. Sign-offs are immutable; admins can void one with a reason (kept on record). --}}
@if (($sigs ?? collect())->isNotEmpty())
@php $isAdminViewer = auth()->check() && auth()->user()->isAdmin(); @endphp
<ul class="pb-sigs">
    @foreach ($sigs as $s)
        <li class="{{ $s->invalidated_at ? 'is-void' : '' }}">
            <span class="pb-sig-meaning">{{ $s->meaning_text }}</span>
            <b>{{ $s->signerName() }}</b>
            <span class="pb-muted">{{ \App\Support\Fmt::dt($s->signed_at) }} ET</span>
            @if ($s->isVoided())
                <span class="pill bad">VOID</span>
                <span class="pb-muted">voided by {{ $s->voidedBy?->name ?? 'an admin' }}{{ $s->invalidated_at ? ', '.\App\Support\Fmt::dt($s->invalidated_at).' ET' : '' }}: {{ $s->void_reason }}</span>
            @elseif ($s->invalidated_at)
                <span class="pill bad">No longer valid (record corrected)</span>
            @elseif ($isAdminViewer)
                <details class="pb-details pb-details-danger">
                    <summary>Void this sign-off</summary>
                    <form method="POST" action="{{ route('signatures.void', $s->id) }}" class="pb-form">
                        @csrf
                        <div class="f-field"><label>Reason (required)</label><input name="reason" class="pb-input" required minlength="3" maxlength="1000"></div>
                        <p class="pb-muted">The sign-off stays on record, marked VOID, with your name and this reason. It is never deleted.</p>
                        <div><button class="btn-save pb-danger" type="submit">Void sign-off</button></div>
                    </form>
                </details>
            @endif
        </li>
    @endforeach
</ul>
@endif
