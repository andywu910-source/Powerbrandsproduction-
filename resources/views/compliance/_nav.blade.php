<div class="cmp-note" id="cmpNote">{{ \App\Support\Compliance::NOTE }}</div>
<div class="cmp-tabs" role="navigation" aria-label="Compliance">
    <a href="{{ route('compliance.period', 'weekly') }}" class="{{ request()->routeIs('compliance.period') && request()->route('freq') === 'weekly' ? 'on' : '' }}">Weekly</a>
    <a href="{{ route('compliance.period', 'monthly') }}" class="{{ request()->routeIs('compliance.period') && request()->route('freq') === 'monthly' ? 'on' : '' }}">Monthly</a>
    <a href="{{ route('compliance.period', 'quarterly') }}" class="{{ request()->routeIs('compliance.period') && request()->route('freq') === 'quarterly' ? 'on' : '' }}">Quarterly</a>
    <a href="{{ route('compliance.period', 'annual') }}" class="{{ request()->routeIs('compliance.period') && request()->route('freq') === 'annual' ? 'on' : '' }}">Annual</a>
    <a href="{{ route('compliance.history') }}" class="{{ request()->routeIs('compliance.history') ? 'on' : '' }}">History</a>
    <a href="{{ route('compliance.setup') }}" class="{{ request()->routeIs('compliance.setup') ? 'on' : '' }}">Items</a>
</div>
