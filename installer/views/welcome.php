<h1>Bienvenue dans RendezVous Manager</h1>
<p>Plateforme de gestion de rendez-vous avec calendrier en grille, notifications côté serveur et synchronisation multi-utilisateurs. Cet assistant vérifie votre serveur, configure la base MySQL/MariaDB, crée le premier administrateur puis se verrouille.</p>
<table><?php foreach ($system as $k => $v): ?><tr><th><?= $e($k) ?></th><td><?= $e($v) ?></td></tr><?php endforeach; ?></table>
<div class="actions"><a class="btn primary" href="/install/prerequisites">Commencer</a></div>
