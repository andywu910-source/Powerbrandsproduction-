{{-- History of a signed item: the original sign-off, every revision (who, when, old -> new, why) and every confirmation.
     $sigs = its signatures, $revs = its revisions. Read-only; nothing here can be edited or deleted. --}}
@php
    $rows = collect();
    foreach ($sigs as $s) {
        $rows->push(['at' => $s->signed_at, 'k' => 0, 'who' => $s->signerName(), 'what' => $s->meaning_text, 'extra' => null, 'ch' => []]);
    }
    foreach ($revs as $r) {
        if ($r->kind === 'confirmed') { continue; }   // shown as the re-confirmation signature itself
        $rows->push(['at' => $r->created_at, 'k' => 1, 'who' => $r->user_name, 'what' => $r->kind === 'revised' ? 'Revised by' : 'Edited by', 'extra' => $r->reason, 'ch' => $r->changeList()]);
    }
    $rows = $rows->sortBy(fn ($x) => $x['at']?->timestamp.'-'.$x['k'])->values();
@endphp
<details class="cmp-history" style="margin:-4px 0 6px;padding:6px 12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;font-size:13px">
    <summary style="cursor:pointer">History ({{ $rows->count() }})</summary>
    <ol class="pb-muted" style="margin:8px 0 0 18px;padding:0">
        @foreach ($rows as $x)
            <li style="margin-bottom:4px">
                {{ $x['what'] }} <b>{{ $x['who'] }}</b> · {{ \App\Support\Fmt::dt($x['at']) }} ET
                @if ($x['extra'])<br>Why: {{ $x['extra'] }}@endif
                @foreach ($x['ch'] as $field => [$old, $new])
                    <br>{{ $field }}: <s>{{ $old === null || $old === '' ? '(empty)' : $old }}</s> → {{ $new === null || $new === '' ? '(empty)' : $new }}
                @endforeach
            </li>
        @endforeach
    </ol>
</details>
