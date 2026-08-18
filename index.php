<?php
/** Menu d'accueil après connexion. */
require __DIR__ . '/bootstrap.php';
require_login();

// Quelques chiffres utiles.
$pdo = db();
$aVenir = (int) $pdo->query('SELECT COUNT(*) FROM reservations WHERE date_depart >= "' . today() . '"')->fetchColumn();
$total  = (int) $pdo->query('SELECT COUNT(*) FROM reservations')->fetchColumn();

$page_title = 'Accueil';
$active = '';
require __DIR__ . '/partials/header.php';
?>
<h1>Bonjour 👋</h1>
<p class="lead">Réservations à venir : <strong><?= $aVenir ?></strong> · total enregistrées : <?= $total ?>.</p>

<?php if (current_role() === 'invite'): ?>
  <div class="notice">Vous êtes connecté en tant qu’<strong>invité</strong>. Lors de l’inscription, vous pourrez indiquer l’actionnaire qui vous invite.</div>
<?php endif; ?>

<div class="menu-grid">
  <a class="menu-card" href="calendrier.php">
    <div class="ico">📅</div><h3>Calendrier</h3>
    <p>Voir qui occupe quelle chambre et à quelles dates.</p>
  </a>
  <a class="menu-card" href="inscription.php">
    <div class="ico">✍️</div><h3>Nouvelle inscription</h3>
    <p>Réserver une chambre pour un séjour (un formulaire par chambre).</p>
  </a>
  <a class="menu-card" href="repas.php">
    <div class="ico">🍽️</div><h3>Repas</h3>
    <p>Présences par catégorie d’âge, par semaine / mois / plage libre. Export Excel.</p>
  </a>
  <a class="menu-card" href="pdf.php">
    <div class="ico">🖨️</div><h3>Impression / PDF</h3>
    <p>Version imprimable des présences aux repas.</p>
  </a>
  <?php if (is_admin()): ?>
  <a class="menu-card" href="admin.php">
    <div class="ico">⚙️</div><h3>Administration</h3>
    <p>Mots de passe, date d’ouverture invités, chambres, annuaire, seuils d’âge.</p>
  </a>
  <?php endif; ?>
</div>

<script>
  window.LC_TOUR_KEY = 'accueil';
  window.LC_TOUR_STEPS = [
    { sel: '.menu-grid', titre: 'Bienvenue ! 👋',
      texte: 'Voici votre espace de réservation de La Croze. Chaque carte mène à une fonction — petite visite rapide ?' },
    { sel: 'a[href="calendrier.php"].menu-card', titre: 'Le calendrier', cliquer: true,
      texte: 'Pour voir en un coup d’œil qui occupe quelle chambre et à quelles dates.' },
    { sel: 'a[href="inscription.php"].menu-card', titre: 'Réserver', cliquer: true,
      texte: 'Pour inscrire votre famille : choisissez les personnes, la chambre et les dates. Le site vous guide à chaque étape.' },
    { sel: 'a[href="repas.php"].menu-card', titre: 'Les repas', cliquer: true,
      texte: 'Pour savoir combien de personnes seront à table chaque midi et chaque soir.' },
    { sel: '.nav', titre: 'La barre de navigation',
      texte: 'Ces mêmes raccourcis restent accessibles en haut de chaque page. Et le bouton « ❓ Visite guidée » relance l’explication de la page où vous êtes. Bonne visite !' }
  ];
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
