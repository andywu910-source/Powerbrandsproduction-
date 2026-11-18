{{-- What is still blocking: $problems (array) --}}
@if (! empty($problems))
<div class="pb-alert pb-alert-warn pb-problems" role="status">
    <b>Before this can be completed:</b>
    <ul>@foreach ($problems as $p)<li>{{ $p }}</li>@endforeach</ul>
</div>
@endif
