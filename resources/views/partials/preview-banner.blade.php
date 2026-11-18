@if (\App\Support\Preview::enabled())
<style>.pb-preview-banner { position: fixed; top: 0; left: 0; right: 0; height: 30px; line-height: 18px; box-sizing: border-box; z-index: 1000; background: repeating-linear-gradient(45deg, #b45309, #b45309 12px, #92400e 12px, #92400e 24px); color: #fff; font-weight: 800; letter-spacing: .06em; text-align: center; padding: 6px 10px; font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
body:has(> .pb-preview-banner) { padding-top: 30px; }
body:has(> .pb-preview-banner) .sidebar { top: 30px; }
body:has(> .pb-preview-banner) .topbar { top: 30px; }
.pb-preview-banner span { font-weight: 500; letter-spacing: 0; opacity: .9; }</style>
<div class="pb-preview-banner" role="note">PREVIEW / TEST DATA <span>· not the live site · nothing here is real</span></div>
@endif
