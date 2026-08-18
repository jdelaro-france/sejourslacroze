<?php
/**
 * Connexion par mot de passe (actionnaire / invité / admin).
 * Le mot de passe est saisi dans un formulaire et vérifié côté serveur :
 * plus jamais de hash exposé dans l'URL comme sur l'ancien site.
 */
require __DIR__ . '/bootstrap.php';

if (!is_configured()) {
    redirect('setup.php');
}
if (is_logged_in()) {
    redirect('index.php');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    // Petit anti-force-brute : pause après échec.
    $res = authenticate($_POST['password'] ?? '');
    if ($res['role']) {
        login_as($res['role']);
        redirect('index.php');
    }
    usleep(400000);
    $error = $res['error'] ?? 'Mot de passe incorrect.';
}

$page_title = 'Connexion';
require __DIR__ . '/partials/header.php';
?>
<div class="login-box">
  <div class="card">
    <div class="brand-big"><?= h(site_title()) ?></div>
    <div class="brand-sub">Réservations & repas</div>

    <?php if ($error): ?><div class="notice notice-danger"><?= h($error) ?></div><?php endif; ?>

    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <label for="password">Mot de passe</label>
      <input type="password" id="password" name="password" required autofocus>
      <div class="actions">
        <button class="btn" type="submit" style="width:100%">Entrer</button>
      </div>
    </form>
    <p class="small muted center" style="margin-top:1rem">
      Actionnaires, invités : utilisez le mot de passe qui vous a été communiqué.
    </p>
  </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
