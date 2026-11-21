// Test navigateur du calendrier (Playwright). Prérequis : base *_test peuplée par tests/e2e/seed.php,
// storage/install.lock présent, serveur lancé (php -S 127.0.0.1:8099 -t public public/index.php) avec
// COOKIE_SECURE=false et les variables DB_*. Usage : NODE_PATH=$(npm root -g) node tests/e2e/calendar.e2e.js
const { chromium } = require('playwright');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8099';
let failed = 0;
const check = (ok, msg) => { console.log((ok ? '  ok    ' : '  FAIL  ') + msg); if (!ok) failed++; };

async function login(page, user) {
  await page.goto(BASE + '/login'); await page.fill('#identifier', user); await page.fill('#password', 'Correct-Horse-9');
  await page.click('#login-submit'); await page.waitForURL(BASE + '/');
}

(async () => {
  const b = await chromium.launch(); const problems = [];
  const ctx = await b.newContext({ viewport: { width: 1280, height: 800 } }); const p = await ctx.newPage();
  p.on('console', m => { if (m.type() === 'error' && !/status of (401|422)/.test(m.text())) problems.push(m.text()); });
  p.on('pageerror', e => problems.push(e.message));
  await login(p, 'admin');

  await p.goto(BASE + '/calendar?view=week&date=2026-11-18'); await p.waitForSelector('.tg-col .ev');
  check((await p.$$('.day-head')).length === 7, 'semaine : 7 colonnes');
  check((await p.$$('.tg-col .ev')).length === 7, 'semaine : 7 rendez-vous horaires positionnés');
  const bars = await p.$$eval('.tg-allday .ev.bar', e => e.map(x => x.getBoundingClientRect().width)); const cw = await p.$eval('.allday-cell', x => x.getBoundingClientRect().width);
  check(bars.length === 1 && bars[0] > 1.8 * cw, 'semaine : journée entière sur 2 jours = une seule barre continue');
  const boxes = await p.$$eval('.tg-row.tg-body > .tg-col:nth-child(4) .ev.blk', e => e.map(x => x.style.left));
  check(new Set(boxes).size >= 3, 'chevauchement : 3 colonnes distinctes le mercredi');
  check(await p.locator('.ev.cancelled').count() === 1, 'rendez-vous annulé barré');

  await p.click('.tg-col .ev >> text=Réunion équipe'); await p.waitForSelector('dialog[open]');
  check((await p.textContent('dialog')).includes('09:00 – 10:30'), 'détails : horaire converti en Europe/Paris');
  await p.keyboard.press('Escape');
  check(await p.evaluate(() => document.activeElement.classList.contains('ev')), 'focus rendu au rendez-vous après fermeture');

  await p.keyboard.press('m'); await p.waitForSelector('.mg-cell');
  check(p.url().includes('view=month'), 'raccourci m : vue mois');
  check((await p.$$('.mg-cell')).length === 42, 'mois : 6 semaines pour novembre 2026 (commence un dimanche)');
  check((await p.textContent('.more')).includes('autre'), 'mois : lien « + X autres »');
  const mb = await p.$$eval('.mg-bars .ev.bar', e => e.map(x => x.getBoundingClientRect().width)); const mw = await p.$eval('.mg-cell', x => x.getBoundingClientRect().width);
  check(mb.length === 1 && mb[0] > 1.8 * mw, 'mois : journée entière sur 2 jours = une seule barre continue');
  await p.click('.more >> nth=0'); await p.waitForSelector('.tg-col');
  check(p.url().includes('view=day'), '« + X autres » ouvre la vue jour');

  await p.keyboard.press('a'); await p.waitForSelector('.ag-day');
  check((await p.$$eval('.ag-day h3', e => e.length)) === 4, 'planning : 4 jours regroupés');

  await p.goto(BASE + '/calendar?view=week&date=2026-11-18'); await p.waitForSelector('.tg-col .ev');
  await p.click('.cal-item >> text=Équipe');
  check((await p.$$('.tg-col .ev')).length === 6, 'masquer un calendrier retire ses rendez-vous');
  await p.click('.cal-item >> text=Équipe');

  await p.click('text=Réglages');
  await p.selectOption('#cal-settings label:has-text("Début") select', '8'); await p.selectOption('#cal-settings label:has-text("Fin") select', '18');
  await p.waitForFunction(() => document.getElementById('cal-hidden').textContent.length > 0);
  check((await p.textContent('#cal-hidden')).includes('non affiché'), 'plage horaire réduite : rendez-vous masqués signalés');
  await p.uncheck('#cal-settings input[type=checkbox]');
  await p.waitForFunction(() => document.querySelectorAll('.day-head').length === 5);
  check(true, 'week-end masqué : 5 colonnes');

  const m = await b.newContext({ viewport: { width: 390, height: 800 }, isMobile: true, hasTouch: true }); const mp = await m.newPage();
  await login(mp, 'bob');
  await mp.goto(BASE + '/calendar?view=day&date=2026-11-18'); await mp.waitForSelector('.tg-col .ev');
  check(JSON.stringify(await mp.$$eval('.ev .tt', e => e.map(x => x.textContent))) === '["SECRET de Bob"]', 'Bob ne voit que ses propres calendriers');
  check(!(await mp.evaluate(() => document.documentElement.scrollWidth > innerWidth)), 'mobile : pas de débordement horizontal');

  check(problems.length === 0, 'aucune erreur console / CSP ' + JSON.stringify(problems));
  await b.close(); process.exit(failed ? 1 : 0);
})();
