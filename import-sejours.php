<?php
/**
 * Importe les séjours reconstitués depuis data/sejours2026.json
 * (produit par extract_sejours.py à partir des exports hebdo de l'ancien site).
 *
 * ⚠️  Remplace TOUTES les réservations de l'année couverte par le fichier,
 *     puis insère les séjours importés avec leurs repas exacts.
 *
 * Usage :  php import-sejours.php            (CLI)
 *          import-sejours.php?confirm=1      (navigateur, admin connecté)
 */
require __DIR__ . '/bootstrap.php';

$cli = (PHP_SAPI === 'cli');
if (!$cli) {
    require_login(['admin']);
    if (($_GET['confirm'] ?? '') !== '1') {
        header('Content-Type: text/html; charset=UTF-8');
        echo '<p style="font-family:sans-serif">Importer les séjours depuis <code>data/sejours2026.json</code> ?<br>
              Les réservations existantes de l’année couverte seront remplacées.<br>
              <a href="import-sejours.php?confirm=1">Confirmer l’import</a> · <a href="admin.php">Annuler</a></p>';
        exit;
    }
}

$path = __DIR__ . '/data/sejours2026.json';
if (!is_file($path)) {
    $m = 'Fichier data/sejours2026.json introuvable.';
    if ($cli) { fwrite(STDERR, $m . "\n"); exit(1); }
    exit(h($m));
}

$data = json_decode((string) file_get_contents($path), true);
if (!is_array($data) || empty($data['reservations'])) { exit('sejours2026.json illisible ou vide.'); }

$annee = substr($data['periode']['debut'], 0, 4);
$pdo = db();
$pdo->beginTransaction();

// Remplace les réservations de l'année couverte (démo comprise).
$del = $pdo->prepare('SELECT id FROM reservations WHERE substr(date_arrivee, 1, 4) = ?');
$del->execute([$annee]);
$supprimees = 0;
foreach ($del->fetchAll() as $row) {
    $pdo->prepare('DELETE FROM reservations WHERE id = ?')->execute([(int) $row['id']]);
    $supprimees++;
}

$insR = $pdo->prepare('
    INSERT INTO reservations
      (room, date_arrivee, moment_arrivee, date_depart, moment_depart,
       email, invite_par, source, edit_token, created_at, updated_at)
    VALUES (:room,:da,:ma,:dd,:md,"","",:source,:token,:now,:now)
');
$insP = $pdo->prepare('INSERT INTO participants (reservation_id, position, nom, categorie) VALUES (?,?,?,?)');
$insM = $pdo->prepare('INSERT INTO repas (reservation_id, jour, repas, present) VALUES (?,?,?,?)');

$roomsConnues = rooms();
$roomsInconnues = [];
$nb = 0; $nbPers = 0;

// Anciens noms de chambres -> noms actuels.
$mapping = ['Maison des filles' => 'Maison des filles - Bas'];

foreach ($data['reservations'] as $r) {
    $r['room'] = $mapping[$r['room']] ?? $r['room'];
    if (!in_array($r['room'], $roomsConnues, true)) { $roomsInconnues[$r['room']] = true; }
    $insR->execute([
        ':room' => $r['room'],
        ':da' => $r['date_arrivee'], ':ma' => $r['moment_arrivee'],
        ':dd' => $r['date_depart'], ':md' => $r['moment_depart'],
        ':source' => ($r['source'] === 'invite' ? 'invite' : 'actionnaire'),
        ':token' => bin2hex(random_bytes(16)),
        ':now' => now(),
    ]);
    $rid = (int) $pdo->lastInsertId();
    foreach ($r['participants'] as $i => $p) {
        $insP->execute([$rid, $i + 1, $p['nom'], $p['categorie'] ?? '']);
        $nbPers++;
    }
    // Repas exacts issus de l'ancien site (pas les valeurs par défaut).
    foreach (jours_entre($r['date_arrivee'], $r['date_depart']) as $jour) {
        $jr = $r['repas'][$jour] ?? ['midi' => 0, 'soir' => 0];
        foreach (LC_MEALS as $meal => $_l) {
            $insM->execute([$rid, $jour, $meal, !empty($jr[$meal]) ? 1 : 0]);
        }
    }
    $nb++;
}

$pdo->commit();

$msg = "Import $annee terminé : $nb réservations, $nbPers participants "
     . "($supprimees anciennes réservations $annee remplacées).";
if ($roomsInconnues) {
    $msg .= "\nAttention, chambres absentes des réglages : " . implode(', ', array_keys($roomsInconnues));
}
if ($cli) {
    echo $msg . "\n";
} else {
    flash_set($msg);
    redirect('calendrier.php?m=' . substr($data['periode']['debut'], 0, 7));
}
