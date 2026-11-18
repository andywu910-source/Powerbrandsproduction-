<div class="pb-grid-3">
    <div class="f-field"><label>Units packed</label><input class="pb-input" type="number" min="0" name="data[units_packed]" value="{{ old('data.units_packed') }}" required></div>
    <div class="f-field"><label>Label version used</label><input class="pb-input" name="data[label_version]" value="{{ old('data.label_version', $batch->mmp->label_version) }}" required></div>
    <div class="f-field"><label>Labels issued</label><input class="pb-input" type="number" min="0" name="data[labels_issued]" value="{{ old('data.labels_issued') }}" required></div>
    <div class="f-field"><label>Labels used</label><input class="pb-input" type="number" min="0" name="data[labels_used]" value="{{ old('data.labels_used') }}" required></div>
    <div class="f-field"><label>Labels destroyed</label><input class="pb-input" type="number" min="0" name="data[labels_destroyed]" value="{{ old('data.labels_destroyed') }}" required></div>
    <div class="f-field"><label>Labels returned</label><input class="pb-input" type="number" min="0" name="data[labels_returned]" value="{{ old('data.labels_returned') }}" required></div>
</div>
@if ($devs->isNotEmpty())
<div class="f-field"><label>If counts are off: deviation that covers it</label><select name="data[deviation_id]" class="pb-select"><option value="">—</option>
    @foreach ($devs as $d)<option value="{{ $d->id }}">{{ $d->public_id }} · {{ $d->title }}</option>@endforeach</select></div>
@endif
