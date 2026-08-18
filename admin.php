<?php
/** Administration (réservée au mot de passe admin). */
require __DIR__ . '/bootstrap.php';
require_login(['admin']);

$msg = [];
$err = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $section = $_POST['section'] ?? '';

    if ($section === 'general') {
        $title = trim($_POST['site_title'] ?? '') ?: 'Séjours La Croze';
        set_setting('site_title', $title);
        $msg[] = 'Réglages généraux enregistrés.';
    }

    if ($section === 'ouverture') {
        $annee = trim($_POST['annee_resa'] ?? '');
        $ouv   = trim($_POST['date_ouverture_invites'] ?? '');
        $ferm  = trim($_POST['date_fermeture_resa'] ?? '');
        if ($annee !== '' && !preg_match('/^\d{4}$/', $annee)) {
            $err[] = 'Année de réservation invalide (format attendu : 2026).';
        } elseif ($ouv !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ouv)) {
            $err[] = 'Date d’ouverture aux non-actionnaires invalide.';
        } elseif ($ferm !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ferm)) {
            $err[] = 'Date de fermeture des inscriptions invalide.';
        } else {
            set_setting('annee_resa', $annee);
            set_setting('date_ouverture_invites', $ouv);
            set_setting('date_fermeture_resa', $ferm);
            $msg[] = 'Ouverture des réservations enregistrée'
                   . ($annee !== '' ? " : année $annee uniquement" : ' : toutes années')
                   . ($ouv !== '' ? ', non-actionnaires à partir du ' . fr_date($ouv) : ', non-actionnaires sans restriction')
                   . ($ferm !== '' ? ', fermeture générale après le ' . fr_date($ferm) . '.' : ', pas de date de fermeture.');
        }
    }

    if ($section === 'rooms') {
        $noms  = $_POST['rooms_nom'] ?? [];
        $caps  = $_POST['rooms_cap'] ?? [];
        $types = $_POST['rooms_type'] ?? [];
        $colls = $_POST['rooms_coll'] ?? [];
        $liste = [];
        foreach ($noms as $i => $nom) {   // champs indexés par ligne (les cases non cochées sont absentes)
            $nom = trim((string) $nom);
            if ($nom === '') { continue; }
            $cap = trim((string) ($caps[$i] ?? ''));
            $type = (($types[$i] ?? '') === 'partagee') ? 'partagee' : 'exclusive';
            $liste[] = [
                'nom' => $nom,
                'couchages' => ($cap === '' ? null : max(0, (int) $cap)),
                'type' => $type,
                'collective' => !empty($colls[$i]),
            ];
        }
        if (!$liste) {
            $err[] = 'La liste des chambres ne peut pas être vide.';
        } else {
            set_setting('rooms', json_encode($liste, JSON_UNESCAPED_UNICODE));
            $msg[] = 'Chambres mises à jour (' . count($liste) . ').';
        }
    }

    if ($section === 'ages') {
        $bebe   = max(1, (int) ($_POST['seuil_bebe'] ?? 3));
        $enfant = max($bebe + 1, (int) ($_POST['seuil_enfant'] ?? 12));
        $ado    = max($enfant + 1, (int) ($_POST['seuil_ado'] ?? 18));
        set_setting('age_seuils', json_encode(['bebe' => $bebe, 'enfant' => $enfant, 'ado' => $ado]));
        set_setting('age_parts', (string) max(18, (int) ($_POST['age_parts'] ?? 25)));
        set_setting('jours_par_part', (string) max(1, (int) ($_POST['jours_par_part'] ?? 11)));
        $msg[] = "Seuils enregistrés : bébé < $bebe ans, enfant < $enfant ans, ado < $ado ans ; "
               . 'parts surnuméraires : ' . age_parts() . ' ans et +, ' . jours_par_part() . ' jours/part/an.';
    }

    if ($section === 'passwords') {
        $map = [
            'actionnaire' => ['pwd_actionnaire', 6],
            'invite'      => ['pwd_invite', 6],
            'admin'       => ['pwd_admin', 8],
        ];
        $changed = [];
        foreach ($map as $role => [$key, $min]) {
            $new = $_POST['new_' . $role] ?? '';
            if ($new === '') { continue; } // champ vide = inchangé
            if (strlen($new) < $min) {
                $err[] = "Le mot de passe $role doit faire au moins $min caractères.";
                continue;
            }
            set_setting($key, password_hash($new, PASSWORD_DEFAULT));
            $changed[] = $role;
        }
        if ($changed) { $msg[] = 'Mot(s) de passe modifié(s) : ' . implode(', ', $changed) . '.'; }
        elseif (!$err) { $msg[] = 'Aucun mot de passe modifié.'; }
    }
}

$stats = [
    'resas' => (int) db()->query('SELECT COUNT(*) FROM reservations')->fetchColumn(),
    'pers'  => (int) db()->query('SELECT COUNT(*) FROM participants')->fetchColumn(),
];

$page_title = 'Administration';
$active = 'admin';
require __DIR__ . '/partials/header.php';
?>
<h1>Administration</h1>

<?php foreach ($msg as $m): ?><div class="notice"><?= h($m) ?></div><?php endforeach; ?>
<?php foreach ($err as $e): ?><div class="notice notice-danger"><?= h($e) ?></div><?php endforeach; ?>

<p class="lead"><?= $stats['resas'] ?> réservation(s), <?= $stats['pers'] ?> personne(s) enregistrées.</p>

<div class="card">
  <h2 style="margin-top:0">Réglages généraux</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="general">
    <label>Nom du site
      <input type="text" name="site_title" value="<?= h(site_title()) ?>">
    </label>
    <div class="actions"><button class="btn" type="submit">Enregistrer</button></div>
  </form>
</div>

<div class="card">
  <h2 style="margin-top:0">Ouverture des réservations</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="ouverture">
    <div class="row">
      <div>
        <label>Année ouverte à la réservation
          <span class="hint">seule cette année est réservable (ex. 2026). Vide = toutes les années.
            L'administrateur n'est pas limité.</span>
          <input type="text" name="annee_resa" inputmode="numeric" pattern="\d{4}" placeholder="2026"
                 value="<?= h(setting('annee_resa', '')) ?>">
        </label>
      </div>
      <div>
        <label>Ouverture aux non-actionnaires à partir du
          <span class="hint">avant cette date, le mot de passe « invités / non-actionnaires » est refusé
            à la connexion. Vide = ouvert tout de suite.</span>
          <input type="date" name="date_ouverture_invites" value="<?= h(setting('date_ouverture_invites', '')) ?>">
        </label>
      </div>
      <div>
        <label>Fermeture des inscriptions après le
          <span class="hint">après cette date, plus aucune inscription ni modification (associés ET invités).
            L'administrateur n'est pas concerné. Vide = jamais fermé.</span>
          <input type="date" name="date_fermeture_resa" value="<?= h(setting('date_fermeture_resa', '')) ?>">
        </label>
      </div>
    </div>
    <div class="actions"><button class="btn" type="submit">Enregistrer l’ouverture</button></div>
  </form>
</div>

<div class="card">
  <h2 style="margin-top:0">Chambres &amp; couchages</h2>
  <p class="muted small">Renseignez le nombre de couchages (lits) par chambre. Laissez vide si non pertinent.
    Modifier une chambre déjà utilisée ne change pas les réservations existantes.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="rooms">
    <table class="data" id="tbl-rooms">
      <thead><tr><th>Chambre</th><th style="width:110px">Couchages</th><th style="width:160px">Type</th>
        <th style="width:90px">Collective</th><th style="width:60px"></th></tr></thead>
      <tbody>
        <?php foreach (rooms_full() as $i => $r): ?>
          <tr>
            <td><input type="text" name="rooms_nom[<?= $i ?>]" value="<?= h($r['nom']) ?>" required></td>
            <td><input type="number" name="rooms_cap[<?= $i ?>]" min="0" value="<?= $r['couchages'] === null ? '' : (int) $r['couchages'] ?>"></td>
            <td>
              <select name="rooms_type[<?= $i ?>]">
                <option value="exclusive" <?= $r['type'] === 'exclusive' ? 'selected' : '' ?>>Exclusive (1 résa)</option>
                <option value="partagee" <?= $r['type'] === 'partagee' ? 'selected' : '' ?>>Partagée (dortoir)</option>
              </select>
            </td>
            <td class="center"><input type="checkbox" name="rooms_coll[<?= $i ?>]" value="1" <?= $r['collective'] ? 'checked' : '' ?>></td>
            <td><button type="button" class="btn btn-ghost btn-sm" onclick="this.closest('tr').remove()">✕</button></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="muted small"><strong>Exclusive</strong> : une seule réservation à la fois — impossible de réserver
      la chambre si elle est déjà prise sur les mêmes dates (chambres de couple).<br>
      <strong>Partagée</strong> : plusieurs réservations en même temps, tant que le total de personnes ne
      dépasse pas le nombre de couchages (dortoirs, chambres d'enfants).<br>
      <strong>Collective</strong> : seules ces chambres sont accessibles aux enfants majeurs célibataires
      rattachés à une part surnuméraire (dortoir, maisons des enfants, maison de François…).</p>
    <div class="actions">
      <button type="button" class="btn btn-ghost btn-sm" onclick="ajouterChambre()">+ Ajouter une chambre</button>
      <button class="btn" type="submit">Enregistrer les chambres</button>
    </div>
  </form>
</div>

<div class="card">
  <h2 style="margin-top:0">Catégories d'âge</h2>
  <p class="muted small">Ces seuils déterminent la catégorie attribuée automatiquement à chaque personne
    selon sa date de naissance (au jour d'arrivée du séjour). Modifiable à tout moment.</p>
  <?php $s = seuils_ages(); ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="ages">
    <div class="row">
      <div><label>Bébé : moins de <span class="hint">(ans)</span>
        <input type="number" id="seuil_bebe" name="seuil_bebe" min="1" max="10" value="<?= (int) $s['bebe'] ?>"></label></div>
      <div><label>Enfant : moins de <span class="hint">(ans)</span>
        <input type="number" id="seuil_enfant" name="seuil_enfant" min="2" max="17" value="<?= (int) $s['enfant'] ?>"></label></div>
      <div><label>Ado / jeune : moins de <span class="hint">(ans)</span>
        <input type="number" id="seuil_ado" name="seuil_ado" min="3" max="30" value="<?= (int) $s['ado'] ?>"></label></div>
    </div>
    <p class="muted small" id="phrase-seuils"></p>
    <script>
      // Phrase récapitulative mise à jour EN DIRECT selon les champs ci-dessus.
      (function () {
        var b = document.getElementById('seuil_bebe'),
            e = document.getElementById('seuil_enfant'),
            a = document.getElementById('seuil_ado'),
            p = document.getElementById('phrase-seuils');
        function maj() {
          p.innerHTML = 'Au-delà du dernier seuil : <strong>Adulte</strong>. Avec ces valeurs : bébé &lt; ' +
            (b.value || '?') + ' ans · enfant &lt; ' + (e.value || '?') + ' ans · ado &lt; ' +
            (a.value || '?') + ' ans · adulte ensuite.' +
            ((+e.value <= +b.value || +a.value <= +e.value) ? ' <span style="color:var(--danger)">⚠ Les seuils doivent être croissants.</span>' : '');
        }
        [b, e, a].forEach(function (x) { x.addEventListener('input', maj); });
        maj();
      })();
    </script>
    <hr class="sep">
    <h3 style="margin:.2rem 0 .4rem; font-size:1rem">Parts surnuméraires</h3>
    <div class="row">
      <div><label>Âge de rattachement aux parts <span class="hint">(ans — défaut 25)</span>
        <input type="number" name="age_parts" min="18" max="40" value="<?= age_parts() ?>"></label></div>
      <div><label>Jours offerts par part surnuméraire et par an <span class="hint">(défaut 11)</span>
        <input type="number" name="jours_par_part" min="1" max="60" value="<?= jours_par_part() ?>"></label></div>
    </div>
    <p class="muted small">À partir de cet âge (ou dès le mariage), un enfant rattaché à un actionnaire
      relève des parts surnuméraires : (parts − 1) × jours offerts, ensuite frais de nuitée.</p>
    <div class="actions"><button class="btn" type="submit">Enregistrer les seuils</button></div>
  </form>
</div>

<div class="card">
  <h2 style="margin-top:0">Annuaire des actionnaires</h2>
  <p class="muted small">Gérez la liste des actionnaires et leurs dates de naissance (utilisées pour pré-remplir
    les inscriptions et calculer automatiquement la catégorie d’âge).</p>
  <a class="btn" href="admin-personnes.php">Ouvrir l’annuaire</a>
</div>

<script>
  var idxChambre = 1000; // index libre pour les nouvelles lignes
  function ajouterChambre() {
    var tb = document.querySelector('#tbl-rooms tbody');
    var i = idxChambre++;
    var tr = document.createElement('tr');
    tr.innerHTML = '<td><input type="text" name="rooms_nom[' + i + ']" required></td>' +
      '<td><input type="number" name="rooms_cap[' + i + ']" min="0"></td>' +
      '<td><select name="rooms_type[' + i + ']"><option value="exclusive">Exclusive (1 résa)</option>' +
      '<option value="partagee">Partagée (dortoir)</option></select></td>' +
      '<td class="center"><input type="checkbox" name="rooms_coll[' + i + ']" value="1"></td>' +
      '<td><button type="button" class="btn btn-ghost btn-sm" onclick="this.closest(\'tr\').remove()">✕</button></td>';
    tb.appendChild(tr);
    tr.querySelector('input').focus();
  }
</script>

<div class="card">
  <h2 style="margin-top:0">Mots de passe</h2>
  <p class="muted small">Laissez un champ vide pour ne pas changer le mot de passe correspondant.</p>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="passwords">
    <label>Nouveau mot de passe <strong>actionnaires</strong> <span class="hint">(min. 6)</span>
      <input type="text" name="new_actionnaire" autocomplete="new-password">
    </label>
    <label>Nouveau mot de passe <strong>invités</strong> <span class="hint">(min. 6)</span>
      <input type="text" name="new_invite" autocomplete="new-password">
    </label>
    <label>Nouveau mot de passe <strong>administrateur</strong> <span class="hint">(min. 8)</span>
      <input type="text" name="new_admin" autocomplete="new-password">
    </label>
    <div class="actions"><button class="btn" type="submit">Mettre à jour les mots de passe</button></div>
  </form>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
