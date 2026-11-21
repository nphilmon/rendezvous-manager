<h1>Sauvegardes</h1>
<div id="backups" data-keep="<?= (int) $keep ?>">
  <p class="muted">Sauvegarde complète de la base (fichier compressé avec somme de contrôle). Les <?= (int) $keep ?> plus récentes sont conservées. Ces fichiers contiennent toutes les données, y compris les condensats de mots de passe : ne les partagez pas. Avec une clé <code>BACKUP_KEY</code>, ils sont chiffrés (AES-256-GCM) : conservez cette clé hors du serveur, sans elle ils sont illisibles.</p>
  <div class="manage-toolbar"><button class="btn btn-primary" type="button" id="b-create">Créer une sauvegarde</button></div>
  <div class="alert alert-error" id="b-err" role="alert" hidden></div>
  <div id="b-results" aria-live="polite"></div>
  <section class="panel">
    <h2>Planification et restauration</h2>
    <p class="small muted">Sauvegarde automatique (cron, chaque nuit) : <code>30 2 * * * php /chemin/workers/backup_worker.php</code>. Restauration, volontairement en ligne de commande seulement : <code>php scripts/restore.php NOM.sql.gz</code> vérifie le fichier sans rien modifier ; ajoutez <code>--yes</code> pour restaurer (une sauvegarde de sécurité de l'état actuel est faite avant). Les sessions ne sont pas sauvegardées : tout le monde doit se reconnecter après une restauration. Conservez aussi une copie des sauvegardes hors du serveur.</p>
  </section>
  <noscript><p class="notice">JavaScript est nécessaire pour afficher cette page.</p></noscript>
</div>
