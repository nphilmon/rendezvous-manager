// Test navigateur des contacts, catégories et calendriers (Playwright). Mêmes prérequis que calendar.e2e.js
// (seed.php, storage/install.lock, php -S avec DB_* et COOKIE_SECURE=false). Usage : NODE_PATH=$(npm root -g) node tests/e2e/manage.e2e.js
const { chromium } = require('playwright');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8099';
let failed = 0;
const check = (ok, msg) => { console.log((ok ? '  ok    ' : '  FAIL  ') + msg); if (!ok) failed++; };
const api = (p, path) => p.evaluate(async (u) => (await (await fetch(u)).json()), path);
async function login(page, user) {
  await page.goto(BASE + '/login'); await page.fill('#identifier', user); await page.fill('#password', 'Correct-Horse-9');
  await page.click('#login-submit'); await page.waitForURL(BASE + '/');
}
const closed = (p, sel) => p.waitForFunction((q) => !document.querySelector(q + '[open]'), sel);

(async () => {
  const b = await chromium.launch(); const problems = [];
  const ctx = await b.newContext({ viewport: { width: 1280, height: 900 } }); const p = await ctx.newPage();
  p.on('console', m => { if (m.type() === 'error' && !/status of (401|409|422)/.test(m.text())) problems.push(m.text()); });
  p.on('pageerror', e => problems.push(e.message));
  p.on('dialog', d => d.accept());
  await login(p, 'admin');

  // ---- contacts ----
  await p.goto(BASE + '/contacts'); await p.waitForSelector('#manage-results .empty');
  check((await p.textContent('#manage-results')).includes('Aucun contact'), 'contacts : message de liste vide');
  await p.click('text=Nouveau contact'); await p.waitForSelector('.manage-dialog[open]');
  await p.fill('#mg-f-1', 'Marie'); await p.fill('#mg-f-4', 'pas-un-email'); await p.click('.manage-dialog .af-actions >> text=Enregistrer');
  check(await p.locator('#mg-f-0-err').isVisible(), 'nom requis : erreur sous le champ');
  await p.fill('#mg-f-0', 'Dupont'); await p.click('.manage-dialog .af-actions >> text=Enregistrer'); await p.waitForSelector('#mg-f-4-err:not([hidden])');
  check((await p.textContent('#mg-f-4-err')).includes('e-mail'), 'e-mail invalide : erreur du serveur sous le champ'); await p.fill('#mg-f-2', 'ACME'); await p.fill('#mg-f-3', '+33 1 23 45 67 89'); await p.fill('#mg-f-4', 'marie@example.org'); await p.fill('#mg-f-6', 'Cliente VIP');
  await p.click('.manage-dialog .af-actions >> text=Enregistrer'); await p.waitForSelector('#manage-results .tbl');
  check((await p.textContent('#manage-results')).includes('Marie Dupont') && (await p.textContent('#manage-results')).includes('ACME'), 'contact créé et listé');
  await p.fill('.search-inline input', 'zzz'); await p.click('.search-inline >> text=Rechercher'); await p.waitForSelector('#manage-results .empty');
  await p.fill('.search-inline input', 'acme'); await p.click('.search-inline >> text=Rechercher'); await p.waitForSelector('#manage-results .tbl');
  check(true, 'recherche : sans résultat puis trouvé par société');
  await p.click('button[aria-label="Modifier : Marie Dupont"]'); await p.waitForSelector('.manage-dialog[open]');
  await p.fill('#mg-f-2', 'Globex'); await p.click('.manage-dialog .af-actions >> text=Enregistrer'); await p.waitForSelector('#manage-results .empty');
  await p.fill('.search-inline input', ''); await p.click('.search-inline >> text=Rechercher'); await p.waitForSelector('#manage-results td:has-text("Globex")');
  check(true, 'contact modifié');

  // rendez-vous lié via « Nouveau rendez-vous » depuis la fiche
  await p.click('button.link:has-text("Marie Dupont")'); await p.waitForSelector('dialog.ev-dialog[open]');
  await p.waitForSelector('.linked:has-text("Aucun rendez-vous lié")'); check(true, 'fiche : aucun rendez-vous lié au départ');
  await p.click('dialog.ev-dialog >> text=Nouveau rendez-vous'); await p.waitForSelector('.form-dialog[open] #af-con');
  check((await p.locator('#af-con option:checked').textContent()).includes('Marie Dupont'), 'le contact est présélectionné dans le formulaire');
  await p.fill('#af-title-in', 'Rendez-vous Marie'); await p.click('.af-actions >> text=Enregistrer >> nth=0'); await closed(p, '.form-dialog');
  await p.click('button.link:has-text("Marie Dupont")'); await p.waitForSelector('.linked .link-row');
  check((await p.textContent('.linked')).includes('Rendez-vous Marie'), 'fiche : le rendez-vous apparaît dans « Rendez-vous liés »');
  await p.keyboard.press('Escape');

  // ---- catégories ----
  await p.goto(BASE + '/categories'); await p.waitForSelector('#manage-results .tbl');
  await p.click('text=Nouvelle catégorie'); await p.waitForSelector('.manage-dialog[open]'); await p.fill('#mg-f-0', 'Prestataire'); await p.fill('#mg-f-1', '#0891b2');
  await p.click('.manage-dialog .af-actions >> text=Enregistrer'); await p.waitForSelector('#manage-results td:has-text("Prestataire")');
  await p.click('text=Nouvelle catégorie'); await p.fill('#mg-f-0', 'prestataire'); await p.click('.manage-dialog .af-actions >> text=Enregistrer'); await p.waitForSelector('#mg-f-0-err:not([hidden])');
  check((await p.textContent('#mg-f-0-err')).includes('déjà'), 'nom de catégorie unique (insensible à la casse)'); await p.click('.manage-dialog .af-actions >> text=Annuler');
  await p.goto(BASE + '/appointments'); await p.waitForSelector('#list-results .tbl'); await p.click('button.link:has-text("Rendez-vous Marie")'); await p.click('dialog.ev-dialog >> text=Modifier');
  await p.waitForSelector('.form-dialog[open]'); check((await p.locator('#af-cat option').allTextContents()).includes('Prestataire'), 'la catégorie apparaît dans le formulaire de rendez-vous');
  await p.selectOption('#af-cat', { label: 'Prestataire' }); await p.click('.af-actions >> text=Enregistrer >> nth=0'); await closed(p, '.form-dialog');
  await p.goto(BASE + '/categories'); await p.waitForSelector('#manage-results .tbl');
  check((await p.locator('#manage-results tr:has-text("Prestataire")').textContent()).includes('1'), 'décompte des rendez-vous de la catégorie');
  await p.click('button[aria-label="Supprimer : Prestataire"]'); await p.waitForFunction(() => !document.getElementById('manage-results').textContent.includes('Prestataire'));
  const rv = await api(p, '/api/v1/appointments?q=Marie'); check(rv.data[0].category === null, 'suppression de la catégorie : rendez-vous détaché');

  // ---- calendriers et partage ----
  await p.goto(BASE + '/calendars'); await p.waitForSelector('#manage-results .tbl');
  await p.click('text=Nouveau calendrier'); await p.waitForSelector('.manage-dialog[open]');
  await p.fill('#mg-f-0', 'Projet'); await p.selectOption('#mg-f-3', 'team'); await p.selectOption('#mg-f-4', 'shared'); await p.fill('#mg-f-2', '#f97316');
  await p.click('.manage-dialog .af-actions >> text=Enregistrer'); await p.waitForSelector('#manage-results td:has-text("Projet")');
  check((await p.locator('#manage-results tr:has-text("Projet")').textContent()).includes('Équipe') && (await p.locator('#manage-results tr:has-text("Projet")').textContent()).includes('Partagé'), 'calendrier créé (type Équipe, visibilité Partagé)');
  await p.click('button[aria-label="Partager : Projet"]'); await p.waitForSelector('.manage-dialog[open] #sh-q');
  await p.fill('#sh-q', 'bo'); await p.waitForSelector('.lookup .link-row'); await p.click('.lookup .link-row:has-text("bob")');
  await p.check('.share-tbl input[aria-label="Créer — Bob Martin"]'); await p.click('.manage-dialog .af-actions >> text=Enregistrer'); await closed(p, '.manage-dialog');
  const perms = await api(p, '/api/v1/calendars/' + (await api(p, '/api/v1/calendars')).data.find(c => c.name === 'Projet').id + '/permissions');
  check(perms.data.length === 1 && perms.data[0].can_read && perms.data[0].can_create && !perms.data[0].can_update, 'partage enregistré : Bob lit et crée');
  await p.click('button[aria-label="Modifier : Projet"]'); await p.waitForSelector('.manage-dialog[open]'); await p.fill('#mg-f-0', 'Projet Alpha'); await p.click('.manage-dialog .af-actions >> text=Enregistrer');
  await p.waitForSelector('#manage-results td:has-text("Projet Alpha")'); check(true, 'calendrier renommé');

  // ---- côté Bob ----
  const bob = await b.newContext({ viewport: { width: 1280, height: 900 } }); const bp = await bob.newPage(); bp.on('dialog', d => d.accept());
  bp.on('console', m => { if (m.type() === 'error' && !/status of (401|403|409|422)/.test(m.text())) problems.push(m.text()); });
  await login(bp, 'bob');
  await bp.goto(BASE + '/calendars'); await bp.waitForSelector('#manage-results .tbl');
  const row = bp.locator('#manage-results tr:has-text("Projet Alpha")');
  check(await row.count() === 1 && await row.locator('button').count() === 0, 'Bob voit le calendrier partagé sans bouton de gestion');
  check(await bp.locator('text=Nouveau calendrier').count() === 1, 'Bob (utilisateur) peut créer ses propres calendriers');
  await bp.goto(BASE + '/categories'); await bp.waitForSelector('#manage-results .tbl, #manage-results .empty');
  check(await bp.locator('text=Nouvelle catégorie').count() === 0, 'Bob ne gère pas les catégories');
  await bp.goto(BASE + '/calendar?view=week&date=2026-11-18'); await bp.waitForSelector('.cal-item');
  check((await bp.locator('.cal-item').allTextContents()).some(t => t.includes('Projet Alpha')), 'le calendrier partagé apparaît dans la barre latérale de Bob');
  await bp.keyboard.press('c'); await bp.waitForSelector('.form-dialog[open]');
  check((await bp.locator('#af-cal option').allTextContents()).some(t => t.includes('Projet Alpha')), 'Bob peut y créer un rendez-vous (droit « Créer »)');
  await bp.keyboard.press('Escape');
  const denied = await bp.evaluate(async (id) => { const csrf = document.querySelector('meta[name=csrf-token]').content; const r = await fetch('/api/v1/calendars/' + id, { method: 'DELETE', headers: { 'X-CSRF-Token': csrf } }); return r.status; },
    (await api(bp, '/api/v1/calendars')).data.find(c => c.name === 'Projet Alpha').id);
  check(denied === 403, 'Bob ne peut pas supprimer le calendrier (403)');

  // ---- suppression du calendrier par le propriétaire ----
  await p.goto(BASE + '/calendars'); await p.waitForSelector('#manage-results .tbl'); await p.click('button[aria-label="Supprimer : Projet Alpha"]');
  await p.waitForFunction(() => !document.getElementById('manage-results').textContent.includes('Projet Alpha'));
  check(true, 'calendrier supprimé');

  // ---- contact supprimé ----
  await p.goto(BASE + '/contacts'); await p.waitForSelector('#manage-results .tbl'); await p.click('button[aria-label="Supprimer : Marie Dupont"]'); await p.waitForSelector('#manage-results .empty');
  check(true, 'contact supprimé');

  check(problems.length === 0, 'aucune erreur console / CSP ' + JSON.stringify(problems));
  await b.close(); process.exit(failed ? 1 : 0);
})();
