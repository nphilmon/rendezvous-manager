// Test navigateur des rapports et de la recherche globale (Playwright). Mêmes prérequis que calendar.e2e.js
// (base *_test peuplée par tests/e2e/seed.php, storage/install.lock, php -S avec DB_* et COOKIE_SECURE=false).
// Usage : NODE_PATH=$(npm root -g) node tests/e2e/reports.e2e.js
const { chromium } = require('playwright');
const fs = require('fs'); const os = require('os'); const path = require('path');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8099';
let failed = 0;
const check = (ok, msg) => { console.log((ok ? '  ok    ' : '  FAIL  ') + msg); if (!ok) failed++; };
async function login(page, user) {
  await page.goto(BASE + '/login'); await page.fill('#identifier', user); await page.fill('#password', 'Correct-Horse-9');
  await page.click('#login-submit'); await page.waitForURL(BASE + '/');
}

(async () => {
  const b = await chromium.launch(); const problems = [];
  const ctx = await b.newContext({ viewport: { width: 1280, height: 1000 }, acceptDownloads: true }); const p = await ctx.newPage();
  p.on('console', m => { if (m.type() === 'error' && !/status of (403|422)/.test(m.text())) problems.push(m.text()); });
  p.on('pageerror', e => problems.push(e.message));
  await login(p, 'admin');
  check(await p.locator('nav a:has-text("Rapports")').count() === 1, 'entrée « Rapports » dans le menu');
  await p.goto(BASE + '/reports'); await p.waitForSelector('#rp-cards .stat');
  check(await p.locator('#rp-cards .stat').count() === 6, '6 indicateurs affichés');
  check(!(await p.textContent('main, .content, body')).includes('pas encore disponible'), 'plus de page « module non disponible »');
  await p.click('[data-preset="year"]'); await p.waitForFunction(() => document.querySelectorAll('#rp-grid .panel').length >= 9);
  check(await p.locator('#rp-grid .panel').count() === 9, 'neuf répartitions (statut, priorité, catégorie, calendrier, créateur, contacts, jour, heure, mois)');
  const api = await p.evaluate(async () => (await (await fetch('/api/v1/reports?from=' + document.getElementById('rp-from').value + '&to=' + document.getElementById('rp-to').value)).json()).data);
  const shown = parseInt(await p.textContent('#rp-cards .stat:first-child .stat-n'), 10);
  check(shown === api.totals.appointments && shown > 0, 'le total affiché correspond à l\'API (' + shown + ' rendez-vous)');
  check(await p.locator('#rp-grid details table').count() >= 5, 'alternative « tableau » présente');
  await p.fill('#rp-from', '2026-11-10'); await p.fill('#rp-to', '2026-11-01'); await p.click('#rp-form button[type=submit]');
  await p.waitForSelector('#rp-err:not([hidden])'); check((await p.textContent('#rp-err')).includes('postérieur'), 'période incohérente : message lisible');
  await p.click('[data-preset="month"]'); await p.waitForSelector('#rp-err', { state: 'hidden' });
  const [dl] = await Promise.all([p.waitForEvent('download'), p.click('#rp-csv')]);
  const f = path.join(os.tmpdir(), 'rvm-report.csv'); await dl.saveAs(f);
  check(/^rapport-\d{4}-\d{2}-\d{2}_\d{4}-\d{2}-\d{2}\.csv$/.test(dl.suggestedFilename()) && fs.readFileSync(f, 'utf8').includes('Rubrique,Libellé,Nombre'), 'export CSV du rapport');
  await p.screenshot({ path: process.env.SHOT || '/tmp/reports.png', fullPage: true });

  // recherche globale et filtres combinés
  await p.goto(BASE + '/appointments?q=Cliente'); await p.waitForSelector('#list-results .tbl, #list-results .empty');
  const g = await p.evaluate(async () => (await (await fetch('/api/v1/search?q=Dupont')).json()).data);
  check(typeof g.appointments.total === 'number' && typeof g.contacts.total === 'number', 'API de recherche globale (rendez-vous et contacts)');
  await p.fill('#q', 'Séminaire'); await p.press('#q', 'Enter'); await p.waitForURL(/\/appointments\?q=/);
  await p.waitForSelector('#list-results .tbl'); check((await p.textContent('#list-results')).includes('Séminaire'), 'recherche depuis la barre supérieure');

  // Bob (utilisateur) : ni menu ni page ni API
  const m = await b.newContext({ viewport: { width: 1280, height: 900 } }); const bp = await m.newPage();
  bp.on('pageerror', e => problems.push(e.message));
  await login(bp, 'bob');
  check(await bp.locator('nav a:has-text("Rapports")').count() === 0, 'Bob ne voit pas « Rapports »');
  check((await bp.goto(BASE + '/reports')).status() === 403 && (await bp.evaluate(async () => (await fetch('/api/v1/reports')).status)) === 403, 'page et API refusées (403)');
  check(problems.length === 0, 'aucune erreur console / CSP ' + JSON.stringify(problems));
  await b.close(); process.exit(failed ? 1 : 0);
})();
