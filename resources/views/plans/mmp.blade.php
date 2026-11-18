@extends('layouts.app')
@section('title', $m->public_id)
@section('page_title', $m->public_id.' · '.$m->product->name)
@section('parent_title', 'Recipes & plans')
@section('parent_url', route('plans.index'))
@php
    use App\Support\Fmt;
    $edit = $m->isEditable() && auth()->user()->isSupervisorOrAdmin();
    $dis = $edit ? '' : 'disabled';
    $fam = $m->product->family;
@endphp
@section('content')
<section class="card">
    <div class="card-head"><span class="pill {{ Fmt::pill($m->status) }}">{{ ucfirst($m->status) }}</span><b>Version {{ $m->version }}</b>
        <span class="pb-muted">{{ $m->product->brand?->name }} · {{ \App\Models\Product::FAMILIES[$fam] ?? 'no family' }}</span>
        <span class="pb-spacer"></span>
        @if (! $m->isEditable() && auth()->user()->isSupervisorOrAdmin())
            <form method="POST" action="{{ route('plans.mmp.version', $m) }}">@csrf<button class="pb-btn pb-btn-ghost" type="submit">Make a new version</button></form>
        @endif
    </div>
    @if (! $m->isEditable())<p class="pb-muted">Approved recipes are locked. To change it, make a new version; this one stays in use until the new one is approved.</p>@endif
    @if ($m->status === 'approved' && $m->isCurrent() && ! auth()->user()->isViewer())
        <p style="margin-top:8px"><a class="btn-save" id="startFromMmp" href="{{ route('production.index', ['product_id' => $m->product_id]) }}">Start a batch with {{ $m->product->name }}</a></p>
    @endif
</section>

<form method="POST" action="{{ route('plans.mmp.update', $m) }}" class="pb-form">@csrf @method('PUT')
<section class="card">
    <div class="card-head"><h3>Recipe header</h3></div>
    <div class="pb-grid-3">
        <div class="f-field"><label>Title</label><input class="pb-input" name="title" value="{{ old('title', $m->title) }}" {{ $dis }}></div>
        <div class="f-field"><label>Quality plan (PQP)</label><select name="pqp_id" class="pb-select" {{ $dis }}><option value="">—</option>
            @foreach ($pqps as $p)<option value="{{ $p->id }}" @selected($m->pqp_id === $p->id)>{{ $p->public_id }} · {{ $p->title }}</option>@endforeach</select></div>
        <div class="f-field"><label>Written for (units)</label><input class="pb-input" type="number" min="1" name="basis_units" value="{{ old('basis_units', $m->basis_units) }}" {{ $dis }}></div>
        <div class="f-field"><label>Fill per unit (g)</label><input class="pb-input" type="number" step="0.0001" name="unit_fill_mass_g" value="{{ old('unit_fill_mass_g', $m->unit_fill_mass_g) }}" {{ $dis }}></div>
        <div class="f-field"><label>Potency measured as</label><select name="potency_basis" class="pb-select" {{ $dis }}><option value="pct" @selected($m->potency_basis === 'pct')>% of fill</option><option value="mg" @selected($m->potency_basis === 'mg')>mg per unit</option></select></div>
        <div class="f-field"><label>Target potency</label><input class="pb-input" type="number" step="0.001" name="target_potency" value="{{ $m->target_potency }}" {{ $dis }}></div>
        <div class="f-field"><label>Potency allowed min</label><input class="pb-input" type="number" step="0.001" name="potency_min" value="{{ $m->potency_min }}" {{ $dis }}></div>
        <div class="f-field"><label>Potency allowed max</label><input class="pb-input" type="number" step="0.001" name="potency_max" value="{{ $m->potency_max }}" {{ $dis }}></div>
        <div class="f-field"><label>Hardware SKU</label><input class="pb-input" name="hardware_sku" value="{{ $m->hardware_sku }}" {{ $dis }}></div>
        <div class="f-field"><label>Expected yield %</label><input class="pb-input" type="number" step="0.01" name="yield_expected_pct" value="{{ $m->yield_expected_pct ?? 100 }}" {{ $dis }}></div>
        <div class="f-field"><label>Yield min %</label><input class="pb-input" type="number" step="0.01" name="yield_min_pct" value="{{ $m->yield_min_pct ?? 95 }}" {{ $dis }}></div>
        <div class="f-field"><label>Yield max %</label><input class="pb-input" type="number" step="0.01" name="yield_max_pct" value="{{ $m->yield_max_pct ?? 102 }}" {{ $dis }}></div>
        @if ($fam === 'preroll')
            <div class="f-field"><label>Infusion type</label><select name="infusion_type" class="pb-select" {{ $dis }}><option value="">None</option>
                @foreach (\App\Models\Mmp::INFUSION_TYPES as $k => $l)<option value="{{ $k }}" @selected($m->infusion_type === $k)>{{ $l }}</option>@endforeach</select></div>
            <div class="f-field"><label>Infusion %</label><input class="pb-input" name="infusion_pct" value="{{ $m->infusion_pct }}" placeholder="e.g. 10%" {{ $dis }}></div>
            <div class="f-field"><label>Terp brand</label><input class="pb-input" name="terp_brand" value="{{ $m->terp_brand }}" {{ $dis }}></div>
            <div class="f-field"><label>Terp flavor</label><input class="pb-input" name="terp_flavor" value="{{ $m->terp_flavor }}" {{ $dis }}></div>
        @endif
        @if ($fam === 'vape')
            <div class="f-field"><label>Mix type</label><input class="pb-input" name="mix_type" value="{{ $m->mix_type }}" placeholder="e.g. distillate + terp" {{ $dis }}></div>
            <div class="f-field"><label>Mix (% or ratio, e.g. 90/5/5)</label><input class="pb-input" name="mix_spec" value="{{ $m->mix_spec }}" {{ $dis }}>
                @if ($mixPreview)<small class="pb-muted">Reads as: {{ $mixPreview }}</small>@endif</div>
            <div class="f-field"><label>Terp brand</label><input class="pb-input" name="terp_brand" value="{{ $m->terp_brand }}" {{ $dis }}></div>
            <div class="f-field"><label>Terp flavor</label><input class="pb-input" name="terp_flavor" value="{{ $m->terp_flavor }}" {{ $dis }}></div>
            @if ($edit)<label class="pb-check-inline" style="margin-top:26px"><input type="checkbox" name="apply_mix" value="1"> Fill ingredient amounts from the mix (in line order)</label>@endif
        @endif
        <div class="f-field"><label>Label version</label><input class="pb-input" name="label_version" value="{{ $m->label_version }}" {{ $dis }}></div>
        <div class="f-field" style="grid-column:1/-1"><label>Packaging</label><textarea name="packaging_desc" rows="2" {{ $dis }}>{{ $m->packaging_desc }}</textarea></div>
    </div>
</section>

<section class="card">
    <div class="card-head"><h3>Ingredients</h3><span class="pb-muted">Amounts are for {{ $m->basis_units }} units. Type a number (g, ml, ea) or a % of the fill, e.g. 5%. Blank tolerance = default from Settings.</span></div>
    <div class="table-wrap"><table class="pb-table">
        <thead><tr><th>#</th><th>Ingredient</th><th>Amount</th><th>Unit</th><th>How it scales</th><th>Tolerance ±%</th><th>Overage %</th></tr></thead>
        <tbody>
        @foreach ($m->lines->concat($edit ? collect(range(1, 3))->map(fn () => new \App\Models\MmpLine) : collect()) as $i => $l)
            <tr>
                <td>{{ $l->exists ? $l->line_no : '+' }}<input type="hidden" name="lines[{{ $i }}][id]" value="{{ $l->id }}"></td>
                <td><select name="lines[{{ $i }}][ingredient_id]" class="pb-select" {{ $dis }}><option value="">—</option>
                    @foreach ($ingredients as $g)<option value="{{ $g->id }}" @selected($l->ingredient_id === $g->id)>{{ $g->name }}</option>@endforeach</select></td>
                <td><input class="pb-input" name="lines[{{ $i }}][qty]" value="{{ $l->exists ? (float) $l->qty_per_basis : '' }}" {{ $dis }} style="width:110px"></td>
                <td><select name="lines[{{ $i }}][unit]" class="pb-select" {{ $dis }}>@foreach (\App\Support\Units::entryOptions() as $k => $lab)<option value="{{ $k }}" @selected(($l->unit ?? 'g') === $k)>{{ $lab }}</option>@endforeach</select></td>
                <td><select name="lines[{{ $i }}][scale_mode]" class="pb-select" {{ $dis }}>@foreach (\App\Models\MmpLine::SCALE_MODES as $k => $lab)<option value="{{ $k }}" @selected(($l->scale_mode ?? 'by_mass') === $k)>{{ $lab }}</option>@endforeach</select></td>
                <td><input class="pb-input" type="number" step="0.001" name="lines[{{ $i }}][tolerance_pct]" value="{{ $l->tolerance_pct }}" placeholder="{{ $l->exists ? $l->effectiveTolerancePct() : '' }}" {{ $dis }} style="width:90px"></td>
                <td><input class="pb-input" type="number" step="0.001" name="lines[{{ $i }}][overage_pct]" value="{{ $l->overage_pct ?? 0 }}" {{ $dis }} style="width:90px"></td>
            </tr>
        @endforeach
        </tbody></table></div>
</section>

<section class="card">
    <div class="card-head"><h3>Manufacturing steps (batch step 6)</h3><span class="pb-muted">Stages come from the {{ \App\Models\Product::FAMILIES[$fam] ?? '' }} template.</span></div>
    <div class="table-wrap"><table class="pb-table">
        <thead><tr><th>#</th><th>Stage</th><th>Instruction</th><th>Check</th><th>Unit</th><th>Min</th><th>Max</th><th>2nd person</th></tr></thead>
        <tbody>
        @foreach ($m->steps->concat($edit ? collect(range(1, 2))->map(fn () => new \App\Models\MmpStep(['requires_verifier' => true])) : collect()) as $i => $s)
            <tr>
                <td>{{ $s->exists ? $s->step_no : '+' }}<input type="hidden" name="steps[{{ $i }}][id]" value="{{ $s->id }}"></td>
                <td><select name="steps[{{ $i }}][stage_code]" class="pb-select" {{ $dis }}>@foreach ($stages as $st)<option value="{{ $st->code }}" @selected($s->stage_code === $st->code)>{{ $st->name }}</option>@endforeach</select></td>
                <td><input class="pb-input" name="steps[{{ $i }}][instruction]" value="{{ $s->instruction }}" {{ $dis }} style="min-width:240px"></td>
                <td><input class="pb-input" name="steps[{{ $i }}][parameter]" value="{{ $s->parameter }}" {{ $dis }} style="width:130px"></td>
                <td><input class="pb-input" name="steps[{{ $i }}][unit]" value="{{ $s->unit }}" {{ $dis }} style="width:70px"></td>
                <td><input class="pb-input" type="number" step="any" name="steps[{{ $i }}][limit_min]" value="{{ $s->limit_min }}" {{ $dis }} style="width:90px"></td>
                <td><input class="pb-input" type="number" step="any" name="steps[{{ $i }}][limit_max]" value="{{ $s->limit_max }}" {{ $dis }} style="width:90px"></td>
                <td><input type="checkbox" name="steps[{{ $i }}][requires_verifier]" value="1" @checked($s->requires_verifier) {{ $dis }}></td>
            </tr>
        @endforeach
        </tbody></table></div>
    @if ($edit)<div style="margin-top:12px"><button class="btn-save" type="submit">Save recipe draft</button></div>@endif
</section>
</form>

<section class="card" id="approve">
    <div class="card-head"><h3>QC approval</h3></div>
    @if ($m->status === 'draft')
        @php
            $todo = [];
            if (! $m->pqp || $m->pqp->status !== 'approved') $todo[] = 'Pick an approved quality plan (PQP) in the recipe header and save.';
            if ($m->lines->where('line_no', '>', 0)->isEmpty()) $todo[] = 'Add at least one ingredient and save.';
            if ($m->steps->where('step_no', '>', 0)->isEmpty()) $todo[] = 'Add the manufacturing steps and save.';
        @endphp
        @if ($todo)<div class="pb-alert pb-alert-warn" id="approveTodo"><b>Before it can be approved:</b><ul>@foreach ($todo as $t)<li>{{ $t }}</li>@endforeach</ul></div>
        @else<p class="pb-muted" id="approveReady">Ready to approve. A QC reviewer signs below.</p>@endif
    @endif
    @include('partials.rec.signatures', ['sigs' => $m->signatures])
    @if ($edit)
        <form method="POST" action="{{ route('plans.mmp.approve', $m) }}" class="pb-form">@csrf
            @include('partials.rec.sign', ['label' => 'Approved by (QC reviewer)', 'only' => $signers->filter->isQcReviewer()])
            <div><button class="btn-save" type="submit">Approve recipe</button></div></form>
    @endif
</section>
@endsection
