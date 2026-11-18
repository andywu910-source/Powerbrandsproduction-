<div class="table-wrap"><table class="pb-table">
    <thead><tr><th>Equipment</th><th>Scale</th><th>Calibration due</th><th>Maintenance due</th><th>Condition</th><th>Status</th></tr></thead>
    <tbody>
    @forelse ($batch->equipment as $be)
        @php $probs = $be->equipment->problems(); @endphp
        <tr><td>{{ $be->equipment->equip_id }} · {{ $be->equipment->name }}</td><td>{{ $be->equipment->is_scale ? 'Yes' : '' }}</td>
            <td>{{ \App\Support\Fmt::date($be->calibration_due_on) }}</td><td>{{ \App\Support\Fmt::date($be->maintenance_due_on) }}</td>
            <td>{{ $be->condition_ok ? 'OK' : 'Not OK' }}</td>
            <td>@if ($probs)<span class="pill bad" title="{{ implode(' ', $probs) }}">{{ $probs[0] }}</span>@else<span class="pill ok">Ready</span>@endif</td></tr>
    @empty
        <tr><td colspan="6" class="pb-muted">No equipment added yet.</td></tr>
    @endforelse
    </tbody></table></div>
@if ($isOpen)
<form method="POST" action="{{ route('production.equipment', $batch) }}" class="pb-inline-form">@csrf
    <div class="f-field"><label>Add equipment</label><select name="equipment_id" class="pb-select" required><option value="">—</option>
        @foreach ($equipmentList as $e)<option value="{{ $e->id }}">{{ $e->equip_id }} · {{ $e->name }}{{ $e->is_scale ? ' (scale)' : '' }}</option>@endforeach</select></div>
    <label class="pb-check-inline"><input type="checkbox" name="condition_ok" value="1"> Clean and in good condition</label>
    <button class="pb-btn pb-btn-ghost" type="submit">Add</button>
</form>
@endif
