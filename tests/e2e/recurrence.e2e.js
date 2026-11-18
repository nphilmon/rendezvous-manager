// Test navigateur des récurrences (Playwright). Mêmes prérequis que calendar.e2e.js (seed.php, install.lock, php -S avec DB_*, COOKIE_SECURE=false).
// Usage : NODE_PATH=$(npm root -g) node tests/e2e/recurrence.e2e.js
const { chromium } = require('playwright');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8099';
let failed = 0;
const check = (ok, msg) => { console.log((ok ? '  ok    ' : '  FAIL  ') + msg); if (!ok) failed++; };
const PX_H = 48;
const range = (p, a, b) => p.evaluate(async ([a, b]) => (await (await fetch('/api/v1/appointments?start=' + a + '&end=' + b)).json()).data, [a, b]);
const mine = async (p, title, a = '2026-11-01', b = '2027-01-30') => (await range(p, a, b)).filter(x => x.title === title);
async function pickScope(p, value) { await p.waitForSelector('.scope-dialog[open]'); await p.check('.scope-dialog input[value=' + value + ']'); await p.click('.scope-dialog >> text=OK'); }
async function week(p, date) { await p.goto(BASE + '/calendar?view=week&date=' + date); await p.waitForSelector('.tg-col'); await p.evaluate((h) => { document.querySelector('.tg-scroll').scrollTop = 8 * h; }, PX_H); await p.waitForTimeout(150); }

(async () => {
  const b = await chromium.launch(); const problems = [];
  const ctx = await b.newContext({ viewport: { width: 1280, height: 900 } }); const p = await ctx.newPage();
  p.on('console', m => { if (m.type() === 'error' && !/status of (401|409|422)/.test(m.text())) problems.push(m.text()); });
  p.on('pageerror', e => problems.push(e.message));
  p.on('dialog', d => d.accept());
  await p.goto(BASE + '/login'); await p.fill('#identifier', 'admin'); await p.fill('#password', 'Correct-Horse-9'); await p.click('#login-submit'); await p.waitForURL(BASE + '/');

  // 1. création d'une série hebdomadaire de 4 occurrences par le formulaire
  await week(p, '2026-11-18');
  await p.locator('.tg-body > .tg-col').nth(3).click({ position: { x: 30, y: 10 * 60 * 0.8 + 3 } }); await p.waitForSelector('.form-dialog[open]');
  await p.fill('#af-title-in', 'Point hebdo'); await p.selectOption('#af-rep', 'weekly'); await p.selectOption('.rep-end select', 'count'); await p.fill('.rep-end input[type=number]', '4');
  await p.click('.af-actions >> text=Enregistrer >> nth=0'); await p.waitForSelector('.form-dialog:not([open])', { state: 'attached' });
  await p.waitForSelector('.tg-col .ev:has-text("Point hebdo")');
  let occ = await mine(p, 'Point hebdo');
  check(occ.length === 4 && new Set(occ.map(o => o.id)).size === 1, 'série hebdomadaire : 4 occurrences pour une seule ligne en base');
  check(occ.map(o => o.start.slice(0, 10)).join() === '2026-11-19,2026-11-26,2026-12-03,2026-12-10', 'occurrences les jeudis 19/11, 26/11, 3/12, 10/12');
  check(await p.locator('.ev[data-recurring="1"]').count() >= 1, 'les occurrences sont marquées « série »');
  await p.click('.tg-col .ev:has-text("Point hebdo")'); await p.waitForSelector('dialog.ev-dialog[open]');
  await p.waitForFunction(() => /Toutes les semaines/.test(document.querySelector('dialog.ev-dialog').textContent));
  check(true, 'détails : « Toutes les semaines, 4 fois » décrit la règle'); await p.keyboard.press('Escape');

  // 2. modifier une seule occurrence (26/11) : titre et heure
  await week(p, '2026-11-25');
  await p.click('.tg-col .ev:has-text("Point hebdo")'); await p.click('.dlg-actions >> text=Modifier'); await pickScope(p, 'single');
  await p.waitForSelector('.form-dialog[open]');
  check(await p.locator('#af-rep').count() === 0, 'une seule occurrence : pas de section répétition');
  await p.fill('#af-title-in', 'Point exceptionnel'); await p.fill('#af-st', '14:00'); await p.click('.af-actions >> text=Enregistrer >> nth=0');
  await p.waitForSelector('.tg-col .ev:has-text("Point exceptionnel")');
  occ = await range(p, '2026-11-01', '2027-01-30'); const titles = occ.filter(o => /Point/.test(o.title)).map(o => o.title);
  check(titles.join() === 'Point hebdo,Point exceptionnel,Point hebdo,Point hebdo', 'seule l\'occurrence du 26/11 est modifiée');
  check((await mine(p, 'Point exceptionnel'))[0].start === '2026-11-26T13:00:00Z', 'nouvelle heure : 14:00 Paris');

  // 3. glisser l'occurrence du 3/12 vers le vendredi : portée « cet événement »
  await week(p, '2026-12-02');
  const blk = p.locator('.tg-col .ev:has-text("Point hebdo")'); const bb = await blk.boundingBox();
  const cw = (await p.locator('.tg-body > .tg-col').nth(4).boundingBox()).x - (await p.locator('.tg-body > .tg-col').nth(3).boundingBox()).x;
  await p.mouse.move(bb.x + bb.width / 2, bb.y + bb.height / 2); await p.mouse.down(); await p.mouse.move(bb.x + bb.width / 2 + cw / 2, bb.y + bb.height / 2, { steps: 5 }); await p.mouse.move(bb.x + bb.width / 2 + cw, bb.y + bb.height / 2, { steps: 5 }); await p.mouse.up();
  await pickScope(p, 'single'); await p.waitForTimeout(700);
  occ = await range(p, '2026-11-01', '2027-01-30');
  check(occ.filter(o => /Point/.test(o.title)).map(o => o.start.slice(0, 10)).join() === '2026-11-19,2026-11-26,2026-12-04,2026-12-10', 'glisser-déposer d\'une occurrence : 3/12 → 4/12, les autres inchangées');

  // 4. changer le titre de cette occurrence et des suivantes
  await week(p, '2026-12-09');
  await p.click('.tg-col .ev:has-text("Point hebdo")'); await p.click('.dlg-actions >> text=Modifier'); await pickScope(p, 'following');
  await p.waitForSelector('.form-dialog[open]'); check((await p.textContent('#af-title')).includes('et les suivants'), 'titre du formulaire : portée « et les suivants »');
  await p.fill('#af-title-in', 'Nouveau cycle'); await p.click('.af-actions >> text=Enregistrer >> nth=0'); await p.waitForSelector('.tg-col .ev:has-text("Nouveau cycle")');
  occ = await range(p, '2026-11-01', '2027-01-30');
  check(occ.filter(o => /Nouveau cycle/.test(o.title)).length === 1 && occ.filter(o => o.title === 'Point hebdo').length === 2, 'coupure de la série : 1 occurrence « Nouveau cycle », 2 « Point hebdo » restantes (4 − 1 exception − 1 nouvelle)');
  check(new Set(occ.filter(o => /Nouveau cycle/.test(o.title)).map(o => o.id)).size === 1 && occ.find(o => o.title === 'Nouveau cycle').id !== occ.find(o => o.title === 'Point hebdo').id, 'la suite devient une nouvelle série');

  // 5. supprimer « tous les événements » de la première série
  await week(p, '2026-11-18');
  await p.click('.tg-col .ev:has-text("Point hebdo")'); await p.click('.dlg-actions >> text=Supprimer'); await pickScope(p, 'all'); await p.waitForTimeout(700);
  check((await mine(p, 'Point hebdo')).length === 0 && (await mine(p, 'Point exceptionnel')).length === 0, 'suppression de toute la série : plus aucune occurrence (même modifiée)');
  check((await mine(p, 'Nouveau cycle')).length === 1, 'l\'autre série est intacte');

  // 6. règle personnalisée : toutes les 2 semaines le lundi et le mercredi, jusqu'à une date
  await week(p, '2026-11-18'); await p.keyboard.press('c'); await p.waitForSelector('.form-dialog[open]');
  await p.fill('#af-title-in', 'Cours'); await p.fill('#af-sd', '2026-11-16'); await p.fill('#af-st', '18:00'); await p.fill('#af-et', '19:00'); await p.fill('#af-ed', '2026-11-16');
  await p.selectOption('#af-rep', 'custom'); await p.selectOption('.rep-line select', 'weekly'); await p.fill('.rep-line input', '2');
  await p.check('.rep-days input[value=MO]'); await p.check('.rep-days input[value=WE]');
  await p.selectOption('.rep-end select', 'until'); await p.fill('.rep-end input[type=date]', '2026-12-31');
  await p.click('.af-actions >> text=Enregistrer >> nth=0'); await p.waitForSelector('.form-dialog:not([open])', { state: 'attached' }); await p.waitForTimeout(500);
  const cours = await mine(p, 'Cours', '2026-11-01', '2027-01-30');
  check(cours.map(o => o.start.slice(0, 10)).join() === '2026-11-16,2026-11-18,2026-11-30,2026-12-02,2026-12-14,2026-12-16,2026-12-28,2026-12-30', 'personnalisée : 1 semaine sur 2, lundi et mercredi, jusqu\'au 31/12 inclus');
  const full = await p.evaluate(async (id) => (await (await fetch('/api/v1/appointments/' + id)).json()).data, cours[0].id);
  check(full.recurrence.interval === 2 && full.recurrence.end.until === '2026-12-31', 'règle relue : intervalle 2, fin 2026-12-31');

  // 7. validation de la section répétition
  await p.keyboard.press('c'); await p.waitForSelector('.form-dialog[open]'); await p.fill('#af-title-in', 'Invalide');
  await p.selectOption('#af-rep', 'daily'); await p.selectOption('.rep-end select', 'count'); await p.fill('.rep-end input[type=number]', '0');
  await p.click('.af-actions >> text=Enregistrer >> nth=0'); await p.waitForSelector('#af-rep-err:not([hidden])');
  check((await p.textContent('#af-rep-err')).includes('Nombre'), 'nombre d\'occurrences invalide : erreur affichée'); await p.click('.af-actions >> text=Annuler');

  // 8. vue mois et liste « À venir »
  await p.goto(BASE + '/calendar?view=month&date=2026-12-01'); await p.waitForSelector('.mg-cell');
  check(await p.locator('.mg-cell .ev:has-text("Cours")').count() === 6, 'vue mois : 6 occurrences de « Cours » dans la grille de décembre (30/11, 2, 14, 16, 28 et 30)');
  await p.goto(BASE + '/appointments/upcoming'); await p.waitForSelector('#list-results .tbl');
  check(await p.locator('#list-results tbody tr:has-text("Cours")').count() === 1 && await p.locator('#list-results tbody tr:has-text("Cours") .rec').count() === 1, '« À venir » : une ligne par série avec sa prochaine occurrence et le repère ↻');

  check(problems.length === 0, 'aucune erreur console / CSP ' + JSON.stringify(problems));
  await b.close(); process.exit(failed ? 1 : 0);
})();
