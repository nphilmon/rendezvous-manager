// Test navigateur des paramètres enregistrés sur le serveur (Playwright). Mêmes prérequis que calendar.e2e.js
// (base *_test peuplée par tests/e2e/seed.php, storage/install.lock, php -S avec DB_* et COOKIE_SECURE=false).
// Usage : NODE_PATH=$(npm root -g) node tests/e2e/settings.e2e.js
const { chromium } = require('playwright');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8099';
let failed = 0;
const check = (ok, msg) => { console.log((ok ? '  ok    ' : '  FAIL  ') + msg); if (!ok) failed++; };
async function login(page, user) {
  await page.goto(BASE + '/login'); await page.fill('#identifier', user); await page.fill('#password', 'Correct-Horse-9');
  await page.click('#login-submit'); await page.waitForURL(BASE + '/');
}
const settings = (p) => p.evaluate(async () => (await (await fetch('/api/v1/settings')).json()));

(async () => {
  const b = await chromium.launch(); const problems = [];
  const ctx = await b.newContext({ viewport: { width: 1280, height: 1000 } }); const p = await ctx.newPage();
  p.on('console', m => { if (m.type() === 'error' && !/status of (422)/.test(m.text())) problems.push(m.text()); });
  p.on('pageerror', e => problems.push(e.message));
  await login(p, 'admin');

  // ---- profil ----
  await p.goto(BASE + '/settings'); await p.waitForFunction(() => document.getElementById('pf-first').value !== '');
  await p.fill('#pf-first', 'Léa-Marie'); await p.click('#profile-form button[type=submit]'); await p.waitForSelector('#profile-msg.alert-ok');
  check((await settings(p)).profile.first_name === 'Léa-Marie', 'profil : prénom enregistré sur le serveur');
  await p.fill('#pf-tz', 'Mars/Phobos'); await p.click('#profile-form button[type=submit]'); await p.waitForSelector('#profile-msg.alert-error');
  check((await p.textContent('#profile-msg')).includes('Fuseau'), 'profil : fuseau invalide refusé avec message');
  await p.fill('#pf-tz', 'Europe/Paris');

  // ---- affichage : thème, vue d'ouverture, durée par défaut ----
  await p.waitForSelector('#u-theme');
  await p.selectOption('#u-theme', 'dark'); await p.selectOption('#u-view', 'month'); await p.selectOption('#u-default_duration', '30'); await p.selectOption('#u-start_hour', '8'); await p.selectOption('#u-end_hour', '19');
  await p.click('#ui-form button[type=submit]'); await p.waitForSelector('#ui-msg.alert-ok');
  check(await p.getAttribute('html', 'data-theme') === 'dark', 'thème sombre appliqué immédiatement');
  const s1 = (await settings(p)).ui; check(s1.theme === 'dark' && s1.view === 'month' && s1.default_duration === 30 && s1.start_hour === 8, 'préférences enregistrées sur le serveur');
  await p.selectOption('#u-start_hour', '20'); await p.click('#ui-form button[type=submit]'); await p.waitForSelector('#ui-msg.alert-error');
  check((await p.textContent('#ui-msg')).includes('end_hour'), 'début ≥ fin refusé avec message');

  // ---- les préférences suivent l'utilisateur sur un autre appareil (autre contexte, aucun stockage local) ----
  const ctx2 = await b.newContext({ viewport: { width: 1280, height: 900 } }); const p2 = await ctx2.newPage();
  p2.on('pageerror', e => problems.push(e.message));
  await login(p2, 'admin');
  check(await p2.getAttribute('html', 'data-theme') === 'dark', 'autre appareil : thème sombre dès le chargement (sans flash)');
  await p2.goto(BASE + '/calendar'); await p2.waitForSelector('#calendar-root[data-view="month"]'); check(true, 'autre appareil : le calendrier s\'ouvre en vue Mois');
  await p2.goto(BASE + '/calendar?view=week&date=2026-11-18'); await p2.waitForSelector('.tg-col .ev');
  check(await p2.evaluate(() => window.RVM.ui.start_hour === 8 && window.RVM.ui.end_hour === 19), 'autre appareil : grille de 08:00 à 19:00');
  const hours = await p2.locator('.tg-gutter .hour, .gutter .hour').allTextContents();
  check(hours.filter(Boolean)[0] === '09:00' && hours.filter(Boolean).slice(-1)[0] === '18:00', 'grille réellement limitée aux heures choisies (' + hours.filter(Boolean)[0] + ' … ' + hours.filter(Boolean).slice(-1)[0] + ')');
  await p2.keyboard.press('c'); await p2.waitForSelector('.form-dialog[open]');
  const st = await p2.inputValue('#af-st'), et = await p2.inputValue('#af-et'), toMin = (t) => +t.slice(0, 2) * 60 + +t.slice(3);
  check(toMin(et) - toMin(st) === 30, 'nouveau rendez-vous : durée par défaut de 30 minutes (' + st + ' → ' + et + ')'); await p2.keyboard.press('Escape');
  // réglage depuis le calendrier : enregistré côté serveur
  await p2.click('button:has-text("Réglages")'); await p2.selectOption('#cal-settings label:has-text("Pas") select', '15');
  await p2.waitForFunction(() => window.RVM.ui.step === 15); await p2.waitForTimeout(400);
  check((await settings(p)).ui.step === 15, 'réglage fait dans le calendrier : enregistré sur le serveur');
  const theme = await p2.evaluate(() => localStorage.getItem('rvm_cal_settings')); check(theme === null, 'plus aucun réglage de calendrier dans le navigateur');
  // calendriers masqués : enregistrés sur le serveur, retrouvés sur un autre appareil
  await p2.waitForSelector('.cal-item input'); const calName = await p2.locator('.cal-item .name').first().textContent();
  await p2.uncheck('.cal-item >> nth=0 >> input'); await p2.waitForFunction(() => window.RVM.ui.hidden_calendars.length === 1);
  await p2.waitForTimeout(500);
  check((await settings(p)).ui.hidden_calendars.length === 1, 'calendrier masqué (« ' + calName.trim() + ' ») : enregistré sur le serveur');
  const ctx4 = await b.newContext({ viewport: { width: 1280, height: 900 } }); const p4 = await ctx4.newPage(); p4.on('pageerror', e => problems.push(e.message));
  await login(p4, 'admin'); await p4.goto(BASE + '/calendar?view=week&date=2026-11-18'); await p4.waitForSelector('.cal-item input');
  check(await p4.locator('.cal-item >> nth=0 >> input').isChecked() === false && await p4.locator('.cal-item input:checked').count() >= 1, 'autre appareil : le même calendrier est masqué, les autres restent affichés');

  // ---- reprise des anciens réglages du navigateur (compte sans réglage serveur) ----
  const ctx3 = await b.newContext({ viewport: { width: 1280, height: 900 } });
  await ctx3.addInitScript(() => { if (!localStorage.getItem('seeded')) { localStorage.setItem('seeded', '1'); localStorage.setItem('rvm_cal_settings', JSON.stringify({ startHour: 9, endHour: 17, step: 10, weekStart: 0, weekend: false, hour12: true })); } });
  const p3 = await ctx3.newPage(); p3.on('pageerror', e => problems.push(e.message));
  await login(p3, 'bob'); await p3.waitForFunction(() => window.RVM.ui && window.RVM.ui.stored === true, null, { timeout: 8000 });
  await p3.waitForTimeout(500);
  const bob = (await settings(p3)).ui;
  check(bob.start_hour === 9 && bob.end_hour === 17 && bob.step === 10 && bob.week_start === 0 && bob.weekend === false && bob.hour12 === true, 'anciens réglages du navigateur repris une seule fois sur le serveur');
  check(await p3.evaluate(() => localStorage.getItem('rvm_cal_settings')) === null, 'puis supprimés du navigateur');
  check(await p3.locator('#org-form').count() === 0 && await p3.goto(BASE + '/settings').then(() => p3.locator('#org-form').count()) === 0, 'un simple utilisateur ne voit pas les réglages d\'organisation');
  check((await p3.evaluate(async () => (await fetch('/api/v1/settings/global')).status)) === 403, 'API d\'organisation refusée (403)');

  // ---- organisation (administrateur) ----
  await p.waitForSelector('#org-form'); await p.waitForFunction(() => document.getElementById('org-tz').value !== '');
  await p.fill('#org-name', 'Cabinet e2e & Fils'); await p.click('#org-form button[type=submit]'); await p.waitForSelector('#org-msg.alert-ok');
  await p.reload(); await p.waitForSelector('.brand span');
  check((await p.textContent('.brand span')).includes('Cabinet e2e & Fils') && (await p.title()).includes('Cabinet e2e & Fils'), 'nom de l\'organisation dans l\'en-tête et le titre');
  const anon = await b.newContext(); const pa = await anon.newPage(); await pa.goto(BASE + '/login');
  check((await pa.textContent('.brand')).includes('Cabinet e2e & Fils'), 'nom de l\'organisation sur la page de connexion');

  await p.screenshot({ path: process.env.SHOT || '/tmp/settings.png', fullPage: true });
  check(problems.length === 0, 'aucune erreur console / CSP ' + JSON.stringify(problems));
  await b.close(); process.exit(failed ? 1 : 0);
})();
