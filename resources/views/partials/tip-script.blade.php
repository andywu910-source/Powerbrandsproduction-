<div class="pb-tip-pop" id="pbTipPop" role="tooltip" hidden></div>
<script>
(function () {
    var pop = document.getElementById('pbTipPop'), cur = null;
    function hide() { pop.hidden = true; if (cur) cur.setAttribute('aria-expanded', 'false'); cur = null; }
    function show(b) {
        if (cur === b) { hide(); return; }
        hide(); cur = b; b.setAttribute('aria-expanded', 'true');
        pop.textContent = b.getAttribute('data-tip'); pop.hidden = false;
        var r = b.getBoundingClientRect(), w = Math.min(340, window.innerWidth - 24);
        pop.style.width = w + 'px';
        pop.style.left = Math.max(12, Math.min(window.scrollX + r.left + r.width / 2 - w / 2, window.scrollX + window.innerWidth - w - 12)) + 'px';
        pop.style.top = (window.scrollY + r.bottom + 8) + 'px';
    }
    document.addEventListener('click', function (e) {
        var b = e.target.closest('.pb-tip');
        if (b) { e.preventDefault(); e.stopPropagation(); show(b); } else if (!e.target.closest('#pbTipPop')) hide();
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') hide(); });
    window.addEventListener('resize', hide);
    document.querySelectorAll('.pb-tip').forEach(function (b) { b.removeAttribute('title'); b.addEventListener('mouseenter', function () { if (matchMedia('(hover: hover)').matches && cur !== b) show(b); }); });
})();
</script>
