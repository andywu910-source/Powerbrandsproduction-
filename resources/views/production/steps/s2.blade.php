@php $chk = $s->checklist; @endphp
@if ($chk)
    <p>Changeover cleaning <a href="{{ route('checklists.show', $chk) }}"><b>{{ $chk->public_id }}</b></a>
        <span class="pill {{ \App\Support\Fmt::pill($chk->status) }}">{{ ucfirst(str_replace('_', ' ', $chk->status)) }}</span></p>
@endif
@if ($isOpen && (! $chk || in_array($chk->status, ['void'], true) || ($chk->status === 'completed' && app(\App\Services\ProductionWorkflow::class)->checklistFailed($chk))))
    <form method="POST" action="{{ route('production.cleaning', $batch) }}" class="pb-inline-form">@csrf
        <button class="pb-btn pb-btn-ghost" type="submit">{{ $chk ? 'Start a new changeover cleaning checklist' : 'Start the changeover cleaning checklist' }}</button></form>
@elseif ($isOpen && $chk && $chk->status !== 'completed')
    <p><a class="pb-btn pb-btn-ghost" href="{{ route('checklists.show', $chk) }}">Open the checklist</a></p>
@endif
