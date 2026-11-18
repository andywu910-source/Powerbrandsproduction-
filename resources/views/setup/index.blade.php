@extends('layouts.app')
@section('title', $title)
@section('page_title', $title)
@section('parent_title', 'Setup')
@section('parent_url', route('setup.home'))
@section('content')
<section class="card">
    <div class="card-head"><h3>{{ $title }}</h3><span class="pb-spacer"></span>
        <form method="GET" class="pb-row"><input class="pb-input pb-sm" name="q" value="{{ request('q') }}" placeholder="Search">
            @if ($res === 'products')<label class="pb-check-inline"><input type="checkbox" name="review" value="1" @checked(request('review')) onchange="this.form.submit()"> Needs review only</label>@endif
            <button class="pb-btn pb-btn-ghost">Search</button></form></div>
    <div class="table-wrap"><table class="pb-table">
        <thead><tr>@foreach ($cols as $c)<th>{{ $fields[$c]['label'] ?? $c }}</th>@endforeach<th></th></tr></thead>
        <tbody>
        @forelse ($rows as $row)
            <tr>@foreach ($cols as $c)
                @php $f = $fields[$c]; $v = $row->{$c}; @endphp
                <td>@switch($f['type'])
                    @case('bool'){!! $v ? ($c === 'needs_review' ? '<span class="pill warn">Review</span>' : 'Yes') : '' !!}@break
                    @case('model'){{ $options[$c][$v] ?? '' }}@break
                    @case('select'){{ $f['options'][$v] ?? $v }}@break
                    @case('code')<span class="pb-bn">{{ $v }}</span>@if (! $v)<span class="pill bad">missing</span>@endif @break
                    @case('date'){{ \App\Support\Fmt::date($v) }}@break
                    @default{{ \Illuminate\Support\Str::limit((string) $v, 60) }}
                @endswitch</td>
            @endforeach
            <td><a class="pb-btn pb-btn-ghost" href="{{ route('setup.edit', [$res, $row->id]) }}">{{ $canEdit ? 'Edit' : 'View' }}</a></td></tr>
        @empty
            <tr><td colspan="{{ count($cols) + 1 }}" class="pb-muted">Nothing yet.</td></tr>
        @endforelse
        </tbody></table></div>
    {{ $rows->links() }}
</section>
@if ($canEdit)
<section class="card pb-form-card">
    <div class="card-head"><h3>Add</h3></div>
    <form method="POST" action="{{ route('setup.store', $res) }}" class="pb-form">@csrf
        <div class="pb-grid">@foreach ($fields as $k => $f)@include('setup.field', ['item' => $item, 'disabled' => false])@endforeach</div>
        <div><button class="btn-save" type="submit">Add</button></div></form>
</section>
@endif
@endsection
