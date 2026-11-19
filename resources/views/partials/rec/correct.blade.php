{{-- Correct a field: $record, $type (morph alias), $fields [field => label] --}}
@unless (auth()->user()->isViewer())
<details class="pb-details">
    <summary>Correct a mistake</summary>
    <form method="POST" action="{{ route('records.correct', [$type, $record->id]) }}" class="pb-form pb-grid">
        @csrf
        <div class="f-field"><label>Field</label>
            <select name="field" class="pb-select" required>@foreach ($fields as $f => $l)<option value="{{ $f }}">{{ $l }}</option>@endforeach</select></div>
        @php $cid = 'corr_'.$type.'_'.$record->id; $scanCorr = collect(array_keys($fields))->contains(fn ($k) => preg_match('/uid|barcode|tag/i', $k)); @endphp
        <div class="f-field"><label for="{{ $cid }}">Correct value</label>
            @if ($scanCorr)<div class="pb-scan-wrap"><input id="{{ $cid }}" name="new_value" class="pb-input" data-scan="fill">@include('partials.scan', ['for' => $cid, 'label' => 'the corrected value'])</div>
            @else<input id="{{ $cid }}" name="new_value" class="pb-input">@endif</div>
        <div class="f-field"><label>Reason for the correction</label><input name="reason" class="pb-input" required></div>
        <div class="f-field"><label>Your initials</label><input name="initials" class="pb-input" maxlength="10" required></div>
        <div class="form-actions"><button class="btn-save" type="submit">Save correction</button>
            <span class="pb-muted">The old value stays on the record, struck through.</span></div>
    </form>
</details>
@endunless
