@php
    use App\Support\Fmt;
    $qcProblems = $isOpen ? app(\App\Services\ProductionWorkflow::class)->qcProblems($batch, null) : [];
    $defaultQc = (int) \App\Models\Setting::get('qc_default_reviewer_user_id');
@endphp
@if ($batch->coa_result)
    <div class="pb-kv"><div><span>Lab</span><b>{{ $batch->coa_lab }}</b></div><div><span>Sample ID</span><b>{{ $batch->coa_sample_id }}</b></div>
        <div><span>Potency</span><b>{{ $batch->coa_potency }}</b></div><div><span>COA result</span><b><span class="pill {{ Fmt::pill($batch->coa_result) }}">{{ strtoupper($batch->coa_result) }}</span></b></div>
        <div><span>Metrc package (output)</span><b>{{ $batch->output_metrc_uid ?: '—' }}</b></div>
        @if ($batch->qc_decision)<div><span>QC decision</span><b>{{ ucfirst($batch->qc_decision) }} by {{ $batch->qcReviewer?->name }}</b></div>@endif</div>
@endif
<h4>Batch COA</h4>
@include('partials.rec.attachments', ['files' => $batch->attachments->where('kind', 'coa'), 'upload' => $isOpen ? route('production.upload', $batch) : null, 'kinds' => ['coa' => 'Batch COA']])
@if ($isOpen)
    <form method="POST" action="{{ route('production.coa', $batch) }}" class="pb-form">@csrf
        <div class="pb-grid-3">
            <div class="f-field"><label>Lab</label><input class="pb-input" name="coa_lab" value="{{ old('coa_lab', $batch->coa_lab) }}" required></div>
            <div class="f-field"><label>Sample ID</label><input class="pb-input" name="coa_sample_id" value="{{ old('coa_sample_id', $batch->coa_sample_id) }}" required></div>
            <div class="f-field"><label>Potency on COA</label><input class="pb-input" type="number" step="0.001" name="coa_potency" value="{{ old('coa_potency', $batch->coa_potency) }}"></div>
            <div class="f-field"><label>Result</label><select name="coa_result" class="pb-select" required><option value="">—</option><option value="pass" @selected($batch->coa_result === 'pass')>Pass</option><option value="fail" @selected($batch->coa_result === 'fail')>Fail</option></select></div>
            <div class="f-field"><label for="s9_uid">Metrc package UID (output)</label><div class="pb-scan-wrap"><input id="s9_uid" class="pb-input" name="output_metrc_uid" value="{{ old('output_metrc_uid', $batch->output_metrc_uid) }}" data-scan="metrc" placeholder="Type or scan">@include('partials.scan', ['for' => 's9_uid', 'label' => 'output Metrc package UID'])</div></div>
        </div>
        <div><button class="pb-btn pb-btn-ghost" type="submit">Save COA details</button></div>
    </form>
    @if (config('pbp.qc_release_step'))
    @include('partials.rec.problems', ['problems' => $qcProblems])
    <form method="POST" action="{{ route('production.qc', $batch) }}" class="pb-form" style="margin-top:12px">@csrf
        <div class="f-field pb-sign"><label>QC reviewer</label>
            <select name="signer_id" class="pb-select" required><option value="">— pick your name —</option>
                @foreach ($qcReviewers as $q)<option value="{{ $q->id }}" @selected($q->id === $defaultQc)>{{ $q->name }}{{ $q->id === $defaultQc ? ' (default reviewer)' : '' }}</option>@endforeach</select>
            @if (\App\Models\Setting::get('signing_auth_mode', 'name') !== 'name')<input type="password" name="signing_credential" class="pb-input" autocomplete="off" placeholder="Your signing PIN / password" required>@endif
            <small class="pb-muted">The reviewer can't be anyone who performed or verified any step of this batch.</small></div>
        <div class="f-field"><label>Note</label><input class="pb-input" name="note"></div>
        <div class="pb-row">
            <button class="btn-save" type="submit" name="decision" value="released" @disabled($qcProblems)>Release batch</button>
            <button class="btn-save pb-danger" type="submit" name="decision" value="rejected">Reject batch</button>
        </div>
    </form>
    @else
    <p class="pb-muted">Record the COA here. When the batch is done, set its status to Finished in the <a href="{{ route('batch-log.index') }}">Batch Log</a>.</p>
    @endif
@endif
