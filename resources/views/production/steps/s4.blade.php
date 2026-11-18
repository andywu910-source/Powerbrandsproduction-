<div class="table-wrap"><table class="pb-table">
    <thead><tr><th>Name</th><th>Task</th><th>PPE</th><th>Training on file</th></tr></thead>
    <tbody>
    @forelse ($batch->people as $bp)
        <tr><td>{{ $bp->person->full_name }}</td><td>{{ $bp->task }}</td><td>{{ $bp->ppe_ok ? 'OK' : 'Missing' }}</td><td>{{ count((array) $bp->training_record_ids) }} record(s)</td></tr>
    @empty
        <tr><td colspan="4" class="pb-muted">Nobody added yet.</td></tr>
    @endforelse
    </tbody></table></div>
@if ($isOpen)
<form method="POST" action="{{ route('production.people', $batch) }}" class="pb-inline-form">@csrf
    <div class="f-field"><label>Add a person</label><select name="person_id" class="pb-select" required><option value="">—</option>
        @foreach ($people->where('no_documentation', false) as $p)<option value="{{ $p->id }}">{{ $p->full_name }}</option>@endforeach</select></div>
    <div class="f-field"><label>Task</label><input class="pb-input" name="task" placeholder="e.g. filling"></div>
    <label class="pb-check-inline"><input type="checkbox" name="ppe_ok" value="1"> Wearing the right PPE</label>
    <button class="pb-btn pb-btn-ghost" type="submit">Add</button>
</form>
@endif
