// Test navigateur de l'import / export (Playwright). Mêmes prérequis que calendar.e2e.js
// (base *_test peuplée par tests/e2e/seed.php, storage/install.lock, php -S avec DB_* et COOKIE_SECURE=false).
// Usage : NODE_PATH=$(npm root -g) node tests/e2e/transfer.e2e.js
const { chromium } = require('playwright');
const fs = require('fs'); const os = require('os'); const path = require('path');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8099';
let failed = 0;
const check = (ok, msg) => { console.log((ok ? '  ok    ' : '  FAIL  ') + msg); if (!ok) failed++; };
async function login(page, user) {
  await page.goto(BASE + '/login'); await page.fill('#identifier', user); await page.fill('#password', 'Correct-Horse-9');
  await page.click('#login-submit'); await page.waitForURL(BASE + '/');
}
const total = (p) => p.evaluate(async () => (await (await fetch('/api/v1/appointments?q=Import%20e2e')).json()).meta.total);

(async () => {
  const b = await chromium.launch(); const problems = [];
  const ctx = await b.newContext({ viewport: { width: 1280, height: 1000 }, acceptDownloads: true }); const p = await ctx.newPage();
  p.on('console', m => { if (m.type() === 'error' && !/status of (422)/.test(m.text())) problems.push(m.text()); });
  p.on('pageerror', e => problems.push(e.message));
  await login(p, 'admin');
  check(await p.locator('nav a:has-text("Import / export")').count() === 1, 'entrée « Import / export » dans le menu');
  await p.goto(BASE + '/import-export'); await p.waitForSelector('#ex-cal option:nth-child(2)', { state: 'attached' });

  // ---- exports ----
  const download = async (fmt, what) => {
    if (what) await p.selectOption('#ex-what', what);
    await p.selectOption('#ex-format', fmt);
    const [d] = await Promise.all([p.waitForEvent('download'), p.click('#ex-go')]);
    const f = path.join(os.tmpdir(), 'rvm-' + d.suggestedFilename()); await d.saveAs(f); return { name: d.suggestedFilename(), data: fs.readFileSync(f) };
  };
  const csv = await download('csv', 'appointments');
  check(/^rendezvous-.*\.csv$/.test(csv.name) && csv.data.toString('utf8').includes('Titre,Calendrier,Début'), 'export CSV téléchargé (' + csv.name + ')');
  check(csv.data.toString('utf8').split('\r\n').length > 5, 'le CSV contient les rendez-vous de démonstration');
  const xl = await download('xlsx'); check(xl.data.slice(0, 2).toString() === 'PK', 'export XLSX : archive ZIP');
  const pdf = await download('pdf'); check(pdf.data.slice(0, 5).toString() === '%PDF-', 'export PDF');
  const ics = await download('ics'); check(ics.data.toString().startsWith('BEGIN:VCALENDAR') && ics.data.toString().includes('BEGIN:VEVENT'), 'export ICS');
  const con = await download('csv', 'contacts'); check(con.data.toString('utf8').includes('Nom,Prénom'), 'export contacts CSV');
  await p.selectOption('#ex-what', 'appointments'); await p.fill('#ex-from', '2026-12-01'); await p.fill('#ex-to', '2026-01-01'); await p.click('#ex-go');
  await p.waitForSelector('#ex-err:not([hidden])'); check((await p.textContent('#ex-err')).includes('postérieur'), 'période incohérente : erreur lisible');
  await p.fill('#ex-from', ''); await p.fill('#ex-to', '');

  // ---- import ----
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'rvmi-'));
  const good = path.join(dir, 'ok.csv');
  fs.writeFileSync(good, 'Titre,Début,Fin,Journée entière,Lieu\nImport e2e A,2026-11-24 09:00,2026-11-24 10:00,non,Salle\nImport e2e B,2026-11-25,,oui,\n=Import e2e C,2026-11-26 09:00,2026-11-26 10:00,non,\n');
  await p.selectOption('#im-kind', 'appointments:csv');
  await p.click('#im-go'); await p.waitForSelector('#im-err:not([hidden])'); check((await p.textContent('#im-err')).includes('Choisissez un fichier'), 'sans fichier : message');
  await p.setInputFiles('#im-file', good);
  const before = await total(p);
  await p.click('#im-dry'); await p.waitForSelector('#im-result:not([hidden]) h3');
  check((await p.textContent('#im-result')).includes('Simulation') && (await p.textContent('#im-result dl')).includes('Seraient créées3'), 'simulation : 3 lignes seraient créées');
  check(await total(p) === before, 'simulation : rien n\'est créé');
  await p.click('#im-go'); await p.waitForFunction(() => document.querySelector('#im-result h3') && document.querySelector('#im-result h3').textContent.includes('terminé'));
  check((await p.textContent('#im-result dl')).includes('Créées3'), 'import réel : 3 créés');
  check(await total(p) === before + 3, 'les rendez-vous existent côté serveur');
  const stored = await p.evaluate(async () => (await (await fetch('/api/v1/appointments?q=Import%20e2e%20C')).json()).data.map(a => a.title));
  check(stored.includes('=Import e2e C'), 'valeur commençant par « = » importée telle quelle (le texte reste du texte)');
  await p.click('#im-go'); await p.waitForFunction(() => document.querySelector('#im-result dl') && document.querySelector('#im-result dl').textContent.includes('Doublons ignorés3'));
  check(await total(p) === before + 3, 'réimport : 3 doublons ignorés, rien de plus');
  const exp = await download('csv', 'appointments');
  check(exp.data.toString('utf8').includes("'=Import e2e C"), 'réexport : la formule est neutralisée par une apostrophe');

  const bad = path.join(dir, 'bad.csv');
  fs.writeFileSync(bad, 'Titre,Début\nValide e2e,2026-11-27 09:00\n,2026-11-27 09:00\nMauvaise date,31/02/2026 10:00\n');
  await p.setInputFiles('#im-file', bad); await p.click('#im-go');
  await p.waitForSelector('#im-result .tr-list');
  const txt = await p.textContent('#im-result');
  check(txt.includes('Ligne 3') && txt.includes('Ligne 4') && (await p.textContent('#im-result dl')).includes('Créées1'), 'erreurs par ligne signalées, la ligne valide est importée');

  const icsf = path.join(dir, 'a.ics');
  fs.writeFileSync(icsf, 'BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:z@x\r\nDTSTART:20261130T090000Z\r\nDTEND:20261130T100000Z\r\nSUMMARY:Import e2e ICS\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n');
  await p.selectOption('#im-kind', 'appointments:ics'); await p.setInputFiles('#im-file', icsf); await p.click('#im-go');
  await p.waitForFunction(() => document.querySelector('#im-result dl') && document.querySelector('#im-result dl').textContent.includes('Créées1'));
  check(true, 'import ICS');
  const notcsv = path.join(dir, 'x.ics'); fs.writeFileSync(notcsv, 'ceci n\'est pas un calendrier');
  await p.setInputFiles('#im-file', notcsv); await p.click('#im-go'); await p.waitForSelector('#im-err:not([hidden])');
  check((await p.textContent('#im-err')).includes('iCalendar'), 'fichier non ICS : refus lisible');

  const cf = path.join(dir, 'c.csv'); fs.writeFileSync(cf, 'Nom,Prénom,E-mail\nImportée,Zoé,zoe.e2e@example.org\n');
  await p.selectOption('#im-kind', 'contacts:csv'); await p.setInputFiles('#im-file', cf); await p.click('#im-go');
  await p.waitForFunction(() => document.querySelector('#im-result dl') && document.querySelector('#im-result dl').textContent.includes('Créées1'));
  check(true, 'import de contacts');

  // ---- Bob : voit ses propres données seulement ----
  const m = await b.newContext({ viewport: { width: 1280, height: 900 }, acceptDownloads: true }); const bp = await m.newPage();
  bp.on('pageerror', e => problems.push(e.message));
  await login(bp, 'bob'); await bp.goto(BASE + '/import-export'); await bp.waitForSelector('#ex-cal option:nth-child(2)', { state: 'attached' });
  const [bd] = await Promise.all([bp.waitForEvent('download'), bp.click('#ex-go')]);
  const bf = path.join(os.tmpdir(), 'rvm-bob.csv'); await bd.saveAs(bf);
  const rows = fs.readFileSync(bf, 'utf8').trim().split('\r\n').length - 1; // en-tête retiré (aucune cellule multi-ligne dans les données de démonstration)
  const visible = await bp.evaluate(async () => (await (await fetch('/api/v1/appointments?per_page=100')).json()).meta.total);
  check(rows === visible, 'export de Bob = exactement ce que Bob voit dans l\'application (' + rows + ' lignes)');
  const allTotal = await p.evaluate(async () => (await (await fetch('/api/v1/appointments?per_page=100')).json()).meta.total);
  check(visible < allTotal, 'Bob voit moins de rendez-vous que l\'administrateur (' + visible + ' < ' + allTotal + ')');

  await p.screenshot({ path: process.env.SHOT || '/tmp/transfer.png', fullPage: true });
  check(problems.length === 0, 'aucune erreur console / CSP ' + JSON.stringify(problems));
  await b.close(); process.exit(failed ? 1 : 0);
})();
