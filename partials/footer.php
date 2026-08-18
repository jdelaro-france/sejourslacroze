</main>
<footer class="foot no-print">
  <?= h(site_title()) ?> · outil de réservation interne · <?= date('Y') ?>
</footer>
<script>
  // Visite guidée : bouton + lancement auto la première fois (si la page définit un tour).
  window.addEventListener('DOMContentLoaded', function () {
    if (window.LCTour) { window.LCTour.init(); }
  });
</script>
</body>
</html>
