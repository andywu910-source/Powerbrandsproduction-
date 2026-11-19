{{-- Signature picker. Blank by default: the person signing picks their own name.
     Params: $signers (collection), $name (input name), $label, optional $only (collection subset), $required --}}
@php
    $name = $name ?? 'signer_id';
    $list = $only ?? $signers;
    $mode = \App\Models\Setting::get('signing_auth_mode', 'name');
    // Staff accounts sign as themselves only: their own name is pre-picked and is the only choice.
    $me = auth()->user();
    $self = $me && $me->signsAsSelf();
    if ($self) {
        $mine = $list->filter(fn ($u) => $u->id === $me->id);
        $list = $mine->isNotEmpty() ? $mine : $list;
    }
    $fid = 'sg_'.preg_replace('/\W+/', '_', $name).'_'.\Illuminate\Support\Str::random(4);
@endphp
<div class="f-field pb-sign">
    <label for="{{ $fid }}">{{ $label ?? 'Signed by' }}</label>
    <select id="{{ $fid }}" name="{{ $name }}" class="pb-select" {{ ($required ?? true) ? 'required' : '' }}>
        @unless ($self && $list->count() === 1 && $list->first()->id === $me->id)<option value="">— pick your name —</option>@endunless
        @foreach ($list as $s)
            <option value="{{ $s->id }}" @selected($self && $s->id === $me->id)>{{ $s->name }}</option>
        @endforeach
    </select>
    @if ($mode !== 'name')
        <input type="password" name="signing_credential" class="pb-input" autocomplete="off" placeholder="Your signing {{ $mode === 'pin' ? 'PIN' : 'password' }}" required>
    @endif
    <small class="pb-muted">{{ $self ? 'You sign in your own name.' : '' }} Signing records your name, the date/time and this computer's address, and cannot be edited afterwards.</small>
</div>
