@if (session('status'))
    <div class="pb-alert pb-alert-ok" role="status">{{ session('status') }}</div>
@endif
@if ($errors->any())
    <div class="pb-alert pb-alert-err" role="alert">
        @foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach
    </div>
@endif
