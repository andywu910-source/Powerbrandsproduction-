@extends('layouts.app')
@section('title', 'Receive a delivery')
@section('page_title', 'Receive a delivery')
@section('parent_title', 'Receiving')
@section('parent_url', route('receiving.index'))
@section('content')
<section class="card pb-form-card">
    <div class="card-head"><h3>New received lot</h3><span class="pb-muted">It goes into quarantine until QC releases it.</span></div>
    <form method="POST" action="{{ route('receiving.store') }}" class="pb-form">
        @csrf
        <div class="pb-grid">
            <div class="f-field"><label>Material</label><select name="ingredient_id" class="pb-select" required><option value="">—</option>
                @foreach ($ingredients as $g)<option value="{{ $g->id }}" @selected(old('ingredient_id') == $g->id)>{{ $g->name }}</option>@endforeach</select></div>
            <div class="f-field"><label>Supplier</label><select name="supplier_id" class="pb-select" required><option value="">—</option>
                @foreach ($suppliers as $s)<option value="{{ $s->id }}" @selected(old('supplier_id') == $s->id)>{{ $s->name }}{{ $s->approved ? '' : ' (not approved)' }}</option>@endforeach</select></div>
            <div class="f-field"><label>Quantity received</label><input class="pb-input" type="number" step="0.0001" min="0" name="qty" value="{{ old('qty') }}" required></div>
            <div class="f-field"><label>Unit</label><select name="unit" class="pb-select" required>@foreach ($units as $k => $l)<option value="{{ $k }}" @selected(old('unit') === $k)>{{ $l }}</option>@endforeach</select></div>
            <div class="f-field"><label>Supplier lot #</label><input class="pb-input" name="supplier_lot" value="{{ old('supplier_lot') }}"></div>
            <div class="f-field"><label>Grade</label><input class="pb-input" name="grade" value="{{ old('grade') }}"></div>
            <div class="f-field"><label>Metrc manifest #</label><input class="pb-input" name="metrc_manifest_no" value="{{ old('metrc_manifest_no') }}"></div>
            <div class="f-field"><label for="rc_uid">Metrc package UID</label><div class="pb-scan-wrap"><input id="rc_uid" class="pb-input" name="metrc_package_uid" value="{{ old('metrc_package_uid') }}" data-scan="metrc" placeholder="Type or scan">@include('partials.scan', ['for' => 'rc_uid', 'label' => 'Metrc package UID'])</div></div>
            <div class="f-field"><label>Expiration date</label><input class="pb-input" type="date" name="expiration_date" value="{{ old('expiration_date') }}"></div>
            <div class="f-field"><label>Received at (blank = now)</label><input class="pb-input" type="datetime-local" name="received_at" value="{{ old('received_at') }}"></div>
        </div>
        <label class="pb-check-inline"><input type="checkbox" name="condition_ok" value="1"> Containers in good condition</label>
        <label class="pb-check-inline"><input type="checkbox" name="seals_ok" value="1"> Seals intact</label>
        <div class="f-field"><label>Condition notes</label><textarea name="condition_notes" rows="2">{{ old('condition_notes') }}</textarea></div>
        @include('partials.rec.sign', ['label' => 'Received by'])
        <div><button class="btn-save" type="submit">Save &amp; put in quarantine</button></div>
    </form>
</section>
@endsection
