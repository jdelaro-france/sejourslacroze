<?php
/**
 * Version imprimable / PDF du récapitulatif des repas.
 * Pas de bibliothèque : on utilise l'impression du navigateur (« Enregistrer en PDF »).
 */
require __DIR__ . '/bootstrap.php';
require_login();
require __DIR__ . '/partials/recap_lib.php';

$reDate = '/^\d{4}-\d{2}-\d{2}$/';
$from = $_GET['from'] ?? today();
$to   = $_GET['to']   ?? (new DateTimeImmutable(today()))->modify('+13 days')->format('Y-m-d');
if (!preg_match($reDate, $from)) { $from = today(); }
if (!preg_match($reDate, $to))   { $to = (new DateTimeImmutable($from))->modify('+13 days')->format('Y-m-d'); }
if ($to < $from) { $to = $from; }

$days = calcul_recap($from, $to);
$categories = array_keys(LC_CATEGORIES);
$totMidi = 0; $totSoir = 0;
foreach ($days as $d) { $totMidi += $d['midi']['total']; $totSoir += $d['soir']['total']; }

// Arrivées / départs de la période.
$mv = db()->prepare('
    SELECT * FROM reservations
    WHERE (date_arrivee BETWEEN :f AND :t) OR (date_depart BETWEEN :f AND :t)
    ORDER BY date_arrivee, room
');
$mv->execute([':f' => $from, ':t' => $to]);
$mouvements = $mv->fetchAll();

$page_title = 'Impression / PDF';
$active = 'pdf';
require __DIR__ . '/partials/header.php';
?>
<div class="actions no-print">
  <button class="btn" onclick="window.print()">🖨️ Imprimer / Enregistrer en PDF</button>
  <a class="btn btn-ghost" href="repas.php">← Retour aux repas</a>
  <span class="muted small">Dans la boîte d’impression, choisissez « Enregistrer au format PDF ».</span>
</div>

<h1><?= h(site_title()) ?> — Présences aux repas</h1>
<p class="lead">Période : <?= h(fr_date($from)) ?> → <?= h(fr_date($to)) ?>
   · Total : <?= $totMidi ?> déjeuners / <?= $totSoir ?> dîners</p>

<div class="table-scroll">
  <table class="data recap-table">
    <thead>
      <tr>
        <th rowspan="2">Catégorie</th>
        <?php foreach ($days as $j => $d): ?><th colspan="2" class="num daysep"><?= h(fr_jour_court($j)) ?></th><?php endforeach; ?>
      </tr>
      <tr>
        <?php foreach ($days as $j => $d): ?>
          <th class="num daysep"><?= h(LC_MEALS['midi']) ?></th><th class="num"><?= h(LC_MEALS['soir']) ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($categories as $cat): ?>
        <tr>
          <th><?= h(categorie_label($cat)) ?></th>
          <?php foreach ($days as $d): ?>
            <td class="num daysep"><?= $d['midi'][$cat] ?: '' ?></td>
            <td class="num"><?= $d['soir'][$cat] ?: '' ?></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <th>Total</th>
        <?php foreach ($days as $d): ?>
          <th class="num daysep"><?= $d['midi']['total'] ?: '' ?></th><th class="num"><?= $d['soir']['total'] ?: '' ?></th>
        <?php endforeach; ?>
      </tr>
    </tfoot>
  </table>
</div>

<h2>Arrivées &amp; départs</h2>
<div class="table-scroll">
  <table class="data">
    <thead><tr><th>Chambre</th><th>Personnes</th><th>Arrivée</th><th>Départ</th><th>Invité par</th></tr></thead>
    <tbody>
      <?php foreach ($mouvements as $r): ?>
        <tr>
          <td><?= h($r['room']) ?></td>
          <td><?= h(noms_participants(get_participants((int) $r['id']))) ?></td>
          <td><?= h(fr_date($r['date_arrivee'])) ?> (<?= h(moment_label($r['moment_arrivee'])) ?>)</td>
          <td><?= h(fr_date($r['date_depart'])) ?> (<?= h(moment_label($r['moment_depart'])) ?>)</td>
          <td><?= h($r['invite_par']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
