<?php
/**
 * Importe l'annuaire (extrait de l'ancien fichier Excel) dans la table `personnes`.
 * Lit data/annuaire.json — format : {"actionnaires":[{nom,prenom,naissance}], "invites":[...]}.
 *
 * Usage :  php import-annuaire.php               (CLI)
 *          import-annuaire.php?confirm=1         (navigateur, réservé à l'admin connecté)
 *
 * Réservé aux actionnaires par défaut. Réexécutable : remplace l'annuaire existant du type importé.
 */
require __DIR__ . '/bootstrap.php';

$cli = (PHP_SAPI === 'cli');
if (!$cli) {
    require_login(['admin']);
    if (($_GET['confirm'] ?? '') !== '1') {
        header('Content-Type: text/html; charset=UTF-8');
        echo '<p style="font-family:sans-serif">Importer l’annuaire depuis <code>data/annuaire.json</code> ?<br>
              <a href="import-annuaire.php?confirm=1">Confirmer l’import</a> · <a href="admin-personnes.php">Annuler</a></p>';
        exit;
    }
}

$path = __DIR__ . '/data/annuaire.json';
if (!is_file($path)) { $path = __DIR__ . '/annuaire.json'; }
if (!is_file($path)) {
    $m = "Fichier annuaire.json introuvable (attendu dans data/).";
    if ($cli) { fwrite(STDERR, $m . "\n"); exit(1); }
    exit(h($m));
}

$data = json_decode((string) file_get_contents($path), true);
if (!is_array($data)) { exit('annuaire.json illisible.'); }

// Par défaut on importe seulement les actionnaires (les invités « on verra plus tard »).
$types = ['actionnaires' => 'actionnaire'];
if (($_GET['invites'] ?? '') === '1' || in_array('--invites', $argv ?? [], true)) {
    $types['invites'] = 'invite';
}

$pdo = db();
$total = 0;
foreach ($types as $cle => $type) {
    $pdo->prepare('DELETE FROM personnes WHERE type = ?')->execute([$type]);
    foreach (($data[$cle] ?? []) as $p) {
        personne_save(null, $type, trim($p['nom'] ?? ''), trim($p['prenom'] ?? ''), $p['naissance'] ?? null);
        $total++;
    }
}

$msg = "Annuaire importé : $total personnes (" . implode(', ', array_values($types)) . ").";
if ($cli) {
    echo $msg . "\n";
} else {
    flash_set($msg);
    redirect('admin-personnes.php');
}
