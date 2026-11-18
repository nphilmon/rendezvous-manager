<table>
<thead><tr><th>Contrôle</th><th>État</th><th>Détail</th></tr></thead>
<tbody>
<?php foreach ($checks as $c):
    $cls = ['ok' => 'ok', 'missing' => 'bad', 'incompatible' => 'bad', 'warning' => 'warn', 'optional' => 'muted'][$c['status']]; ?>
<tr><td><?= $e($c['label']) ?><?= $c['required'] ? ' <span class="muted">(requis)</span>' : '' ?></td>
<td class="<?= $cls ?>"><strong><?= $e($labels[$c['status']]) ?></strong></td><td><?= $e($c['message']) ?></td></tr>
<?php endforeach; ?>
</tbody></table>
