// Test navigateur du déplacement / redimensionnement (Playwright). Mêmes prérequis que calendar.e2e.js :
// base *_test peuplée par tests/e2e/seed.php, storage/install.lock, serveur php -S avec COOKIE_SECURE=false et DB_*.
// Usage : NODE_PATH=$(npm root -g) node tests/e2e/dragdrop.e2e.js
const { chromium } = require('playwright');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8099';
let failed = 0;
const check = (ok, msg) => { console.log((ok ? '  ok    ' : '  FAIL  ') + msg); if (!ok) failed++; };
const PX_H = 48; // 0,8 px/min (pas de 30 min) => 48 px par heure

async function login(page, user) {
  await page.goto(BASE + '/login'); await page.fill('#identifier', user); await page.fill('#password', 'Correct-Horse-9');
  await page.click('#login-submit'); await page.waitForURL(BASE + '/');
}
const appt = (page, title) => page.evaluate(async (t) => {
  const r = await fetch('/api/v1/appointments?q=' + encodeURIComponent(t)); return (await r.json()).data.find(a => a.title === t);
}, title);
async function drag(page, from, to, steps = 8) {
  await page.mouse.move(from.x, from.y); await page.mouse.down();
  await page.mouse.move((from.x + to.x) / 2, (from.y + to.y) / 2, { steps }); await page.mouse.move(to.x, to.y, { steps }); await page.mouse.up();
  await page.waitForTimeout(600); // laisse l'appel API et le rechargement de la grille se terminer
}
async function openWeek(page) {
  await page.goto(BASE + '/calendar?view=week&date=2026-11-18'); await page.waitForSelector('.tg-col .ev');
  await page.evaluate((h) => { document.querySelector('.tg-scroll').scrollTop = 8 * h; }, PX_H); await page.waitForTimeout(100);
}

(async () => {
  const b = await chromium.launch(); const problems = []; const dialogs = [];
  const ctx = await b.newContext({ viewport: { width: 1280, height: 900 } }); const p = await ctx.newPage();
  p.on('console', m => { if (m.type() === 'error' && !/status of (401|409|422)/.test(m.text())) problems.push(m.text()); });
  p.on('pageerror', e => problems.push(e.message));
  p.on('dialog', d => { dialogs.push(d.message()); d.accept(); });
  await login(p, 'admin');
  await openWeek(p);

  // 1. redimensionnement par le bord bas : Formation jeu. 14:00–17:00 → 18:00
  const f0 = await appt(p, 'Formation');
  let blk = p.locator('.tg-col .ev:has-text("Formation")'); let bb = await blk.boundingBox();
  check((await blk.locator('.rs-bot').count()) === 1 && (await blk.locator('.rs-top').count()) === 1, 'poignées haut et bas présentes');
  await drag(p, { x: bb.x + bb.width / 2, y: bb.y + bb.height - 3 }, { x: bb.x + bb.width / 2, y: bb.y + bb.height - 3 + PX_H });
  await p.waitForFunction(() => /18:00/.test([...document.querySelectorAll('.tg-col .ev')].find(e => e.textContent.includes('Formation'))?.querySelector('.tm')?.textContent || ''));
  const f1 = await appt(p, 'Formation');
  check(f1.start === f0.start && f1.end === '2026-11-19T17:00:00Z' && f1.version === f0.version + 1, 'bord bas : fin 18:00 Paris (17:00Z), début inchangé, version +1');

  // 2. redimensionnement par le bord haut : début 14:00 → 13:00
  blk = p.locator('.tg-col .ev:has-text("Formation")'); bb = await blk.boundingBox();
  await drag(p, { x: bb.x + bb.width / 2, y: bb.y + 3 }, { x: bb.x + bb.width / 2, y: bb.y + 3 - PX_H });
  await p.waitForFunction(() => /13:00/.test([...document.querySelectorAll('.tg-col .ev')].find(e => e.textContent.includes('Formation'))?.querySelector('.tm')?.textContent || ''));
  const f2 = await appt(p, 'Formation');
  check(f2.start === '2026-11-19T12:00:00Z' && f2.end === '2026-11-19T17:00:00Z', 'bord haut : début 13:00 Paris');

  // 3. durée minimale = un pas
  blk = p.locator('.tg-col .ev:has-text("Formation")'); bb = await blk.boundingBox();
  await drag(p, { x: bb.x + bb.width / 2, y: bb.y + bb.height - 3 }, { x: bb.x + bb.width / 2, y: bb.y - 400 });
  await p.waitForFunction(() => /13:30/.test([...document.querySelectorAll('.tg-col .ev')].find(e => e.textContent.includes('Formation'))?.querySelector('.tm')?.textContent || ''));
  const f3 = await appt(p, 'Formation');
  check(f3.end === '2026-11-19T12:30:00Z', 'réduction plafonnée à un pas (30 min)');

  // 4. déplacement vers un autre jour et une autre heure : jeu. 13:00 → ven. 16:00
  blk = p.locator('.tg-col .ev:has-text("Formation")'); bb = await blk.boundingBox();
  const colW = (await p.locator('.tg-body > .tg-col').nth(4).boundingBox()).x - (await p.locator('.tg-body > .tg-col').nth(3).boundingBox()).x;
  await drag(p, { x: bb.x + bb.width / 2, y: bb.y + bb.height / 2 }, { x: bb.x + bb.width / 2 + colW, y: bb.y + bb.height / 2 + 3 * PX_H });
  await p.waitForSelector('.tg-body > .tg-col:nth-child(6) .ev:has-text("Formation")');
  const f4 = await appt(p, 'Formation');
  check(f4.start === '2026-11-20T15:00:00Z' && f4.end === '2026-11-20T15:30:00Z', 'déplacement : vendredi 16:00 Paris, durée conservée');

  // 5. clic simple : détails (pas de déplacement)
  await p.click('.tg-col .ev:has-text("Formation")'); await p.waitForSelector('dialog.ev-dialog[open]'); await p.keyboard.press('Escape');
  check((await appt(p, 'Formation')).version === f4.version, 'un clic ouvre les détails sans modifier le rendez-vous');

  // 6. annulation par Échap : aucune modification
  blk = p.locator('.tg-col .ev:has-text("Formation")'); bb = await blk.boundingBox();
  await p.mouse.move(bb.x + bb.width / 2, bb.y + bb.height / 2); await p.mouse.down(); await p.mouse.move(bb.x + bb.width / 2, bb.y + bb.height / 2 + 120, { steps: 6 });
  check(await p.locator('.drag-ghost').count() === 1, 'fantôme de déplacement visible pendant le glissement');
  await p.keyboard.press('Escape'); await p.mouse.up(); await p.waitForTimeout(150);
  check(await p.locator('.drag-ghost').count() === 0 && (await appt(p, 'Formation')).version === f4.version, 'Échap annule le déplacement');

  // 7. déplacement dans la même colonne (régression : le « click » suivant ne doit pas ouvrir la création)
  await p.evaluate((h) => { document.querySelector('.tg-scroll').scrollTop = 12 * h; }, PX_H); await p.waitForTimeout(150);
  blk = p.locator('.tg-col .ev:has-text("Formation")'); bb = await blk.boundingBox();
  dialogs.length = 0;
  await drag(p, { x: bb.x + bb.width / 2, y: bb.y + bb.height / 2 }, { x: bb.x + bb.width / 2, y: bb.y + bb.height / 2 + 6 * PX_H }); // 16:00 → 22:00 : bloc de 30 min, pas de chevauchement
  await p.waitForFunction(() => /22:00/.test([...document.querySelectorAll('.tg-col .ev')].find(e => e.textContent.includes('Formation'))?.querySelector('.tm')?.textContent || ''));
  blk = p.locator('.tg-col .ev:has-text("Formation")'); bb = await blk.boundingBox();
  await drag(p, { x: bb.x + bb.width / 2, y: bb.y + bb.height / 2 }, { x: bb.x + bb.width / 2, y: bb.y + bb.height / 2 + PX_H }); // 23:00 : chevauche 22:30–23:30
  await p.waitForFunction(() => /23:00/.test([...document.querySelectorAll('.tg-col .ev')].find(e => e.textContent.includes('Formation'))?.querySelector('.tm')?.textContent || ''));
  check(await p.locator('.form-dialog[open]').count() === 0, 'aucun formulaire de création ouvert après un glissement');
  check((await appt(p, 'Formation')).start === '2026-11-20T22:00:00Z', 'déplacement dans la même colonne : 23:00 Paris, sans ouvrir le formulaire de création');

  // 8. clavier : Alt+↓ déplace d'un pas, Alt+Maj+↓ étend la fin, Alt+→ passe au lendemain
  await openWeek(p);
  const a0 = await appt(p, 'Appel client'); // mer. 09:30–10:00 Paris
  await p.focus('.tg-col .ev:has-text("Appel client")'); dialogs.length = 0;
  await p.keyboard.press('Alt+ArrowDown'); await p.waitForTimeout(600);
  check(dialogs.some(m => m.includes('Conflit horaire')), 'conflit horaire : confirmation demandée puis enregistrement forcé');
  const a1 = await appt(p, 'Appel client');
  check(a1.start === '2026-11-18T09:00:00Z' && a1.end === '2026-11-18T09:30:00Z', 'clavier : Alt+↓ décale d\'un pas (30 min)');
  await p.focus('.tg-col .ev:has-text("Appel client")'); await p.keyboard.press('Alt+Shift+ArrowDown'); await p.waitForTimeout(600);
  const a2 = await appt(p, 'Appel client');
  check(a2.start === a1.start && a2.end === '2026-11-18T10:00:00Z', 'clavier : Alt+Maj+↓ prolonge la fin d\'un pas');
  await p.focus('.tg-col .ev:has-text("Appel client")'); await p.keyboard.press('Alt+ArrowRight'); await p.waitForTimeout(600);
  check((await appt(p, 'Appel client')).start === '2026-11-19T09:00:00Z', 'clavier : Alt+→ passe au jour suivant');

  // 9. journée entière : chip glissé de deux jours (jeu.–ven. → sam.–dim.)
  await openWeek(p);
  const s0 = await appt(p, 'Séminaire');
  const chip = p.locator('.allday-cell .ev:has-text("Séminaire")').first(); const cb = await chip.boundingBox();
  const dw = (await p.locator('.allday-cell').nth(1).boundingBox()).x - (await p.locator('.allday-cell').nth(0).boundingBox()).x;
  await drag(p, { x: cb.x + 20, y: cb.y + cb.height / 2 }, { x: cb.x + 20 + 2 * dw, y: cb.y + cb.height / 2 });
  await p.waitForFunction(() => document.querySelectorAll('.allday-cell .ev').length === 2);
  const s1 = await appt(p, 'Séminaire');
  check(s1.start === '2026-11-21T00:00:00Z' && s1.end === '2026-11-23T00:00:00Z' && s0.all_day && s1.all_day, 'journée entière : décalée de 2 jours, reste « journée entière »');

  // 10. vue mois : déplacement vers un autre jour en gardant l'heure
  await p.goto(BASE + '/calendar?view=month&date=2026-11-18'); await p.waitForSelector('.mg-cell .ev');
  const m0 = await appt(p, 'Mensuel A');
  const line = p.locator('.mg-cell .ev:has-text("Mensuel A")'); const lb = await line.boundingBox();
  const nextCell = await p.locator('.mg-cell[data-day]').nth(0).boundingBox(); const cellW = (await p.locator('.mg-cell').nth(1).boundingBox()).x - nextCell.x;
  await drag(p, { x: lb.x + 30, y: lb.y + lb.height / 2 }, { x: lb.x + 30 + cellW, y: lb.y + lb.height / 2 });
  await p.waitForFunction(() => { const c = [...document.querySelectorAll('.mg-cell')].find(x => x.querySelector('.ev') && /Mensuel A/.test(x.textContent)); return c && c.dataset.day !== undefined; });
  await p.waitForTimeout(400);
  const m1 = await appt(p, 'Mensuel A');
  check(m1.start === '2026-11-26T09:00:00Z' && m1.end === '2026-11-26T10:00:00Z' && m0.start === '2026-11-25T09:00:00Z', 'mois : déplacé au 26/11, même heure');

  // 11. droits : sans droit de modification, aucune poignée ni glissement (Bob lit « Équipe »)
  const bob = await b.newContext({ viewport: { width: 1280, height: 900 } }); const bp = await bob.newPage(); bp.on('dialog', d => d.accept());
  await login(bp, 'bob'); await openWeek(bp);
  const ro = bp.locator('.tg-col .ev:has-text("Formation")');
  check(await ro.count() === 1 && !(await ro.evaluate(e => e.classList.contains('editable'))) && await ro.locator('.rs').count() === 0, 'calendrier en lecture seule : rendez-vous non déplaçable, sans poignées');
  const bb2 = await ro.boundingBox(); const v0 = (await appt(bp, 'Formation')).version;
  await drag(bp, { x: bb2.x + 20, y: bb2.y + 4 }, { x: bb2.x + 20, y: bb2.y + 100 }); await bp.waitForTimeout(300);
  check((await appt(bp, 'Formation')).version === v0, 'glisser sans droit ne modifie rien');

  // 12. toucher : appui long puis glissement (Chromium, événements tactiles réels via CDP)
  const mob = await b.newContext({ viewport: { width: 390, height: 800 }, isMobile: true, hasTouch: true }); const mp = await mob.newPage(); mp.on('dialog', d => d.accept());
  await login(mp, 'admin'); await mp.goto(BASE + '/calendar?view=day&date=2026-11-18'); await mp.waitForSelector('.tg-col .ev');
  await mp.evaluate((h) => { document.querySelector('.tg-scroll').scrollTop = 7 * h; }, PX_H); await mp.waitForTimeout(200);
  const t0 = await appt(mp, 'Réunion équipe'); // 09:00–10:30 Paris
  const tb = await mp.locator('.tg-col .ev:has-text("Réunion équipe")').first().boundingBox();
  const cdp = await mp.context().newCDPSession(mp); const cx = tb.x + tb.width / 2, cy = tb.y + 20;
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: cx, y: cy }] });
  await mp.waitForTimeout(700);
  for (let i = 1; i <= 8; i++) { await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: cx, y: cy + i * (PX_H / 8) }] }); await mp.waitForTimeout(30); }
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
  await mp.waitForTimeout(800);
  const t1 = await appt(mp, 'Réunion équipe');
  check(t1.start === '2026-11-18T09:00:00Z' && t0.start === '2026-11-18T08:00:00Z', 'toucher : appui long + glissement décale d\'une heure (10:00 Paris)');
  const sx = await appt(mp, 'Annulé'); const ab = await mp.locator('.tg-col .ev:has-text("Réunion équipe")').first().boundingBox();
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: ab.x + ab.width / 2, y: ab.y + 20 }] });
  for (let i = 1; i <= 6; i++) { await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: ab.x + ab.width / 2, y: ab.y + 20 + i * 20 }] }); await mp.waitForTimeout(20); }
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] }); await mp.waitForTimeout(400);
  check((await appt(mp, 'Réunion équipe')).version === t1.version, 'toucher : un glissement sans appui long fait défiler la page sans déplacer');

  check(problems.length === 0, 'aucune erreur console / CSP ' + JSON.stringify(problems));
  await b.close(); process.exit(failed ? 1 : 0);
})();
