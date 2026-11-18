// Test navigateur de la gestion des rendez-vous (Playwright). Mêmes prérequis que calendar.e2e.js
// (base *_test peuplée par tests/e2e/seed.php, storage/install.lock, serveur php -S avec COOKIE_SECURE=false et DB_*).
// Usage : NODE_PATH=$(npm root -g) node tests/e2e/appointments.e2e.js
const { chromium } = require('playwright');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8099';
let failed = 0;
const check = (ok, msg) => { console.log((ok ? '  ok    ' : '  FAIL  ') + msg); if (!ok) failed++; };

(async () => {
  const b = await chromium.launch(); const problems = [];
  const ctx = await b.newContext({ viewport: { width: 1280, height: 900 } }); const p = await ctx.newPage();
  p.on('console', m => { if (m.type() === 'error' && !/status of (401|409|422)/.test(m.text())) problems.push(m.text()); });
  p.on('pageerror', e => problems.push(e.message));
  p.on('dialog', d => d.accept());
  await p.goto(BASE + '/login'); await p.fill('#identifier', 'admin'); await p.fill('#password', 'Correct-Horse-9');
  await p.click('#login-submit'); await p.waitForURL(BASE + '/');

  await p.goto(BASE + '/calendar?view=week&date=2026-11-18'); await p.waitForSelector('.tg-col .ev');
  const thursday = p.locator('.tg-body > .tg-col').nth(3); // lun=0 … jeu=3

  // 1. création par clic sur un créneau (jeudi 10:00)
  await thursday.click({ position: { x: 30, y: 10 * 60 * 0.8 + 3 } });
  await p.waitForSelector('.form-dialog[open]');
  check(await p.inputValue('#af-sd') === '2026-11-19' && await p.inputValue('#af-st') === '10:00', 'clic : date et heure préremplies (19/11 10:00)');
  check(await p.inputValue('#af-et') === '11:00', 'clic : durée par défaut 1 h');
  // 2. validation : titre vide
  await p.click('.af-actions >> text=Enregistrer'); check(await p.locator('#af-title-in-err').isVisible(), 'titre vide : erreur affichée sous le champ');
  await p.fill('#af-title-in', 'Rendez-vous créé au clic'); await p.selectOption('#af-prio', 'high');
  await p.click('.af-actions >> text=Enregistrer >> nth=0'); await p.waitForSelector('.form-dialog:not([open])', { state: 'attached' });
  await p.waitForSelector('.tg-col .ev:has-text("Rendez-vous créé au clic")');
  check(true, 'création : le rendez-vous apparaît dans la grille sans rechargement');

  // 3. conflit horaire puis « Enregistrer quand même »
  await thursday.click({ position: { x: 30, y: 13 * 60 * 0.8 + 3 } }); await p.waitForSelector('.form-dialog[open]');
  await p.fill('#af-st', '10:30'); // la fin suit : durée conservée
  check(await p.inputValue('#af-et') === '11:30', 'changer le début conserve la durée');
  await p.fill('#af-title-in', 'Chevauche'); await p.click('.af-actions >> text=Enregistrer >> nth=0');
  await p.waitForSelector('#af-conflict:not([hidden])');
  check((await p.textContent('#af-conflict')).includes('Rendez-vous créé au clic'), 'conflit détecté et nommé');
  await p.click('#af-conflict >> text=Enregistrer quand même'); await p.waitForSelector('.form-dialog:not([open])', { state: 'attached' });
  await p.waitForSelector('.tg-col .ev:has-text("Chevauche")'); check(true, 'enregistrement forcé malgré le conflit');

  // 4. création par glissement (vendredi 14:00 → 16:00)
  const friday = p.locator('.tg-body > .tg-col').nth(4); const fb = await friday.boundingBox();
  await p.evaluate(() => document.querySelector('.tg-scroll').scrollTop = 600);
  const fb2 = await friday.boundingBox(); const scrollOff = fb.y - fb2.y;
  const y14 = fb2.y + 14 * 60 * 0.8 + 2, y16 = fb2.y + 16 * 60 * 0.8 - 2;
  await p.mouse.move(fb2.x + 40, y14); await p.mouse.down(); await p.mouse.move(fb2.x + 40, y14 + 40, { steps: 4 }); await p.mouse.move(fb2.x + 40, y16, { steps: 6 });
  check(await p.locator('.ghost').count() === 1, 'glissement : fantôme de sélection affiché');
  await p.mouse.up(); await p.waitForSelector('.form-dialog[open]');
  check(await p.inputValue('#af-st') === '14:00' && await p.inputValue('#af-et') === '16:00', 'glissement : 14:00 → 16:00 prérempli (' + await p.inputValue('#af-st') + ' → ' + await p.inputValue('#af-et') + ')');
  check(await p.locator('.form-dialog[open]').count() === 1, 'glissement : un seul formulaire ouvert');
  await p.fill('#af-title-in', 'Créé par sélection'); await p.click('.af-actions >> text=Enregistrer et nouveau');
  await p.waitForSelector('.tg-col .ev:has-text("Créé par sélection")');
  await p.waitForSelector('.form-dialog[open]'); check((await p.textContent('#af-title')).includes('Nouveau'), '« Enregistrer et nouveau » rouvre un formulaire vierge');
  check(await p.inputValue('#af-title-in') === '', 'formulaire vierge');
  await p.click('.af-actions >> text=Annuler'); await p.waitForSelector('.form-dialog:not([open])', { state: 'attached' });

  // 5. modification via les détails + conflit de version
  await p.click('.tg-col .ev:has-text("Créé par sélection")'); await p.waitForSelector('dialog.ev-dialog[open]');
  await p.click('.dlg-actions >> text=Modifier'); await p.waitForSelector('.form-dialog[open]');
  check((await p.textContent('#af-title')).includes('Modifier'), 'détails → Modifier ouvre le formulaire d\'édition');
  const id = await p.evaluate(async () => { const r = await fetch('/api/v1/appointments?q=' + encodeURIComponent('Créé par sélection')); return (await r.json()).data[0].id; });
  await p.evaluate(async (id) => { // un autre utilisateur modifie entre-temps
    const csrf = document.querySelector('meta[name=csrf-token]').content;
    await fetch('/api/v1/appointments/' + id, { method: 'PUT', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify({ version: 1, title: 'Modifié ailleurs' }) });
  }, id);
  await p.fill('#af-title-in', 'Ma version'); await p.click('.af-actions >> text=Enregistrer >> nth=0');
  await p.waitForSelector('#af-conflict:not([hidden])');
  check((await p.textContent('#af-conflict')).includes('Modifié par quelqu'), 'conflit de version signalé (verrou optimiste)');
  await p.click('#af-conflict >> text=Écraser avec mes modifications'); await p.waitForSelector('.form-dialog:not([open])', { state: 'attached' });
  await p.waitForSelector('.tg-col .ev:has-text("Ma version")'); check(true, 'écrasement explicite accepté avec la version à jour');

  // 6. report, annulation, terminaison
  await p.click('.tg-col .ev:has-text("Ma version")'); await p.waitForSelector('dialog.ev-dialog[open]');
  await p.click('.dlg-actions >> text=Reporter de 1 jour'); await p.waitForSelector('dialog.ev-dialog:not([open])', { state: 'attached' });
  await p.goto(BASE + '/calendar?view=week&date=2026-11-18'); await p.waitForSelector('.tg-col .ev');
  const sat = p.locator('.tg-body > .tg-col').nth(5);
  check(await sat.locator('.ev:has-text("Ma version")').count() === 1, 'report de 1 jour : le rendez-vous passe au samedi');
  await sat.locator('.ev:has-text("Ma version")').click(); await p.click('.dlg-actions >> text=Annuler le rendez-vous');
  await p.waitForSelector('.tg-col .ev.cancelled:has-text("Ma version")'); check(true, 'annulation : rendez-vous barré');

  // 7. suppression logique puis restauration depuis la corbeille
  await p.locator('.ev:has-text("Ma version")').click(); await p.click('.dlg-actions >> text=Supprimer');
  await p.locator('.tg-col .ev:has-text("Ma version")').waitFor({ state: 'detached' });
  check(await p.locator('.tg-col .ev:has-text("Ma version")').count() === 0, 'suppression : disparaît du calendrier');
  await p.goto(BASE + '/appointments'); await p.waitForSelector('#list-results .tbl');
  await p.check('input[name=trash]'); await p.waitForSelector('#list-results .tbl button.link:has-text("Ma version")');
  check(true, 'corbeille : le rendez-vous supprimé y figure');
  await p.click('button.link:has-text("Ma version")'); await p.click('.dlg-actions >> text=Restaurer');
  await p.waitForFunction(() => document.querySelector('#list-results .empty'));
  await p.uncheck('input[name=trash]'); await p.waitForSelector('button.link:has-text("Ma version")'); check(true, 'restauration : le rendez-vous est de retour');

  // 8. liste : recherche, filtre, tri, pagination
  await p.fill('#f-q', 'Chevauche'); await p.click('#filters >> text=Filtrer'); await p.waitForFunction(() => document.querySelectorAll('#list-results tbody tr').length === 1);
  check(await p.locator('#list-pager').textContent().then(t => t.includes('1 résultat')), 'recherche : 1 résultat');
  await p.click('#filters >> text=Réinitialiser'); await p.waitForFunction(() => document.querySelectorAll('#list-results tbody tr').length > 5);
  await p.selectOption('#f-status', 'tentative'); await p.waitForFunction(() => document.querySelectorAll('#list-results tbody tr').length === 1);
  check(true, 'filtre par statut');
  await p.goto(BASE + '/appointments/mine'); await p.waitForSelector('#list-results .tbl');
  check(await p.locator('#list-results tbody tr').count() >= 10, 'Mes rendez-vous : liste chargée');
  await p.goto(BASE + '/appointments/new'); await p.waitForSelector('.form-dialog[open]'); check(true, '/appointments/new ouvre le formulaire');
  await p.keyboard.press('Escape');
  await p.goto(BASE + '/calendar?view=week&date=2026-11-18'); await p.waitForSelector('.tg-col .ev');
  await p.keyboard.press('c'); await p.waitForSelector('.form-dialog[open]'); check(true, 'raccourci clavier « c » : nouveau rendez-vous sans souris');
  await p.keyboard.press('Escape');

  // 9. journée entière via la cellule « Journée »
  await p.locator('.allday-cell').nth(0).click({ position: { x: 10, y: 10 } }); await p.waitForSelector('.form-dialog[open]');
  check(await p.isChecked('#af-allday') && await p.isHidden('#af-st'), 'cellule journée entière : case cochée, heures masquées');
  await p.fill('#af-title-in', 'Férié'); await p.click('.af-actions >> text=Enregistrer >> nth=0');
  await p.waitForSelector('.allday-cell .ev:has-text("Férié")'); check(true, 'journée entière créée (date de fin inclusive côté formulaire)');

  // 10. lecture seule : aucune création
  const m = await b.newContext({ viewport: { width: 1280, height: 800 } }); const mp = await m.newPage();
  await mp.goto(BASE + '/login'); await mp.fill('#identifier', 'bob'); await mp.fill('#password', 'Correct-Horse-9'); await mp.click('#login-submit'); await mp.waitForURL(BASE + '/');
  await mp.goto(BASE + '/calendar?view=day&date=2026-11-18'); await mp.waitForSelector('.tg-col .ev');
  await mp.click('.tg-col .ev'); await mp.waitForSelector('dialog.ev-dialog[open]');
  check(await mp.locator('.dlg-actions .btn:has-text("Modifier")').count() === 1, 'Bob (propriétaire) peut modifier son rendez-vous');
  await mp.keyboard.press('Escape');
  await mp.goto(BASE + '/calendar?view=week&date=2026-11-18'); await mp.waitForSelector('.tg-col .ev');
  const before = await mp.locator('.tg-col .ev').count();
  check(before >= 1, 'Bob ne voit pas les rendez-vous des calendriers d\'autrui sauf partage');

  check(problems.length === 0, 'aucune erreur console / CSP ' + JSON.stringify(problems));
  await b.close(); process.exit(failed ? 1 : 0);
})();
