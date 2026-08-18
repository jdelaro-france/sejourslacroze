<?php
/**
 * En-tête commun : ouvre <html>, la barre de navigation, le conteneur.
 * Variables optionnelles avant l'include :
 *   $page_title (string)   — titre de l'onglet et <h1> possible
 *   $active     (string)   — clé de nav à surligner
 *   $wrap_class (string)   — classes supplémentaires du conteneur
 */
if (!function_exists('db')) {
    require __DIR__ . '/../bootstrap.php';
}
$page_title = $page_title ?? site_title();
$active     = $active ?? '';
$wrap_class = $wrap_class ?? '';
$role       = current_role();

$nav = [];
if (can_manage()) {
    $nav = [
        'calendrier'  => ['Calendrier', 'calendrier.php'],
        'inscription' => ['Nouvelle inscription', 'inscription.php'],
        'repas'       => ['Repas', 'repas.php'],
        'pdf'         => ['Impression / PDF', 'pdf.php'],
    ];
    if (is_admin()) {
        $nav['admin'] = ['Administration', 'admin.php'];
    }
}
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($page_title) ?> — <?= h(site_title()) ?></title>
<link rel="stylesheet" href="assets/style.css">
<script src="assets/tour.js" defer></script>
</head>
<body>
<?php if (is_logged_in()): ?>
<header class="topbar no-print">
  <div class="topbar-inner">
    <a class="brand" href="index.php"><?= h(site_title()) ?><small>Réservations & repas</small></a>
    <nav class="nav">
      <?php foreach ($nav as $key => [$label, $url]): ?>
        <a href="<?= h($url) ?>"<?= $active === $key ? ' class="active"' : '' ?>><?= h($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <span class="role-chip"><?= h($role) ?></span>
    <a class="nav" href="logout.php" style="color:#f4d7c9">Déconnexion</a>
  </div>
</header>
<?php endif; ?>
<main class="wrap <?= h($wrap_class) ?>">
<?php if ($f = flash_get()): ?>
  <div class="flash"><?= h($f) ?></div>
<?php endif; ?>
