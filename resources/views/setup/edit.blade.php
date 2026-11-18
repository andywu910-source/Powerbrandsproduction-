@extends('layouts.app')
@section('title', $title)
@section('page_title', method_exists($item, 'label') ? $item->label() : $title)
@section('parent_title', $title)
@section('parent_url', route('setup.index', $res))
@section('content')
<section class="card pb-form-card">
    <form method="POST" action="{{ route('setup.update', [$res, $item->id]) }}" class="pb-form">@csrf @method('PUT')
        <div class="pb-grid">@foreach ($fields as $k => $f)@include('setup.field', ['disabled' => ! $canEdit])@endforeach</div>
        @if ($canEdit)<div><button class="btn-save" type="submit">Save</button></div>@endif
    </form>
    @if ($aliases->isNotEmpty())
        <p class="pb-muted" style="margin-top:12px">Spellings in the Production Log: {{ $aliases->implode(' · ') }}</p>
    @endif
    @if ($res === 'products')
        <p style="margin-top:12px"><a class="pb-btn pb-btn-ghost" href="{{ route('plans.index') }}#mmp">Recipes for this product</a></p>
    @endif
</section>
@endsection
