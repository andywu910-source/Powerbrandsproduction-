{{-- One entry-form input. $f = field, $lists = picker suggestions, $signers --}}
@php $name = 'f['.$f['key'].']'; $id = 'lgf_'.$f['key']; $old = old('f.'.$f['key']); $req = ! empty($f['req']);
    // Free-text fields that hold Metrc tags / UIDs also get the scan control (each scan goes on its own line).
    $tagish = in_array($f['type'], ['textarea', 'text'], true) && preg_match('/\b(tags?|uids?|barcodes?)\b/i', $f['label']); @endphp
@php [$reqIfLabel, $reqIfBool] = ! empty($f['req_if']) && isset($log) ? \App\Support\LogHub::reqIfTarget($log, $f) : [null, false]; @endphp
<div class="f-field lg-f lg-f-{{ $f['type'] }}" @if ($reqIfBool) data-show-if="lgf_{{ $f['req_if'][0] }}" @endif>
    @if ($f['type'] === 'bool')
        <label class="pb-check"><input type="checkbox" id="{{ $id }}" name="{{ $name }}" value="1" @checked($old)> <span>{{ $f['label'] }}</span>@if (! empty($f['new']))<span class="lg-new">new</span>@endif</label>
    @else
        <label for="{{ $id }}">{{ $f['label'] }}@if ($req) <span class="lg-req" aria-hidden="true">*</span>@endif @if (! empty($f['new']))<span class="lg-new">new</span>@endif @if (! empty($f['renamed_from']))<span class="pb-muted lg-renamed">(was “{{ $f['renamed_from'] }}”)</span>@endif @if (! empty($f['req_if']))<span class="pb-muted lg-req-if">{{ $reqIfBool ? '(required when ticked)' : '(required for '.strtolower($f['req_if'][1]).')' }}</span>@endif</label>
        @switch($f['type'])
            @case('date') <input id="{{ $id }}" type="date" name="{{ $name }}" class="pb-input" value="{{ $old }}" @required($req)> @break
            @case('time') <input id="{{ $id }}" type="time" name="{{ $name }}" class="pb-input" value="{{ $old }}" @required($req)> @break
            @case('datetime') <input id="{{ $id }}" type="datetime-local" name="{{ $name }}" class="pb-input" value="{{ $old }}" @required($req)> @break
            @case('number') <input id="{{ $id }}" type="number" step="any" name="{{ $name }}" class="pb-input" value="{{ $old }}" @required($req)> @break
            @case('textarea')
                @if ($tagish)<div class="pb-scan-wrap"><textarea id="{{ $id }}" name="{{ $name }}" class="pb-input" rows="2" maxlength="5000" data-scan="append" @required($req)>{{ $old }}</textarea>@include('partials.scan', ['for' => $id, 'label' => $f['label']])</div>
                @else<textarea id="{{ $id }}" name="{{ $name }}" class="pb-input" rows="2" maxlength="5000" @required($req)>{{ $old }}</textarea>@endif @break
            @case('url') <input id="{{ $id }}" type="url" name="{{ $name }}" class="pb-input" value="{{ $old }}" placeholder="https://" @required($req)> @break
            @case('yn')
                <select id="{{ $id }}" name="{{ $name }}" class="pb-select" @required($req)><option value="">—</option><option value="Y" @selected($old === 'Y')>Y</option><option value="N" @selected($old === 'N')>N</option></select> @break
            @case('select')
                <select id="{{ $id }}" name="{{ $name }}" class="pb-select" @required($req)><option value="">—</option>@foreach ($f['options'] ?? [] as $o)<option @selected($old === $o)>{{ $o }}</option>@endforeach</select> @break
            @case('metrc')
                <div class="pb-scan-wrap"><input id="{{ $id }}" type="text" name="{{ $name }}" class="pb-input lg-metrc" value="{{ $old }}" minlength="24" maxlength="24" pattern="[A-Za-z0-9]{24}"
                       placeholder="24-character Metrc UID: type or scan" autocomplete="off" spellcheck="false" data-scan="metrc" @required($req) title="Exactly 24 letters and numbers">@include('partials.scan', ['for' => $id, 'label' => $f['label']])</div>
                <small class="pb-muted lg-count" data-for="{{ $id }}">0 / 24</small> @break
            @case('employee') <input id="{{ $id }}" type="text" name="{{ $name }}" class="pb-input" value="{{ $old }}" list="lgEmployees" autocomplete="off" @required($req)> @break
            @case('room') <input id="{{ $id }}" type="text" name="{{ $name }}" class="pb-input" value="{{ $old }}" list="lgRooms" autocomplete="off" @required($req)> @break
            @case('equipment') <input id="{{ $id }}" type="text" name="{{ $name }}" class="pb-input" value="{{ $old }}" list="lgEquipment" autocomplete="off" @required($req)> @break
            @case('batch') <input id="{{ $id }}" type="text" name="{{ $name }}" class="pb-input" value="{{ $old }}" list="lgBatches" autocomplete="off" @required($req)> @break
            @case('signature')
                {{-- Staff logins sign in their own name only (Andy, Nov 19): they are offered just themselves. --}}
                @php $sigList = auth()->user()?->signsAsSelf() ? collect([auth()->user()]) : $signers; @endphp
                <select id="{{ $id }}" name="{{ $name }}" class="pb-select" @required($req)><option value="">— pick your name to sign —</option>@foreach ($sigList as $s)<option value="{{ $s->id }}" @selected((string) $old === (string) $s->id)>{{ $s->name }}</option>@endforeach</select> @break
            @case('file') <input id="{{ $id }}" type="file" name="{{ $name }}" class="pb-input" accept="image/*,.pdf" @required($req)> @break
            @default
                @if ($tagish)<div class="pb-scan-wrap"><input id="{{ $id }}" type="text" name="{{ $name }}" class="pb-input" value="{{ $old }}" maxlength="255" data-scan="barcode" @required($req)>@include('partials.scan', ['for' => $id, 'label' => $f['label']])</div>
                @else<input id="{{ $id }}" type="text" name="{{ $name }}" class="pb-input" value="{{ $old }}" maxlength="255" @required($req)>@endif
        @endswitch
    @endif
</div>
