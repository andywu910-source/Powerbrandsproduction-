<header class="topbar">
    <button class="menu-btn" id="menuBtn" type="button" aria-label="Toggle sidebar (Ctrl+B)" title="Toggle sidebar (Ctrl+B)">
        <svg viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
    </button>

    <div class="title-wrap">
        @hasSection('page_icon')
            <span class="title-icon">@yield('page_icon')</span>
        @endif
        <div class="title-text">
            <h2 class="page-title">@yield('page_title', 'Dashboard')</h2>
            <nav class="breadcrumb" aria-label="Breadcrumb">
                <a href="{{ route('dashboard') }}">
                    <svg viewBox="0 0 24 24"><path d="M3 12l9-9 9 9M5 10v10h5v-6h4v6h5V10"/></svg>
                    Home
                </a>
                <span class="sep">/</span>
                @hasSection('parent_title')
                    @hasSection('parent_url')
                        <a href="@yield('parent_url')">@yield('parent_title')</a>
                    @else
                        <span>@yield('parent_title')</span>
                    @endif
                    <span class="sep">/</span>
                @endif
                <span class="current" aria-current="page">@yield('page_title', 'Dashboard')</span>
            </nav>
        </div>
    </div>

    <div class="top-actions">
        <div class="user" id="userMenu">
            <button type="button" class="user-trigger" id="userTrigger" aria-haspopup="true" aria-expanded="false" aria-controls="userDropdown">
                <span class="avatar">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span>
                <span class="user-info">
                    <b>{{ auth()->user()->name }}</b>
                    <small>{{ auth()->user()->email }}</small>
                </span>
                <svg class="chev" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg>
            </button>

            <div class="dropdown" id="userDropdown" role="menu">
                <div class="dropdown-head">
                    <b>{{ auth()->user()->name }}</b>
                    <small>{{ auth()->user()->email }}</small>
                    <span class="role-badge">{{ auth()->user()->roleLabel() }}</span>
                </div>
                <a href="{{ route('password.edit') }}" class="dropdown-item" role="menuitem">
                    <svg viewBox="0 0 24 24"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 018 0v4"/></svg>
                    Change password
                </a>
                <form method="POST" action="{{ route('logout') }}" class="pb-logout-form">
                    @csrf
                    <button type="submit" class="dropdown-item danger" role="menuitem">
                        <svg viewBox="0 0 24 24"><path d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4M10 17l5-5-5-5M15 12H3"/></svg>
                        Logout
                    </button>
                </form>
            </div>
        </div>
    </div>
</header>
