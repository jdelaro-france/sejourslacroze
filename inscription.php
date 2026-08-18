<?php
/** Nouvelle inscription (actionnaire ou invité). Crée la réservation + les repas par défaut. */
require __DIR__ . '/bootstrap.php';
require_login();
require __DIR__ . '/partials/resa_shared.php';

$errors = [];
$values = [
    'nb' => 1, 'participants' => [], 'room' => '',
    'date_arrivee' => '', 'moment_arrivee' => 'apres-midi',
    'date_depart' => '', 'moment_depart' => 'matin',
    'email' => '', 'invite_par' => '',
    'mdm' => 0, 'mdm_debut' => '', 'mdm_fin' => '',
];
// Pré-remplissage par l'URL (bouton « autre chambre pour ces mêmes dates »).
$reD = '/^\d{4}-\d{2}-\d{2}$/';
if (preg_match($reD, $_GET['arr'] ?? ''))  { $values['date_arrivee'] = $_GET['arr']; }
if (preg_match($reD, $_GET['dep'] ?? ''))  { $values['date_depart'] = $_GET['dep']; }
if (isset(LC_MOMENTS[$_GET['ma'] ?? ''])) { $values['moment_arrivee'] = $_GET['ma']; }
if (isset(LC_MOMENTS[$_GET['md'] ?? ''])) { $values['moment_depart'] = $_GET['md']; }
$confirmation = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $res = valider_resa($_POST);
    $values = array_merge($values, $res['data'], ['participants' => $res['participants']]);
    $errors = $res['errors'];

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        $token = bin2hex(random_bytes(16));
        $stmt = $pdo->prepare('
            INSERT INTO reservations
              (room, date_arrivee, moment_arrivee, date_depart, moment_depart,
               email, invite_par, source, edit_token, mdm, mdm_debut, mdm_fin, created_at, updated_at)
            VALUES (:room, :da, :ma, :dd, :md, :email, :invite, :source, :token, :mdm, :mdmd, :mdmf, :now, :now)
        ');
        $stmt->execute([
            ':room' => $values['room'],
            ':da' => $values['date_arrivee'], ':ma' => $values['moment_arrivee'],
            ':dd' => $values['date_depart'], ':md' => $values['moment_depart'],
            ':email' => $values['email'],
            ':invite' => $values['invite_par'],
            ':source' => (current_role() === 'invite' ? 'invite' : 'actionnaire'),
            ':token' => $token,
            ':mdm' => (int) ($values['mdm'] ?? 0),
            ':mdmd' => ($values['mdm_debut'] ?? '') ?: null,
            ':mdmf' => ($values['mdm_fin'] ?? '') ?: null,
            ':now' => now(),
        ]);
        $rid = (int) $pdo->lastInsertId();

        $insP = $pdo->prepare('INSERT INTO participants (reservation_id, position, nom, categorie, personne_id) VALUES (?, ?, ?, ?, ?)');
        foreach ($res['participants'] as $pos => $p) {
            $insP->execute([$rid, $pos, $p['nom'], $p['categorie'], $p['personne_id'] ?? null]);
        }
        enregistrer_repas($rid, $values, $_POST['present'] ?? []);
        $pdo->commit();

        // Prévient les maîtres de maison concernés par ces dates.
        notifier_maitres_de_maison(array_merge($values, ['id' => $rid]), 'créé', $res['participants']);

        $confirmation = [
            'id' => $rid,
            'token' => $token,
            'lien' => base_url() . '/modification.php?token=' . $token,
            'infos' => $res['infos'] ?? [],
        ];
    }
}

$page_title = 'Nouvelle inscription';
$active = 'inscription';
$wrap_class = 'wrap-narrow';
require __DIR__ . '/partials/header.php';
?>
<?php if ($confirmation): ?>
  <div class="card">
    <h1>✅ Réservation enregistrée</h1>
    <p>La chambre <strong><?= h($values['room']) ?></strong> est réservée du
       <strong><?= h(fr_date($values['date_arrivee'])) ?></strong> (<?= h(moment_label($values['moment_arrivee'])) ?>)
       au <strong><?= h(fr_date($values['date_depart'])) ?></strong> (<?= h(moment_label($values['moment_depart'])) ?>).</p>
    <p>Les repas midi et soir ont été <strong>pré-cochés</strong> pour vos journées de présence.
       Vous pouvez les ajuster sur la page <a href="repas.php">Repas</a>.</p>

    <?php foreach (($confirmation['infos'] ?? []) as $info): ?>
      <div class="notice">ℹ️ <?= h($info) ?></div>
    <?php endforeach; ?>

    <div class="notice">
      <strong>Lien pour modifier cette réservation</strong> — conservez-le :<br>
      <a class="mono" href="<?= h($confirmation['lien']) ?>"><?= h($confirmation['lien']) ?></a>
    </div>

    <?php $memesDates = 'inscription.php?arr=' . urlencode($values['date_arrivee'])
        . '&dep=' . urlencode($values['date_depart'])
        . '&ma=' . urlencode($values['moment_arrivee'])
        . '&md=' . urlencode($values['moment_depart']); ?>
    <div class="actions">
      <a class="btn" href="calendrier.php">Voir le calendrier</a>
      <a class="btn btn-ghost" href="<?= h($memesDates) ?>">➕ Autre chambre, mêmes dates</a>
      <a class="btn btn-ghost" href="repas.php">Voir les repas</a>
    </div>
    <p class="muted small">« Autre chambre, mêmes dates » : pratique pour inscrire les enfants au dortoir
      ou une seconde chambre pour le même séjour — les dates sont déjà remplies.</p>
  </div>
<?php else: ?>
  <h1>Nouvelle inscription</h1>

  <?php if (inscriptions_fermees()): ?>
    <div class="notice notice-warn">🔒 Les inscriptions sont <strong>fermées</strong> depuis le
      <?= h(fr_date(setting('date_fermeture_resa', ''))) ?>. Contactez l’administrateur pour toute demande.</div>
    <?php require __DIR__ . '/partials/footer.php'; exit; ?>
  <?php endif; ?>

  <p class="lead">Un formulaire <strong>par chambre</strong>. Les repas midi et soir seront cochés par défaut.</p>

  <details class="aide">
    <summary>Comment ça marche ?</summary>
    <ul>
      <li><strong>1.</strong> Indiquez combien vous êtes, puis choisissez chaque personne dans la liste
        (ou tapez son nom). La catégorie d’âge se remplit toute seule.</li>
      <li><strong>2.</strong> Des <strong>suggestions de votre famille</strong> apparaissent : un clic suffit pour les ajouter.</li>
      <li><strong>3.</strong> Choisissez la chambre — si elle est déjà prise à ces dates, le site vous prévient
        immédiatement et l’inscription sera refusée.</li>
      <li><strong>4.</strong> Décochez les repas où vous serez absents, puis enregistrez. Vous recevrez un
        lien pour modifier plus tard.</li>
      <li>Une autre chambre pour le même séjour (ex. les enfants au dortoir) ? Remplissez simplement un
        second formulaire — un bouton vous le proposera après l’enregistrement.</li>
    </ul>
  </details>

  <?php foreach ($errors as $e): ?><div class="notice notice-danger"><?= h($e) ?></div><?php endforeach; ?>

  <div class="card">
    <?php render_resa_form($values, 'Enregistrer l’inscription'); ?>
  </div>

  <script>
    window.LC_TOUR_KEY = 'inscription';
    window.LC_TOUR_STEPS = [
      { sel: '#nb', titre: 'Combien de personnes ?',
        texte: 'Commencez par indiquer le nombre de personnes à inscrire dans cette chambre (une ligne apparaît pour chacune).' },
      <?php if (in_array(current_role(), ['actionnaire', 'admin'], true)): ?>
      { sel: '.person-picker', titre: 'Choisissez dans l’annuaire',
        texte: 'Ouvrez la liste : les membres de la famille y sont déjà. En choisissant quelqu’un, son nom et sa catégorie d’âge se remplissent automatiquement.' },
      <?php endif; ?>
      { sel: 'input[name="personne[1]"]', titre: 'Ou tapez un nom librement', taper: 'Marie Dupont',
        texte: 'Vous pouvez aussi écrire le nom directement, comme ceci. (Ne vous inquiétez pas : ce texte de démonstration s’effacera à la fin de la visite.)' },
      { sel: '#suggestions-famille', titre: 'Suggestions familiales',
        texte: 'Dès qu’un nom de famille est reconnu, les autres membres — les enfants d’abord — apparaissent ici : un clic les ajoute à l’inscription.' },
      { sel: '#room', titre: 'La chambre',
        texte: 'Choisissez la chambre. Le nombre de couchages est indiqué. Une chambre « partagée » (dortoir) peut accueillir plusieurs familles ; les autres n’acceptent qu’une réservation à la fois.' },
      { sel: '#date_arrivee', titre: 'Les dates',
        texte: 'Indiquez les dates, puis le moment d’arrivée et de départ (matin, après-midi, soir) : ils déterminent les repas comptés.' },
      { sel: '#dispo-alerte, #room', titre: 'Le site veille',
        texte: 'Si la chambre est déjà réservée à ces dates, un message rouge apparaît ici immédiatement — impossible de réserver par erreur.' },
      { sel: '#repas-grid', titre: 'Les repas',
        texte: 'Midi et soir sont cochés d’office pour vos jours de présence. Décochez simplement les repas où vous ne serez pas là.' },
      { sel: '.resa-form button[type=submit]', titre: 'Et voilà !', cliquer: true,
        texte: 'Cliquez ici pour enregistrer. Vous recevrez un lien à conserver pour modifier ou annuler plus tard.' }
    ];
  </script>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
