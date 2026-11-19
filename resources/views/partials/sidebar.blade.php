@php
    use App\Support\Modules;
    $u = auth()->user();
    $tab = function ($route, $label, $icon, $active, $module = null, $soon = null) {
        $on = $module === null || Modules::enabled($module);
        $href = $on ? route($route) : route('soon', $soon ?? $module);
        $cls = (request()->routeIs($active) || (! $on && request()->is('soon/'.($soon ?? $module)))) ? 'active' : '';
        return '<a href="'.e($href).'" data-tip="'.e($label).'" class="'.$cls.'">'.$icon.'<span class="nav-text">'.e($label).'</span>'.($on ? '' : '<span class="nav-soon">Soon</span>').'</a>';
    };
    $I = [
        'dash' => '<svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>',
        'prod' => '<svg viewBox="0 0 24 24"><path d="M2 20h20M4 20V10l5 3V10l5 3V6l6 4v10"/></svg>',
        'recv' => '<svg viewBox="0 0 24 24"><path d="M21 8l-9-5-9 5v8l9 5 9-5V8z"/><path d="M3 8l9 5 9-5M12 13v8"/></svg>',
        'check' => '<svg viewBox="0 0 24 24"><path d="M9 4H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-2"/><rect x="9" y="2" width="6" height="4" rx="1"/><path d="M9 13l2 2 4-4"/></svg>',
        'log' => '<svg viewBox="0 0 24 24"><path d="M4 4h12l4 4v12H4z"/><path d="M8 12h8M8 16h8M8 8h4"/></svg>',
        'label' => '<svg viewBox="0 0 24 24"><path d="M20.6 13.4l-7.2 7.2a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="7.5" r="1.5"/></svg>',
        'logs' => '<svg viewBox="0 0 24 24"><path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6l8-3z"/></svg>',
        'dev' => '<svg viewBox="0 0 24 24"><path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>',
        'setup' => '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>',
        'plans' => '<svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M9 15l2 2 4-4"/></svg>',
        'users' => '<svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M21 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'cal' => '<svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4M7 13h3M14 13h3M7 17h3"/></svg>',
        'cog' => '<svg viewBox="0 0 24 24"><path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/></svg>',
        'book' => '<svg viewBox="0 0 24 24"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>',
    ];
@endphp
<aside class="sidebar" id="sidebar">
    <div class="side-logo">
        <img src="{{ asset('img/logo.svg') }}" alt="PowerBrands Productions logo">
        <div>
            <span class="side-title">POWERBRANDS</span>
            <span class="side-tag">Productions</span>
        </div>
    </div>

    <nav class="side-nav">
        <div class="nav-sec" data-group="main">
            <button type="button" class="nav-sec-btn" aria-expanded="true"><span>Main</span><svg viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg></button>
            <div class="nav-sub">{!! $tab('dashboard', 'Dashboard', $I['dash'], 'dashboard') !!}</div>
        </div>

        <div class="nav-sec" data-group="work">
            <button type="button" class="nav-sec-btn" aria-expanded="true"><span>Work</span><svg viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg></button>
            <div class="nav-sub">
                {!! $tab('production.index', 'Production', $I['prod'], 'production.*', 'production') !!}
                {!! $tab('receiving.index', 'Receiving', $I['recv'], 'receiving.*', 'receiving') !!}
                @php($hubOn = \App\Support\LogHub::hubVisible(request()))
                @if ($hubOn){!! $tab('logs.index', 'Compliance', $I['logs'], 'logs.*', 'logs') !!}@endif
                {!! $tab('checklists.index', 'Checklists', $I['check'], 'checklists.*', 'checklists') !!}
                {!! $tab('batch-log.index', 'Batch Log', $I['log'], 'batch-log.*', 'batchlog') !!}
                {!! $tab('labels.index', 'Labels', $I['label'], 'labels.*', 'labels') !!}
                @if (Modules::enabled('schedule')){!! $tab('schedule.index', 'Schedule', $I['cal'], 'schedule.*', 'schedule') !!}@endif
                @if (Modules::enabled('compliance')){!! $tab('compliance.home', $hubOn ? 'Compliance checklists' : 'Compliance', $I['check'], 'compliance.*', 'compliance') !!}@endif
                @unless ($hubOn)<a href="{{ route('soon', 'logs') }}" data-tip="Logs" class="{{ request()->is('soon/logs') ? 'active' : '' }}">{!! $I['logs'] !!}<span class="nav-text">Logs</span><span class="nav-soon">Soon</span></a>@endunless
                {!! $tab('deviations.index', 'Deviations', $I['dev'], 'deviations.*') !!}
            </div>
        </div>

        <div class="nav-sec" data-group="setup">
            <button type="button" class="nav-sec-btn" aria-expanded="true"><span>Setup</span><svg viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg></button>
            <div class="nav-sub">
                {!! $tab('setup.home', 'Products & people', $I['setup'], ['setup.*', 'process.*']) !!}
                {!! $tab('plans.index', 'Recipes & plans', $I['plans'], 'plans.*') !!}
                @if ($u->isAdmin())
                    {!! $tab('settings.edit', 'Settings', $I['cog'], 'settings.*') !!}
                    {!! $tab('users.index', 'Users', $I['users'], 'users.*') !!}
                    @if (Modules::enabled('bookkeeping')){!! $tab('bookkeeping.index', 'Bookkeeping', $I['book'], 'bookkeeping.*', 'bookkeeping') !!}@endif
                @endif
            </div>
        </div>
    </nav>

    <div class="side-foot">
        <svg viewBox="0 0 24 24"><path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6l8-3z"/></svg>
        <span class="foot-text">{{ $u->isViewer() ? 'Read-only account.' : 'Internal use only.' }}</span>
    </div>
</aside>
<div class="backdrop" id="backdrop"></div>
