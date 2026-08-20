<style>
    .settings-card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; margin-bottom:16px; overflow:hidden; }
    .settings-header { display:inline-flex; align-items:center; gap:8px; margin:20px 20px 0 20px; padding:8px 18px; border-radius:999px; font-size:13px; font-weight:700; color:#fff; cursor:pointer; user-select:none; letter-spacing:0.01em; transition:opacity 0.15s; }
    .settings-header:hover { opacity:0.9; }
    .settings-header.static { cursor:default; }
    .settings-header.static:hover { opacity:1; }
    .settings-header .icon { font-size:15px; line-height:1; }
    .settings-header .chevron { margin-left:4px; transition:transform 0.2s; }
    .settings-header.open .chevron { transform:rotate(180deg); }
    .settings-divider { height:1px; background:#f3f4f6; margin:16px 0 0 0; }
    .settings-body { padding:8px 0 20px 0; }
    .settings-row { display:grid; grid-template-columns:180px 1fr; gap:12px; align-items:flex-start; padding:12px 24px; }
    .settings-label { font-size:13px; font-weight:600; color:#1f2937; padding-top:8px; line-height:1.4; }
    .settings-desc { font-size:11px; color:#9ca3af; margin-top:2px; }
    .settings-input { width:100%; border:1px solid #d1d5db; border-radius:6px; padding:7px 11px; font-size:13px; color:#1f2937; background:#fff; outline:none; transition:border-color 0.15s,box-shadow 0.15s; }
    .settings-input:focus { border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,0.1); }
    select.settings-input { cursor:pointer; }
    textarea.settings-input { resize:vertical; font-family:monospace; font-size:12px; }
    .secret-wrap { display:flex; gap:8px; }
    .secret-wrap .settings-input { font-family:monospace; }
    .reveal-btn { padding:7px 12px; font-size:11px; border:1px solid #d1d5db; border-radius:6px; background:#f9fafb; color:#6b7280; cursor:pointer; white-space:nowrap; transition:background 0.15s; }
    .reveal-btn:hover { background:#f3f4f6; }
    .reveal-btn:disabled { opacity:0.4; cursor:not-allowed; }
    .toggle-wrap { display:flex; align-items:center; gap:10px; padding-top:6px; }
    .toggle-track { position:relative; display:inline-flex; align-items:center; width:44px; height:24px; border-radius:999px; background:#d1d5db; cursor:pointer; transition:background 0.2s; }
    .toggle-track.on { background:#f97316; }
    .toggle-thumb { position:absolute; left:3px; width:18px; height:18px; border-radius:50%; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,0.2); transition:transform 0.2s; }
    .toggle-track.on .toggle-thumb { transform:translateX(20px); }
    .toggle-label { font-size:13px; color:#6b7280; }
    .save-bar { position:sticky; bottom:0; left:0; right:0; background:rgba(255,255,255,0.95); backdrop-filter:blur(8px); border-top:1px solid #e5e7eb; padding:14px 0; margin-top:8px; display:flex; align-items:center; justify-content:space-between; }
    .save-btn { background:#f97316; color:#fff; font-size:13px; font-weight:700; padding:10px 28px; border-radius:8px; border:none; cursor:pointer; transition:background 0.15s; letter-spacing:0.01em; }
    .save-btn:hover { background:#ea6c00; }
    .erp-pill { display:inline-flex; align-items:center; gap:6px; padding:4px 12px; border-radius:999px; font-size:11px; font-weight:600; border:1.5px solid #d1d5db; cursor:pointer; transition:all 0.15s; background:#fff; color:#6b7280; }
    .erp-pill.selected { background:#1e293b; color:#fff; border-color:#1e293b; }
    .secret-badge { display:inline-flex; align-items:center; gap:3px; font-size:10px; color:#ef4444; background:#fef2f2; border:1px solid #fecaca; padding:1px 6px; border-radius:999px; margin-top:4px; }
    .dir-grid  { display:grid; grid-template-columns:repeat(auto-fit,minmax(300px,1fr)); gap:16px; margin-bottom:20px; }
    .dir-card  { background:#fff; border:1px solid #e5e7eb; border-radius:12px; overflow:hidden; }
    .dir-pill  { display:inline-flex; align-items:center; gap:8px; margin:18px 18px 0 18px; padding:8px 20px; border-radius:999px; font-size:13px; font-weight:700; color:#fff; background:linear-gradient(135deg,#f97316,#ef4444); user-select:none; }
    .dir-body  { padding:14px 18px 18px; }
    .dir-row   { display:flex; align-items:center; justify-content:space-between; padding:10px 0; border-bottom:1px solid #f3f4f6; gap:12px; }
    .dir-row:last-child { border-bottom:none; padding-bottom:0; }
    .dir-label { font-size:12px; font-weight:600; color:#374151; white-space:nowrap; }
    .sync-mode-group { display:flex; gap:6px; flex-wrap:wrap; }
    .smode-btn { display:inline-flex; align-items:center; gap:5px; padding:5px 11px; border-radius:999px; font-size:11px; font-weight:600; border:1.5px solid #e5e7eb; background:#f9fafb; color:#6b7280; cursor:pointer; transition:all .15s; user-select:none; white-space:nowrap; }
    .smode-btn:hover { border-color:#f97316; color:#f97316; background:#fff7ed; }
    .smode-btn.active { background:linear-gradient(135deg,#f97316,#ef4444); border-color:transparent; color:#fff; box-shadow:0 2px 8px rgba(249,115,22,.35); }
    .smode-arrow { font-size:13px; line-height:1; }
    .flow-diagram { display:flex; align-items:center; gap:6px; margin-top:10px; padding:8px 12px; background:#fff7ed; border:1px solid #fed7aa; border-radius:8px; font-size:11px; color:#c2410c; font-weight:600; }
    .flow-diagram .flow-node { background:#fff; border:1.5px solid #f97316; border-radius:6px; padding:3px 9px; font-size:11px; color:#ea580c; font-weight:700; }
    .flow-diagram .flow-arrow { color:#f97316; font-size:14px; font-weight:700; }
</style>
