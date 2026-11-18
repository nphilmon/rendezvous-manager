<?php
declare(strict_types=1);

// Données de démonstration pour le test navigateur. Usage (base de test UNIQUEMENT, jamais la production) :
//   DB_* pointant vers une base *_test, puis : php tests/e2e/seed.php
require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Migrator;
use App\Repositories\UserRepository;
use App\Security\PasswordHasher;

if (!str_ends_with((string) Config::get('database.name'), '_test')) {
    fwrite(STDERR, "Refus : la base doit se terminer par _test.\n");
    exit(1);
}
$p = Database::connection();
$p->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($p->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
    $p->exec("DROP TABLE `$t`");
}
$p->exec('SET FOREIGN_KEY_CHECKS=1');
(new Migrator($p, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
$u = new UserRepository($p);
foreach ([['admin', 'admin', 'Léa', 'Durand'], ['bob', 'user', 'Bob', 'Martin']] as [$n, $role, $f, $l]) {
    $u->create(['role_code' => $role, 'username' => $n, 'email' => "$n@example.org", 'first_name' => $f, 'last_name' => $l, 'password_hash' => PasswordHasher::hash('Correct-Horse-9')]);
}
$p->exec("INSERT INTO calendars (owner_id,name,color,visibility) VALUES (1,'Professionnel','#2563eb','private'),(1,'Équipe','#16a34a','organization'),(2,'Privé de Bob','#dc2626','private')");
$p->exec("INSERT INTO categories (name,color) VALUES ('Client','#9333ea')");
$ins = $p->prepare('INSERT INTO appointments (uid,calendar_id,category_id,created_by,title,description,start_utc,end_utc,all_day,location,status,color) VALUES (UUID(),?,?,1,?,?,?,?,?,?,?,?)');
foreach ([
    [1, null, 'Réunion équipe', "Point hebdomadaire\nOrdre du jour", '2026-11-18 08:00:00', '2026-11-18 09:30:00', 0, 'Salle A', 'confirmed', null],
    [1, null, 'Appel client', '', '2026-11-18 08:30:00', '2026-11-18 09:00:00', 0, null, 'scheduled', null],
    [1, 1, 'Démo produit', '', '2026-11-18 08:45:00', '2026-11-18 10:00:00', 0, 'Visio', 'tentative', null],
    [2, null, 'Formation', '', '2026-11-19 13:00:00', '2026-11-19 16:00:00', 0, 'Atelier', 'scheduled', null],
    [1, null, 'Séminaire', '', '2026-11-19 00:00:00', '2026-11-21 00:00:00', 1, 'Lyon', 'scheduled', '#f59e0b'],
    [1, null, 'Annulé', '', '2026-11-20 09:00:00', '2026-11-20 10:00:00', 0, null, 'cancelled', null],
    [1, null, 'Nuit tardive', '', '2026-11-20 21:30:00', '2026-11-20 22:30:00', 0, null, 'scheduled', null],
    [3, null, 'SECRET de Bob', '', '2026-11-18 10:00:00', '2026-11-18 11:00:00', 0, null, 'scheduled', null],
    [1, null, 'Mensuel A', '', '2026-11-25 09:00:00', '2026-11-25 10:00:00', 0, null, 'scheduled', null],
    [1, null, 'Mensuel B', '', '2026-11-25 10:00:00', '2026-11-25 11:00:00', 0, null, 'scheduled', null],
    [1, null, 'Mensuel C', '', '2026-11-25 11:00:00', '2026-11-25 12:00:00', 0, null, 'scheduled', null],
    [1, null, 'Mensuel D', '', '2026-11-25 12:00:00', '2026-11-25 13:00:00', 0, null, 'scheduled', null],
] as $r) {
    $ins->execute($r);
}
echo "Base de test initialisée (admin / bob, mot de passe Correct-Horse-9).\n";
