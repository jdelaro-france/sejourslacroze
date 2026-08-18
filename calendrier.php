<?php
/** Calendrier : planning des chambres en barres (style Gantt) + liste des séjours à venir. */
require __DIR__ . '/bootstrap.php';
require_login();

$peutEditer = is_admin() || current_role() === 'actionnaire';

// Mois affiché (?m=YYYY-MM).
$m = $_GET['m'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $m)) { $m = date('Y-m'); }
$first  = new DateTimeImmutable($m . '-01');
$nbDays = (int) $first->format('t');
$last   = $first->modify('+' . ($nbDays - 1) . ' days');
$firstS = $first->format('Y-m-d');
$lastS  = $last->format('Y-m-d');
$prevM  = $first->modify('-1 month')->format('Y-m');
$nextM  = $first->modify('+1 month')->format('Y-m');
$moisFr = preg_replace('/^\d+\s/', '', fr_date($firstS)); // « août 2026 »

// Dimensions (px).
$dayW = 30; $barH = 24; $laneGap = 3;

// Réservations chevauchant le mois, groupées par chambre.
$stmt = db()->prepare('
    SELECT * FROM reservations
    WHERE date_arrivee <= :fin AND date_depart >= :debut
    ORDER BY date_arrivee, id
');
$stmt->execute([':debut' => $firstS, ':fin' => $lastS]);
$parRoom = [];
foreach ($stmt->fetchAll() as $r) {
    $r['participants'] = get_participants((int) $r['id']);
    $parRoom[$r['room']][] = $r;
}

// Liste des chambres à afficher : celles des réglages + toute chambre présente dans
// des réservations mais absente des réglages (ex. ancien nom) — rien ne disparaît.
$roomList = rooms();
foreach (array_keys($parRoom) as $rn) {
    if (!in_array($rn, $roomList, true)) { $roomList[] = $rn; }
}

/** Décalage en jours entre deux dates (signé). */
function offset_jours(string $ref, string $d): int
{
    return (int) (new DateTimeImmutable($ref))->diff(new DateTimeImmutable($d))->format('%r%a');
}

/** Deux séjours se chevauchent-ils (hors simple journée de rotation) ? */
function se_chevauchent(array $a, array $b): bool
{
    return $a['date_arrivee'] < $b['date_depart'] && $b['date_arrivee'] < $a['date_depart'];
}

$page_title = 'Calendrier';
$active = 'calendrier';
require __DIR__ . '/partials/header.php';

// Liste chronologique à venir.
$aVenir = db()->query('
    SELECT * FROM reservations WHERE date_depart >= "' . today() . '"
    ORDER BY date_arrivee, room
')->fetchAll();
?>
<h1>Calendrier</h1>

<details class="aide">
  <summary>Comment lire ce calendrier ?</summary>
  <ul>
    <li>Chaque <strong>ligne</strong> est une chambre ; chaque <strong>barre verte</strong> est un séjour
      (survolez-la pour voir qui, cliquez pour modifier).</li>
    <li>Une barre <strong>rouge</strong> signale un chevauchement — cela ne peut plus se produire pour les
      nouvelles réservations (le site les refuse), mais d’anciennes données peuvent en contenir.</li>
    <li>Les barres <strong>violettes</strong> sont des séjours d’invités.</li>
    <li>Utilisez « mois précédent / suivant » pour naviguer, et la liste en bas pour une vue d’ensemble.</li>
  </ul>
</details>

<div class="card">
  <div class="actions" style="justify-content:space-between; margin-top:0">
    <a class="btn btn-ghost btn-sm" href="?m=<?= h($prevM) ?>">← mois précédent</a>
    <strong style="text-transform:capitalize"><?= h($moisFr) ?></strong>
    <a class="btn btn-ghost btn-sm" href="?m=<?= h($nextM) ?>">mois suivant →</a>
  </div>

  <div class="legend">
    <span><span class="swatch" style="background:var(--accent)"></span> séjour</span>
    <span><span class="swatch" style="background:#7a5aa0"></span> invités</span>
    <span><span class="swatch" style="background:var(--danger)"></span> conflit (chevauchement)</span>
    <span><span class="swatch" style="background:#8ee08a; border:1px solid #2c7a2c"></span> MdM = maître / maîtresse de maison</span>
  </div>

  <div class="gantt-scroll">
    <div class="gantt" style="--dayw:<?= $dayW ?>px">
      <!-- En-tête : numéros de jours -->
      <div class="gantt-row gantt-head">
        <div class="gantt-label">Chambre</div>
        <div class="gantt-track" style="width:<?= $nbDays * $dayW ?>px">
          <?php for ($i = 0; $i < $nbDays; $i++):
              $jour = $first->modify("+$i days"); $dow = (int) $jour->format('w'); ?>
            <div class="gantt-day<?= ($dow === 0 || $dow === 6) ? ' we' : '' ?>"
                 style="left:<?= $i * $dayW ?>px; width:<?= $dayW ?>px">
              <span class="dow"><?= ['D','L','M','M','J','V','S'][$dow] ?></span><?= (int) $jour->format('j') ?>
            </div>
          <?php endfor; ?>
        </div>
      </div>

      <?php foreach ($roomList as $room):
          $resas = $parRoom[$room] ?? [];
          // Attribution des « lanes » pour empiler les séjours qui se chevauchent.
          $laneEnds = []; // lane => date_depart de la dernière résa placée
          foreach ($resas as $idx => &$r) {
              $r['_conflit'] = false;
              $placed = false;
              foreach ($laneEnds as $l => $end) {
                  if ($end < $r['date_arrivee']) { $r['_lane'] = $l; $laneEnds[$l] = $r['date_depart']; $placed = true; break; }
              }
              if (!$placed) { $r['_lane'] = count($laneEnds); $laneEnds[] = $r['date_depart']; }
          }
          unset($r);
          // Marquage des conflits (chevauchement réel).
          foreach ($resas as $i => $ri) {
              foreach ($resas as $j => $rj) {
                  if ($i !== $j && se_chevauchent($ri, $rj)) { $resas[$i]['_conflit'] = true; }
              }
          }
          $nbLanes = max(1, count($laneEnds));
          $rowH = max(46, $nbLanes * ($barH + $laneGap) + $laneGap);
      ?>
        <div class="gantt-row" style="height:<?= $rowH ?>px">
          <div class="gantt-label"><span><?= h($room) ?><?php $cap = room_capacity($room); if ($cap !== null): ?><span class="cap"><?= $cap ?> couch.</span><?php endif; ?></span></div>
          <div class="gantt-track" style="width:<?= $nbDays * $dayW ?>px; height:<?= $rowH ?>px">
            <?php foreach ($resas as $r):
                $startIdx = max(0, offset_jours($firstS, $r['date_arrivee']));
                $endIdx   = min($nbDays - 1, offset_jours($firstS, $r['date_depart']));
                if ($endIdx < $startIdx) { continue; }
                $left  = $startIdx * $dayW + 1;
                $width = ($endIdx - $startIdx + 1) * $dayW - 2;
                $top   = ($nbLanes === 1) ? intdiv($rowH - $barH, 2) : $laneGap + $r['_lane'] * ($barH + $laneGap);
                $noms  = noms_participants($r['participants']);
                $nb    = count($r['participants']);
                $estMdm = !empty($r['mdm']);
                $tip   = $noms . ' — ' . fr_date($r['date_arrivee']) . ' → ' . fr_date($r['date_depart'])
                       . ($r['invite_par'] ? ' (invité par ' . $r['invite_par'] . ')' : '')
                       . ($estMdm ? ' · Maître de maison du ' . fr_date($r['mdm_debut']) . ' au ' . fr_date($r['mdm_fin']) : '');
                $href  = $peutEditer ? ('modification.php?token=' . $r['edit_token']) : null;
                $cls   = 'gantt-bar' . ($r['_conflit'] ? ' conflit' : '') . ($r['source'] === 'invite' ? ' invite' : '');
            ?>
              <?php if ($href): ?><a href="<?= h($href) ?>"<?php else: ?><span<?php endif; ?>
                 class="<?= $cls ?>" title="<?= h($tip) ?>"
                 style="left:<?= $left ?>px; width:<?= $width ?>px; top:<?= $top ?>px; height:<?= $barH ?>px">
                <span class="gb-txt"><?= h($noms) ?></span><?php if ($estMdm): ?><span class="gb-mdm" title="Maître de maison">MdM</span><?php endif; ?><span class="gb-nb"><?= $nb ?></span>
              <?= $href ? '</a>' : '</span>' ?>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <p class="muted small">Cliquez sur un séjour pour le modifier<?= $peutEditer ? '' : ' (réservé aux actionnaires)' ?>. Faites défiler horizontalement si nécessaire.</p>
</div>

<h2>Séjours à venir</h2>
<?php if (!$aVenir): ?>
  <p class="muted">Aucune réservation à venir. <a href="inscription.php">Créer une inscription</a>.</p>
<?php else: ?>
  <div class="table-scroll">
    <table class="data">
      <thead>
        <tr><th>Arrivée</th><th>Départ</th><th>Chambre</th><th>Personnes</th>
          <th class="num">Nb</th><th>Invité par</th><?php if ($peutEditer): ?><th></th><?php endif; ?></tr>
      </thead>
      <tbody>
        <?php foreach ($aVenir as $r): $parts = get_participants((int) $r['id']); ?>
          <tr>
            <td><?= h(fr_date($r['date_arrivee'])) ?><br><span class="muted small"><?= h(moment_label($r['moment_arrivee'])) ?></span></td>
            <td><?= h(fr_date($r['date_depart'])) ?><br><span class="muted small"><?= h(moment_label($r['moment_depart'])) ?></span></td>
            <td><?= h($r['room']) ?><?php if ($r['source'] === 'invite'): ?><br><span class="badge badge-invite">invité</span><?php endif; ?></td>
            <td><?= h(noms_participants($parts)) ?>
              <?php if (!empty($r['mdm'])): ?><span class="badge badge-mdm" title="Maître de maison du <?= h(fr_date($r['mdm_debut'])) ?> au <?= h(fr_date($r['mdm_fin'])) ?>">🏠 MdM</span><?php endif; ?>
            </td>
            <td class="num"><?= count($parts) ?></td>
            <td><?= h($r['invite_par']) ?></td>
            <?php if ($peutEditer): ?><td><a class="btn btn-ghost btn-sm" href="modification.php?token=<?= h($r['edit_token']) ?>">Modifier</a></td><?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
<script>
  window.LC_TOUR_KEY = 'calendrier';
  window.LC_TOUR_STEPS = [
    { sel: '.gantt', titre: 'Le planning des chambres',
      texte: 'Chaque ligne est une chambre de La Croze, chaque colonne un jour du mois. Les barres vertes sont les séjours réservés.' },
    { sel: '.gantt-bar', titre: 'Un séjour', cliquer: true,
      texte: 'Survolez une barre pour voir qui séjourne et à quelles dates. Un clic ouvre la page de modification.' },
    { sel: '.legend', titre: 'Le code couleur',
      texte: 'Vert : séjour normal. Violet : invités. Rouge : chevauchement — désormais impossible pour les nouvelles réservations, le site les refuse.' },
    { sel: '.card .actions a', titre: 'Changer de mois',
      texte: 'Naviguez de mois en mois avec ces boutons.' },
    { sel: 'table.data', titre: 'Les séjours à venir',
      texte: 'Et ici, la liste complète des prochains séjours, avec un bouton « Modifier » pour chacun.' }
  ];
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
