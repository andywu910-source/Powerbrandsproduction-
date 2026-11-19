{{-- Related logs panel. $selected (entry), $related (LogHub::related) --}}
<section class="card lg-related" id="lgRelated">
    <div class="card-head"><h3>Related logs</h3></div>
    @if (! $selected)
        <p class="pb-muted">No entries yet. Related entries (same Metrc UID, batch, equipment, room or employee) show here once this log has an entry.</p>
    @else
        <p class="pb-muted lg-rel-for">For entry #{{ $selected->id }} · {{ $selected->occurred_on?->format('M j, Y') }}</p>
        @if ($selected->links->isEmpty())
            <p class="pb-muted">This entry has no Metrc UID, batch, equipment, room or employee to link on.</p>
        @elseif ($related->isEmpty())
            <p class="pb-muted">No other log shares
                @foreach ($selected->links as $l)<a class="lg-key" href="{{ route('logs.related', ['kind' => $l->kind, 'value' => $l->value]) }}">{{ \App\Models\LogEntryLink::KINDS[$l->kind] }}: {{ $l->value }}</a>@endforeach yet.</p>
        @endif
        @foreach ($related as $g)
            <div class="lg-rel-group">
                <a class="lg-key" href="{{ route('logs.related', ['kind' => $g['kind'], 'value' => $g['value']]) }}">{{ \App\Models\LogEntryLink::KINDS[$g['kind']] }}: <b>{{ $g['kind'] === 'employee' ? \Illuminate\Support\Str::title($g['value']) : $g['value'] }}</b></a>
                <ul>
                @foreach ($g['entries'] as $e)
                    <li><a href="{{ route('logs.show', [$e->definition, 'entry' => $e->id]) }}"><span class="lg-code">{{ $e->definition->code }}</span> {{ $e->definition->name }}</a>
                        <span class="pb-muted">{{ $e->occurred_on?->format('M j, Y') }} · {{ $e->enteredBy?->name }}</span></li>
                @endforeach
                </ul>
            </div>
        @endforeach
    @endif
</section>
