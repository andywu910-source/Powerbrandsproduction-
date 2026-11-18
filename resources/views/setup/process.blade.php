@extends('layouts.app')
@section('title', 'Manufacturing stages')
@section('page_title', 'Manufacturing stages by family')
@section('parent_title', 'Setup')
@section('parent_url', route('setup.home'))
@section('content')
<section class="card"><p class="pb-muted">Step 6 of every batch follows these stages. A stage that is switched off is skipped for that whole family (never by the operator).
    Starter steps are copied into each new recipe; one per line: <code>Instruction | parameter | unit | min | max</code>.</p></section>
@foreach ($templates as $t)
<section class="card">
    <form method="POST" action="{{ route('process.update', $t) }}" class="pb-form">@csrf @method('PUT')
        <div class="card-head"><h3>{{ \App\Models\Product::FAMILIES[$t->family] ?? $t->family }}</h3></div>
        <div class="f-field"><label>Name</label><input class="pb-input" name="name" value="{{ $t->name }}"></div>
        <input type="hidden" name="active" value="1">
        @foreach ($t->stages->concat([new \App\Models\ProcessTemplateStage]) as $i => $st)
            <div class="pb-grid-3" style="align-items:start">
                <div class="f-field"><label>Stage code</label><input class="pb-input" name="stages[{{ $i }}][code]" value="{{ $st->code }}" placeholder="{{ $st->exists ? '' : 'new stage…' }}"></div>
                <div class="f-field"><label>Stage name</label><input class="pb-input" name="stages[{{ $i }}][name]" value="{{ $st->name }}"></div>
                <label class="pb-check-inline" style="margin-top:26px"><input type="checkbox" name="stages[{{ $i }}][active]" value="1" @checked($st->active ?? ! $st->exists)> In use</label>
                <div class="f-field" style="grid-column:1/-1"><label>Starter steps</label>
                    <textarea name="stages[{{ $i }}][default_steps]" rows="3" class="pb-input">{{ collect((array) $st->default_steps)->map(fn ($s) => rtrim(implode(' | ', [$s['instruction'] ?? '', $s['parameter'] ?? '', $s['unit'] ?? '', $s['limit_min'] ?? '', $s['limit_max'] ?? '']), ' |'))->implode("\n") }}</textarea></div>
            </div>
        @endforeach
        <div><button class="btn-save" type="submit">Save {{ $t->name }}</button></div>
    </form>
</section>
@endforeach
@endsection
