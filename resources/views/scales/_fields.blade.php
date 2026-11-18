{{-- Scale register fields. $scale (Equipment), $rooms, $disabled --}}
@php
    $v = fn ($k) => old($k, $scale->{$k} instanceof \DateTimeInterface ? $scale->{$k}->format('Y-m-d') : $scale->{$k});
    $units = \App\Http\Controllers\ScaleController::UNITS;
@endphp
<fieldset class="pb-fieldset" @disabled($disabled)>
    <legend>The scale</legend>
    <div class="pb-grid">
        <div class="f-field"><label for="s_equip_id">Scale ID *</label><input id="s_equip_id" name="equip_id" class="pb-input" value="{{ $v('equip_id') }}" required maxlength="40" placeholder="e.g. SC-01"></div>
        <div class="f-field"><label for="s_name">Name *</label><input id="s_name" name="name" class="pb-input" value="{{ $v('name') }}" required placeholder="e.g. Ohaus bench scale"></div>
        <div class="f-field"><label for="s_serial">Serial #</label><input id="s_serial" name="serial" class="pb-input" value="{{ $v('serial') }}"></div>
        <div class="f-field"><label for="s_room">Room</label><select id="s_room" name="room_id" class="pb-select"><option value="">—</option>
            @foreach ($rooms as $rm)<option value="{{ $rm->id }}" @selected((string) $v('room_id') === (string) $rm->id)>{{ $rm->room_no }} · {{ $rm->name }}</option>@endforeach</select></div>
        <div class="f-field"><label for="s_location">Location</label><input id="s_location" name="location" class="pb-input" value="{{ $v('location') }}" placeholder="e.g. Filling bench 2"></div>
        <div class="f-field"><label>Capacity</label><div class="pb-row pb-nowrap"><input name="capacity" type="number" step="any" min="0" inputmode="decimal" class="pb-input" value="{{ $v('capacity') }}" aria-label="Capacity">
            <select name="capacity_unit" class="pb-select pb-sm" aria-label="Unit"><option value="">unit</option>@foreach ($units as $uk => $ul)<option value="{{ $uk }}" @selected($v('capacity_unit') === $uk)>{{ $ul }}</option>@endforeach</select></div></div>
        <div class="f-field"><label for="s_division">Division (d), same unit</label><input id="s_division" name="division" type="number" step="any" min="0" inputmode="decimal" class="pb-input" value="{{ $v('division') }}" placeholder="e.g. 0.01"></div>
        <div class="f-field"><label class="pb-check-inline" style="margin-top:28px"><input type="checkbox" name="active" value="1" @checked(old('active', $scale->active ?? true))> Active (in use)</label></div>
    </div>
</fieldset>
<fieldset class="pb-fieldset" @disabled($disabled)>
    <legend>Weights &amp; Measures</legend>
    <div class="pb-grid">
        <div class="f-field"><label for="s_rep">Reported to local Weights &amp; Measures on</label><input id="s_rep" type="date" name="wm_reported_on" class="pb-input" value="{{ $v('wm_reported_on') }}"></div>
        <div class="f-field"><label for="s_sealed">Inspected and sealed on</label><input id="s_sealed" type="date" name="wm_sealed_on" class="pb-input" value="{{ $v('wm_sealed_on') }}"></div>
        <div class="f-field"><label for="s_seal">Seal number</label><input id="s_seal" name="wm_seal_ref" class="pb-input" value="{{ $v('wm_seal_ref') }}"></div>
        <div class="f-field"><label for="s_last">Last inspection</label><input id="s_last" type="date" name="last_inspected_on" class="pb-input" value="{{ $v('last_inspected_on') }}"></div>
        <div class="f-field"><label for="s_due">Next inspection due</label><input id="s_due" type="date" name="inspection_due_on" class="pb-input" value="{{ $v('inspection_due_on') }}">
            <small class="pb-muted">Leave empty for last inspection + {{ \App\Models\Equipment::inspectionMonths() }} months (NY Ag &amp; Markets Law §182, 1 NYCRR §220.5: at least yearly).</small></div>
    </div>
</fieldset>
<fieldset class="pb-fieldset" @disabled($disabled)>
    <legend>Calibration &amp; maintenance</legend>
    <div class="pb-grid">
        <div class="f-field"><label for="s_lcal">Last calibrated</label><input id="s_lcal" type="date" name="last_calibrated_on" class="pb-input" value="{{ $v('last_calibrated_on') }}"></div>
        <div class="f-field"><label for="s_cal">Calibration due</label><input id="s_cal" type="date" name="calibration_due_on" class="pb-input" value="{{ $v('calibration_due_on') }}"></div>
        <div class="f-field"><label for="s_mnt">Maintenance due</label><input id="s_mnt" type="date" name="maintenance_due_on" class="pb-input" value="{{ $v('maintenance_due_on') }}"></div>
        <div class="f-field" style="grid-column:1/-1"><label for="s_notes">Notes</label><textarea id="s_notes" name="notes" rows="3" class="pb-input">{{ $v('notes') }}</textarea></div>
    </div>
</fieldset>
