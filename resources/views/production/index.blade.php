@extends('layouts.app')
@section('title', 'Production')
@section('page_title', 'Production')
@php use App\Support\Fmt; $u = auth()->user(); @endphp

@section('content')
<section class="card">
    <div class="card-head"><h3>Batches in progress</h3></div>
    <div class="table-wrap"><table class="pb-table">
        <thead><tr><th>Batch number</th><th>Product</th><th>Brand</th><th>Units</th><th>Step</th><th>Started</th><th>Status</th></tr></thead>
        <tbody>
        @forelse ($batches as $b)
            <tr><td><a class="pb-bn" href="{{ route('production.show', $b) }}">{{ $b->batch_number }}</a></td>
                <td>{{ $b->product?->name }}</td><td>{{ $b->product?->brand?->name }}</td><td>{{ number_format($b->target_units) }}</td>
                <td>{{ min($b->current_step, 9) }} of 9 · {{ \App\Services\ProductionWorkflow::STEPS[min($b->current_step, 9)][1] }}</td>
                <td>{{ Fmt::dt($b->started_at) }}</td>
                <td><span class="{{ $b->logStatusClass() }}">{{ $b->logStatusLabel() }}</span></td></tr>
        @empty
            <tr><td colspan="7" class="pb-muted">No batches in progress.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</section>

@if ($incomingJobs->count())
<section class="card" id="incomingJobs">
    <div class="card-head"><h3>Incoming jobs</h3><span class="pb-muted">Planned, not started yet. "Start this job" runs the start checks.</span></div>
    <div class="table-wrap"><table class="pb-table">
        <thead><tr><th>Batch number</th><th>Product</th><th>Brand</th><th>Planned units</th><th>Planned date</th><th>Status</th><th></th></tr></thead>
        <tbody>
        @foreach ($incomingJobs as $b)
            <tr><td><a class="pb-bn" href="{{ route('batch-log.show', $b) }}">{{ $b->batch_number }}</a></td>
                <td>{{ $b->product?->name }}</td><td>{{ $b->product?->brand?->name }}</td><td>{{ $b->target_units !== null ? number_format($b->target_units) : '—' }}</td>
                <td>{{ $b->planned_date ? $b->planned_date->format('D M j, Y') : '—' }}</td>
                <td><span class="{{ $b->logStatusClass() }}">{{ $b->logStatusLabel() }}</span></td>
                <td>@unless ($u->isViewer())<a class="pb-btn pb-btn-ghost" href="{{ route('production.index', ['start' => $b->batch_number]) }}">Start this job</a>@endunless</td></tr>
        @endforeach
        </tbody>
    </table></div>
</section>
@endif

@unless ($u->isViewer())
<section class="card" id="newJob">
    <div class="card-head"><h3>{{ $incoming ? 'Start Incoming job '.$incoming->batch_number : 'Start a new batch' }}</h3><span class="pb-muted">Step 1 of 9 · Batch setup</span></div>
    @include('production.partials.product-picker')

    @if ($selected)
        <div class="pb-kv" style="margin-top:14px">
            <div><span>Product</span><b id="selProduct">{{ $selected->name }}</b></div>
            <div><span>Brand · code</span><b>{{ $selected->brand?->name ?? '—' }} · {{ $selected->brand?->short_code ?? 'missing' }}</b></div>
            <div><span>Product code</span><b>{{ $selected->short_code ?? 'missing' }}</b></div>
            <div><span>Family</span><b>{{ \App\Models\Product::FAMILIES[$selected->family] ?? 'not set' }}</b></div>
        </div>
        @include('partials.rec.problems', ['problems' => $selectedProblems])
        @if ($selectedProblems)
            @php
                $okMmp = $selected->currentMmp();
                $draftMmp = $okMmp ? null : \App\Models\Mmp::where('product_id', $selected->id)->where('status', 'draft')->orderByDesc('version')->first();
                $canPlan = $u->isSupervisorOrAdmin();
            @endphp
            <div class="pb-alert pb-alert-warn pb-mmp-needed" id="mmpNeeded" role="status" style="margin-top:12px">
                @if (! $okMmp && $draftMmp)
                    <b>This product has an MMP that is not approved yet ({{ $draftMmp->public_id }} v{{ $draftMmp->version }}, draft). Approve it to start a batch.</b>
                    @if ($canPlan)<div style="margin-top:8px"><a class="btn-save" id="mmpNeededLink" href="{{ route('plans.mmp', $draftMmp) }}#approve">Open the MMP to finish and approve it</a></div>@endif
                @elseif (! $okMmp)
                    <b>This product has no approved MMP yet. Add and approve one to start a batch.</b>
                    @if ($canPlan)<div style="margin-top:8px"><a class="btn-save" id="mmpNeededLink" href="{{ route('plans.index', ['product_id' => $selected->id]) }}#newMmp">Add an MMP for {{ $selected->name }}</a></div>@endif
                @else
                    <b>This batch can't start until the items above are fixed.</b>
                @endif
                @unless ($canPlan)<div class="pb-muted" style="margin-top:6px">Ask an admin or supervisor (Andy, Jenny or Leo) to set it up.</div>@endunless
            </div>
        @endif
        @if ($selectedProblems && $u->isAdmin())
            <p class="pb-muted" style="margin-top:8px">Fix it in <a href="{{ route('setup.edit', ['products', $selected->id]) }}">Setup → Products</a>
                @if ($selected->brand) or <a href="{{ route('setup.edit', ['brands', $selected->brand->id]) }}">Setup → Brands</a>@endif
                or <a href="{{ route('plans.index') }}">Recipes &amp; plans</a>.</p>
        @endif
        @if ($incoming)
            <div class="pb-alert" style="margin-top:12px">This is Incoming job <b class="pb-bn">{{ $incoming->batch_number }}</b>{{ $incoming->planned_date ? ', planned for '.$incoming->planned_date->format('D M j') : '' }}. Starting it runs the normal start checks and moves it to In production.</div>
        @endif
        @unless ($selectedProblems)
        <form method="POST" action="{{ $incoming ? route('production.start-incoming', $incoming) : route('production.store') }}" class="pb-form pb-grid" style="margin-top:14px">
            @csrf
            <input type="hidden" name="product_id" value="{{ $selected->id }}">
            @if ($scheduleJob && $scheduleJob->product_id === $selected->id)
                <input type="hidden" name="schedule_job" value="{{ $scheduleJob->id }}">
                <div class="pb-alert" style="grid-column:1/-1">From the Weekly Schedule: {{ $scheduleJob->cell->employee->name }}, {{ $scheduleJob->cell->day->format('D M j') }}{{ $scheduleJob->process_step ? ' · '.$scheduleJob->process_step : '' }}. The new batch will show on that schedule cell.</div>
            @endif
            <div class="f-field pb-bn-field" style="grid-column:1/-1"><label for="batch_number">Batch number</label>
                <div class="pb-combo-box">
                    @if ($incoming)
                    <input id="batch_number" class="pb-input pb-bn" value="{{ $incoming->batch_number }}" readonly data-fixed="1" aria-describedby="bnHelp">
                    @else
                    <input id="batch_number" class="pb-input pb-bn" name="batch_number" value="{{ old('batch_number') }}" required maxlength="60" autocomplete="off" spellcheck="false"
                        autocapitalize="characters" placeholder="Type the batch number" aria-describedby="bnHelp bnCheck">
                    @endif
                    @if ($suggest && ! $incoming)<button type="button" class="pb-btn pb-btn-ghost pb-bn-suggest" id="bnSuggest" data-value="{{ $suggest }}">Suggest</button>@endif
                </div>
                <small class="pb-muted" id="bnHelp">Type your own batch number (any format). It is used everywhere: labels, the Batch Log and the batch record, and it cannot be changed once the batch starts.@if ($suggest) "Suggest" fills in {{ $suggest }} from the batch number pattern.@endif</small>
                <div class="pb-alert pb-alert-err" id="bnCheck" role="status" aria-live="polite" hidden></div>
            </div>
            @php $serving = \App\Support\OcmSampling::servingFor($selected); @endphp
            <div class="f-field"><label for="target_units">Number of units</label><input id="target_units" class="pb-input" type="number" min="1" max="{{ \App\Support\OcmSampling::MAX_UNITS }}" name="target_units" value="{{ old('target_units', $incoming?->target_units ?? $scheduleJob?->target_qty) }}" required>
                <div class="pb-ocm" id="ocmSamples" data-serving="{{ $serving }}" aria-live="polite" title="{{ \App\Support\OcmSampling::INFO }}">
                    <span>Lab samples (per test): <b id="ocmPer">—</b></span> · <span>Retention samples needed: <b id="ocmRet">—</b></span>
                    <span class="pb-muted">({{ $serving === 'multi' ? 'multi-serving' : 'single-serve' }} · ⓘ)</span>
                    <div class="pb-alert pb-alert-err" id="ocmTooBig" hidden>{{ \App\Support\OcmSampling::TOO_BIG }}</div>
                </div>
                <small class="pb-muted">{{ \App\Support\OcmSampling::INFO }}</small>
            </div>
            <script>
            (function () {
                var i = document.getElementById('batch_number'), box = document.getElementById('bnCheck'), sug = document.getElementById('bnSuggest'), t, seq = 0;
                if (i.getAttribute('data-fixed')) return;   // Incoming job: its number is already reserved
                var URL = @json(route('production.check-number', [], false));
                function check() {
                    var n = i.value.trim(), my = ++seq;
                    if (!n) { box.hidden = true; i.setCustomValidity(''); return; }
                    fetch(URL + '?n=' + encodeURIComponent(n), {headers: {'Accept': 'application/json'}}).then(function (r) { return r.json(); }).then(function (j) {
                        if (my !== seq) return;
                        box.hidden = j.ok; box.textContent = j.message || ''; i.setCustomValidity(j.ok ? '' : (j.message || 'Batch number not allowed'));
                    }).catch(function () {});
                }
                i.addEventListener('input', function () { clearTimeout(t); i.setCustomValidity(''); t = setTimeout(check, 250); });
                i.addEventListener('blur', function () { i.value = i.value.trim(); check(); });
                if (sug) sug.addEventListener('click', function () { i.value = sug.getAttribute('data-value'); check(); i.focus(); });
                if (i.value) check();
            })();
            (function () {
                var T = @json(\App\Support\OcmSampling::tableForJs()), box = document.getElementById('ocmSamples'), inp = document.getElementById('target_units');
                var multi = box.getAttribute('data-serving') === 'multi', btn = inp.form.querySelector('button[type=submit]');
                function per(n) { if (!(n >= 1) || n > T.max) return null; for (var i = 0; i < T.rows.length; i++) { if (n <= T.rows[i][0]) return T.rows[i][multi ? 2 : 1]; } return null; }
                function upd() {
                    var n = parseInt(inp.value, 10), p = per(n), big = n > T.max;
                    document.getElementById('ocmPer').textContent = p === null ? '—' : p;
                    document.getElementById('ocmRet').textContent = p === null ? '—' : p * T.copies;
                    document.getElementById('ocmTooBig').hidden = !big;
                    inp.setCustomValidity(big ? T.too_big : '');
                }
                inp.addEventListener('input', upd); upd();
            })();
            </script>
            <div class="f-field"><label>Target potency (optional, as in the recipe)</label><input class="pb-input" type="number" step="0.001" name="target_potency" value="{{ old('target_potency') }}"></div>
            <div class="f-field"><label>Room</label><select name="room_id" class="pb-select"><option value="">—</option>@foreach ($rooms as $rm)<option value="{{ $rm->id }}">{{ $rm->room_no }} · {{ $rm->name }}</option>@endforeach</select></div>
            <div class="f-field"><label>Line (optional)</label><input class="pb-input" name="line" value="{{ old('line') }}"></div>
            <div class="form-actions"><button class="btn-save" type="submit" id="btnStart">{{ $incoming ? 'Start this job' : 'Start batch' }}</button>
                <span class="pb-muted">The batch number is fixed once the batch starts.</span></div>
        </form>
        @endunless
        @unless ($incoming)
        <details class="pb-details sch-incoming" id="incomingBox" @if ($selectedProblems || old('incoming')) open @endif>
            <summary>Or save it as Incoming (planned, start later)</summary>
            <form method="POST" action="{{ route('production.incoming') }}" class="pb-form pb-grid" id="incomingForm">@csrf
                <input type="hidden" name="incoming" value="1"><input type="hidden" name="product_id" value="{{ $selected->id }}">
                @if ($scheduleJob && $scheduleJob->product_id === $selected->id)<input type="hidden" name="schedule_job" value="{{ $scheduleJob->id }}">@endif
                <p class="pb-muted" style="grid-column:1/-1">Only the product and a batch number are needed now. The batch number is reserved and fixed.
                    @if ($selectedProblems) The problems above must be fixed before the job can start.@endif</p>
                <div class="f-field"><label for="inc_batch_number">Batch number</label><input id="inc_batch_number" class="pb-input pb-bn" name="batch_number" value="{{ old('incoming') ? old('batch_number') : '' }}" required maxlength="60" autocomplete="off" spellcheck="false" autocapitalize="characters" placeholder="Type the batch number"></div>
                <div class="f-field"><label for="planned_units">Planned units (optional)</label><input id="planned_units" class="pb-input" type="number" min="1" max="{{ \App\Support\OcmSampling::MAX_UNITS }}" name="planned_units" value="{{ old('planned_units', $scheduleJob?->target_qty) }}"></div>
                <div class="f-field"><label for="planned_date">Planned date (optional)</label><input id="planned_date" class="pb-input" type="date" name="planned_date" value="{{ old('planned_date', $scheduleJob?->cell?->day?->toDateString()) }}"></div>
                <div class="form-actions"><button class="btn-save" type="submit" id="btnIncoming">Save as Incoming</button></div>
            </form>
        </details>
        @endunless
    @endif
</section>
@endunless
@endsection
