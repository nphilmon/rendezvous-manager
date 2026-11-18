<h1>Import / export</h1>
<p class="muted">Sauvegardez ou échangez vos données avec un tableur ou un autre agenda. Seuls les calendriers auxquels vous avez accès sont concernés.</p>

<section class="panel" aria-labelledby="h-export">
  <h2 id="h-export">Exporter</h2>
  <form id="export-form" class="tr-form" novalidate>
    <label>Données
      <select id="ex-what">
        <option value="appointments">Rendez-vous</option>
        <?php if ($canContacts): ?><option value="contacts">Contacts</option><?php endif; ?>
      </select>
    </label>
    <label>Format
      <select id="ex-format"></select>
    </label>
    <div id="ex-appt" class="tr-filters">
      <label>Du <input type="date" id="ex-from"></label>
      <label>Au (inclus) <input type="date" id="ex-to"></label>
      <label>Calendrier <select id="ex-cal"><option value="">Tous</option></select></label>
      <label>Statut
        <select id="ex-status"><option value="">Tous</option>
          <?php foreach ($statuses as $k => $label): ?><option value="<?= $e($k) ?>"><?= $e($label) ?></option><?php endforeach; ?>
        </select>
      </label>
    </div>
    <div><button type="submit" class="btn btn-primary" id="ex-go">Télécharger</button></div>
  </form>
  <div class="alert alert-error" id="ex-err" role="alert" hidden></div>
  <p class="muted small" id="ex-ok" role="status" hidden></p>
  <p class="muted small">Dans les fichiers CSV, XLSX et PDF, toute valeur qui commencerait par <code>=</code>, <code>+</code>, <code>-</code> ou <code>@</code> reçoit une apostrophe de tête pour ne jamais être interprétée comme une formule par le tableur (un téléphone <code>+33…</code> apparaît donc avec une apostrophe ; elle est retirée à l'import). Les séries sont exportées sur une seule ligne avec leur règle (colonne « Récurrence » ; format ICS : exceptions incluses). Maximum 5 000 lignes par export.</p>
</section>

<?php if ($canImport): ?>
<section class="panel" aria-labelledby="h-import">
  <h2 id="h-import">Importer</h2>
  <form id="import-form" class="tr-form" novalidate>
    <label>Type de fichier
      <select id="im-kind">
        <?php if ($canCreate): ?>
        <option value="appointments:csv">Rendez-vous — CSV</option>
        <option value="appointments:ics">Rendez-vous — iCalendar (.ics)</option>
        <?php endif; ?>
        <?php if ($canImportContacts): ?><option value="contacts:csv">Contacts — CSV</option><?php endif; ?>
      </select>
    </label>
    <label id="im-cal-wrap">Calendrier par défaut
      <select id="im-cal"></select>
    </label>
    <label>Fichier (2 Mo maximum)
      <input type="file" id="im-file" accept=".csv,.ics,text/csv,text/calendar,text/plain">
    </label>
    <div class="tr-actions">
      <button type="button" class="btn" id="im-dry">Vérifier sans importer</button>
      <button type="submit" class="btn btn-primary" id="im-go">Importer</button>
    </div>
  </form>
  <div class="alert alert-error" id="im-err" role="alert" hidden></div>
  <div id="im-result" role="status" hidden></div>
  <p class="muted small">Colonnes CSV reconnues (rendez-vous) : Titre*, Début*, Fin, Journée entière (oui/non), Calendrier, Fuseau, Statut, Priorité, Catégorie, Lieu, Adresse, Description, Notes, Récurrence (règle RRULE). Dates : <code>AAAA-MM-JJ HH:MM</code> ou <code>JJ/MM/AAAA HH:MM</code> ; pour une journée entière, la date de fin est incluse. La colonne « Calendrier » prime sur le calendrier par défaut. Contacts : Nom*, Prénom, Société, Téléphone, E-mail, Adresse, Notes.</p>
  <p class="muted small">Chaque ligne est contrôlée comme dans l'application ; une ligne en erreur n'empêche pas les autres. Les doublons (même calendrier, titre, début et fin ; même e-mail pour un contact) sont ignorés : réimporter un même fichier est sans effet. Les chevauchements horaires ne bloquent pas l'import. Importés depuis ICS : titre, dates, fuseau, lieu, description, statut, priorité, catégorie et règle de répétition ; non importés : participants, alarmes, pièces jointes, dates exclues et occurrences modifiées (signalés). Import XLSX non disponible : enregistrez le classeur au format CSV.</p>
</section>
<?php endif; ?>
