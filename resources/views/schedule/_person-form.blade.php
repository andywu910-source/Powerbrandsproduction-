@php use App\Support\Schedule; use App\Models\ScheduleEmployee; $p = $p ?? null; @endphp
<form method="POST" action="{{ $p ? route('schedule.people.update', $p) : route('schedule.people.store') }}" class="pb-form sch-person-form">@csrf
    <div class="pb-grid-3">
        <div class="f-field"><label>Name</label><input class="pb-input" name="name" maxlength="80" required value="{{ $p?->name }}"></div>
        <div class="f-field"><label>Role</label><input class="pb-input" name="role" maxlength="80" value="{{ $p?->role }}" placeholder="e.g. Production, Billing / compliance"></div>
        <div class="f-field"><label>Default day (hours)</label><input class="pb-input" type="number" step="0.25" min="0" max="16" name="default_hours" required value="{{ $p ? Schedule::fmtHours($p->default_hours) : '8' }}"></div>
        @if ($showLabor)<div class="f-field"><label>Hourly rate ($)</label><input class="pb-input" type="number" step="0.01" min="0" name="hourly_rate" value="{{ $p?->hourly_rate }}"></div>@endif
        <div class="f-field"><label>Login (optional)</label><select class="pb-select" name="user_id"><option value="">—</option>@foreach ($users as $u)<option value="{{ $u->id }}" @selected($p?->user_id === $u->id)>{{ $u->name }}</option>@endforeach</select></div>
        <div class="f-field"><label>Order</label><input class="pb-input" type="number" min="0" name="sort_order" value="{{ $p?->sort_order ?? 0 }}"></div>
    </div>
    <fieldset class="sch-days"><legend>Recurring day off</legend>
        @foreach (ScheduleEmployee::WEEKDAYS as $n => $l)<label class="sch-opt"><input type="checkbox" name="recurring_off[]" value="{{ $n }}" @checked($p?->offOn($n))> <span>{{ $l }}</span></label>@endforeach
    </fieldset>
    <label class="sch-opt"><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1" @checked($p ? $p->active : true)> <span>Active (on new weeks)</span></label>
    <div><button class="btn-save">{{ $p ? 'Save' : 'Add person' }}</button></div>
</form>
