<?php
/**
 * Repas : récapitulatif des présences par catégorie d'âge (Déjeuner / Dîner),
 * consultable par semaine, par mois ou sur une plage de dates libre.
 * Export CSV (ouvrable dans Excel).
 */
require __DIR__ . '/bootstrap.php';
require_login();
require __DIR__ . '/partials/recap_lib.php';

// ---- Détermination de la période selon le mode ----
$mode = $_GET['mode'] ?? 'semaine';
if (!in_array($mode, ['semaine', 'mois', 'libre'], true)) { $mode = 'semaine'; }

$reDate = '/^\d{4}-\d{2}-\d{2}$/';
$ref = $_GET['ref'] ?? today();
if (!preg_match($reDate, $ref)) { $ref = today(); }

if ($mode === 'semaine') {
    [$from, $to] = semaine_bornes($ref);
    $prevRef = (new DateTimeImmutable($from))->modify('-7 days')->format('Y-m-d');
    $nextRef = (new DateTimeImmutable($from))->modify('+7 days')->format('Y-m-d');
} elseif ($mode === 'mois') {
    [$from, $to] = mois_bornes($ref);
    $prevRef = (new DateTimeImmutable($from))->modify('-1 month')->format('Y-m-d');
    $nextRef = (new DateTimeImmutable($from))->modify('+1 month')->format('Y-m-d');
} else { // libre
    $from = $_GET['from'] ?? today();
    $to   = $_GET['to']   ?? (new DateTimeImmutable(today()))->modify('+13 days')->format('Y-m-d');
    if (!preg_match($reDate, $from)) { $from = today(); }
    if (!preg_match($reDate, $to))   { $to = (new DateTimeImmutable($from))->modify('+13 days')->format('Y-m-d'); }
    if ($to < $from) { $to = $from; }
}

$days = calcul_recap($from, $to);
$categories = array_keys(LC_CATEGORIES);

// ---- Export CSV (avant tout affichage HTML) ----
if (($_GET['export'] ?? '') === 'csv') {
    $filename = 'repas_' . $from . '_' . $to . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    echo "\xEF\xBB\xBF"; // BOM UTF-8 pour Excel
    // En-têtes : Catégorie ; puis 2 colonnes par jour
    $head = ['Catégorie'];
    foreach ($days as $j => $_d) {
        $lib = fr_jour_court($j);
        $head[] = $lib . ' ' . LC_MEALS['midi'];
        $head[] = $lib . ' ' . LC_MEALS['soir'];
    }
    fputcsv($out, $head, ';', '"', '');
    // Lignes par catégorie
    foreach ($categories as $cat) {
        $line = [categorie_label($cat)];
        foreach ($days as $d) {
            $line[] = $d['midi'][$cat];
            $line[] = $d['soir'][$cat];
        }
        fputcsv($out, $line, ';', '"', '');
    }
    // Ligne total
    $line = ['Total'];
    foreach ($days as $d) { $line[] = $d['midi']['total']; $line[] = $d['soir']['total']; }
    fputcsv($out, $line, ';', '"', '');
    fclose($out);
    exit;
}

// Lien d'export conservant les paramètres courants.
$exportUrl = 'repas.php?' . http_build_query(array_merge($_GET, ['export' => 'csv']));

$page_title = 'Repas';
$active = 'repas';
require __DIR__ . '/partials/header.php';
?>
<h1>Repas</h1>
<p class="lead">Présences aux repas (déjeuner &amp; dîner) par catégorie d’âge.</p>

<details class="aide">
  <summary>À quoi sert cette page ?</summary>
  <ul>
    <li>Elle compte, jour par jour, <strong>combien de personnes</strong> seront présentes à chaque repas —
      pratique pour la cuisine et les courses.</li>
    <li>Choisissez l’affichage : <strong>par semaine</strong>, <strong>par mois</strong>, ou une
      <strong>plage de dates libre</strong>.</li>
    <li>Les repas se cochent lors de l’inscription : pour corriger, repassez par le lien de modification du séjour.</li>
    <li>« Télécharger (Excel / CSV) » exporte le tableau tel quel.</li>
  </ul>
</details>

<div class="card no-print">
  <form method="get" class="repas-filtre">
    <div class="segmented" style="max-width:420px">
      <?php foreach (['semaine' => 'Par semaine', 'mois' => 'Par mois', 'libre' => 'Plage libre'] as $mk => $ml): ?>
        <label><input type="radio" name="mode" value="<?= $mk ?>" <?= $mode === $mk ? 'checked' : '' ?>
               onchange="this.form.submit()"><span><?= h($ml) ?></span></label>
      <?php endforeach; ?>
    </div>

    <?php if ($mode === 'libre'): ?>
      <div class="row" style="align-items:flex-end; margin-top:1rem">
        <div><label>Du <input type="date" name="from" value="<?= h($from) ?>"></label></div>
        <div><label>Au <input type="date" name="to" value="<?= h($to) ?>"></label></div>
        <div style="flex:0 0 auto"><button class="btn" type="submit">Afficher</button></div>
      </div>
    <?php else: ?>
      <input type="hidden" name="ref" value="<?= h($ref) ?>">
      <div class="actions" style="justify-content:space-between; margin-top:1rem">
        <button class="btn btn-ghost btn-sm" type="submit" name="ref" value="<?= h($prevRef) ?>">← précédent</button>
        <strong><?= h(fr_date($from)) ?> → <?= h(fr_date($to)) ?></strong>
        <button class="btn btn-ghost btn-sm" type="submit" name="ref" value="<?= h($nextRef) ?>">suivant →</button>
      </div>
    <?php endif; ?>
  </form>
</div>

<div class="actions no-print" style="margin-top:0">
  <a class="btn btn-ghost" href="<?= h($exportUrl) ?>">⬇︎ Télécharger (Excel / CSV)</a>
  <a class="btn btn-ghost" href="pdf.php?from=<?= h($from) ?>&to=<?= h($to) ?>">🖨️ Version imprimable</a>
</div>

<div class="table-scroll">
  <table class="data recap-table">
    <thead>
      <tr>
        <th rowspan="2">Catégorie</th>
        <?php foreach ($days as $j => $d): ?>
          <th colspan="2" class="num daysep"><?= h(fr_jour_court($j)) ?></th>
        <?php endforeach; ?>
      </tr>
      <tr>
        <?php foreach ($days as $j => $d): ?>
          <th class="num daysep"><?= h(LC_MEALS['midi']) ?></th>
          <th class="num"><?= h(LC_MEALS['soir']) ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($categories as $cat): ?>
        <tr>
          <th><?= h(categorie_label($cat)) ?></th>
          <?php foreach ($days as $d): ?>
            <td class="num daysep <?= $d['midi'][$cat] ? '' : 'zero' ?>"><?= $d['midi'][$cat] ?: '·' ?></td>
            <td class="num <?= $d['soir'][$cat] ? '' : 'zero' ?>"><?= $d['soir'][$cat] ?: '·' ?></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <th>Total</th>
        <?php foreach ($days as $d): ?>
          <th class="num daysep"><?= $d['midi']['total'] ?: '' ?></th>
          <th class="num"><?= $d['soir']['total'] ?: '' ?></th>
        <?php endforeach; ?>
      </tr>
    </tfoot>
  </table>
</div>
<p class="muted small">« · » = aucune présence. Astuce : la vue « Par semaine » est la plus lisible ; utilisez l’export pour les longues périodes.</p>

<script>
  window.LC_TOUR_KEY = 'repas';
  window.LC_TOUR_STEPS = [
    { sel: '.repas-filtre .segmented', titre: 'Trois affichages', cliquer: true,
      texte: 'Choisissez la période : par semaine (la plus lisible), par mois, ou une plage de dates libre que vous fixez vous-même.' },
    { sel: '.recap-table', titre: 'Le tableau des repas',
      texte: 'Une colonne Déjeuner et une colonne Dîner par jour, une ligne par catégorie d’âge, et la ligne Total en bas — de quoi prévoir les quantités.' },
    { sel: '.actions a[href*="export"]', titre: 'Export Excel', cliquer: true,
      texte: 'Ce bouton télécharge le tableau au format CSV, qui s’ouvre directement dans Excel.' },
    { sel: '.actions a[href*="pdf"]', titre: 'Impression',
      texte: 'Et celui-ci ouvre la version imprimable, pour l’afficher dans la cuisine par exemple.' }
  ];
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
