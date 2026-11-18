{{-- $sigs: signatures collection --}}
@if (($sigs ?? collect())->isNotEmpty())
<ul class="pb-sigs">
    @foreach ($sigs as $s)
        <li class="{{ $s->invalidated_at ? 'is-void' : '' }}">
            <span class="pb-sig-meaning">{{ $s->meaning_text }}</span>
            <b>{{ $s->user?->name }}</b>
            <span class="pb-muted">{{ \App\Support\Fmt::dt($s->signed_at) }}</span>
            @if ($s->invalidated_at)<span class="pill bad">No longer valid (record corrected)</span>@endif
        </li>
    @endforeach
</ul>
@endif
