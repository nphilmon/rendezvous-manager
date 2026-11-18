<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Push\Base64Url as B;
use App\Services\Push\EcKeys;
use App\Services\Push\EndpointPolicy;
use App\Services\Push\Vapid;
use App\Services\Push\WebPushEncryptor as W;
use Tests\TestCase;

final class WebPushCryptoTest extends TestCase
{
    public function testRfc8291AppendixAVector(): void
    {
        $body = W::encrypt('When I grow up, I want to be a watermelon',
            (string) B::decode('BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4'), (string) B::decode('BTBZMqHH6r4Tts7J_aSIgg'),
            ['private' => (string) B::decode('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw'), 'public' => (string) B::decode('BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8')],
            (string) B::decode('DGv6ra1nlYgDCS1FRnbzlw'));
        $this->assertSame('DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN', B::encode($body), 'corps identique au vecteur de la RFC 8291');
        $this->assertSame('When I grow up, I want to be a watermelon', W::decrypt($body, (string) B::decode('q1dXpw3UpT5VOmu_cf_v6ih07Aems3njxI-JWgLcM94'),
            (string) B::decode('BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4'), (string) B::decode('BTBZMqHH6r4Tts7J_aSIgg')));
    }

    public function testRoundTripTamperingAndLimits(): void
    {
        $ua = EcKeys::generate();
        $auth = random_bytes(16);
        foreach (['', 'é€ 🔔 {"a":1}', str_repeat('x', W::MAX_PAYLOAD)] as $msg) {
            $enc = W::encrypt($msg, $ua['public'], $auth);
            $this->assertSame($msg, W::decrypt($enc, $ua['private'], $ua['public'], $auth));
            $this->assertTrue(strlen($enc) <= 4096 + 0, 'tient dans un enregistrement de 4096 octets');
        }
        $enc = W::encrypt('secret', $ua['public'], $auth);
        $this->assertTrue(W::encrypt('secret', $ua['public'], $auth) !== $enc, 'sel et clé éphémère aléatoires');
        $bad = $enc;
        $bad[strlen($bad) - 20] = $bad[strlen($bad) - 20] ^ "\x01";
        $this->assertSame(null, W::decrypt($bad, $ua['private'], $ua['public'], $auth), 'altération détectée (AES-GCM)');
        $this->assertSame(null, W::decrypt($enc, $ua['private'], $ua['public'], random_bytes(16)), 'mauvais secret d\'authentification');
        $other = EcKeys::generate();
        $this->assertSame(null, W::decrypt($enc, $other['private'], $other['public'], $auth), 'mauvaise clé');
        $this->assertSame(null, W::decrypt('trop court', $ua['private'], $ua['public'], $auth));
        foreach ([fn () => W::encrypt(str_repeat('x', W::MAX_PAYLOAD + 1), $ua['public'], $auth), fn () => W::encrypt('x', $ua['public'], 'court'), fn () => W::encrypt('x', 'pas un point', $auth),
            fn () => W::encrypt('x', "\x04" . str_repeat("\x01", 64), $auth)] as $f) {
            try {
                $f();
                $this->assertTrue(false, 'exception attendue');
            } catch (\RuntimeException) {
            }
        }
    }

    public function testEcKeyConversionsAndSignatures(): void
    {
        $k = EcKeys::generate();
        $this->assertSame([32, 65], [strlen($k['private']), strlen($k['public'])]);
        $pub = EcKeys::publicFromPoint($k['public']);
        $priv = EcKeys::privateFromScalar($k['private'], $k['public']);
        for ($i = 0; $i < 20; $i++) { // la conversion DER ↔ r||s doit tenir pour toutes les longueurs de r et s
            $sig = EcKeys::signEs256("message $i", $priv);
            $this->assertSame(64, strlen($sig));
            $this->assertTrue(EcKeys::verifyEs256("message $i", $sig, $pub));
            $this->assertTrue(!EcKeys::verifyEs256("autre $i", $sig, $pub));
        }
        $this->assertTrue(!EcKeys::verifyEs256('x', 'court', $pub));
        $this->assertSame(null, B::decode('***'));
        $this->assertSame('é', B::decode(B::encode('é')));
    }

    public function testVapidJwt(): void
    {
        $k = Vapid::generate();
        $v = new Vapid($k['public'], $k['private'], 'mailto:admin@example.org');
        $jwt = $v->jwt('https://fcm.googleapis.com/fcm/send/abc', 43200, 1_800_000_000);
        $claims = Vapid::verify($jwt, $k['public']);
        $this->assertSame(['aud' => 'https://fcm.googleapis.com', 'exp' => 1_800_043_200, 'sub' => 'mailto:admin@example.org'], $claims);
        $this->assertTrue(Vapid::verify($v->jwt('https://x.example:8443/p', 999999, 1_800_000_000), $k['public'])['exp'] === 1_800_086_400, 'durée plafonnée à 24 h');
        $this->assertSame('https://x.example:8443', Vapid::verify($v->jwt('https://x.example:8443/p'), $k['public'])['aud'], 'le port fait partie de l\'audience');
        $header = json_decode((string) B::decode(explode('.', $jwt)[0]), true);
        $this->assertSame(['typ' => 'JWT', 'alg' => 'ES256'], $header);
        $this->assertSame(null, Vapid::verify($jwt, Vapid::generate()['public']), 'autre clé publique');
        $parts = explode('.', $jwt);
        $parts[1] = B::encode('{"aud":"https://evil.example","exp":9999999999,"sub":"mailto:a@b.c"}');
        $this->assertSame(null, Vapid::verify(implode('.', $parts), $k['public']), 'revendications altérées');
        $this->assertTrue(str_starts_with($v->authorization('https://fcm.googleapis.com/x'), 'vapid t=') && str_ends_with($v->authorization('https://fcm.googleapis.com/x'), ', k=' . $k['public']));
        foreach (['admin@example.org', 'http://insecure.example', 'mailto:', ''] as $bad) {
            try {
                new Vapid($k['public'], $k['private'], $bad);
                $this->assertTrue(false, $bad);
            } catch (\RuntimeException) {
            }
        }
        try {
            $v->jwt('pas-une-url');
            $this->assertTrue(false);
        } catch (\RuntimeException) {
        }
    }

    public function testEndpointPolicyBlocksSsrf(): void
    {
        $public = fn (string $h) => ['93.184.216.34'];
        $p = new EndpointPolicy(['fcm.googleapis.com', 'updates.push.services.mozilla.com'], false, false, $public);
        $this->assertTrue($p->check('https://fcm.googleapis.com/fcm/send/abc')['ok']);
        $this->assertTrue($p->check('https://eu.fcm.googleapis.com/x')['ok'], 'sous-domaine d\'un service reconnu');
        $this->assertSame('93.184.216.34', $p->check('https://updates.push.services.mozilla.com/wpush/v2/abc')['ip'], 'IP validée renvoyée pour l\'épinglage');
        foreach ([
            'http://fcm.googleapis.com/x' => 'http', 'https://evil.example/x' => 'inconnu', 'https://fcm.googleapis.com.evil.example/x' => 'suffixe piégé',
            'https://evilfcm.googleapis.com.example/x' => 'préfixe', 'https://xfcm.googleapis.com/x' => 'nom voisin', 'https://user:pw@fcm.googleapis.com/x' => 'identifiants',
            'https://127.0.0.1/x' => 'IP locale', 'ftp://fcm.googleapis.com/x' => 'protocole', "https://fcm.googleapis.com/x\ny" => 'saut de ligne', 'pas une url' => 'invalide',
            'https://fcm.googleapis.com/' . str_repeat('a', 2100) => 'trop long',
        ] as $url => $why) {
            $this->assertTrue(!$p->check($url)['ok'], "doit être refusé : $why");
        }
        $private = new EndpointPolicy(['fcm.googleapis.com'], false, false, fn () => ['10.0.0.5']);
        $this->assertTrue(!$private->check('https://fcm.googleapis.com/x')['ok'], 'hôte autorisé mais résolu vers une adresse privée (DNS rebinding)');
        $mixed = new EndpointPolicy(['fcm.googleapis.com'], false, false, fn () => ['93.184.216.34', '169.254.169.254']);
        $this->assertTrue(!$mixed->check('https://fcm.googleapis.com/x')['ok'], 'une seule adresse non publique suffit à refuser');
        $none = new EndpointPolicy(['fcm.googleapis.com'], false, false, fn () => []);
        $this->assertTrue(!$none->check('https://fcm.googleapis.com/x')['ok']);
        $dev = new EndpointPolicy([], true, true);
        $this->assertTrue($dev->check('http://127.0.0.1:8123/ok')['ok'], 'mode test : http local accepté');
    }
}
