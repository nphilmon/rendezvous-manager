// Test navigateur Web Push + e-mail (Playwright). Autonome : lance lui-même un faux service push, un faux serveur SMTP et le serveur
// de l'application (php -S, port 8098) avec des clés VAPID générées pour l'occasion. Prérequis : base *_test (seed.php), storage/install.lock,
// variables DB_* dans l'environnement. Usage : NODE_PATH=$(npm root -g) node tests/e2e/push.e2e.js
//
// Limite assumée : Chromium sans accès à Internet ne peut pas obtenir de véritable abonnement auprès d'un service push (FCM, Mozilla…).
// L'abonnement du navigateur est donc simulé (PushManager.subscribe remplacé) avec des clés connues du faux service ; le service worker,
// lui, est réel et reçoit ses messages via le protocole de débogage de Chromium (ServiceWorker.deliverPushMessage).
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');
const net = require('net');
const ROOT = path.join(__dirname, '..', '..');
let failed = 0;
const check = (ok, msg) => { console.log((ok ? '  ok    ' : '  FAIL  ') + msg); if (!ok) failed++; };
const free = () => new Promise(r => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const waitPort = async (port) => { for (let i = 0; i < 60; i++) { if (await new Promise(r => { const c = net.connect(port, '127.0.0.1', () => { c.destroy(); r(true); }); c.on('error', () => r(false)); })) return; await new Promise(r => setTimeout(r, 100)); } throw new Error('port ' + port); };
const php = (code) => execFileSync('php', ['-r', 'require "' + ROOT + '/app/bootstrap.php"; ' + code], { env: process.env, encoding: 'utf8' }).trim();
const iso = (ms) => new Date(ms).toISOString().slice(0, 19) + 'Z';
const lines = (f) => (fs.existsSync(f) ? fs.readFileSync(f, 'utf8').split('\n').filter(Boolean).map(l => JSON.parse(l)) : []);

(async () => {
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'rvm-push-'));
  const pushLog = path.join(tmp, 'push.log'), smtpOut = path.join(tmp, 'smtp.out');
  const vapid = JSON.parse(php('echo json_encode(App\\Services\\Push\\Vapid::generate());'));
  const ua = JSON.parse(php('$k = App\\Services\\Push\\EcKeys::generate(); echo json_encode(["priv" => App\\Services\\Push\\Base64Url::encode($k["private"]), "pub" => App\\Services\\Push\\Base64Url::encode($k["public"]), "auth" => App\\Services\\Push\\Base64Url::encode(random_bytes(16))]);'));
  const [pushPort, smtpPort, appPort] = [await free(), await free(), 8098];
  const kids = [];
  const run = (cmd, args, env) => { const p = spawn(cmd, args, { cwd: ROOT, env: { ...process.env, ...env }, stdio: 'ignore' }); kids.push(p); return p; };
  run('php', ['-S', '127.0.0.1:' + pushPort, 'tests/Support/fake_push_service.php'], { FAKE_PUSH_VAPID_PUB: vapid.public, FAKE_PUSH_UA_PRIV: ua.priv, FAKE_PUSH_UA_PUB: ua.pub, FAKE_PUSH_AUTH: ua.auth, FAKE_PUSH_LOG: pushLog });
  run('php', ['tests/Support/fake_smtp.php', String(smtpPort), smtpOut], {});
  const appEnv = { VAPID_PUBLIC_KEY: vapid.public, VAPID_PRIVATE_KEY: vapid.private, VAPID_SUBJECT: 'mailto:admin@example.org', PUSH_ALLOW_ANY: '1', MAIL_ENABLED: 'true', MAIL_HOST: '127.0.0.1',
    MAIL_PORT: String(smtpPort), MAIL_ENCRYPTION: 'none', MAIL_FROM: 'noreply@example.org', SYNC_INTERVAL: '2', COOKIE_SECURE: 'false', APP_ENV: 'development' };
  run('php', ['-S', '127.0.0.1:' + appPort, '-t', 'public', 'public/index.php'], appEnv);
  await Promise.all([waitPort(pushPort), waitPort(smtpPort), waitPort(appPort)]);
  const BASE = 'http://127.0.0.1:' + appPort, ENDPOINT = 'http://127.0.0.1:' + pushPort + '/ok';
  const b = await chromium.launch({ channel: 'chromium' }); const problems = []; // « nouveau mode headless » : seul à pouvoir accorder la permission notifications
  try {
    const ctx = await b.newContext({ viewport: { width: 1280, height: 900 } }); await ctx.grantPermissions(['notifications'], { origin: BASE }); const p = await ctx.newPage();
    p.on('console', m => { if (m.type() === 'error' && !/status of (401|403|409|422|502|503)/.test(m.text())) problems.push(m.text()); }); p.on('pageerror', e => problems.push(e.message)); p.on('dialog', d => d.accept());
    // abonnement simulé : clés connues du faux service push ; le service worker et l'API Notification restent réels
    await p.addInitScript(([endpoint, pub, auth]) => {
      // l'abonnement survit aux rechargements de page, comme un vrai abonnement du navigateur
      const make = () => ({ endpoint, expirationTime: null, toJSON() { return { endpoint, expirationTime: null, keys: { p256dh: pub, auth } }; }, async unsubscribe() { localStorage.removeItem('stub_push'); return true; } });
      PushManager.prototype.subscribe = async function () { localStorage.setItem('stub_push', '1'); return make(); };
      PushManager.prototype.getSubscription = async function () { return localStorage.getItem('stub_push') ? make() : null; };
    }, [ENDPOINT, ua.pub, ua.auth]);
    await p.goto(BASE + '/login'); await p.fill('#identifier', 'admin'); await p.fill('#password', 'Correct-Horse-9'); await p.click('#login-submit'); await p.waitForURL(BASE + '/');
    const api = (method, url, body) => p.evaluate(async ([m, u, bd]) => { const csrf = document.querySelector('meta[name=csrf-token]').content; const r = await fetch(u, { method: m, headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: bd ? JSON.stringify(bd) : undefined }); return { status: r.status, json: await r.json().catch(() => ({})) }; }, [method, url, body]);

    await p.goto(BASE + '/settings'); await p.waitForSelector('#push-status:not(:has-text("Vérification"))');
    check(await p.locator('#push-panel').isVisible() && await p.locator('#email-panel').isVisible(), 'serveur configuré : panneaux push et e-mail affichés');
    check(!(await p.isDisabled('#n-ch-push')) && !(await p.isDisabled('#n-ch-email')), 'canaux push et e-mail proposés dans les réglages');
    await p.waitForSelector('#push-enable:not([hidden])');
    check(/pas abonné|Non activées/.test(await p.textContent('#push-status')), 'état initial : non activé sur cet appareil');

    // 1. activation : permission, enregistrement du service worker réel, abonnement simulé, enregistrement serveur
    await p.click('#push-enable'); await p.waitForSelector('#push-disable:not([hidden])');
    check(/activées sur cet appareil/.test(await p.textContent('#push-status')), 'activation : état « activées sur cet appareil »');
    const swUrl = await p.evaluate(async () => (await navigator.serviceWorker.getRegistration('/')).active.scriptURL);
    check(swUrl.endsWith('/service-worker.js'), 'service worker réel enregistré et actif');
    const subs = await api('GET', '/api/v1/push/subscriptions');
    check(subs.json.data.length === 1 && subs.json.data[0].service === '127.0.0.1' && !JSON.stringify(subs.json).includes('/ok'), 'abonnement enregistré (l\'URL complète n\'est pas exposée)');
    check(await p.locator('#push-devices .dev').count() === 1, 'l\'appareil apparaît dans la liste');

    // 2. notification de test : serveur → service push simulé (VAPID + chiffrement vérifiés)
    await p.click('#push-test'); await p.waitForSelector('.toast:has-text("Notification envoyée")');
    const l1 = lines(pushLog)[0];
    check(l1 && l1.vapid_ok && l1.aud_ok && l1.exp_ok && l1.encoding === 'aes128gcm', 'service push : jeton VAPID valide et contenu aes128gcm — ' + JSON.stringify(l1 && { v: l1.vapid_ok, a: l1.aud_ok, e: l1.exp_ok }));
    check(l1 && JSON.parse(l1.plaintext).title === 'Notification de test', 'la charge utile est déchiffrable par l\'abonné');

    // 3. réception par le service worker (protocole de débogage) : affichage de la notification, URL sûre
    const cdp = await ctx.newCDPSession(p); let regId = null;
    cdp.on('ServiceWorker.workerRegistrationUpdated', e => { for (const r of e.registrations) if (r.scopeURL.startsWith(BASE)) regId = r.registrationId; });
    await cdp.send('ServiceWorker.enable'); await p.waitForTimeout(500);
    check(regId !== null, 'enregistrement du service worker repéré');
    await cdp.send('ServiceWorker.deliverPushMessage', { origin: BASE, registrationId: regId, data: JSON.stringify({ title: 'Rappel : Point projet', body: 'Le 18/11/2026 à 10:30', url: '//evil.example/phish', tag: 'rvm-1' }) });
    const notifs = () => p.evaluate(async () => (await (await navigator.serviceWorker.getRegistration('/')).getNotifications()).map(x => ({ title: x.title, body: x.body, tag: x.tag, url: x.data && x.data.url })));
    const until = async (f) => { for (let i = 0; i < 40; i++) { const r = await f(); if (r) return r; await p.waitForTimeout(200); } return null; };
    const first = await until(async () => { const l = await notifs(); return l.length ? l : null; });
    check(first !== null, 'le service worker a affiché une notification après réception du message push');
    const n = first && first[0] || await p.evaluate(async () => { const x = (await (await navigator.serviceWorker.getRegistration('/')).getNotifications())[0]; return { title: x.title, body: x.body, tag: x.tag, url: x.data.url }; });
    check(n && n.title === 'Rappel : Point projet' && n.body.includes('10:30') && n.tag === 'rvm-1', 'le service worker affiche la notification reçue');
    check(n && n.url === '/notifications', 'URL de la charge utile non fiable (« //evil.example ») remplacée par /notifications');
    await cdp.send('ServiceWorker.deliverPushMessage', { origin: BASE, registrationId: regId, data: 'texte brut sans JSON' });
    const plain = await until(async () => (await notifs()).find(x => x.body === 'texte brut sans JSON') || null);
    check(plain !== null && plain.title === 'RendezVous Manager', 'charge utile non JSON : affichée en texte avec le titre par défaut, sans erreur');

    // 4. chaîne complète : rappel dû → worker (cron) → in-app + push + e-mail
    await p.goto(BASE + '/settings'); await p.waitForFunction(() => !document.getElementById('n-ch-push').disabled);
    await p.check('#n-ch-push'); await p.check('#n-ch-email'); await p.click('#notif-form >> text=Enregistrer'); await p.waitForFunction(() => /Réglages enregistrés/.test(document.getElementById('notif-msg').textContent));
    const created = await api('POST', '/api/v1/appointments', { calendar_id: 1, title: 'Réunion multicanal', location: 'Salle 7', start: iso(Date.now() + 8 * 60000), end: iso(Date.now() + 68 * 60000), reminder_minutes: 10 });
    const rem = php('echo json_encode(App\\Core\\Database::connection()->query("SELECT channel FROM reminders WHERE appointment_id = ' + created.json.data.id + ' ORDER BY channel")->fetchAll(PDO::FETCH_COLUMN));');
    check(JSON.parse(rem).sort().join() === 'email,inapp,push', 'un rappel par canal activé : ' + rem);
    const before = lines(pushLog).length;
    const w = JSON.parse(execFileSync('php', [path.join(ROOT, 'workers', 'reminder_worker.php')], { env: { ...process.env, ...appEnv }, encoding: 'utf8' }).trim());
    check(w.planned === 3 && w.sent === 3 && w.failed === 0, 'worker : 3 envois (in-app, push, e-mail) — ' + JSON.stringify(w));
    const l2 = lines(pushLog); check(l2.length === before + 1 && JSON.parse(l2[l2.length - 1].plaintext).title === 'Rappel : Réunion multicanal', 'push reçu par le service simulé avec le titre du rappel');
    const mails = lines(smtpOut); const mail = mails[mails.length - 1];
    check(mail && /Salle 7/.test(Buffer.from(mail.data.split('\r\n\r\n')[1].replace(/=\r\n/g, '').replace(/=([0-9A-F]{2})/g, (_, h) => String.fromCharCode(parseInt(h, 16))), 'latin1').toString('utf8')), 'e-mail reçu par le faux SMTP avec le lieu du rendez-vous');

    // 5. e-mail de test
    await p.goto(BASE + '/settings'); await p.waitForSelector('#email-test'); await p.click('#email-test');
    await p.waitForFunction(() => /E-mail de test envoyé à/.test(document.getElementById('email-status').textContent));
    check((await p.textContent('#email-status')).includes('a***@example.org'), 'e-mail de test : adresse masquée à l\'écran');
    check(lines(smtpOut).length === mails.length + 1, 'e-mail de test remis au serveur SMTP');

    // 6. désactivation
    await p.waitForSelector('#push-disable:not([hidden])'); await p.click('#push-disable'); await p.waitForSelector('#push-enable:not([hidden])');
    check((await api('GET', '/api/v1/push/subscriptions')).json.data.length === 0, 'désactivation : l\'abonnement est supprimé côté serveur');

    // 7. échec réel de l'abonnement (aucun service push joignable) : message clair, aucune exception
    const ctx2 = await b.newContext({ viewport: { width: 1280, height: 900 } }); await ctx2.grantPermissions(['notifications'], { origin: BASE }); const q = await ctx2.newPage();
    q.on('pageerror', e => problems.push('q: ' + e.message));
    await q.goto(BASE + '/login'); await q.fill('#identifier', 'admin'); await q.fill('#password', 'Correct-Horse-9'); await q.click('#login-submit'); await q.waitForURL(BASE + '/');
    await q.goto(BASE + '/settings'); await q.waitForSelector('#push-enable:not([hidden])'); await q.click('#push-enable');
    await q.waitForFunction(() => /Impossible de s'abonner|activées sur cet appareil/.test(document.getElementById('push-status').textContent), null, { timeout: 20000 });
    check(/Impossible de s'abonner/.test(await q.textContent('#push-status')), 'abonnement impossible (service push injoignable) : message explicite, jamais de faux succès');

    check(problems.length === 0, 'aucune erreur console / CSP ' + JSON.stringify(problems));
  } finally {
    await b.close(); kids.forEach(k => k.kill()); fs.rmSync(tmp, { recursive: true, force: true });
  }
  process.exit(failed ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
