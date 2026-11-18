@extends('layouts.app')
@section('title', 'Compliance · History')
@section('page_title', 'Compliance Checklists')
@section('content')
@include('compliance._nav')
@foreach (\App\Models\ComplianceItem::FREQUENCIES as $f => $label)
<section class="card" id="hist-{{ $f }}">
    <div class="card-head"><h3>{{ $label }}</h3></div>
    <table class="cmp-hist"><thead><tr><th>Period</th><th>Progress</th></tr></thead><tbody>
    @foreach ($rows[$f] ?? [] as $row)
        <tr><td><a href="{{ route('compliance.period', [$f, 'period' => $row['start']->toDateString()]) }}">{{ $row['label'] }}</a>@if ($row['current']) <b>(current)</b>@endif</td>
            <td>{{ $row['progress']['done'] }} of {{ $row['progress']['total'] }} done</td></tr>
    @endforeach
    </tbody></table>
</section>
@endforeach
@endsection
