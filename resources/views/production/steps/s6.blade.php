@php
    use App\Support\Fmt;
    $recs = $batch->mmpStepRecords->whereNull('voided_at')->keyBy('mmp_step_id');
    $stage = null;
    $nextId = null;
    foreach ($mmpSteps as $ms) { $r = $recs->get($ms->id); if (! $r) { $nextId = $ms->id; break; } if ($ms->requires_verifier && $r->status !== 'verified') { $nextId = null; break; } }
@endphp
@forelse ($mmpSteps as $ms)
    @php $r = $recs->get($ms->id); @endphp
    @if ($ms->stage_code !== $stage)
        @php $stage = $ms->stage_code; @endphp
        <h4 style="margin-top:12px">{{ ucfirst(str_replace('_', ' ', $stage ?? 'Steps')) }}</h4>
    @endif
    <div class="pb-list" style="border-bottom:1px solid var(--pb-border);padding:8px 0">
        <div class="pb-row"><b>{{ $ms->step_no }}.</b> <span>{{ $ms->instruction }}</span>
            @if ($ms->hasLimits())<span class="pill blue">{{ $ms->parameter }} {{ $ms->limit_min }}–{{ $ms->limit_max }} {{ $ms->unit }}</span>@endif
            <span class="pb-spacer"></span>
            @if ($r)<span class="pill {{ Fmt::pill($r->status) }}">{{ ucfirst($r->status) }}</span>@endif
        </div>
        @if ($r)
            <p class="pb-muted">@if ($r->actual_value !== null)Result: <b class="{{ $r->within_limit === false ? 'pb-tol-bad' : 'pb-tol-ok' }}">{{ (float) $r->actual_value }} {{ $ms->unit }}</b> · @endif
                {{ $r->observation }} · {{ Fmt::dt($r->performed_at) }}
                @if ($r->staff_person_ids) · by {{ \App\Models\Person::whereIn('id', $r->staff_person_ids)->pluck('full_name')->implode(', ') }}@endif
                @if ($r->late_entry_reason) · <span class="pill warn">Late entry: {{ $r->late_entry_reason }}</span>@endif</p>
            @include('partials.rec.signatures', ['sigs' => $r->signatures])
            @include('partials.rec.revisions', ['revisions' => $r->revisions])
            @if ($r->deviation)<p>Deviation <a href="{{ route('deviations.show', $r->deviation) }}">{{ $r->deviation->public_id }}</a></p>@endif
            @if ($isOpen && $r->status === 'recorded' && $ms->requires_verifier)
                <form method="POST" action="{{ route('production.mmp.verify', [$batch, $r->id]) }}" class="pb-inline-form">@csrf
                    @include('partials.rec.sign', ['label' => 'Verified by (a different person)'])<button class="pb-btn pb-btn-ghost" type="submit">Verify</button></form>
            @endif
            @if ($isOpen && $r->within_limit === false && ! $r->deviation)
                <details class="pb-details"><summary>Out of limits: open a deviation</summary>
                    <form method="POST" action="{{ route('production.mmp.deviation', [$batch, $r->id]) }}" class="pb-form">@csrf
                        <textarea name="description" rows="2" required></textarea>@include('partials.rec.sign', ['label' => 'Reported by'])
                        <button class="pb-btn pb-btn-ghost" type="submit">Open deviation</button></form></details>
            @endif
            @if ($isOpen)
                @include('partials.rec.correct', ['record' => $r, 'type' => 'batch_mmp_step', 'fields' => ['actual_value' => 'Result', 'observation' => 'Observation']])
            @endif
        @elseif ($isOpen && $ms->id === $nextId)
            <form method="POST" action="{{ route('production.mmp.record', [$batch, $ms]) }}" class="pb-form">@csrf
                <div class="pb-grid">
                    @if ($ms->hasLimits() || $ms->parameter)<div class="f-field"><label>{{ $ms->parameter ?: 'Result' }} {{ $ms->unit ? '('.$ms->unit.')' : '' }}</label><input class="pb-input" type="number" step="0.0001" name="actual_value" required></div>@endif
                    <div class="f-field"><label>Observation</label><input class="pb-input" name="observation"></div>
                    <div class="f-field"><label>Done at (leave blank for now)</label><input class="pb-input" type="datetime-local" name="performed_at"></div>
                    <div class="f-field"><label>If entered late: why</label><input class="pb-input" name="late_entry_reason"></div>
                </div>
                <div class="f-field"><label>Who did it</label><div class="pb-row">
                    @foreach ($crew as $p)<label class="pb-check-inline"><input type="checkbox" name="staff[]" value="{{ $p->id }}"> {{ $p->full_name }}</label>@endforeach</div></div>
                @include('partials.rec.sign', ['label' => 'Recorded by'])
                <div><button class="pb-btn pb-btn-ghost" type="submit">Record step {{ $ms->step_no }}</button></div>
            </form>
        @endif
    </div>
@empty
    <p class="pb-muted">This recipe has no manufacturing steps for this product family.</p>
@endforelse
