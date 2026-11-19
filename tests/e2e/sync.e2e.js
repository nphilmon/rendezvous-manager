// Test navigateur de la synchronisation multi-utilisateurs (Playwright). Mêmes prérequis que calendar.e2e.js, avec en plus
// SYNC_INTERVAL=2 dans l'environnement du serveur (intervalle d'actualisation de 2 s au lieu de 30 s).
// Usage : NODE_PATH=$(npm root -g) node tests/e2e/sync.e2e.js
const { chromium } = require('playwright');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8099';
let failed = 0;
const check = (ok, msg) => { console.log((ok ? '  ok    ' : '  FAIL  ') + msg); if (!ok) failed++; };
async function login(page, user) {
  await page.goto(BASE + '/login'); await page.fill('#identifier', user); await page.fill('#password', 'Correct-Horse-9');
  await page.click('#login-submit'); await page.waitForURL(BASE + '/');
}
const call = (page, method, path, body) => page.evaluate(async ([m, p, b]) => {
  const csrf = document.querySelector('meta[name=csrf-token]').content;
  const r = await fetch(p, { method: m, headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: b ? JSON.stringify(b) : undefined });
  return { status: r.status, json: await r.json().catch(() => ({})) };
}, [method, path, body]);
const T = 9000; // délai maximal d'attente d'un cycle de synchronisation

(async () => {
  const b = await chromium.launch(); const problems = [];
  const mk = async (user) => { const c = await b.newContext({ viewport: { width: 1280, height: 900 } }); const p = await c.newPage();
    p.on('console', m => { if (m.type() === 'error' && !/status of (401|403|409|422)/.test(m.text())) problems.push(user + ': ' + m.text()); });
    p.on('pageerror', e => problems.push(user + ': ' + e.message)); p.on('dialog', d => d.accept()); await login(p, user); return [c, p]; };
  const [, a1] = await mk('admin'); const [, a2] = await mk('admin'); const [bobCtx, bp] = await mk('bob');

  const syncBodies = []; bp.on('response', async r => { if (r.url().includes('/sync/changes')) { try { syncBodies.push(await r.json()); } catch (e) {} } });
  let syncCalls = 0; bp.on('request', r => { if (r.url().includes('/sync/changes')) syncCalls++; });

  await bp.goto(BASE + '/calendar?view=week&date=2026-11-18'); await bp.waitForSelector('.tg-col .ev');
  await bp.waitForFunction(() => /À jour/.test(document.getElementById('sync-label').textContent));
  check(true, 'indicateur : « À jour » après le chargement');
  const n0 = await bp.locator('.tg-col .ev').count();

  // 1. création par un autre utilisateur dans un calendrier visible
  const created = await call(a1, 'POST', '/api/v1/appointments', { calendar_id: 2, title: 'Créé par admin', start: '2026-11-20T10:00:00Z', end: '2026-11-20T11:00:00Z' });
  check(created.status === 201, 'admin crée un rendez-vous dans « Équipe »');
  await bp.waitForSelector('.tg-col .ev:has-text("Créé par admin")', { timeout: T });
  check(true, 'Bob le voit apparaître sans recharger la page');
  const id = created.json.data.id;

  // 2. modification puis suppression
  await call(a1, 'PUT', '/api/v1/appointments/' + id, { version: 1, title: 'Renommé par admin' });
  await bp.waitForSelector('.tg-col .ev:has-text("Renommé par admin")', { timeout: T }); check(true, 'modification propagée');
  await call(a1, 'DELETE', '/api/v1/appointments/' + id, { version: 2 });
  await bp.locator('.tg-col .ev:has-text("Renommé par admin")').waitFor({ state: 'detached', timeout: T }); check(true, 'suppression propagée (le bloc disparaît)');
  check(await bp.locator('.tg-col .ev').count() === n0, 'retour à l\'état initial');

  // 3. calendrier non autorisé : aucune fuite
  const priv = await call(a1, 'POST', '/api/v1/appointments', { calendar_id: 1, title: 'Privé admin', start: '2026-11-20T12:00:00Z', end: '2026-11-20T13:00:00Z' });
  await bp.waitForTimeout(5000);
  const leaked = syncBodies.some(j => (j.changes || []).some(c => c.entity_id === priv.json.data.id && c.entity === 'appointment'));
  check(!leaked && await bp.locator('.tg-col .ev:has-text("Privé admin")').count() === 0, 'rien d\'un calendrier non autorisé ne parvient à Bob (ni changement ni contenu)');
  check(!JSON.stringify(syncBodies).includes('Privé admin'), 'les réponses de synchronisation ne contiennent aucun titre');

  // 4. partage accordé puis retiré : l'ensemble des calendriers visibles est rechargé
  await call(a1, 'PUT', '/api/v1/calendars/1/permissions', { permissions: [{ user_id: 2, can_read: true }] });
  await bp.waitForSelector('.cal-item:has-text("Professionnel")', { timeout: T });
  await bp.waitForSelector('.tg-col .ev:has-text("Privé admin")', { timeout: T }); check(true, 'partage accordé : calendrier et rendez-vous apparaissent');
  await call(a1, 'PUT', '/api/v1/calendars/1/permissions', { permissions: [] });
  await bp.locator('.cal-item:has-text("Professionnel")').waitFor({ state: 'detached', timeout: T });
  await bp.locator('.tg-col .ev:has-text("Privé admin")').waitFor({ state: 'detached', timeout: T }); check(true, 'partage retiré : tout disparaît');

  // 5. actualisation manuelle
  const before = syncCalls; await bp.click('#sync-state'); await bp.waitForTimeout(400);
  check(syncCalls > before, 'clic sur l\'indicateur : synchronisation immédiate');

  // 6. onglet masqué : aucune requête ; réveil : synchronisation immédiate
  await bp.evaluate(() => { Object.defineProperty(document, 'hidden', { configurable: true, get: () => true }); document.dispatchEvent(new Event('visibilitychange')); });
  await bp.waitForTimeout(500); const hiddenFrom = syncCalls; await bp.waitForTimeout(5000);
  check(syncCalls === hiddenFrom, 'onglet masqué : plus aucune requête de synchronisation');
  await bp.evaluate(() => { Object.defineProperty(document, 'hidden', { configurable: true, get: () => false }); document.dispatchEvent(new Event('visibilitychange')); });
  await bp.waitForTimeout(600); check(syncCalls > hiddenFrom, 'retour au premier plan : synchronisation immédiate');

  // 7. hors ligne puis rattrapage
  await bobCtx.setOffline(true); await bp.evaluate(() => window.dispatchEvent(new Event('offline')));
  await bp.waitForFunction(() => /Hors ligne/.test(document.getElementById('sync-label').textContent)); check(true, 'indicateur « Hors ligne »');
  const off = await call(a1, 'POST', '/api/v1/appointments', { calendar_id: 2, title: 'Pendant la coupure', start: '2026-11-21T10:00:00Z', end: '2026-11-21T11:00:00Z' });
  await bp.waitForTimeout(2500); check(await bp.locator('.tg-col .ev:has-text("Pendant la coupure")').count() === 0, 'hors ligne : rien ne change');
  await bobCtx.setOffline(false); await bp.evaluate(() => window.dispatchEvent(new Event('online')));
  await bp.waitForSelector('.tg-col .ev:has-text("Pendant la coupure")', { timeout: T }); check(true, 'retour en ligne : rattrapage des changements manqués');
  await bp.waitForFunction(() => /À jour/.test(document.getElementById('sync-label').textContent)); check(off.status === 201, 'indicateur revenu à « À jour »');

  // 8. édition concurrente : avertissement dans le formulaire
  await a2.goto(BASE + '/calendar?view=week&date=2026-11-18'); await a2.waitForSelector('.tg-col .ev');
  await a2.click('.tg-col .ev:has-text("Pendant la coupure")'); await a2.click('.dlg-actions >> text=Modifier'); await a2.waitForSelector('.form-dialog[open]');
  await call(a1, 'PUT', '/api/v1/appointments/' + off.json.data.id, { version: 1, title: 'Modifié pendant l\'édition' });
  await a2.waitForSelector('#af-conflict:not([hidden])', { timeout: T });
  check((await a2.textContent('#af-conflict')).includes('modifié'), 'formulaire ouvert : avertissement « modifié par un autre utilisateur »');
  await a2.click('#af-conflict >> text=Recharger sa version'); await a2.waitForFunction(() => document.getElementById('af-title-in') && document.getElementById('af-title-in').value.includes('pendant l\'édition'));
  check(true, '« Recharger sa version » recharge les valeurs du serveur');
  await a2.keyboard.press('Escape');

  // 9. liste et contacts en direct, compteurs du tableau de bord
  await a2.goto(BASE + '/contacts'); await a2.waitForSelector('#manage-results .empty, #manage-results .tbl');
  await call(a1, 'POST', '/api/v1/contacts', { last_name: 'Apparu en direct' });
  await a2.waitForSelector('#manage-results td:has-text("Apparu en direct")', { timeout: T }); check(true, 'page Contacts : nouveau contact (autre onglet) visible sans recharger');
  await bp.goto(BASE + '/'); await bp.waitForFunction(() => document.querySelector('[data-k="total"]').textContent !== '–');
  const total = () => bp.evaluate(() => parseInt(document.querySelector('[data-k="total"]').textContent, 10)), dashBefore = await total();
  await call(a1, 'POST', '/api/v1/appointments', { calendar_id: 2, title: 'Pour le tableau de bord', start: '2026-11-22T10:00:00Z', end: '2026-11-22T11:00:00Z' });
  await bp.waitForFunction((n) => parseInt(document.querySelector('[data-k="total"]').textContent, 10) === n + 1, dashBefore, { timeout: T }); check(true, 'tableau de bord : les compteurs se mettent à jour seuls');

  check(problems.length === 0, 'aucune erreur console / CSP ' + JSON.stringify(problems));
  await b.close(); process.exit(failed ? 1 : 0);
})();
