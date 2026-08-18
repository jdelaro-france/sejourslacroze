<?php
/**
 * Logique partagée entre « Nouvelle inscription » et « Modification ».
 *  - valider_resa()     : validation + normalisation des champs POST
 *  - render_resa_form() : affichage du formulaire (nom + catégorie d'âge par personne,
 *                         chambre, dates/moments, e-mail, et grille de repas décochable)
 */

/**
 * Valide les données d'une réservation, y compris la DISPONIBILITÉ de la chambre
 * (règle stricte : voir verifier_disponibilite()).
 * @param ?int $excludeId id de la réservation en cours de modification (à ignorer dans le contrôle)
 * @return array{data: array, errors: string[], participants: array<int,array{nom:string,categorie:string}>}
 */
function valider_resa(array $post, ?int $excludeId = null): array
{
    $errors = [];

    // Fermeture des inscriptions (réglée en administration ; associés ET invités).
    if (inscriptions_fermees()) {
        $errors[] = 'Les inscriptions et modifications sont fermées depuis le '
                  . fr_date(setting('date_fermeture_resa', '')) . '. Contactez l’administrateur si nécessaire.';
    }

    $nb = (int) ($post['nb'] ?? 0);
    if ($nb < 1 || $nb > LC_MAX_PERSONNES) {
        $errors[] = 'Le nombre de personnes doit être compris entre 1 et ' . LC_MAX_PERSONNES . '.';
        $nb = max(1, min(LC_MAX_PERSONNES, $nb));
    }

    $catsValides = array_keys(LC_CATEGORIES); // inclut '' (non renseigné)
    $participants = [];
    for ($i = 1; $i <= $nb; $i++) {
        $nom = trim($post['personne'][$i] ?? '');
        $cat = $post['categorie'][$i] ?? '';
        if (!in_array($cat, $catsValides, true)) { $cat = ''; }
        if ($nom === '') {
            $errors[] = "Le nom de la personne n°$i est requis.";
        }
        // Rattachement à la fiche annuaire : id transmis par le formulaire, sinon
        // correspondance par le nom (sert aux quotas de parts et à la filiation).
        $pid = (int) ($post['personne_id'][$i] ?? 0) ?: null;
        if ($pid && !personne_get($pid)) { $pid = null; }
        if (!$pid && $nom !== '') {
            $m = personne_match($nom);
            $pid = $m ? (int) $m['id'] : null;
        }
        $participants[$i] = ['nom' => $nom, 'categorie' => $cat, 'personne_id' => $pid];
    }

    $room = trim($post['room'] ?? '');
    if (!in_array($room, rooms(), true)) {
        $errors[] = 'Veuillez choisir une chambre valide.';
    }

    $dateArr = trim($post['date_arrivee'] ?? '');
    $dateDep = trim($post['date_depart'] ?? '');
    $reDate  = '/^\d{4}-\d{2}-\d{2}$/';
    if (!preg_match($reDate, $dateArr)) { $errors[] = 'Date d’arrivée invalide.'; }
    if (!preg_match($reDate, $dateDep)) { $errors[] = 'Date de départ invalide.'; }
    if (preg_match($reDate, $dateArr) && preg_match($reDate, $dateDep) && $dateDep < $dateArr) {
        $errors[] = 'La date de départ ne peut pas précéder la date d’arrivée.';
    }

    $momArr = $post['moment_arrivee'] ?? '';
    $momDep = $post['moment_depart'] ?? '';
    if (!isset(LC_MOMENTS[$momArr])) { $errors[] = 'Moment d’arrivée invalide.'; }
    if (!isset(LC_MOMENTS[$momDep])) { $errors[] = 'Moment de départ invalide.'; }

    // Année ouverte à la réservation (réglée en administration ; l'admin n'est pas limité).
    $anneeOuverte = setting('annee_resa', '');
    if ($anneeOuverte !== '' && !is_admin() && preg_match('/^\d{4}-/', $dateArr)
        && substr($dateArr, 0, 4) !== $anneeOuverte) {
        $errors[] = "Les réservations ne sont ouvertes que pour l'année $anneeOuverte. "
                  . "Pour une autre année, rapprochez-vous de l'administrateur.";
    }

    $email = trim($post['email'] ?? '');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Adresse e-mail invalide.';
    }

    // « Invité par » : menu déroulant des actionnaires titulaires, ou saisie libre (« __autre__ »).
    // Facultatif désormais.
    $invitePar = trim($post['invite_par'] ?? '');
    if ($invitePar === '__autre__') {
        $invitePar = trim($post['invite_par_autre'] ?? '');
    }

    // Maître / maîtresse de maison (réservé aux associés).
    $mdm = (!empty($post['mdm']) && current_role() !== 'invite') ? 1 : 0;
    $mdmDebut = trim($post['mdm_debut'] ?? '');
    $mdmFin   = trim($post['mdm_fin'] ?? '');
    if ($mdm) {
        if (!preg_match($reDate, $mdmDebut) || !preg_match($reDate, $mdmFin)) {
            $errors[] = 'Maître de maison : indiquez les dates de début et de fin de votre service.';
        } elseif ($mdmFin < $mdmDebut) {
            $errors[] = 'Maître de maison : la fin du service ne peut pas précéder son début.';
        } else {
            if ($email === '') {
                $errors[] = 'Maître de maison : votre adresse e-mail est indispensable (vous serez prévenu des changements de séjours pour adapter les courses).';
            }
            $conflit = verifier_mdm($mdmDebut, $mdmFin, $excludeId);
            if ($conflit !== null) { $errors[] = $conflit; }
        }
    } else {
        $mdmDebut = $mdmFin = '';
    }

    // Disponibilité STRICTE + règles de parts : on ne vérifie que si le reste est cohérent.
    $infos = [];
    if (!$errors) {
        $dispo = verifier_disponibilite($room, $dateArr, $dateDep, $nb, $excludeId);
        if ($dispo !== null) { $errors[] = $dispo; }

        $analyse = analyse_participants($participants, $room, $dateArr, $dateDep, $excludeId);
        foreach ($analyse['erreurs'] as $e) { $errors[] = $e; }   // restriction chambres collectives
        $infos = $analyse['infos'];                                // quotas : informatif
    }

    $data = [
        'room'           => $room,
        'date_arrivee'   => $dateArr,
        'moment_arrivee' => $momArr,
        'date_depart'    => $dateDep,
        'moment_depart'  => $momDep,
        'email'          => $email,
        'invite_par'     => $invitePar,
        'nb'             => $nb,
        'mdm'            => $mdm,
        'mdm_debut'      => $mdmDebut,
        'mdm_fin'        => $mdmFin,
    ];

    return ['data' => $data, 'errors' => $errors, 'participants' => $participants, 'infos' => $infos];
}

/**
 * Affiche le formulaire de réservation.
 * @param array  $v            valeurs pré-remplies. 'participants' = [pos => ['nom'=>,'categorie'=>]]
 * @param string $submitLabel  texte du bouton
 * @param array  $hidden       champs cachés supplémentaires [name => value] (ex. token)
 * @param array  $existingMeals présences déjà en base [jour => ['midi'=>0/1,'soir'=>0/1]]
 * @param ?int   $excludeId    réservation à ignorer dans le contrôle de disponibilité (modification)
 */
function render_resa_form(array $v, string $submitLabel, array $hidden = [], array $existingMeals = [], ?int $excludeId = null): void
{
    $isInvite = (current_role() === 'invite') || !empty($v['invite_par']);
    $nb = (int) ($v['nb'] ?? 1);
    $parts = $v['participants'] ?? [];
    // Sélecteur depuis l'annuaire : uniquement pour actionnaires / admin.
    $showPicker = in_array(current_role(), ['actionnaire', 'admin'], true);
    $annuaire = $showPicker ? personnes_list('actionnaire') : [];
    // Annuaire compact pour le JavaScript (suggestions familiales, âge automatique).
    $annuaireJs = array_map(fn($p) => [
        'id' => (int) $p['id'],
        'famille' => $p['nom'],
        'prenom' => $p['prenom'],
        'naissance' => $p['naissance'],
        'email' => $p['email'] ?? '',
        'complet' => trim($p['prenom'] . ' ' . $p['nom']),
    ], $annuaire);
    ?>
    <form method="post" class="resa-form" autocomplete="off">
      <?= csrf_field() ?>
      <?php foreach ($hidden as $hName => $hVal): ?>
        <input type="hidden" name="<?= h($hName) ?>" value="<?= h($hVal) ?>">
      <?php endforeach; ?>

      <label>Personnes à inscrire <span class="hint">(1 à <?= LC_MAX_PERSONNES ?>)</span>
        <input type="number" id="nb" name="nb" min="1" max="<?= LC_MAX_PERSONNES ?>"
               value="<?= (int) $nb ?>" required>
      </label>

      <?php if ($showPicker): ?>
        <p class="muted small">Choisissez une personne dans l’annuaire (le nom et la catégorie d’âge se
          remplissent tout seuls) ou saisissez un nom directement.</p>
      <?php endif; ?>

      <div id="personnes">
        <?php for ($i = 1; $i <= LC_MAX_PERSONNES; $i++):
            $pNom = $parts[$i]['nom'] ?? '';
            $pCat = $parts[$i]['categorie'] ?? 'adulte';
            $pPid = $parts[$i]['personne_id'] ?? ''; ?>
          <div class="personne-row" data-index="<?= $i ?>" style="<?= $i <= $nb ? '' : 'display:none' ?>">
            <input type="hidden" class="pid" name="personne_id[<?= $i ?>]" value="<?= h((string) $pPid) ?>">
            <div class="row" style="align-items:flex-end; gap:.6rem">
              <?php if ($showPicker): ?>
                <div style="flex:2 1 200px">
                  <label>Personne n°<?= $i ?> <span class="hint">annuaire</span>
                    <select class="person-picker" data-index="<?= $i ?>">
                      <option value="">— annuaire ou saisie libre —</option>
                      <?php foreach ($annuaire as $p):
                          $label = trim($p['prenom'] . ' ' . $p['nom']);
                          $age = age_a($p['naissance']);
                          if ($age !== null) { $label .= " ($age ans)"; } ?>
                        <option value="<?= (int) $p['id'] ?>"
                                data-nom="<?= h(trim($p['prenom'] . ' ' . $p['nom'])) ?>"
                                data-naissance="<?= h($p['naissance'] ?? '') ?>"><?= h($label) ?></option>
                      <?php endforeach; ?>
                      <option value="__manual__">➕ Saisie manuelle…</option>
                    </select>
                  </label>
                </div>
                <div style="flex:2 1 180px">
                  <label>Nom affiché
                    <input type="text" name="personne[<?= $i ?>]" value="<?= h($pNom) ?>" <?= $i <= $nb ? 'required' : '' ?>>
                  </label>
                </div>
              <?php else: ?>
                <div style="flex:3 1 220px">
                  <label>Personne n°<?= $i ?> <span class="hint">nom et prénom</span>
                    <input type="text" name="personne[<?= $i ?>]" value="<?= h($pNom) ?>" <?= $i <= $nb ? 'required' : '' ?>>
                  </label>
                </div>
              <?php endif; ?>
              <div style="flex:1 1 120px">
                <label>Âge
                  <select name="categorie[<?= $i ?>]" class="cat-select">
                    <?php foreach (LC_CATEGORIES as $ck => $cl): ?>
                      <option value="<?= h($ck) ?>" <?= $pCat === $ck ? 'selected' : '' ?>><?= h($cl) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
              </div>
              <div style="flex:0 0 auto">
                <button type="button" class="btn btn-ghost btn-sm btn-retirer" title="Retirer cette personne de l’inscription">✕ Retirer</button>
              </div>
            </div>
          </div>
        <?php endfor; ?>
      </div>

      <?php if ($showPicker): ?>
        <div id="suggestions-famille" class="suggestions" style="display:none">
          <span class="sugg-titre">💡 De la même famille — cliquez pour ajouter :</span>
          <span id="suggestions-chips"></span>
        </div>
      <?php endif; ?>

      <?php if ($isInvite):
          // Menu déroulant des actionnaires titulaires (détenteurs de parts — pas leurs enfants).
          $titulaires = array_map(fn($t) => trim($t['prenom'] . ' ' . $t['nom']), actionnaires_titulaires());
          $iv = trim($v['invite_par'] ?? '');
          $ivConnu = ($iv === '' || in_array($iv, $titulaires, true));
      ?>
        <label>Invité par <span class="hint">l’actionnaire qui vous invite (facultatif)</span>
          <select name="invite_par" id="invite_par_sel">
            <option value="">— choisir un actionnaire —</option>
            <?php foreach ($titulaires as $t): ?>
              <option value="<?= h($t) ?>" <?= $iv === $t ? 'selected' : '' ?>><?= h($t) ?></option>
            <?php endforeach; ?>
            <option value="__autre__" <?= !$ivConnu ? 'selected' : '' ?>>Autre…</option>
          </select>
        </label>
        <input type="text" name="invite_par_autre" id="invite_par_autre"
               placeholder="Nom de la personne qui vous invite"
               value="<?= !$ivConnu ? h($iv) : '' ?>" style="<?= !$ivConnu ? '' : 'display:none' ?>">
        <script>
          (function () {
            var sel = document.getElementById('invite_par_sel');
            var autre = document.getElementById('invite_par_autre');
            sel.addEventListener('change', function () {
              autre.style.display = (sel.value === '__autre__') ? '' : 'none';
              if (sel.value === '__autre__') { autre.focus(); }
            });
          })();
        </script>
      <?php endif; ?>

      <label>Chambre
        <select id="room" name="room" required>
          <option value="">— choisir —</option>
          <?php foreach (rooms_full() as $r):
              $det = [];
              if ($r['couchages'] !== null) { $det[] = (int) $r['couchages'] . ' couch.'; }
              if ($r['type'] === 'partagee') { $det[] = 'partagée'; } ?>
            <option value="<?= h($r['nom']) ?>" <?= ($v['room'] ?? '') === $r['nom'] ? 'selected' : '' ?>>
              <?= h($r['nom']) ?><?= $det ? ' (' . implode(', ', $det) . ')' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <div id="capacite-alerte" class="notice notice-warn" style="display:none"></div>
      <div id="dispo-alerte" class="notice notice-danger" style="display:none"></div>
      <div id="parts-info" class="notice" style="display:none"></div>

      <div class="row">
        <div>
          <label>Date d’arrivée
            <input type="date" id="date_arrivee" name="date_arrivee" value="<?= h($v['date_arrivee'] ?? '') ?>" required>
          </label>
          <div class="segmented" role="radiogroup" aria-label="Moment d’arrivée">
            <?php foreach (LC_MOMENTS as $key => $label): ?>
              <label><input class="mom-arr" type="radio" name="moment_arrivee" value="<?= h($key) ?>"
                     <?= ($v['moment_arrivee'] ?? 'apres-midi') === $key ? 'checked' : '' ?>><span><?= h($label) ?></span></label>
            <?php endforeach; ?>
          </div>
        </div>
        <div>
          <label>Date de départ
            <input type="date" id="date_depart" name="date_depart" value="<?= h($v['date_depart'] ?? '') ?>" required>
          </label>
          <div class="segmented" role="radiogroup" aria-label="Moment de départ">
            <?php foreach (LC_MOMENTS as $key => $label): ?>
              <label><input class="mom-dep" type="radio" name="moment_depart" value="<?= h($key) ?>"
                     <?= ($v['moment_depart'] ?? 'matin') === $key ? 'checked' : '' ?>><span><?= h($label) ?></span></label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <label>Adresse e-mail <span class="hint">pour retrouver / modifier votre réservation</span>
        <input type="email" id="email" name="email" value="<?= h($v['email'] ?? '') ?>" placeholder="prenom@exemple.fr">
      </label>

      <?php if (current_role() !== 'invite'): ?>
        <div class="mdm-bloc">
          <label class="mdm-check">
            <input type="checkbox" id="mdm" name="mdm" value="1" <?= !empty($v['mdm']) ? 'checked' : '' ?>>
            <span>🏠 <strong>Maître / maîtresse de maison</strong> — j’assure le service sur une période</span>
          </label>
          <div id="mdm-dates" style="<?= !empty($v['mdm']) ? '' : 'display:none' ?>">
            <div class="row">
              <div><label>Début du service
                <input type="date" id="mdm_debut" name="mdm_debut" value="<?= h($v['mdm_debut'] ?? '') ?>"></label></div>
              <div><label>Fin du service
                <input type="date" id="mdm_fin" name="mdm_fin" value="<?= h($v['mdm_fin'] ?? '') ?>"></label></div>
            </div>
            <p class="muted small">Votre e-mail (ci-dessus) devient obligatoire : vous serez prévenu·e de toute
              création, modification ou annulation de séjour sur votre période, pour ajuster courses et
              ravitaillement. Deux services ne peuvent pas se chevaucher de plus de 2 jours (passation).</p>
          </div>
        </div>
      <?php endif; ?>

      <h2>Repas</h2>
      <p class="muted small">Midi et soir sont <strong>cochés par défaut</strong> selon vos moments d’arrivée et de
        départ. Décochez les repas auxquels vous ne serez pas présent(e).</p>
      <div id="repas-grid" class="table-scroll"></div>

      <div class="actions">
        <button class="btn" type="submit"><?= h($submitLabel) ?></button>
        <a class="btn btn-ghost" href="calendrier.php">Annuler</a>
      </div>
    </form>

    <script>
      (function () {
        var MEALS = <?= json_encode(LC_MEALS, JSON_UNESCAPED_UNICODE) ?>;
        var EXISTING = <?= json_encode((object) $existingMeals, JSON_UNESCAPED_UNICODE) ?>;
        var CAPACITE = <?= json_encode(array_column(rooms_full(), 'couchages', 'nom'), JSON_UNESCAPED_UNICODE) ?>;
        var ANNUAIRE = <?= json_encode($annuaireJs, JSON_UNESCAPED_UNICODE) ?>;
        var SEUILS = <?= json_encode(seuils_ages(), JSON_UNESCAPED_UNICODE) ?>;
        var EXCLUDE_ID = <?= (int) ($excludeId ?? 0) ?>;

        // --- Affichage du bon nombre de personnes ---
        var nb = document.getElementById('nb');
        var rows = document.querySelectorAll('#personnes .personne-row');
        function syncPersonnes() {
          var n = Math.max(1, Math.min(<?= LC_MAX_PERSONNES ?>, parseInt(nb.value || '1', 10)));
          rows.forEach(function (row) {
            var i = parseInt(row.dataset.index, 10);
            var input = row.querySelector('input[type=text]');
            var show = i <= n;
            row.style.display = show ? '' : 'none';
            if (show) { input.setAttribute('required', 'required'); }
            else { input.removeAttribute('required'); }
          });
        }
        nb.addEventListener('input', syncPersonnes);
        syncPersonnes();

        // --- Grille de repas dépendante des dates/moments ---
        var elArr = document.getElementById('date_arrivee');
        var elDep = document.getElementById('date_depart');
        var container = document.getElementById('repas-grid');

        function momArr() { var e = document.querySelector('.mom-arr:checked'); return e ? e.value : 'apres-midi'; }
        function momDep() { var e = document.querySelector('.mom-dep:checked'); return e ? e.value : 'matin'; }

        function defautRepas(jour, arr, mArr, dep, mDep) {
          var isArr = jour === arr, isDep = jour === dep;
          var midi = (isArr ? mArr === 'matin' : true) && (isDep ? mDep !== 'matin' : true);
          var soir = (isDep ? mDep === 'soir' : true);
          return { midi: midi, soir: soir };
        }

        function fmtLocal(d) {
          return d.getFullYear() + '-' +
                 String(d.getMonth() + 1).padStart(2, '0') + '-' +
                 String(d.getDate()).padStart(2, '0');
        }
        function joursEntre(a, b) {
          var out = [], cur = new Date(a + 'T00:00:00'), fin = new Date(b + 'T00:00:00');
          if (isNaN(cur) || isNaN(fin) || fin < cur) return out;
          while (cur <= fin) {
            out.push(fmtLocal(cur));   // format local (évite le décalage UTC)
            cur.setDate(cur.getDate() + 1);
          }
          return out;
        }

        var JOURS_FR = ['Dim', 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam'];
        function libJour(j) {
          var d = new Date(j + 'T00:00:00');
          return JOURS_FR[d.getDay()] + '. ' + d.getDate() + '/' + (d.getMonth() + 1);
        }

        function buildGrid() {
          var arr = elArr.value, dep = elDep.value;
          var jours = joursEntre(arr, dep);
          if (!jours.length) {
            container.innerHTML = '<p class="muted small">Choisissez les dates d’arrivée et de départ pour gérer les repas.</p>';
            return;
          }
          var mArr = momArr(), mDep = momDep();
          var html = '<table class="data repas-grid"><thead><tr><th>Jour</th>';
          Object.keys(MEALS).forEach(function (k) { html += '<th class="num">' + MEALS[k] + '</th>'; });
          html += '</tr></thead><tbody>';
          jours.forEach(function (j) {
            var def = defautRepas(j, arr, mArr, dep, mDep);
            html += '<tr><td>' + libJour(j) + '</td>';
            Object.keys(MEALS).forEach(function (k) {
              var checked;
              if (EXISTING[j] && typeof EXISTING[j][k] !== 'undefined') { checked = !!EXISTING[j][k]; }
              else { checked = !!def[k]; }
              html += '<td><input type="checkbox" name="present[' + j + '][' + k + ']" value="1"' +
                      (checked ? ' checked' : '') + '></td>';
            });
            html += '</tr>';
          });
          html += '</tbody></table>';
          container.innerHTML = html;
        }

        [elArr, elDep].forEach(function (el) { el.addEventListener('change', buildGrid); });
        document.querySelectorAll('.mom-arr, .mom-dep').forEach(function (el) { el.addEventListener('change', buildGrid); });
        buildGrid();

        // --- Âge -> catégorie (seuils réglables dans l'administration) ---
        function ageDe(naissance, ref) {
          if (!naissance) return null;
          var n = new Date(naissance + 'T00:00:00');
          var r = ref ? new Date(ref + 'T00:00:00') : new Date();
          if (isNaN(n) || isNaN(r)) return null;
          var age = r.getFullYear() - n.getFullYear();
          var m = r.getMonth() - n.getMonth();
          if (m < 0 || (m === 0 && r.getDate() < n.getDate())) age--;
          return Math.max(0, age);
        }
        function categorieAge(naissance, ref) {
          var age = ageDe(naissance, ref);
          if (age === null) return '';
          if (age < SEUILS.bebe) return 'bebe';
          if (age < SEUILS.enfant) return 'enfant';
          if (age < SEUILS.ado) return 'ado';
          return 'adulte';
        }

        // --- Sélecteur annuaire : remplit le nom, la catégorie, l'e-mail ---
        var personneParId = {};
        ANNUAIRE.forEach(function (p) { personneParId[String(p.id)] = p; });

        function nomsSaisis() {
          var out = [];
          rows.forEach(function (row) {
            if (row.style.display === 'none') return;
            var v = (row.querySelector('input[type=text]').value || '').trim().toLowerCase();
            if (v) out.push(v);
          });
          return out;
        }

        function appliquerPersonne(row, p) {
          var nomInput = row.querySelector('input[type=text]');
          var catSelect = row.querySelector('.cat-select');
          var pick = row.querySelector('.person-picker');
          var pid = row.querySelector('.pid');
          nomInput.value = p.complet;
          if (pick) { pick.value = String(p.id); }
          if (pid) { pid.value = String(p.id); }
          var cat = categorieAge(p.naissance, elArr.value);
          if (cat && catSelect) { catSelect.value = cat; }
          // Pré-remplit l'e-mail de la réservation si vide.
          var email = document.querySelector('input[name=email]');
          if (email && !email.value && p.email) { email.value = p.email; }
          majSuggestions();
          verifDispo();
        }

        document.querySelectorAll('.person-picker').forEach(function (pick) {
          pick.addEventListener('change', function () {
            var row = pick.closest('.personne-row');
            if (pick.value === '' || pick.value === '__manual__') {
              if (pick.value === '__manual__') {
                var inp = row.querySelector('input[type=text]');
                inp.value = ''; inp.focus(); pick.value = '';
                var pid = row.querySelector('.pid'); if (pid) { pid.value = ''; }
              }
              return;
            }
            var p = personneParId[pick.value];
            if (p) { appliquerPersonne(row, p); }
          });
        });

        // Saisie libre : tente de relier à une fiche de l'annuaire (pour parts & quotas).
        function relierParNom(row) {
          var pid = row.querySelector('.pid');
          if (!pid) { return; }
          var v = (row.querySelector('input[type=text]').value || '').trim().toLowerCase();
          var match = null;
          ANNUAIRE.forEach(function (p) { if (v && p.complet.toLowerCase() === v) { match = p; } });
          pid.value = match ? String(match.id) : '';
        }

        // --- Retirer une personne : décale les lignes suivantes vers le haut ---
        function lireLigne(row) {
          return {
            nom: row.querySelector('input[type=text]').value,
            cat: row.querySelector('.cat-select') ? row.querySelector('.cat-select').value : 'adulte',
            pid: row.querySelector('.pid') ? row.querySelector('.pid').value : '',
            pick: row.querySelector('.person-picker') ? row.querySelector('.person-picker').value : ''
          };
        }
        function ecrireLigne(row, d) {
          row.querySelector('input[type=text]').value = d.nom;
          if (row.querySelector('.cat-select')) { row.querySelector('.cat-select').value = d.cat; }
          if (row.querySelector('.pid')) { row.querySelector('.pid').value = d.pid; }
          if (row.querySelector('.person-picker')) { row.querySelector('.person-picker').value = d.pick; }
        }
        document.querySelectorAll('.btn-retirer').forEach(function (btn) {
          btn.addEventListener('click', function () {
            var row = btn.closest('.personne-row');
            var i = parseInt(row.dataset.index, 10);
            var n = Math.max(1, parseInt(nb.value || '1', 10));
            var liste = Array.prototype.slice.call(rows);
            for (var j = i; j < n; j++) {          // décale j+1 -> j
              ecrireLigne(liste[j - 1], lireLigne(liste[j]));
            }
            ecrireLigne(liste[n - 1], { nom: '', cat: 'adulte', pid: '', pick: '' }); // vide la dernière
            if (n > 1) { nb.value = n - 1; }
            syncPersonnes();
            majSuggestions(); verifCapacite(); verifDispo();
          });
        });

        // --- Suggestions familiales : membres du même nom, mineurs d'abord ---
        var suggBox = document.getElementById('suggestions-famille');
        var suggChips = document.getElementById('suggestions-chips');

        function famillesSelectionnees() {
          var fams = {};
          rows.forEach(function (row) {
            if (row.style.display === 'none') return;
            var pick = row.querySelector('.person-picker');
            if (pick && pick.value && personneParId[pick.value]) {
              fams[personneParId[pick.value].famille] = true;
              return;
            }
            // Saisie libre : tente de reconnaître un membre de l'annuaire.
            var v = (row.querySelector('input[type=text]').value || '').trim().toLowerCase();
            ANNUAIRE.forEach(function (p) { if (v && p.complet.toLowerCase() === v) { fams[p.famille] = true; } });
          });
          return Object.keys(fams);
        }

        function majSuggestions() {
          if (!suggBox || !suggChips) return;
          var fams = famillesSelectionnees();
          var deja = nomsSaisis();
          var candidats = ANNUAIRE.filter(function (p) {
            return fams.indexOf(p.famille) >= 0 && deja.indexOf(p.complet.toLowerCase()) < 0;
          });
          // Mineurs (à la date d'arrivée) d'abord, puis par âge croissant.
          candidats.sort(function (a, b) {
            var aa = ageDe(a.naissance, elArr.value), ab = ageDe(b.naissance, elArr.value);
            var ma = (aa !== null && aa < SEUILS.ado) ? 0 : 1;
            var mb = (ab !== null && ab < SEUILS.ado) ? 0 : 1;
            if (ma !== mb) return ma - mb;
            return (aa === null ? 999 : aa) - (ab === null ? 999 : ab);
          });
          candidats = candidats.slice(0, 12);
          if (!candidats.length) { suggBox.style.display = 'none'; return; }
          suggChips.innerHTML = '';
          candidats.forEach(function (p) {
            var age = ageDe(p.naissance, elArr.value);
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'chip';
            btn.textContent = '＋ ' + p.prenom + (age !== null ? ' (' + age + ' ans)' : '');
            btn.title = p.complet;
            btn.addEventListener('click', function () {
              var n = Math.max(1, Math.min(<?= LC_MAX_PERSONNES ?>, parseInt(nb.value || '1', 10)));
              // Réutilise la première ligne visible vide, sinon en ajoute une.
              var cible = null;
              rows.forEach(function (row) {
                if (cible || row.style.display === 'none') return;
                if (!(row.querySelector('input[type=text]').value || '').trim()) { cible = row; }
              });
              if (!cible) {
                if (n >= <?= LC_MAX_PERSONNES ?>) return;
                nb.value = n + 1; syncPersonnes();
                cible = document.querySelector('.personne-row[data-index="' + (n + 1) + '"]');
              }
              if (cible) { appliquerPersonne(cible, p); }
              verifCapacite(); verifDispo();
            });
            suggChips.appendChild(btn);
          });
          suggBox.style.display = '';
        }
        rows.forEach(function (row) {
          var inp = row.querySelector('input[type=text]');
          inp.addEventListener('input', function () {
            relierParNom(row);
            majSuggestions();
            verifDispo();
          });
        });
        nb.addEventListener('input', majSuggestions);

        // --- Alerte de capacité (couchages de la chambre) ---
        var elRoom = document.getElementById('room');
        var elAlerte = document.getElementById('capacite-alerte');
        function verifCapacite() {
          if (!elRoom || !elAlerte) return;
          var cap = CAPACITE[elRoom.value];
          var n = Math.max(1, parseInt(nb.value || '1', 10));
          if (cap != null && cap !== '' && n > cap) {
            elAlerte.textContent = '⚠︎ ' + n + ' personnes pour ' + cap + ' couchage(s) dans « ' + elRoom.value + ' ».';
            elAlerte.style.display = '';
          } else {
            elAlerte.style.display = 'none';
          }
        }

        // --- Vérification en direct : disponibilité + règles de parts (aussi appliquées côté serveur) ---
        var elDispo = document.getElementById('dispo-alerte');
        var elParts = document.getElementById('parts-info');
        var dispoTimer = null;
        function pidsActuels() {
          var ids = [];
          rows.forEach(function (row) {
            if (row.style.display === 'none') return;
            var pid = row.querySelector('.pid');
            if (pid && pid.value) { ids.push(pid.value); }
          });
          return ids.join(',');
        }
        function verifDispo() {
          if (!elDispo || !elRoom) return;
          if (!elRoom.value || !elArr.value || !elDep.value) {
            elDispo.style.display = 'none';
            if (elParts) { elParts.style.display = 'none'; }
            return;
          }
          clearTimeout(dispoTimer);
          dispoTimer = setTimeout(function () {
            var n = Math.max(1, parseInt(nb.value || '1', 10));
            var url = 'api-dispo.php?room=' + encodeURIComponent(elRoom.value) +
                      '&arr=' + encodeURIComponent(elArr.value) +
                      '&dep=' + encodeURIComponent(elDep.value) +
                      '&nb=' + n + '&pids=' + encodeURIComponent(pidsActuels()) +
                      (EXCLUDE_ID ? '&exclude=' + EXCLUDE_ID : '');
            fetch(url).then(function (r) { return r.json(); }).then(function (d) {
              if (d && d.message) {
                elDispo.textContent = '🚫 ' + d.message;
                elDispo.style.display = '';
              } else {
                elDispo.style.display = 'none';
              }
              if (elParts) {
                elParts.textContent = '';
                if (d && d.infos && d.infos.length) {
                  d.infos.forEach(function (t) {
                    var ligne = document.createElement('div');
                    ligne.textContent = 'ℹ️ ' + t;
                    elParts.appendChild(ligne);
                  });
                  elParts.style.display = '';
                } else {
                  elParts.style.display = 'none';
                }
              }
            }).catch(function () {
              elDispo.style.display = 'none';
              if (elParts) { elParts.style.display = 'none'; }
            });
          }, 250);
        }

        if (elRoom) { elRoom.addEventListener('change', function () { verifCapacite(); verifDispo(); }); }
        nb.addEventListener('input', function () { verifCapacite(); verifDispo(); });
        [elArr, elDep].forEach(function (el) { el.addEventListener('change', verifDispo); });
        verifCapacite(); verifDispo(); majSuggestions();

        // --- Maître / maîtresse de maison ---
        var mdmCheck = document.getElementById('mdm');
        var mdmDates = document.getElementById('mdm-dates');
        if (mdmCheck && mdmDates) {
          var mdmDebut = document.getElementById('mdm_debut');
          var mdmFin = document.getElementById('mdm_fin');
          var emailInput = document.getElementById('email');
          function syncMdm() {
            mdmDates.style.display = mdmCheck.checked ? '' : 'none';
            if (emailInput) {
              if (mdmCheck.checked) { emailInput.setAttribute('required', 'required'); }
              else { emailInput.removeAttribute('required'); }
            }
            if (mdmCheck.checked) {
              // Par défaut, le service couvre les dates du séjour.
              if (mdmDebut && !mdmDebut.value && elArr.value) { mdmDebut.value = elArr.value; }
              if (mdmFin && !mdmFin.value && elDep.value) { mdmFin.value = elDep.value; }
            }
          }
          mdmCheck.addEventListener('change', syncMdm);
          syncMdm();
        }
      })();
    </script>
    <?php
}
