<?php
declare(strict_types=1);

/**
 * Faux service push pour les tests : à lancer avec « php -S 127.0.0.1:PORT tests/Support/fake_push_service.php ».
 * Vérifie le jeton VAPID (signature ES256, audience, expiration), les en-têtes, déchiffre la charge utile avec la clé de
 * l'abonné et journalise le résultat (une ligne JSON par requête) dans FAKE_PUSH_LOG. Le chemin choisit la réponse :
 * /ok → 201, /gone → 410, /busy → 503, /throttle → 429, /forbidden → 403.
 */
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Services\Push\Base64Url;
use App\Services\Push\Vapid;
use App\Services\Push\WebPushEncryptor;

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$log = ['path' => $path, 'method' => $_SERVER['REQUEST_METHOD'], 'encoding' => $_SERVER['HTTP_CONTENT_ENCODING'] ?? null, 'ttl' => $_SERVER['HTTP_TTL'] ?? null,
    'urgency' => $_SERVER['HTTP_URGENCY'] ?? null, 'vapid_ok' => false, 'aud_ok' => false, 'exp_ok' => false, 'plaintext' => null];
if (preg_match('/^vapid t=([\w.-]+), k=([\w-]+)$/', $auth, $m)) {
    $claims = Vapid::verify($m[1], $m[2]);
    $log['vapid_ok'] = $claims !== null && hash_equals((string) getenv('FAKE_PUSH_VAPID_PUB'), $m[2]);
    $log['aud_ok'] = $claims !== null && ($claims['aud'] ?? '') === 'http://' . $_SERVER['HTTP_HOST'];
    $log['exp_ok'] = $claims !== null && ($claims['exp'] ?? 0) > time() && ($claims['exp'] ?? 0) <= time() + 86400;
    $log['sub'] = $claims['sub'] ?? null;
}
$body = (string) file_get_contents('php://input');
$log['body_len'] = strlen($body);
$plain = WebPushEncryptor::decrypt($body, (string) Base64Url::decode((string) getenv('FAKE_PUSH_UA_PRIV')), (string) Base64Url::decode((string) getenv('FAKE_PUSH_UA_PUB')), (string) Base64Url::decode((string) getenv('FAKE_PUSH_AUTH')));
$log['plaintext'] = $plain;
file_put_contents((string) getenv('FAKE_PUSH_LOG'), json_encode($log, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
$code = ['/ok' => 201, '/gone' => 410, '/busy' => 503, '/throttle' => 429, '/forbidden' => 403][$path] ?? 404;
if ($code === 201 && !($log['vapid_ok'] && $log['aud_ok'] && $log['exp_ok'] && $plain !== null && $log['encoding'] === 'aes128gcm')) {
    $code = 400; // mauvaise requête : le test échouera et la ligne de journal dit pourquoi
}
http_response_code($code);
echo $code === 201 ? '' : 'refus';
