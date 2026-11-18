@extends('layouts.app')
@section('title', 'Settings')
@section('page_title', 'Settings')
@section('parent_title', 'Setup')
@section('parent_url', route('setup.home'))
@section('content')
<section class="card pb-form-card">
    <form method="POST" action="{{ route('settings.update') }}" class="pb-form">@csrf @method('PUT')
    @foreach ($defs as $k => $d)
        @php $v = old($k, $values[$k]); @endphp
        <div class="f-field">
            <label for="s_{{ $k }}">{{ $d['label'] }}</label>
            @switch($d['type'])
                @case('select')
                    <select id="s_{{ $k }}" name="{{ $k }}" class="pb-select">@foreach ($d['options'] as $ok => $ol)<option value="{{ $ok }}" @selected($v === $ok)>{{ $ol }}</option>@endforeach</select>@break
                @case('users')
                    <div class="pb-row">@foreach ($users as $usr)<label class="pb-check-inline"><input type="checkbox" name="{{ $k }}[]" value="{{ $usr->id }}" @checked(in_array($usr->id, (array) $v))> {{ $usr->name }}</label>@endforeach</div>@break
                @case('user')
                    <select id="s_{{ $k }}" name="{{ $k }}" class="pb-select"><option value="">—</option>@foreach ($users as $usr)<option value="{{ $usr->id }}" @selected((int) $v === $usr->id)>{{ $usr->name }}</option>@endforeach</select>@break
                @case('bool')
                    <label class="pb-check-inline"><input type="checkbox" name="{{ $k }}" value="1" @checked($v)> Yes</label>@break
                @case('map')
                    <div class="pb-row">@foreach ((array) $v as $mk => $mv)<label class="pb-check-inline">{{ \App\Models\Product::FAMILIES[$mk] ?? $mk }} <input class="pb-input pb-sm" name="{{ $k }}[{{ $mk }}]" value="{{ $mv }}" style="width:70px"></label>@endforeach</div>@break
                @case('list')
                    <input id="s_{{ $k }}" class="pb-input" name="{{ $k }}" value="{{ implode(', ', (array) $v) }}">@break
                @case('pattern')
                    <input id="s_{{ $k }}" class="pb-input pb-bn" name="{{ $k }}" value="{{ $v }}" list="patterns">
                    <datalist id="patterns">@foreach ($d['options'] as $ok => $ol)<option value="{{ $ok }}">{{ $ol }}</option>@endforeach</datalist>
                    <small>Next number with this pattern: <b class="pb-bn" id="patPreview">{{ $preview }}</b></small>
                    <ul class="pb-muted" style="margin:6px 0 0 18px">@foreach ($d['options'] as $ok => $ol)<li><code>{{ $ok }}</code> — {{ $ol }}</li>@endforeach</ul>@break
                @case('int') @case('decimal')
                    <input id="s_{{ $k }}" class="pb-input" type="number" step="{{ $d['type'] === 'int' ? 1 : 'any' }}" name="{{ $k }}" value="{{ $v }}">@break
                @default
                    <input id="s_{{ $k }}" class="pb-input" name="{{ $k }}" value="{{ is_array($v) ? '' : $v }}">
            @endswitch
            @if (! empty($d['help']))<small class="pb-muted">{{ $d['help'] }}</small>@endif
        </div>
    @endforeach
    <div><button class="btn-save" type="submit">Save settings</button></div>
    </form>
</section>
@push('scripts')
<script>
(function () {
    var inp = document.getElementById('s_batch_number_pattern'), out = document.getElementById('patPreview'), t;
    if (!inp) return;
    inp.addEventListener('input', function () {
        clearTimeout(t);
        t = setTimeout(function () {
            fetch('{{ route('settings.preview') }}', {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json'}, body: JSON.stringify({pattern: inp.value})})
                .then(function (r) { return r.json(); }).then(function (j) { out.textContent = j.ok ? j.preview : j.errors.join(' '); });
        }, 300);
    });
})();
</script>
@endpush
@endsection
