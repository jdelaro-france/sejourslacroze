<?php
/**
 * Vérification de disponibilité en direct (appelée par le formulaire d'inscription).
 * GET : room, arr (YYYY-MM-DD), dep, nb, exclude (id de résa à ignorer, optionnel).
 * Réponse JSON : { ok: bool, message: string|null }
 * NB : simple confort d'interface — la règle est de toute façon appliquée côté serveur à l'enregistrement.
 */
require __DIR__ . '/bootstrap.php';
require_login();

header('Content-Type: application/json; charset=UTF-8');

$room = trim($_GET['room'] ?? '');
$arr  = trim($_GET['arr'] ?? '');
$dep  = trim($_GET['dep'] ?? '');
$nb   = max(1, (int) ($_GET['nb'] ?? 1));
$ex   = (int) ($_GET['exclude'] ?? 0) ?: null;

$re = '/^\d{4}-\d{2}-\d{2}$/';
if ($room === '' || !in_array($room, rooms(), true) || !preg_match($re, $arr) || !preg_match($re, $dep) || $dep < $arr) {
    echo json_encode(['ok' => true, 'message' => null, 'infos' => []]); // paramètres incomplets : pas d'alerte
    exit;
}

$messages = [];
$dispo = verifier_disponibilite($room, $arr, $dep, $nb, $ex);
if ($dispo !== null) { $messages[] = $dispo; }

// Règles de parts (restriction chambres collectives + quotas) pour les fiches identifiées.
$infos = [];
$pids = array_filter(array_map('intval', explode(',', (string) ($_GET['pids'] ?? ''))));
if ($pids) {
    $participants = [];
    foreach ($pids as $i => $pid) {
        $pers = personne_get($pid);
        if ($pers) {
            $participants[$i] = ['nom' => trim($pers['prenom'] . ' ' . $pers['nom']), 'categorie' => '', 'personne_id' => $pid];
        }
    }
    $analyse = analyse_participants($participants, $room, $arr, $dep, $ex);
    foreach ($analyse['erreurs'] as $e) { $messages[] = $e; }
    $infos = $analyse['infos'];
}

echo json_encode([
    'ok' => !$messages,
    'message' => $messages ? implode(' — ', $messages) : null,
    'infos' => $infos,
], JSON_UNESCAPED_UNICODE);
