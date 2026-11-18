<h1><?= $e($heading) ?></h1>
<div id="manage" data-kind="<?= $e($kind) ?>" data-can-write="<?= $canWrite ? '1' : '0' ?>" data-can-create-appointment="<?= $canCreate ? '1' : '0' ?>">
  <div class="manage-toolbar" id="manage-toolbar"></div>
  <div id="manage-results" aria-live="polite"></div>
  <div id="manage-pager" class="pager"></div>
  <noscript><p class="notice">JavaScript est nécessaire pour afficher cette page.</p></noscript>
</div>
