<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Installation – RendezVous Manager</title>
<style>
:root{--bg:#f4f6fa;--card:#fff;--fg:#1c2434;--muted:#5b667a;--line:#d9dfe9;--brand:#2563eb;--ok:#15803d;--bad:#b91c1c;--warn:#a16207}
@media(prefers-color-scheme:dark){:root{--bg:#0f1420;--card:#171e2e;--fg:#e6eaf2;--muted:#9aa6bd;--line:#2b3550;--brand:#60a5fa;--ok:#4ade80;--bad:#f87171;--warn:#fbbf24}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--fg);font:16px/1.5 system-ui,sans-serif}
main{max-width:760px;margin:0 auto;padding:16px}
header{display:flex;align-items:center;gap:10px;margin:8px 0 16px}header strong{font-size:1.2rem}header small{color:var(--muted)}
ol.steps{display:flex;flex-wrap:wrap;gap:6px;list-style:none;padding:0;margin:0 0 16px}
ol.steps li{padding:4px 10px;border:1px solid var(--line);border-radius:99px;font-size:.8rem;color:var(--muted)}
ol.steps li.cur{background:var(--brand);border-color:var(--brand);color:#fff}ol.steps li.done{color:var(--ok)}
.card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:20px}
h1{font-size:1.3rem;margin:0 0 12px}table{width:100%;border-collapse:collapse}td,th{padding:6px 8px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top;font-size:.92rem}
label{display:block;margin:12px 0 4px;font-weight:600}input,select{width:100%;padding:9px;border:1px solid var(--line);border-radius:6px;background:var(--bg);color:var(--fg);font:inherit}
.row{display:grid;grid-template-columns:1fr 1fr;gap:12px}@media(max-width:560px){.row{grid-template-columns:1fr}}
.btn{display:inline-block;padding:9px 16px;border-radius:6px;border:1px solid var(--line);background:var(--card);color:var(--fg);font:inherit;cursor:pointer;text-decoration:none}
.btn.primary{background:var(--brand);border-color:var(--brand);color:#fff}.btn[disabled],.btn.disabled{opacity:.5;pointer-events:none}
.actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:16px}.ok{color:var(--ok)}.bad{color:var(--bad)}.warn{color:var(--warn)}.muted{color:var(--muted)}
.err{background:color-mix(in srgb,var(--bad) 12%,transparent);border:1px solid var(--bad);padding:10px;border-radius:6px;margin:12px 0}
.pw{display:flex;gap:8px}.pw input{flex:1}code{background:var(--bg);padding:2px 5px;border-radius:4px}
</style>
</head>
<body>
<main>
<header><strong>RendezVous Manager</strong><small>Assistant d'installation · v<?= $e($version) ?></small></header>
<?php if ($step > 0): ?>
<ol class="steps" aria-label="Étapes">
<?php foreach ($steps as $n => $label): ?>
<li class="<?= $n === $step ? 'cur' : ($n < $step ? 'done' : '') ?>"><?= $n ?>. <?= $e($label) ?></li>
<?php endforeach; ?>
</ol>
<?php endif; ?>
<div class="card"><?= $content ?></div>
</main>
<script nonce="<?= $e($nonce) ?>">
document.querySelectorAll('[data-toggle-pw]').forEach(function (b) {
  b.addEventListener('click', function () {
    var i = document.getElementById(b.getAttribute('data-toggle-pw'));
    i.type = i.type === 'password' ? 'text' : 'password';
    b.textContent = i.type === 'password' ? 'Afficher' : 'Masquer';
  });
});
</script>
</body>
</html>
