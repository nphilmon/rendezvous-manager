// Test navigateur des sauvegardes, du journal d'audit et de l'historique d'un rendez-vous (Playwright).
// Mêmes prérequis que calendar.e2e.js (+ BACKUP_DIR vers un dossier temporaire pour le serveur php -S).
// Usage : NODE_PATH=$(npm root -g) node tests/e2e/audit.e2e.js
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
  p.on('console', m => { if (m.type() === 'error' && !/status of (404)/.test(m.text())) problems.push(m.text()); });
  p.on('pageerror', e => problems.push(e.message));
  p.on('dialog', d => d.accept());
  await login(p, 'admin');
  check(await p.locator('nav a:has-text("Sauvegardes")').count() === 1 && await p.locator('nav a:has-text("Historique")').count() === 1, 'menus « Historique » et « Sauvegardes » visibles pour l\'administrateur');

  // ---- sauvegardes ----
  await p.goto(BASE + '/backups'); await p.waitForSelector('#b-results .empty, #b-results .tbl');
  await p.click('#b-create'); await p.waitForSelector('#b-results .tbl tbody tr');
  check(await p.locator('#b-results tbody tr').count() === 1, 'sauvegarde créée et listée');
  const name = (await p.textContent('#b-results tbody tr td')).trim();
  check(/^rvm-\d{8}-\d{6}\.sql\.gz$/.test(name), 'nom de fichier horodaté (' + name + ')');
  await p.click('button[aria-label="Vérifier : ' + name + '"]'); await p.waitForSelector('.toast:has-text("intègre")'); check(true, 'vérification d\'intégrité : correcte');
  const [d] = await Promise.all([p.waitForEvent('download'), p.click('button[aria-label="Télécharger : ' + name + '"]')]);
  const f = path.join(os.tmpdir(), 'rvm-e2e-' + name); await d.saveAs(f); const buf = fs.readFileSync(f);
  check(buf[0] === 0x1f && buf[1] === 0x8b, 'le téléchargement est une archive gzip');
  const sql = require('zlib').gunzipSync(buf).toString('utf8');
  check(sql.includes('CREATE TABLE `appointments`') && sql.trimEnd().endsWith('-- END rvm-backup-1'), 'contenu : schéma et marqueur de fin');
  check(!sql.includes('INSERT INTO `user_sessions`'), 'aucune session dans la sauvegarde');
  await p.click('button[aria-label="Supprimer : ' + name + '"]'); await p.waitForSelector('#b-results .empty'); check(true, 'sauvegarde supprimée');

  // ---- historique d'un rendez-vous (dialogue) ----
  const id = await p.evaluate(async () => {
    const csrf = document.querySelector('meta[name=csrf-token]').content;
    const list = (await (await fetch('/api/v1/appointments?q=Séminaire')).json()).data;
    const a = list[0];
    await fetch('/api/v1/appointments/' + a.id, { method: 'PUT', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify({ version: a.version, title: 'Séminaire (renommé)' }) });
    return a.id;
  });
  await p.goto(BASE + '/appointments'); await p.waitForSelector('#list-results .tbl'); await p.click('button.link:has-text("Séminaire (renommé)")'); await p.waitForSelector('dialog.ev-dialog[open]');
  await p.click('dialog.ev-dialog summary:has-text("Historique")'); await p.waitForSelector('.hist-list li');
  const txt = await p.textContent('.hist-list');
  check(txt.includes('Rendez-vous modifié') && txt.includes('Titre : Séminaire → Séminaire (renommé)'), 'fiche : l\'ancien et le nouveau titre sont affichés');
  check(!/\d+\.\d+\.\d+\.\d+/.test(txt), 'fiche : aucune adresse IP dans l\'historique d\'un rendez-vous');
  await p.keyboard.press('Escape');

  // ---- journal d'audit ----
  await p.goto(BASE + '/history'); await p.waitForSelector('#h-results .tbl');
  check(await p.locator('#h-results tbody tr').count() >= 3, 'journal chargé');
  await p.selectOption('#h-action', 'appointment.update'); await p.click('#h-filter >> text=Filtrer');
  await p.waitForFunction(() => [...document.querySelectorAll('#h-results tbody tr td:nth-child(3)')].every(td => td.textContent === 'Rendez-vous modifié'));
  check((await p.textContent('#h-results')).includes('Titre : Séminaire → Séminaire (renommé)'), 'filtre par action et détail du changement');
  await p.fill('#h-q', '%'); await p.selectOption('#h-action', ''); await p.click('#h-filter >> text=Filtrer'); await p.waitForSelector('#h-results .empty'); check(true, 'recherche « % » sans résultat');
  await p.click('#h-reset'); await p.waitForSelector('#h-results .tbl');

  // ---- un simple utilisateur ----
  const m = await b.newContext({ viewport: { width: 1280, height: 900 } }); const bp = await m.newPage();
  bp.on('pageerror', e => problems.push(e.message));
  await login(bp, 'bob');
  check(await bp.locator('nav a:has-text("Sauvegardes")').count() === 0 && await bp.locator('nav a:has-text("Historique")').count() === 0, 'Bob ne voit ni l\'historique ni les sauvegardes');
  check((await bp.goto(BASE + '/backups')).status() === 403 && (await bp.goto(BASE + '/history')).status() === 403, 'pages refusées (403)');
  const st = await bp.evaluate(async () => [(await fetch('/api/v1/audit')).status, (await fetch('/api/v1/backups')).status]);
  check(st[0] === 403 && st[1] === 403, 'API refusée (403)');

  await p.goto(BASE + '/history'); await p.waitForSelector('#h-results .tbl'); await p.screenshot({ path: process.env.SHOT || '/tmp/history.png', fullPage: true });
  check(problems.length === 0, 'aucune erreur console / CSP ' + JSON.stringify(problems));
  await b.close(); process.exit(failed ? 1 : 0);
})();
