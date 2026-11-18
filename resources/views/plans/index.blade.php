@extends('layouts.app')
@section('title', 'Recipes & plans')
@section('page_title', 'Recipes & quality plans')
@php use App\Support\Fmt; $canEdit = auth()->user()->isSupervisorOrAdmin(); @endphp
@section('content')
<section class="card">
    <div class="card-head"><h3>Product Quality Plans (PQP)</h3><span class="pb-muted">One per product family. QC approves it before recipes can be approved.</span></div>
    <div class="table-wrap"><table class="pb-table">
        <thead><tr><th>ID</th><th>Family</th><th>Title</th><th>Version</th><th>Status</th></tr></thead>
        <tbody>@forelse ($pqps as $p)
            <tr><td><a href="{{ route('plans.pqp', $p) }}">{{ $p->public_id }}</a></td><td>{{ \App\Models\Product::FAMILIES[$p->product_type] ?? $p->product_type }}</td>
                <td>{{ $p->title }}</td><td>v{{ $p->version }}</td><td><span class="pill {{ Fmt::pill($p->status) }}">{{ ucfirst($p->status) }}</span></td></tr>
        @empty<tr><td colspan="5" class="pb-muted">No quality plans yet.</td></tr>@endforelse</tbody></table></div>
    @if ($canEdit)
    <form method="POST" action="{{ route('plans.pqp.store') }}" class="pb-inline-form">@csrf
        <div class="f-field"><label>Family</label><select name="product_type" class="pb-select" required>@foreach (\App\Models\Product::FAMILIES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
        <div class="f-field" style="flex:1"><label>Title</label><input class="pb-input" name="title" placeholder="e.g. Infused preroll quality plan" required></div>
        <button class="pb-btn pb-btn-ghost" type="submit">New PQP draft</button></form>
    @endif
</section>

<section class="card" id="mmp">
    <div class="card-head"><h3>Recipes (Master Manufacturing Protocol, MMP)</h3><span class="pb-muted">A batch can only start from the current QC-approved recipe.</span></div>
    <div class="table-wrap"><table class="pb-table">
        <thead><tr><th>ID</th><th>Product</th><th>Brand</th><th>Version</th><th>Status</th><th>Approved</th></tr></thead>
        <tbody>@forelse ($mmps as $m)
            <tr><td><a href="{{ route('plans.mmp', $m) }}">{{ $m->public_id }}</a></td><td>{{ $m->product?->name }}</td><td>{{ $m->product?->brand?->name }}</td>
                <td>v{{ $m->version }}</td><td><span class="pill {{ Fmt::pill($m->status) }}">{{ ucfirst($m->status) }}</span></td><td>{{ Fmt::date($m->approved_at) }}</td></tr>
        @empty<tr><td colspan="6" class="pb-muted">No recipes yet.</td></tr>@endforelse</tbody></table></div>
    @if ($canEdit)
    @php
        $pre = request()->integer('product_id') ? $products->firstWhere('id', request()->integer('product_id')) : null;
        $prePqp = $pre ? \App\Models\Pqp::where('product_type', $pre->family)->where('status', 'approved')->first() : null;
    @endphp
    @if ($pre)
        <div class="pb-alert" id="newMmpFor" style="margin-top:12px"><b>New MMP for {{ $pre->name }}.</b> Click "New recipe draft", fill in the ingredients, then approve it at the bottom of the recipe page.
            @if (! $pre->family)<br><b>First set the product family</b> in <a href="{{ route('setup.edit', ['products', $pre->id]) }}">Setup → Products</a>.
            @elseif (! $prePqp)<br><b>There is no approved quality plan (PQP) for {{ \App\Models\Product::FAMILIES[$pre->family] ?? $pre->family }} yet.</b> Make one above ("New PQP draft", family {{ \App\Models\Product::FAMILIES[$pre->family] ?? $pre->family }}) and approve it; a recipe can only be approved with an approved PQP.
            @endif</div>
    @endif
    <form method="POST" action="{{ route('plans.mmp.store') }}" class="pb-inline-form" id="newMmp">@csrf
        <div class="f-field" style="flex:1;min-width:260px"><label>Product</label><select name="product_id" class="pb-select" required><option value="">—</option>
            @foreach ($products as $p)<option value="{{ $p->id }}" @selected($pre && $pre->id === $p->id)>{{ $p->name }}</option>@endforeach</select></div>
        <div class="f-field"><label>Recipe written for (units)</label><input class="pb-input" type="number" min="1" name="basis_units" value="100" required></div>
        <button class="pb-btn pb-btn-ghost" type="submit">New recipe draft</button></form>
    @endif
</section>
@endsection
