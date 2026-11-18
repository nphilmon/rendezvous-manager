// Test navigateur du tableau de bord (Playwright). Mêmes prérequis que calendar.e2e.js
// (base *_test peuplée par tests/e2e/seed.php, storage/install.lock, php -S avec DB_* et COOKIE_SECURE=false).
// Usage : NODE_PATH=$(npm root -g) node tests/e2e/dashboard.e2e.js
const { chromium } = require('playwright');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8099';
let failed = 0;
const check = (ok, msg) => { console.log((ok ? '  ok    ' : '  FAIL  ') + msg); if (!ok) failed++; };
async function login(page, user) {
  await page.goto(BASE + '/login'); await page.fill('#identifier', user); await page.fill('#password', 'Correct-Horse-9');
  await page.click('#login-submit'); await page.waitForURL(BASE + '/');
}
const num = (p, k) => p.textContent('[data-k="' + k + '"]').then(t => parseInt(t, 10));

(async () => {
  const b = await chromium.launch(); const problems = [];
  const ctx = await b.newContext({ viewport: { width: 1280, height: 1000 } }); const p = await ctx.newPage();
  p.on('console', m => { if (m.type() === 'error') problems.push(m.text()); });
  p.on('pageerror', e => problems.push(e.message));
  await login(p, 'admin');
  await p.waitForFunction(() => document.querySelector('[data-k="total"]').textContent !== '–');

  const api = await p.evaluate(async () => (await (await fetch('/api/v1/dashboard?period=month')).json()).data);
  check(await num(p, 'total') === api.counts.total && api.counts.total > 0, 'compteur total = API (' + api.counts.total + ')');
  check(await num(p, 'week') === api.counts.week && await num(p, 'upcoming') === api.counts.upcoming, 'semaine / à venir = API');
  check(await p.locator('#dash-status .dv-row').count() === 5, 'répartition par statut : 5 lignes');
  check(await p.locator('#dash-day .dv-col').count() === 14, 'charge sur 14 jours : 14 colonnes');
  check(await p.locator('#dash-cat .dv-row').count() >= 1, 'répartition par catégorie affichée');
  check(await p.locator('#dash-status details table tr').count() === 6, 'alternative tableau présente');
  check(await p.locator('.shortcuts a').count() >= 5 && await p.locator('.shortcuts a:has-text("Nouveau rendez-vous")').count() === 1, 'raccourcis (création autorisée)');
  check(await p.locator('#mini-cal .mc-grid button').count() > 27, 'mini-calendrier présent');

  // période : les compteurs terminés/annulés suivent le choix
  const year = await p.evaluate(async () => (await (await fetch('/api/v1/dashboard?period=year')).json()).data);
  await p.selectOption('#dash-period', 'year');
  await p.waitForFunction(() => document.querySelector('#dash-status-h').textContent.toLowerCase().includes('cette année'));
  check(await num(p, 'cancelled') === year.period.cancelled, 'changement de période : compteurs mis à jour (année)');
  check((await p.textContent('#dash-status-h')).toLowerCase().includes('cette année'), 'le titre des graphiques rappelle la période');
  await p.reload(); await p.waitForFunction(() => document.querySelector('[data-k="total"]').textContent !== '–');
  check(await p.inputValue('#dash-period') === 'year', 'période mémorisée dans le navigateur');

  // prochain rendez-vous → détails
  if (api.next) {
    await p.click('#dash-next .nt'); await p.waitForSelector('dialog.ev-dialog[open]');
    check((await p.textContent('dialog.ev-dialog')).includes(api.next.title), 'prochain rendez-vous : ouvre la fiche');
    await p.keyboard.press('Escape');
  } else check(true, 'aucun prochain rendez-vous dans les données de test');

  // un rendez-vous créé ailleurs met à jour le tableau de bord (synchronisation)
  const before = await num(p, 'total');
  await p.evaluate(async () => {
    const csrf = document.querySelector('meta[name=csrf-token]').content;
    const cals = (await (await fetch('/api/v1/calendars')).json()).data;
    await fetch('/api/v1/appointments', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify({ calendar_id: cals[0].id, title: 'Créé pour le dashboard', timezone: 'Europe/Paris', start: '2026-11-20T08:00:00Z', end: '2026-11-20T09:00:00Z' }) });
  });
  await p.evaluate(() => window.RVM.sync.poll());
  await p.waitForFunction((n) => parseInt(document.querySelector('[data-k="total"]').textContent, 10) === n + 1, before);
  check(true, 'création externe : le total passe à ' + (before + 1) + ' sans rechargement');

  // Bob : uniquement ses données
  const m = await b.newContext({ viewport: { width: 1280, height: 900 } }); const bp = await m.newPage();
  bp.on('pageerror', e => problems.push(e.message));
  await login(bp, 'bob'); await bp.waitForFunction(() => document.querySelector('[data-k="total"]').textContent !== '–');
  const bobApi = await bp.evaluate(async () => (await (await fetch('/api/v1/dashboard')).json()).data);
  check(await num(bp, 'total') === bobApi.counts.total && bobApi.counts.total < before + 1, 'Bob : total limité à ses calendriers (' + bobApi.counts.total + ')');

  // thème sombre : rendu sans erreur
  await p.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'));
  await p.screenshot({ path: process.env.SHOT || '/tmp/dashboard-dark.png', fullPage: true });
  await p.evaluate(() => document.documentElement.setAttribute('data-theme', 'light'));
  await p.screenshot({ path: (process.env.SHOT || '/tmp/dashboard-dark.png').replace('dark', 'light'), fullPage: true });

  check(problems.length === 0, 'aucune erreur console / CSP ' + JSON.stringify(problems));
  await b.close(); process.exit(failed ? 1 : 0);
})();
