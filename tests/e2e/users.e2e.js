// Test navigateur de l'administration des utilisateurs (Playwright). Mêmes prérequis que calendar.e2e.js
// (base *_test peuplée par tests/e2e/seed.php, storage/install.lock, php -S avec DB_* et COOKIE_SECURE=false).
// Usage : NODE_PATH=$(npm root -g) node tests/e2e/users.e2e.js
const { chromium } = require('playwright');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8099';
let failed = 0;
const check = (ok, msg) => { console.log((ok ? '  ok    ' : '  FAIL  ') + msg); if (!ok) failed++; };
async function login(page, user, pw = 'Correct-Horse-9') {
  await page.goto(BASE + '/login'); await page.fill('#identifier', user); await page.fill('#password', pw); await page.click('#login-submit');
}
const closed = (p, sel) => p.waitForFunction((q) => !document.querySelector(q + '[open]'), sel);

(async () => {
  const b = await chromium.launch(); const problems = [];
  const ctx = await b.newContext({ viewport: { width: 1280, height: 900 } }); const p = await ctx.newPage();
  p.on('console', m => { if (m.type() === 'error' && !/status of (401|403|409|422)/.test(m.text())) problems.push(m.text()); });
  p.on('pageerror', e => problems.push(e.message));
  p.on('dialog', d => d.accept());
  await login(p, 'admin'); await p.waitForURL(BASE + '/');
  check(await p.locator('nav a:has-text("Utilisateurs")').count() === 1, 'entrée « Utilisateurs » visible pour l\'administrateur');
  await p.goto(BASE + '/users'); await p.waitForSelector('#users-results .tbl');
  check(await p.locator('#users-results tbody tr').count() >= 2, 'liste des comptes chargée');
  check(await p.locator('#users-results tr:has-text("admin") button:has-text("Désactiver")').count() === 0, 'pas de bouton « Désactiver » sur son propre compte');
  check((await p.textContent('#users-roles summary')).includes('Rôles'), 'référentiel des rôles présent');

  // création : erreurs par champ, puis succès avec mot de passe provisoire unique
  await p.click('#u-new'); await p.waitForSelector('.manage-dialog[open]');
  await p.fill('#us-f-0', 'bob'); await p.fill('#us-f-1', 'Carole'); await p.fill('#us-f-2', 'Test'); await p.fill('#us-f-3', 'carole@example.org');
  await p.click('.manage-dialog .af-actions >> text=Enregistrer'); await p.waitForSelector('#us-f-0-err:not([hidden])');
  check((await p.textContent('#us-f-0-err')).includes('déjà utilisé'), 'identifiant déjà pris : erreur sous le champ');
  await p.fill('#us-f-0', 'carole'); await p.selectOption('#us-f-4', 'user');
  await p.click('.manage-dialog .af-actions >> text=Enregistrer'); await p.waitForSelector('.secret');
  const pw = (await p.textContent('.secret')).trim();
  check(pw.length === 16, 'mot de passe provisoire affiché (16 caractères)');
  await p.click('.manage-dialog .af-actions >> text=Fermer'); await p.waitForSelector('.secret', { state: 'detached' });
  await p.waitForSelector('.secret', { state: 'detached' });
  check(!(await p.content()).includes(pw), 'le mot de passe ne reste pas dans la page');
  await p.waitForSelector('#users-results tr:has-text("carole")');
  check((await p.textContent('#users-results tr:has-text("carole")')).includes('Mot de passe à changer'), 'étiquette « mot de passe à changer »');

  // première connexion de Carole : redirection forcée vers Paramètres, puis accès normal
  const cc = await b.newContext({ viewport: { width: 1280, height: 900 } }); const cp = await cc.newPage();
  cp.on('pageerror', e => problems.push(e.message));
  await login(cp, 'carole', pw); await cp.waitForURL(/\/$|\/settings/);
  await cp.goto(BASE + '/calendar'); await cp.waitForURL(/\/settings\?must_change=1/);
  check((await cp.textContent('.alert-error')).includes('nouveau'), 'changement de mot de passe imposé (redirection depuis /calendar)');
  check((await cp.evaluate(async () => (await fetch('/api/v1/calendars')).status)) === 403, 'API bloquée tant que le mot de passe n\'est pas changé');
  await cp.fill('#current_password', pw); await cp.fill('#new_password', 'Nouveau-Secret-42'); await cp.fill('#confirm_password', 'Nouveau-Secret-42'); await cp.click('#password-form button[type=submit]');
  await cp.waitForURL(BASE + '/'); check(true, 'après changement : retour au tableau de bord');
  check(await cp.locator('nav a:has-text("Utilisateurs")').count() === 0, 'un simple utilisateur ne voit pas l\'administration');
  check((await cp.goto(BASE + '/users')).status() === 403, '/users refusé (403) à un simple utilisateur');

  // modification, désactivation (session de Carole fermée), réactivation, réinitialisation
  await p.reload(); await p.waitForSelector('#users-results .tbl');
  await p.click('button[aria-label="Modifier : carole"]'); await p.waitForSelector('.manage-dialog[open]');
  check(await p.locator('#us-f-0').isDisabled(), 'identifiant non modifiable');
  await p.selectOption('#us-f-4', 'readonly'); await p.fill('#us-f-1', 'Caroline'); await p.click('.manage-dialog .af-actions >> text=Enregistrer'); await closed(p, '.manage-dialog');
  await p.waitForSelector('#users-results tr:has-text("Caroline")'); check((await p.textContent('#users-results tr:has-text("carole")')).includes('Lecture seule'), 'rôle et prénom modifiés');
  check((await cp.evaluate(async () => (await fetch('/api/v1/auth/me')).status)) === 401, 'changement de rôle : la session de Carole est fermée');
  await p.click('button[aria-label="Désactiver : carole"]'); await p.waitForSelector('#users-results tr:has-text("carole") .badge-s.off');
  await login(cp, 'carole', 'Nouveau-Secret-42'); await cp.waitForSelector('#login-error:not([hidden])'); check(true, 'compte désactivé : connexion refusée');
  await p.click('button[aria-label="Activer : carole"]'); await p.waitForSelector('#users-results tr:has-text("carole") .badge-s.ok');
  await p.click('button[aria-label="Mot de passe : carole"]'); await p.waitForSelector('.secret'); const pw2 = (await p.textContent('.secret')).trim();
  await p.click('.manage-dialog .af-actions >> text=Fermer'); await p.waitForSelector('.secret', { state: 'detached' });
  await login(cp, 'carole', pw2); await cp.waitForURL(/\/$|\/settings/); check(pw2 !== pw, 'réinitialisation : le nouveau mot de passe provisoire fonctionne');

  // filtres
  await p.selectOption('#u-role', 'readonly'); await p.click('#users-filter >> text=Filtrer'); await p.waitForFunction(() => document.querySelectorAll('#users-results tbody tr').length === 1);
  check(true, 'filtre par rôle'); await p.selectOption('#u-role', ''); await p.fill('#u-q', '%'); await p.click('#users-filter >> text=Filtrer'); await p.waitForSelector('#users-results .empty');
  check(true, 'recherche « % » sans résultat (joker échappé)');

  await p.screenshot({ path: process.env.SHOT || '/tmp/users.png', fullPage: true });
  check(problems.length === 0, 'aucune erreur console / CSP ' + JSON.stringify(problems));
  await b.close(); process.exit(failed ? 1 : 0);
})();
