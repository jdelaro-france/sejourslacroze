<?php
/** Administration — Annuaire des actionnaires (ajouter / modifier / supprimer). */
require __DIR__ . '/bootstrap.php';
require_login(['admin']);

$err = [];
$edit = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $op = $_POST['op'] ?? 'save';

    if ($op === 'delete') {
        personne_delete((int) ($_POST['id'] ?? 0));
        flash_set('Personne supprimée.');
        redirect('admin-personnes.php');
    }

    // Saisie rapide des parts (grille globale).
    if ($op === 'parts_bulk') {
        $n = 0;
        foreach (($_POST['parts'] ?? []) as $pid => $val) {
            $p = personne_get((int) $pid);
            if (!$p) { continue; }
            $parts = ($val === '' ? 0 : max(0, (float) str_replace(',', '.', (string) $val)));
            if ((float) $p['parts'] !== $parts) {
                db()->prepare('UPDATE personnes SET parts = ? WHERE id = ?')->execute([$parts, (int) $pid]);
                $n++;
            }
        }
        flash_set("Parts mises à jour ($n fiche(s) modifiée(s)).");
        redirect('admin-personnes.php');
    }

    // Rattacher un enfant à la personne en cours d'édition.
    if ($op === 'add_child') {
        $parentId = (int) ($_POST['id'] ?? 0);
        $childId  = (int) ($_POST['child_id'] ?? 0);
        $parentOk = personne_get($parentId);
        $childOk  = personne_get($childId);
        if ($parentOk && $childOk && $childId !== $parentId
            && !in_array($childId, [$parentId], true)
            && !in_array($parentId, descendants_ids($childId), true)) { // anti-cycle
            db()->prepare('UPDATE personnes SET parent_id = ? WHERE id = ?')->execute([$parentId, $childId]);
            flash_set(trim($childOk['prenom'] . ' ' . $childOk['nom']) . ' rattaché·e comme enfant.');
        } else {
            flash_set('Rattachement impossible (lien circulaire ou fiche introuvable).');
        }
        redirect('admin-personnes.php?edit=' . $parentId);
    }

    // Détacher un enfant.
    if ($op === 'detach_child') {
        $childId = (int) ($_POST['child_id'] ?? 0);
        db()->prepare('UPDATE personnes SET parent_id = NULL WHERE id = ?')->execute([$childId]);
        flash_set('Enfant détaché.');
        redirect('admin-personnes.php?edit=' . (int) ($_POST['id'] ?? 0));
    }

    $id     = (int) ($_POST['id'] ?? 0) ?: null;
    $nom    = trim($_POST['nom'] ?? '');
    $prenom = trim($_POST['prenom'] ?? '');
    $naiss  = trim($_POST['naissance'] ?? '');
    $email  = trim($_POST['email'] ?? '');
    $extra  = [
        'parts'       => ($_POST['parts'] ?? '') === '' ? 0 : (float) str_replace(',', '.', $_POST['parts']),
        'parent_id'   => (int) ($_POST['parent_id'] ?? 0) ?: null,
        'marie'       => !empty($_POST['marie']),
        'conjoint_id' => (int) ($_POST['conjoint_id'] ?? 0) ?: null,
    ];
    if ($nom === '' && $prenom === '') { $err[] = 'Indiquez au moins un nom ou un prénom.'; }
    if ($naiss !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $naiss)) { $err[] = 'Date de naissance invalide.'; }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $err[] = 'Adresse e-mail invalide.'; }
    if ($extra['parent_id'] && $id && $extra['parent_id'] === $id) { $err[] = 'Une personne ne peut pas être son propre parent.'; }

    if (!$err) {
        personne_save($id, 'actionnaire', $nom, $prenom, $naiss ?: null, $email, $extra);
        flash_set($id ? 'Personne modifiée.' : 'Personne ajoutée.');
        redirect('admin-personnes.php');
    }
    $edit = ['id' => $id, 'nom' => $nom, 'prenom' => $prenom, 'naissance' => $naiss, 'email' => $email,
             'parts' => $extra['parts'], 'parent_id' => $extra['parent_id'], 'marie' => $extra['marie'] ? 1 : 0,
             'conjoint_id' => $extra['conjoint_id']];
}

if (!$edit && isset($_GET['edit'])) {
    $edit = personne_get((int) $_GET['edit']);
}

$personnes = personnes_list('actionnaire');
$nbSansDate = 0;
$parId = [];
foreach ($personnes as $p) {
    if (!$p['naissance']) { $nbSansDate++; }
    $parId[(int) $p['id']] = $p;
}

// Quotas de parts pour l'année ouverte (ou l'année en cours).
$anneeQuota = setting('annee_resa', '') ?: date('Y');
$quotas = [];
foreach ($personnes as $p) {
    if ((float) $p['parts'] >= 2) {
        $total = quota_total($p);
        $utilise = quota_utilise((int) $p['id'], $anneeQuota);
        $enfants = array_filter($personnes, fn($e) => (int) ($e['parent_id'] ?? 0) === (int) $p['id']);
        $quotas[] = ['p' => $p, 'total' => $total, 'utilise' => $utilise,
                     'reste' => $total - $utilise, 'enfants' => count($enfants)];
    }
}

$page_title = 'Annuaire des actionnaires';
$active = 'admin';
require __DIR__ . '/partials/header.php';
?>
<h1>Annuaire des actionnaires</h1>
<p class="lead"><a href="admin.php">← Administration</a> · <?= count($personnes) ?> personnes<?= $nbSansDate ? " · $nbSansDate sans date de naissance" : '' ?>.</p>

<?php foreach ($err as $e): ?><div class="notice notice-danger"><?= h($e) ?></div><?php endforeach; ?>

<?php if ($quotas): ?>
<div class="card">
  <h2 style="margin-top:0">Quotas des parts surnuméraires — <?= h($anneeQuota) ?></h2>
  <p class="muted small">Chaque part au-delà de la première offre <?= jours_par_part() ?> jours par an aux enfants
    de <?= age_parts() ?> ans et plus (ou mariés). Au-delà : frais de nuitée comme un invité.</p>
  <div class="table-scroll">
    <table class="data">
      <thead><tr><th>Actionnaire</th><th class="num">Parts</th><th class="num">Jours/an</th>
        <th class="num">Utilisés</th><th class="num">Restants</th><th class="num">Enfants rattachés</th></tr></thead>
      <tbody>
        <?php foreach ($quotas as $q): ?>
          <tr>
            <td><?= h(trim($q['p']['prenom'] . ' ' . $q['p']['nom'])) ?></td>
            <td class="num"><?= h((string) $q['p']['parts']) ?></td>
            <td class="num"><?= $q['total'] ?></td>
            <td class="num"><?= $q['utilise'] ?></td>
            <td class="num"><?= $q['reste'] >= 0 ? $q['reste'] : '<span class="badge badge-warn">' . $q['reste'] . '</span>' ?></td>
            <td class="num"><?= $q['enfants'] ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <h2 style="margin-top:0"><?= $edit && !empty($edit['id']) ? 'Modifier une personne' : 'Ajouter une personne' ?></h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="op" value="save">
    <input type="hidden" name="id" value="<?= h((string) ($edit['id'] ?? '')) ?>">
    <div class="row">
      <div><label>Nom<input type="text" name="nom" value="<?= h($edit['nom'] ?? '') ?>"></label></div>
      <div><label>Prénom<input type="text" name="prenom" value="<?= h($edit['prenom'] ?? '') ?>"></label></div>
      <div><label>Date de naissance <span class="hint">(facultatif)</span>
        <input type="date" name="naissance" value="<?= h($edit['naissance'] ?? '') ?>"></label></div>
      <div><label>E-mail <span class="hint">(facultatif)</span>
        <input type="email" name="email" value="<?= h($edit['email'] ?? '') ?>" placeholder="prenom@exemple.fr"></label></div>
    </div>
    <?php
      // Exclusions du menu « Enfant de » : soi-même ET tous ses descendants (anti-cycle).
      $exclus = [];
      if (!empty($edit['id'])) {
          $exclus = descendants_ids((int) $edit['id']);
          $exclus[] = (int) $edit['id'];
      }
    ?>
    <div class="row">
      <div><label>Parts <span class="hint">(nombre de parts d'actionnaire ; 0 = aucune)</span>
        <input type="number" name="parts" min="0" step="0.5"
               value="<?= h((string) ($edit['parts'] ?? '0')) ?>"></label></div>
      <div><label>Enfant de <span class="hint">(rattachement aux parts du parent)</span>
        <select name="parent_id">
          <option value="">— aucun rattachement —</option>
          <?php foreach ($personnes as $pp):
              if (in_array((int) $pp['id'], $exclus, true)) { continue; } ?>
            <option value="<?= (int) $pp['id'] ?>"
              <?= (int) ($edit['parent_id'] ?? 0) === (int) $pp['id'] ? 'selected' : '' ?>>
              <?= h(trim($pp['prenom'] . ' ' . $pp['nom'])) ?><?= (float) $pp['parts'] > 0 ? ' (' . h((string) $pp['parts']) . ' parts)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select></label></div>
      <div><label>Conjoint·e <span class="hint">(lien réciproque automatique)</span>
        <select name="conjoint_id">
          <option value="">— aucun·e —</option>
          <?php foreach ($personnes as $pp):
              if (!empty($edit['id']) && (int) $pp['id'] === (int) $edit['id']) { continue; } ?>
            <option value="<?= (int) $pp['id'] ?>"
              <?= (int) ($edit['conjoint_id'] ?? 0) === (int) $pp['id'] ? 'selected' : '' ?>>
              <?= h(trim($pp['prenom'] . ' ' . $pp['nom'])) ?>
            </option>
          <?php endforeach; ?>
        </select></label></div>
      <div style="flex:0 1 160px"><label>Marié·e
        <span class="hint">(même règle que <?= age_parts() ?> ans et +)</span>
        <select name="marie">
          <option value="" <?= empty($edit['marie']) ? 'selected' : '' ?>>Non</option>
          <option value="1" <?= !empty($edit['marie']) ? 'selected' : '' ?>>Oui</option>
        </select></label></div>
    </div>
    <div class="actions">
      <button class="btn" type="submit"><?= $edit && !empty($edit['id']) ? 'Enregistrer' : 'Ajouter' ?></button>
      <?php if ($edit && !empty($edit['id'])): ?><a class="btn btn-ghost" href="admin-personnes.php">Annuler</a><?php endif; ?>
    </div>
  </form>

  <?php if ($edit && !empty($edit['id'])):
      $editId = (int) $edit['id'];
      $enfants = array_values(array_filter($personnes, fn($e) => (int) ($e['parent_id'] ?? 0) === $editId));
      $exclusEnfants = array_merge([$editId], array_map(fn($e) => (int) $e['id'], $enfants));
  ?>
    <hr class="sep">
    <h2 style="margin-top:0">Enfants de <?= h(trim(($edit['prenom'] ?? '') . ' ' . ($edit['nom'] ?? ''))) ?></h2>
    <?php if ($enfants): ?>
      <ul style="margin:.3rem 0 .8rem 1.2rem; padding:0">
        <?php foreach ($enfants as $e): ?>
          <li style="margin:.2rem 0">
            <?= h(trim($e['prenom'] . ' ' . $e['nom'])) ?>
            <?php $ageE = age_a($e['naissance']); if ($ageE !== null): ?><span class="muted small">(<?= $ageE ?> ans)</span><?php endif; ?>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="op" value="detach_child">
              <input type="hidden" name="id" value="<?= $editId ?>">
              <input type="hidden" name="child_id" value="<?= (int) $e['id'] ?>">
              <button class="btn btn-ghost btn-sm" type="submit" title="Détacher cet enfant">détacher</button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <p class="muted small">Aucun enfant rattaché pour l’instant.</p>
    <?php endif; ?>
    <form method="post" class="row" style="align-items:flex-end; max-width:520px">
      <?= csrf_field() ?>
      <input type="hidden" name="op" value="add_child">
      <input type="hidden" name="id" value="<?= $editId ?>">
      <div><label>Ajouter un enfant
        <select name="child_id" required>
          <option value="">— choisir dans l’annuaire —</option>
          <?php foreach ($personnes as $pp):
              if (in_array((int) $pp['id'], $exclusEnfants, true)) { continue; } ?>
            <option value="<?= (int) $pp['id'] ?>"><?= h(trim($pp['prenom'] . ' ' . $pp['nom'])) ?></option>
          <?php endforeach; ?>
        </select></label></div>
      <div style="flex:0 0 auto"><button class="btn btn-sm" type="submit">Rattacher</button></div>
    </form>
  <?php endif; ?>
</div>

<div class="card">
  <h2 style="margin-top:0">Saisie rapide des parts</h2>
  <p class="muted small">Renseignez le nombre de parts de chaque actionnaire titulaire (laisser 0 pour les
    conjoints et enfants). Un seul bouton pour tout enregistrer.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="op" value="parts_bulk">
    <div class="parts-grid">
      <?php foreach ($personnes as $pp): ?>
        <label class="parts-item">
          <span><?= h(trim($pp['prenom'] . ' ' . $pp['nom'])) ?></span>
          <input type="number" name="parts[<?= (int) $pp['id'] ?>]" min="0" step="0.5"
                 value="<?= (float) $pp['parts'] > 0 ? h((string) $pp['parts']) : '' ?>" placeholder="0">
        </label>
      <?php endforeach; ?>
    </div>
    <div class="actions"><button class="btn" type="submit">Enregistrer toutes les parts</button></div>
  </form>
</div>

<div class="card">
  <div class="actions" style="margin-top:0; justify-content:space-between">
    <input type="search" id="filtre" placeholder="Rechercher un nom…" style="max-width:280px" oninput="filtrer(this.value)">
    <a class="btn btn-ghost btn-sm" href="import-annuaire.php" onclick="return confirm('Réimporter l’annuaire depuis le fichier Excel remplacera la liste actuelle des actionnaires. Continuer ?')">Réimporter depuis l’Excel</a>
  </div>
  <div class="table-scroll">
    <table class="data" id="tbl-personnes">
      <thead><tr><th>Nom</th><th>Prénom</th><th>E-mail</th><th>Naissance</th><th class="num">Âge</th><th>Catégorie</th>
        <th class="num">Parts</th><th>Enfant de</th><th>M.</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($personnes as $p):
            $age = age_a($p['naissance']);
            $cat = categorie_naissance($p['naissance']);
            $parent = !empty($p['parent_id']) ? ($parId[(int) $p['parent_id']] ?? null) : null; ?>
          <tr>
            <td><?= h($p['nom']) ?></td>
            <td><?= h($p['prenom']) ?></td>
            <td class="small"><?= $p['email'] !== '' ? h($p['email']) : '<span class="muted">—</span>' ?></td>
            <td><?= $p['naissance'] ? h(fr_date($p['naissance'])) : '<span class="muted">—</span>' ?></td>
            <td class="num"><?= $age === null ? '<span class="muted">?</span>' : $age ?></td>
            <td><?= $p['naissance'] ? '<span class="badge">' . h(categorie_label($cat)) . '</span>' : '<span class="muted">—</span>' ?></td>
            <td class="num"><?= (float) $p['parts'] > 0 ? h((string) $p['parts']) : '<span class="muted">—</span>' ?></td>
            <td class="small"><?= $parent ? h(trim($parent['prenom'] . ' ' . $parent['nom'])) : '<span class="muted">—</span>' ?></td>
            <td><?= !empty($p['marie']) ? '💍' : '' ?></td>
            <td style="white-space:nowrap">
              <a class="btn btn-ghost btn-sm" href="admin-personnes.php?edit=<?= (int) $p['id'] ?>">Modifier</a>
              <form method="post" style="display:inline" onsubmit="return confirm('Supprimer <?= h(addslashes($p['prenom'] . ' ' . $p['nom'])) ?> ?')">
                <?= csrf_field() ?>
                <input type="hidden" name="op" value="delete">
                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <button class="btn btn-danger btn-sm" type="submit">✕</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
  function filtrer(q) {
    q = (q || '').toLowerCase();
    document.querySelectorAll('#tbl-personnes tbody tr').forEach(function (tr) {
      tr.style.display = tr.textContent.toLowerCase().indexOf(q) >= 0 ? '' : 'none';
    });
  }
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
