@php
    use App\Support\Fmt;
    $sup = $signers->filter->isSupervisorOrAdmin();
    $potLines = $batch->mmp->lines->where('scale_mode', 'potency_adjusted');
    $activeLines = $batch->materialLines->whereNull('voided_at');
@endphp
<h4>1 · Calculator</h4>
@if ($isOpen && $activeLines->isEmpty())
<form method="POST" action="{{ route('production.calculate', $batch) }}" class="pb-inline-form">@csrf
    @foreach ($potLines as $line)
        <div class="f-field"><label>{{ $line->ingredient->name }} lot (its COA potency is used)</label>
            <select name="lots[{{ $line->id }}]" class="pb-select" required><option value="">—</option>
            @foreach ($lotOptions[$line->id] ?? [] as $o)<option value="{{ $o['lot']->id }}">{{ $o['lot']->public_id }} · {{ $o['lot']->potency_pct }}% · {{ Fmt::qty($o['available'], $o['lot']->unit) }} free</option>@endforeach
            </select></div>
    @endforeach
    <button class="pb-btn pb-btn-ghost" type="submit">{{ $calc ? 'Recalculate' : 'Calculate' }} for {{ number_format($batch->target_units) }} units</button>
</form>
@endif
@if ($calc)
    <div class="table-wrap" style="margin-top:10px"><table class="pb-table">
        <thead><tr><th>Ingredient</th><th>Planned</th><th>How</th><th>Tolerance</th></tr></thead>
        <tbody>@foreach ($calc->results as $r)
            @php $ml = $batch->mmp->lines->firstWhere('id', $r['mmp_line_id']); @endphp
            <tr><td>{{ $r['ingredient'] }}</td><td><b>{{ Fmt::qty($r['planned_qty'], $r['unit']) }}</b></td><td class="pb-muted">{{ $r['math'] ?? '' }}</td>
                <td>±{{ $ml?->effectiveTolerancePct() }}%</td></tr>
        @endforeach</tbody></table></div>
    @include('partials.rec.signatures', ['sigs' => $calc->signatures()->with('user')->get()])
    @if ($isOpen && ! $calcConfirmed)
        <form method="POST" action="{{ route('production.calc.confirm', $batch) }}" class="pb-inline-form">@csrf
            @include('partials.rec.sign', ['label' => 'Calculation checked by (supervisor)', 'only' => $sup])
            <button class="pb-btn pb-btn-ghost" type="submit">Confirm calculation</button></form>
    @endif
@endif

@if ($calc && $calcConfirmed)
<h4 style="margin-top:16px">2 · Pick released lots, weigh and verify</h4>
@if ($isOpen)
<form method="POST" action="{{ route('production.pick', $batch) }}" class="pb-inline-form">@csrf
    <div class="f-field"><label>Ingredient</label><select name="mmp_line_id" class="pb-select" required><option value="">—</option>
        @foreach ($batch->mmp->lines as $line)<option value="{{ $line->id }}">{{ $line->ingredient->name }}</option>@endforeach</select></div>
    <div class="f-field"><label>Released lot</label><select name="material_lot_id" class="pb-select" required><option value="">—</option>
        @foreach ($batch->mmp->lines as $line)
            <optgroup label="{{ $line->ingredient->name }}">
            @foreach ($lotOptions[$line->id] ?? [] as $o)<option value="{{ $o['lot']->id }}">{{ $o['lot']->public_id }} · {{ Fmt::qty($o['available'], $o['lot']->unit) }} free{{ $o['lot']->expiration_date ? ' · exp '.Fmt::date($o['lot']->expiration_date) : '' }}</option>@endforeach
            </optgroup>
        @endforeach</select></div>
    <div class="f-field"><label>Amount to take</label><input class="pb-input" type="number" step="0.0001" min="0" name="qty" required></div>
    <button class="pb-btn pb-btn-ghost" type="submit">Pick &amp; reserve</button>
</form>
<p class="pb-muted">Only released lots with enough free stock are listed. Quarantined, rejected or expired lots can't be used.</p>
@endif

<div class="table-wrap" style="margin-top:10px"><table class="pb-table">
    <thead><tr><th>Ingredient / lot</th><th>Planned</th><th>Actual</th><th>Variance</th><th>Status</th><th>Signatures</th><th></th></tr></thead>
    <tbody>
    @forelse ($batch->materialLines as $l)
        <tr class="{{ $l->voided_at ? 'is-void' : '' }}">
            <td>{{ $l->ingredient->name }}<br><span class="pb-muted">{{ $l->lot->public_id }}{{ $l->metrc_package_uid ? ' · '.$l->metrc_package_uid : '' }}</span></td>
            <td>{{ Fmt::qty($l->planned_qty, $l->unit) }}</td>
            <td>{{ $l->actual_qty !== null ? Fmt::qty($l->actual_qty, $l->unit) : '' }}</td>
            <td>@if ($l->variance_pct !== null)<span class="{{ $l->within_tolerance ? 'pb-tol-ok' : 'pb-tol-bad' }}">{{ $l->variance_pct }}% (±{{ $l->tolerance_pct }}%)</span>@endif
                @if ($l->deviation)<br><a href="{{ route('deviations.show', $l->deviation) }}">{{ $l->deviation->public_id }}</a> <span class="pill {{ Fmt::pill($l->deviation->status) }}">{{ $l->deviation->status === 'approved_to_continue' ? 'QC OK to continue' : ucfirst($l->deviation->status) }}</span>@endif</td>
            <td><span class="pill {{ Fmt::pill($l->status) }}">{{ $l->voided_at ? 'Void' : ucfirst($l->status) }}</span></td>
            <td>@include('partials.rec.signatures', ['sigs' => $l->signatures])</td>
            <td>
            @if ($isOpen && ! $l->voided_at)
                @if ($l->status === 'picked')
                    <form method="POST" action="{{ route('production.weigh', [$batch, $l->id]) }}" class="pb-form">@csrf
                        <input class="pb-input" type="number" step="0.0001" min="0" name="actual_qty" placeholder="Weighed amount ({{ $l->unit }})" required>
                        @if ($l->unit !== 'ea')<select name="scale_equipment_id" class="pb-select" required><option value="">Scale used</option>@foreach ($scales as $sc)<option value="{{ $sc->id }}">{{ $sc->equip_id }}</option>@endforeach</select>@endif
                        @include('partials.rec.sign', ['label' => 'Weighed by'])
                        <button class="pb-btn pb-btn-ghost" type="submit">Record weight</button></form>
                @elseif ($l->status === 'weighed')
                    <form method="POST" action="{{ route('production.verify-line', [$batch, $l->id]) }}" class="pb-form">@csrf
                        @include('partials.rec.sign', ['label' => 'Verified by (a different person)'])
                        <button class="pb-btn pb-btn-ghost" type="submit">Verify</button></form>
                @endif
                @if ($l->within_tolerance === false && ! $l->deviation)
                    <details class="pb-details"><summary>Out of tolerance: open a deviation</summary>
                        <form method="POST" action="{{ route('production.line-deviation', [$batch, $l->id]) }}" class="pb-form">@csrf
                            <textarea name="description" rows="2" placeholder="What happened and why" required></textarea>
                            @include('partials.rec.sign', ['label' => 'Reported by'])
                            <button class="pb-btn pb-btn-ghost" type="submit">Open deviation</button></form></details>
                @endif
                @if (in_array($l->status, ['weighed', 'verified'], true))
                    <details class="pb-details"><summary>Re-weigh</summary>
                        <form method="POST" action="{{ route('production.reweigh', [$batch, $l->id]) }}" class="pb-form">@csrf
                            <input class="pb-input" name="reason" placeholder="Reason" required><button class="pb-btn pb-btn-ghost" type="submit">Void weighing &amp; re-weigh</button></form></details>
                @endif
                <details class="pb-details pb-details-danger"><summary>Void pick</summary>
                    <form method="POST" action="{{ route('production.void-pick', [$batch, $l->id]) }}" class="pb-form">@csrf
                        <input class="pb-input" name="reason" placeholder="Reason" required><button class="btn-save pb-danger" type="submit">Void pick</button></form></details>
            @endif
            @if ($l->voided_at)<span class="pb-muted">{{ $l->void_reason }}</span>@endif
            </td>
        </tr>
    @empty
        <tr><td colspan="7" class="pb-muted">No lots picked yet.</td></tr>
    @endforelse
    </tbody></table></div>
<p class="pb-muted">Stock is taken out of the lots only when step 5 is completed, all at once.</p>
@endif
