<style>
:root { --cream:#fbf7f2; --cream-3:#ede3d8; --ink:#2b2420; --ink-5:#6f6460; --rose:#b04a62; --rose-7:#933a50; --gold:#c9a24e; }
* { box-sizing: border-box; }
body { margin:0; background:var(--cream); color:var(--ink); font:15px/1.5 Figtree,-apple-system,'Segoe UI',Helvetica,Arial,sans-serif; }
h1,h2 { font-family: Fraunces,Georgia,serif; font-weight:normal; margin:0; }
a { color:var(--rose-7); }
.wrap { max-width:1280px; margin:0 auto; padding:24px 16px 48px; }
.top { display:flex; flex-wrap:wrap; align-items:baseline; gap:8px 16px; margin-bottom:20px; }
.top h1 { font-size:28px; }
.muted { color:var(--ink-5); font-size:13px; }
.grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); gap:14px; }
.card { background:#fff; border:1px solid var(--cream-3); border-radius:14px; padding:16px; display:flex; flex-direction:column; gap:6px; }
.card h2 { font-size:19px; }
.card .subj { font-size:13px; color:var(--ink); }
.card .subj b { color:var(--ink-5); font-weight:600; text-transform:uppercase; font-size:11px; letter-spacing:.5px; margin-right:6px; }
.pill { display:inline-block; padding:2px 10px; border-radius:999px; font-size:12px; background:#f6ecd4; color:#7a5c1e; margin-right:4px; }
.pill.bad { background:#fbe3e3; color:#8a1f1f; }
.pill.ok { background:#e3f3e8; color:#1f6b3a; }
.actions { margin-top:auto; padding-top:8px; display:flex; gap:8px; flex-wrap:wrap; }
.btn { display:inline-block; padding:8px 16px; border-radius:999px; background:var(--rose); color:#fff; text-decoration:none; font-weight:600; font-size:14px; }
.btn.alt { background:#fff; color:var(--rose-7); border:1px solid var(--rose); }
.tabs { display:flex; gap:8px; margin:12px 0 4px; }
.frames { display:flex; flex-wrap:wrap; gap:24px; align-items:flex-start; margin-top:12px; }
.frame h3 { margin:0 0 6px; font-size:13px; color:var(--ink-5); font-weight:600; }
iframe { border:1px solid var(--cream-3); border-radius:12px; background:#fff; height:820px; max-width:100%; }
.problems { background:#fbe3e3; color:#8a1f1f; border-radius:10px; padding:10px 16px; margin:12px 0; }
.problems ul { margin:4px 0 0; padding-left:20px; }
.meta { display:grid; grid-template-columns:auto 1fr; gap:2px 12px; margin:8px 0; }
.meta dt { color:var(--ink-5); } .meta dd { margin:0; }
@media (max-width: 640px) { .wrap { padding:16px 16px 40px; } .top h1 { font-size:24px; } }
</style>
