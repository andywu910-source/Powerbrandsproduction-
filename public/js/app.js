/* PowerBrands Productions shell UI (sidebar, user menu). No build step, no dependencies. */
(function () {
    var SB_KEY = 'sidebarCollapsed';
    var GROUP_KEY = 'navClosedGroups';
    var sidebar = document.getElementById('sidebar');
    var backdrop = document.getElementById('backdrop');
    var menuBtn = document.getElementById('menuBtn');
    var trigger = document.getElementById('userTrigger');
    var dropdown = document.getElementById('userDropdown');

    function isMobile() { return window.matchMedia('(max-width: 991px)').matches; }
    function toggleMenu(open) {
        if (!sidebar) return;
        sidebar.classList.toggle('open', open);
        if (backdrop) backdrop.classList.toggle('show', open);
    }
    function toggleSidebar() {
        if (isMobile()) { toggleMenu(!sidebar.classList.contains('open')); return; }
        var collapsed = document.documentElement.classList.toggle('sb-collapsed');
        try { localStorage.setItem(SB_KEY, collapsed ? '1' : '0'); } catch (e) {}
        if (menuBtn) menuBtn.setAttribute('aria-expanded', String(!collapsed));
    }
    if (menuBtn) menuBtn.addEventListener('click', toggleSidebar);
    if (backdrop) backdrop.addEventListener('click', function () { toggleMenu(false); });

    function setUser(open) {
        if (!dropdown) return;
        dropdown.classList.toggle('open', open);
        trigger.setAttribute('aria-expanded', String(open));
    }
    if (trigger) {
        trigger.addEventListener('click', function (e) {
            e.stopPropagation();
            setUser(!dropdown.classList.contains('open'));
        });
        dropdown.addEventListener('click', function (e) { e.stopPropagation(); });
        document.addEventListener('click', function () { setUser(false); });
    }

    function closedGroups() {
        try { return JSON.parse(localStorage.getItem(GROUP_KEY)) || []; } catch (e) { return []; }
    }
    document.querySelectorAll('.nav-sec').forEach(function (sec) {
        var key = sec.getAttribute('data-group');
        var btn = sec.querySelector('.nav-sec-btn');
        var isClosed = closedGroups().indexOf(key) > -1 && !sec.querySelector('a.active');
        sec.classList.toggle('closed', isClosed);
        if (btn) {
            btn.setAttribute('aria-expanded', String(!isClosed));
            btn.addEventListener('click', function () {
                var nowClosed = !sec.classList.contains('closed');
                sec.classList.toggle('closed', nowClosed);
                btn.setAttribute('aria-expanded', String(!nowClosed));
                var list = closedGroups().filter(function (k) { return k !== key; });
                if (nowClosed) list.push(key);
                try { localStorage.setItem(GROUP_KEY, JSON.stringify(list)); } catch (e) {}
            });
        }
    });

    document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && (e.key === 'b' || e.key === 'B')) { e.preventDefault(); toggleSidebar(); }
        if (e.key === 'Escape') { setUser(false); toggleMenu(false); }
    });
})();
