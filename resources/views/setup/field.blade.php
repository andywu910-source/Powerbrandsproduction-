{{-- One master-data field. $k, $f, $item, $options, $disabled --}}
@php $v = old($k, $item->{$k} ?? null); $id = 'f_'.$k; @endphp
@if ($f['type'] === 'bool')
    <label class="pb-check-inline" style="margin-top:26px"><input type="checkbox" name="{{ $k }}" value="1" @checked($v ?? ($k === 'active' && ! $item->exists)) @disabled($disabled)> {{ $f['label'] }}</label>
@else
<div class="f-field" @if ($f['type'] === 'textarea') style="grid-column:1/-1" @endif>
    <label for="{{ $id }}">{{ $f['label'] }}{{ ! empty($f['required']) ? ' *' : '' }}</label>
    @switch($f['type'])
        @case('textarea')<textarea id="{{ $id }}" name="{{ $k }}" rows="3" class="pb-input" @disabled($disabled)>{{ $v }}</textarea>@break
        @case('select')
            <select id="{{ $id }}" name="{{ $k }}" class="pb-select" @disabled($disabled)><option value="">—</option>
                @foreach ($f['options'] as $ok => $ol)<option value="{{ $ok }}" @selected((string) $v === (string) $ok)>{{ $ol }}</option>@endforeach</select>@break
        @case('model')
            <select id="{{ $id }}" name="{{ $k }}" class="pb-select" @disabled($disabled)><option value="">—</option>
                @foreach ($options[$k] ?? [] as $ok => $ol)<option value="{{ $ok }}" @selected((string) $v === (string) $ok)>{{ $ol }}</option>@endforeach</select>@break
        @case('code')
            @php $len = $f['code'] === 'brand' ? \App\Support\CodeRules::brandLength() : \App\Support\CodeRules::productLength(); @endphp
            <input id="{{ $id }}" name="{{ $k }}" class="pb-input pb-bn" value="{{ $v }}" maxlength="{{ $len }}" style="text-transform:uppercase" @disabled($disabled)>
            <small class="pb-muted">Exactly {{ $len }} characters: A–Z and 2–9 (no O, 0, I or 1).</small>@break
        @case('date')<input id="{{ $id }}" type="date" name="{{ $k }}" class="pb-input" value="{{ $v ? \Illuminate\Support\Carbon::parse($v)->format('Y-m-d') : '' }}" @disabled($disabled)>@break
        @case('int')<input id="{{ $id }}" type="number" step="1" min="1" name="{{ $k }}" class="pb-input" value="{{ $v }}" @disabled($disabled)>@break
        @case('number')<input id="{{ $id }}" type="number" step="any" name="{{ $k }}" class="pb-input" value="{{ $v }}" @disabled($disabled)>@break
        @default<input id="{{ $id }}" name="{{ $k }}" class="pb-input" value="{{ $v }}" @disabled($disabled)>
    @endswitch
</div>
@endif
