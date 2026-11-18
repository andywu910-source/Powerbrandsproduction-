<div class="sch-tabs" role="navigation" aria-label="Schedule">
    <a href="{{ route('schedule.index', isset($monday) ? ['week' => $monday->toDateString()] : []) }}" class="{{ request()->routeIs('schedule.index', 'schedule.cell', 'schedule.copy') ? 'on' : '' }}">Week</a>
    <a href="{{ route('schedule.today') }}" class="{{ request()->routeIs('schedule.today') ? 'on' : '' }}">Today</a>
    <a href="{{ route('schedule.people') }}" class="{{ request()->routeIs('schedule.people') ? 'on' : '' }}">People &amp; setup</a>
</div>
