<?php
/** Modification / suppression d'une réservation, identifiée par son token d'édition. */
require __DIR__ . '/bootstrap.php';
require_login();
require __DIR__ . '/partials/resa_shared.php';

$token = $_POST['token'] ?? $_GET['token'] ?? '';
$resa = $token ? get_reservation_by_token($token) : null;

$errors = [];
$done = null;

if ($resa && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    // Suppression.
    if (($_POST['op'] ?? '') === 'delete') {
        if (inscriptions_fermees()) {
            flash_set('Les modifications sont fermées depuis le ' . fr_date(setting('date_fermeture_resa', '')) . '.');
            redirect('modification.php?token=' . urlencode($token));
        }
        $ancienne = $resa; // copie pour la notification (avant suppression)
        $stmt = db()->prepare('DELETE FROM reservations WHERE id = ?');
        $stmt->execute([(int) $resa['id']]);
        notifier_maitres_de_maison($ancienne, 'annulé', $ancienne['participants']);
        flash_set('Réservation supprimée.');
        redirect('calendrier.php');
    }

    // Mise à jour (le contrôle de disponibilité ignore la réservation elle-même).
    $res = valider_resa($_POST, (int) $resa['id']);
    $errors = $res['errors'];
    $data = $res['data'];

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('
            UPDATE reservations SET
              room = :room, date_arrivee = :da, moment_arrivee = :ma,
              date_depart = :dd, moment_depart = :md, email = :email,
              invite_par = :invite, mdm = :mdm, mdm_debut = :mdmd, mdm_fin = :mdmf,
              updated_at = :now
            WHERE id = :id
        ');
        $stmt->execute([
            ':room' => $data['room'],
            ':da' => $data['date_arrivee'], ':ma' => $data['moment_arrivee'],
            ':dd' => $data['date_depart'], ':md' => $data['moment_depart'],
            ':email' => $data['email'], ':invite' => $data['invite_par'],
            ':mdm' => (int) ($data['mdm'] ?? 0),
            ':mdmd' => ($data['mdm_debut'] ?? '') ?: null,
            ':mdmf' => ($data['mdm_fin'] ?? '') ?: null,
            ':now' => now(), ':id' => (int) $resa['id'],
        ]);

        // Participants : on remplace.
        $pdo->prepare('DELETE FROM participants WHERE reservation_id = ?')->execute([(int) $resa['id']]);
        $insP = $pdo->prepare('INSERT INTO participants (reservation_id, position, nom, categorie, personne_id) VALUES (?, ?, ?, ?, ?)');
        foreach ($res['participants'] as $pos => $p) {
            $insP->execute([(int) $resa['id'], $pos, $p['nom'], $p['categorie'], $p['personne_id'] ?? null]);
        }

        // Repas : on enregistre l'état des cases soumises (grille du formulaire).
        enregistrer_repas((int) $resa['id'], $data, $_POST['present'] ?? []);

        $pdo->commit();

        // Prévient les maîtres de maison concernés (nouvelles dates).
        notifier_maitres_de_maison(array_merge($data, ['id' => (int) $resa['id']]), 'modifié', $res['participants']);

        $suffixe = '';
        foreach (($res['infos'] ?? []) as $info) { $suffixe .= ' — ' . $info; }
        flash_set('Réservation mise à jour.' . $suffixe);
        redirect('modification.php?token=' . urlencode($token));
    }

    // En cas d'erreur on réaffiche les valeurs saisies.
    $resa = array_merge($resa, $data);
    $resa['participants'] = [];
    foreach ($res['participants'] as $pos => $p) {
        $resa['participants'][$pos] = ['position' => $pos, 'nom' => $p['nom'],
                                       'categorie' => $p['categorie'], 'personne_id' => $p['personne_id'] ?? ''];
    }
}

$page_title = 'Modifier une réservation';
$active = '';
$wrap_class = 'wrap-narrow';
require __DIR__ . '/partials/header.php';
?>
<h1>Modifier une réservation</h1>

<?php if (inscriptions_fermees()): ?>
  <div class="notice notice-warn">🔒 Les modifications sont <strong>fermées</strong> depuis le
    <?= h(fr_date(setting('date_fermeture_resa', ''))) ?>. Contactez l’administrateur pour toute demande.</div>
  <?php require __DIR__ . '/partials/footer.php'; exit; ?>
<?php endif; ?>

<?php if (!$resa): ?>
  <div class="card">
    <p class="notice notice-warn">Réservation introuvable. Le lien de modification est peut-être incorrect ou la
      réservation a été supprimée.</p>
    <p>Vous pouvez retrouver une réservation depuis le <a href="calendrier.php">calendrier</a>.</p>
  </div>
<?php else: ?>
  <?php
    // Prépare les valeurs pour le formulaire.
    $parts = [];
    foreach ($resa['participants'] as $p) {
        $parts[(int) $p['position']] = [
            'nom' => $p['nom'],
            'categorie' => $p['categorie'] ?? 'adulte',
            'personne_id' => $p['personne_id'] ?? '',
        ];
    }
    $values = [
        'nb' => count($parts) ?: 1,
        'participants' => $parts,
        'room' => $resa['room'],
        'date_arrivee' => $resa['date_arrivee'], 'moment_arrivee' => $resa['moment_arrivee'],
        'date_depart' => $resa['date_depart'], 'moment_depart' => $resa['moment_depart'],
        'email' => $resa['email'], 'invite_par' => $resa['invite_par'],
        'mdm' => (int) ($resa['mdm'] ?? 0),
        'mdm_debut' => $resa['mdm_debut'] ?? '', 'mdm_fin' => $resa['mdm_fin'] ?? '',
    ];
    // Présences déjà enregistrées (pour pré-cocher la grille).
    $existingMeals = [];
    $mstmt = db()->prepare('SELECT jour, repas, present FROM repas WHERE reservation_id = ?');
    $mstmt->execute([(int) $resa['id']]);
    foreach ($mstmt->fetchAll() as $mr) { $existingMeals[$mr['jour']][$mr['repas']] = (int) $mr['present']; }
  ?>
  <p class="lead">Chambre actuelle : <strong><?= h($resa['room']) ?></strong>
    <?php if ($resa['source'] === 'invite'): ?><span class="badge badge-invite">invité</span><?php endif; ?></p>

  <?php foreach ($errors as $e): ?><div class="notice notice-danger"><?= h($e) ?></div><?php endforeach; ?>

  <div class="card">
    <?php render_resa_form($values, 'Enregistrer les modifications', ['token' => $token], $existingMeals, (int) $resa['id']); ?>
  </div>

  <div class="card">
    <h2>Supprimer</h2>
    <p class="muted small">Cette action est définitive et retire aussi les repas associés.</p>
    <form method="post" onsubmit="return confirm('Supprimer définitivement cette réservation ?');">
      <?= csrf_field() ?>
      <input type="hidden" name="token" value="<?= h($token) ?>">
      <input type="hidden" name="op" value="delete">
      <button class="btn btn-danger" type="submit">Supprimer cette réservation</button>
    </form>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
