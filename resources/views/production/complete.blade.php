{{-- Complete-step form. $n step no; extra fields go in $slot via @include + $fields html --}}
@php
    $sigDefs = $stepSignatures[$n] ?? [];
    $sup = $signers->filter->isSupervisorOrAdmin();
    $staffList = $n > 4 ? $crew : $people;
@endphp
<form method="POST" action="{{ route('production.complete', [$batch, $n]) }}" class="pb-form">
    @csrf
    @if (! empty($extra)){!! $extra !!}@endif
    @if (in_array($n, \App\Services\ProductionWorkflow::STAFF_STEPS, true))
        <div class="f-field"><label>Who did this step (tick everyone, by name)</label>
            <div class="pb-row">
            @forelse ($staffList as $p)
                <label class="pb-check-inline"><input type="checkbox" name="staff[]" value="{{ $p->id }}"> {{ $p->full_name }}</label>
            @empty
                <span class="pb-muted">{{ $n > 4 ? 'Add the crew in step 4 first.' : 'Add people under Setup → People.' }}</span>
            @endforelse
            </div></div>
    @endif
    <div class="f-field"><label>Notes (optional)</label><input class="pb-input" name="data[notes]"></div>
    <div class="pb-grid">
        @foreach ($sigDefs as [$meaning, $label, $who])
            @include('partials.rec.sign', ['name' => "signers[$meaning]", 'label' => $label, 'only' => $who === 'supervisor' ? $sup : $signers])
        @endforeach
    </div>
    <div><button class="btn-save" type="submit" @disabled(! empty($problems))>Complete step {{ $n }}</button>
        @if (! empty($problems))<span class="pb-muted">Clear the items above first.</span>@endif</div>
</form>
