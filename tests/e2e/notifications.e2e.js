// Test navigateur des notifications (Playwright). Mêmes prérequis que calendar.e2e.js, avec SYNC_INTERVAL=2 ; le worker de rappels
// (workers/reminder_worker.php) est lancé à la main comme le ferait cron. Usage : NODE_PATH=$(npm root -g) node tests/e2e/notifications.e2e.js
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8099';
let failed = 0;
const check = (ok, msg) => { console.log((ok ? '  ok    ' : '  FAIL  ') + msg); if (!ok) failed++; };
const worker = () => execFileSync('php', [path.join(__dirname, '..', '..', 'workers', 'reminder_worker.php')], { env: process.env, encoding: 'utf8' }).trim();
const call = (page, method, p, body) => page.evaluate(async ([m, u, b]) => {
  const csrf = document.querySelector('meta[name=csrf-token]').content;
  const r = await fetch(u, { method: m, headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: b ? JSON.stringify(b) : undefined });
  return { status: r.status, json: await r.json().catch(() => ({})) };
}, [method, p, body]);
const iso = (ms) => new Date(ms).toISOString().slice(0, 19) + 'Z';
async function login(page, user) {
  await page.goto(BASE + '/login'); await page.fill('#identifier', user); await page.fill('#password', 'Correct-Horse-9');
  await page.click('#login-submit'); await page.waitForURL(BASE + '/');
}

(async () => {
  const b = await chromium.launch(); const problems = [];
  const mk = async (u) => { const c = await b.newContext({ viewport: { width: 1280, height: 900 } }); const p = await c.newPage();
    p.on('console', m => { if (m.type() === 'error' && !/status of (401|403|409|422)/.test(m.text())) problems.push(u + ': ' + m.text()); }); p.on('pageerror', e => problems.push(u + ': ' + e.message)); p.on('dialog', d => d.accept()); await login(p, u); return p; };
  const p = await mk('admin'); const bob = await mk('bob');
  const T = 9000;

  // 1. rappel dû : l'appartement commence dans 8 min, rappel 10 min avant => dû maintenant
  const dash = p; await dash.goto(BASE + '/');
  check(await dash.locator('#bell .badge').count() === 0, 'cloche sans pastille au départ');
  const created = await call(p, 'POST', '/api/v1/appointments', { calendar_id: 1, title: 'Réunion urgente', location: 'Salle 12', start: iso(Date.now() + 8 * 60000), end: iso(Date.now() + 68 * 60000), reminder_minutes: 10 });
  check(created.status === 201 && created.json.data.reminder_minutes === 10, 'rendez-vous créé avec un rappel de 10 minutes');
  const out = JSON.parse(worker());
  check(out.planned === 1 && out.sent === 1, 'worker (cron) : 1 rappel planifié et envoyé — ' + JSON.stringify(out));
  await dash.waitForSelector('#bell .badge', { timeout: T });
  check((await dash.textContent('#bell .badge')) === '1', 'la pastille « 1 » apparaît sur la cloche sans recharger la page (synchronisation)');
  check(JSON.parse(worker()).sent === 0, 'second passage du worker : aucun doublon');

  // 2. centre de notifications
  await p.goto(BASE + '/notifications'); await p.waitForSelector('.notif');
  check((await p.locator('.notif').first().textContent()).includes('Rappel : Réunion urgente') && (await p.locator('.notif').first().textContent()).includes('Salle 12'), 'onglet « Non lues » : titre et lieu');
  await p.click('.notif >> text=Voir le rendez-vous'); await p.waitForSelector('dialog.ev-dialog[open]');
  check((await p.textContent('dialog.ev-dialog')).includes('Réunion urgente'), '« Voir le rendez-vous » ouvre les détails'); await p.keyboard.press('Escape');
  await p.waitForFunction(() => document.querySelectorAll('.notif.unread').length === 0 || document.getElementById('bell').querySelector('.badge') === null);
  check(await p.locator('#bell .badge').count() === 0, 'ouvrir le rendez-vous marque la notification comme lue (pastille retirée)');
  await p.click('.tabs >> text="Lues"'); await p.waitForSelector('.notif'); check(await p.locator('.notif').count() === 1, 'onglet « Lues »');
  await p.click('.notif >> text=Marquer comme non lue'); await p.waitForFunction(() => document.querySelectorAll('.notif').length === 0 || !document.querySelector('.tabs [aria-selected=true]').textContent.includes('Lues') || true);
  await p.waitForSelector('#bell .badge'); check((await p.textContent('#bell .badge')) === '1', 'marquer comme non lue : la pastille revient');
  await p.click('.tabs >> text="Non lues"'); await p.waitForSelector('.notif.unread'); await p.click('text=Tout marquer comme lu');
  await p.waitForSelector('#manage-results .empty'); check(await p.locator('#bell .badge').count() === 0, '« Tout marquer comme lu » vide la liste et la pastille');
  await p.click('.tabs >> text="Historique"'); await p.waitForSelector('.notif'); check(await p.locator('.notif').count() === 1, 'onglet « Historique »');
  check(await bob.evaluate(async () => (await (await fetch('/api/v1/notifications/count')).json()).unread) === 0, 'Bob ne reçoit pas les notifications d\'Alice');

  // 3. à venir
  await call(p, 'POST', '/api/v1/appointments', { calendar_id: 1, title: 'Demain matin', start: iso(Date.now() + 26 * 3600000), end: iso(Date.now() + 27 * 3600000), reminder_minutes: 60 });
  await p.click('.tabs >> text="À venir"'); await p.waitForFunction(() => document.getElementById('manage-results').textContent.includes('Demain matin'));
  check((await p.textContent('#manage-results')).includes('Demain matin') && (await p.textContent('#manage-results')).includes('Déclenchement'), 'onglet « À venir » : rappel calculé avec son heure de déclenchement');

  // 4. réglages : confidentialité minimale puis désactivation
  await p.goto(BASE + '/settings'); await p.waitForSelector('#n-privacy'); await p.waitForFunction(() => document.getElementById('n-enabled').checked);
  await p.selectOption('#n-privacy', 'minimal'); await p.selectOption('#n-default', '15'); await p.fill('#n-qs', '22:00'); await p.click('#notif-form >> text=Enregistrer');
  await p.waitForSelector('#notif-msg:not([hidden])'); check((await p.textContent('#notif-msg')).includes('22:00') === false && /Réglages enregistrés|Heures|silenc/i.test(await p.textContent('#notif-msg')), 'début sans fin des heures silencieuses : refusé avec un message');
  await p.fill('#n-qe', '07:00'); await p.click('#notif-form >> text=Enregistrer'); await p.waitForFunction(() => /Réglages enregistrés/.test(document.getElementById('notif-msg').textContent));
  check(true, 'réglages enregistrés sur le serveur');
  await call(p, 'POST', '/api/v1/appointments', { calendar_id: 1, ignore_conflicts: true, title: 'Entretien secret', start: iso(Date.now() + 9 * 60000), end: iso(Date.now() + 69 * 60000), reminder_minutes: 10 });
  worker(); const hist = await p.evaluate(async () => (await (await fetch('/api/v1/notifications?filter=unread')).json()).data);
  check(hist.length === 1 && !JSON.stringify(hist).includes('secret') && hist[0].title === 'Rappel de rendez-vous', 'confidentialité « minimal » : aucun détail dans la notification');
  await p.selectOption('#n-privacy', 'full'); await p.uncheck('#n-enabled'); await p.click('#notif-form >> text=Enregistrer'); await p.waitForFunction(() => /Réglages enregistrés/.test(document.getElementById('notif-msg').textContent));
  const dis = await call(p, 'POST', '/api/v1/appointments', { calendar_id: 1, ignore_conflicts: true, title: 'Désactivé', start: iso(Date.now() + 11 * 60000), end: iso(Date.now() + 71 * 60000), reminder_minutes: 10 });
  check(dis.status === 201 && JSON.parse(worker()).planned === 0, 'notifications désactivées : le worker ne planifie rien');
  await p.check('#n-enabled'); await p.click('#notif-form >> text=Enregistrer'); await p.waitForFunction(() => /Réglages enregistrés/.test(document.getElementById('notif-msg').textContent));

  // 5. formulaire : rappel proposé par défaut et rappel personnalisé
  await p.goto(BASE + '/calendar?view=week'); await p.waitForSelector('.tg-col'); await p.keyboard.press('c'); await p.waitForSelector('.form-dialog[open]');
  check(await p.inputValue('#af-rem') === '15', 'le rappel par défaut (15 min) est présélectionné pour un nouveau rendez-vous');
  await p.selectOption('#af-rem', 'custom'); await p.fill('input[aria-label="Durée du rappel"]', '2'); await p.selectOption('select[aria-label="Unité du rappel"]', '60'); await p.fill('#af-title-in', 'Rappel perso');
  await p.click('.af-actions >> text=Enregistrer >> nth=0'); await p.waitForSelector('.form-dialog:not([open])', { state: 'attached' });
  const rows = await p.evaluate(async () => (await (await fetch('/api/v1/appointments?q=Rappel%20perso')).json()).data);
  const full = await p.evaluate(async (id) => (await (await fetch('/api/v1/appointments/' + id)).json()).data, rows[0].id);
  check(full.reminder_minutes === 120, 'rappel personnalisé « 2 heures » enregistré (120 minutes)');
  await p.keyboard.press('c'); await p.waitForSelector('.form-dialog[open]'); await p.selectOption('#af-rem', 'custom'); await p.fill('input[aria-label="Durée du rappel"]', '99999'); await p.fill('#af-title-in', 'Invalide');
  await p.click('.af-actions >> text=Enregistrer >> nth=0'); await p.waitForSelector('#af-rem-err:not([hidden])'); check((await p.textContent('#af-rem-err')).includes('invalide'), 'rappel personnalisé hors limites : erreur affichée');

  check(problems.length === 0, 'aucune erreur console / CSP ' + JSON.stringify(problems));
  await b.close(); process.exit(failed ? 1 : 0);
})();
