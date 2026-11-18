{{-- Corrections history: old value struck through, never erased. $revisions --}}
@if (($revisions ?? collect())->isNotEmpty())
<div class="pb-revs">
    <h4>Corrections</h4>
    <table class="pb-table"><thead><tr><th>Field</th><th>Was</th><th>Now</th><th>Reason</th><th>By</th><th>When</th></tr></thead><tbody>
    @foreach ($revisions as $v)
        <tr><td>{{ str_replace('_', ' ', $v->field) }}</td><td><s>{{ $v->old_value }}</s></td><td>{{ $v->new_value }}</td><td>{{ $v->reason }}</td>
            <td>{{ $v->initials }} <span class="pb-muted">({{ $v->correctedBy?->name }})</span></td><td>{{ \App\Support\Fmt::dt($v->corrected_at) }}</td></tr>
    @endforeach
    </tbody></table>
</div>
@endif
