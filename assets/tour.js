/**
 * LCTour — visites guidées animées, sans dépendance.
 * Un curseur animé se déplace sur la VRAIE page, met en lumière les éléments,
 * tape dans les champs, et des bulles expliquent chaque étape.
 *
 * Une page déclare :
 *   window.LC_TOUR_KEY   = 'inscription';       // identifiant (mémorisation "déjà vue")
 *   window.LC_TOUR_STEPS = [ { sel, titre, texte, taper?, choisir?, cliquer? }, ... ];
 * Le pied de page appelle LCTour.init() : bouton "Visite guidée" + lancement auto la 1re fois.
 */
window.LCTour = (function () {
  'use strict';

  var steps = [], idx = 0, actif = false;
  var overlay, ring, cursor, bubble;
  var backups = []; // [{el, value}] valeurs modifiées par la démo, restaurées à la fin
  var timers = [];

  function later(fn, ms) { timers.push(setTimeout(fn, ms)); }
  function clearTimers() { timers.forEach(clearTimeout); timers = []; }

  // ---------- Construction du décor ----------
  function build() {
    overlay = el('div', 'lctour-overlay');
    ring = el('div', 'lctour-ring');
    cursor = el('div', 'lctour-cursor');
    cursor.innerHTML =
      '<svg width="26" height="30" viewBox="0 0 26 30">' +
      '<path d="M2 2 L2 24 L8 19 L12 28 L16 26 L12 17 L20 17 Z" ' +
      'fill="#fff" stroke="#2b2b28" stroke-width="1.6" stroke-linejoin="round"/></svg>';
    bubble = el('div', 'lctour-bubble');
    document.body.appendChild(overlay);
    document.body.appendChild(ring);
    document.body.appendChild(cursor);
    document.body.appendChild(bubble);
    // Position de départ du curseur : bas-droite de l'écran.
    cursor.style.left = (window.innerWidth - 80) + 'px';
    cursor.style.top = (window.innerHeight - 80) + 'px';
    document.addEventListener('keydown', onKey);
  }

  function el(tag, cls) { var d = document.createElement(tag); d.className = cls; return d; }

  function onKey(e) { if (e.key === 'Escape') { stop(); } }

  // ---------- Étapes ----------
  function run(i) {
    clearTimers();
    if (i < 0) { i = 0; }
    if (i >= steps.length) { stop(true); return; }
    idx = i;
    var s = steps[i];
    var target = document.querySelector(s.sel);
    if (!target || target.offsetParent === null) { run(i + 1); return; } // élément absent : on saute
    target.scrollIntoView({ behavior: 'smooth', block: 'center' });
    later(function () { place(target, s); }, 420);
  }

  function place(target, s) {
    var r = target.getBoundingClientRect();
    var pad = 6;

    // Anneau de mise en lumière (le reste de la page est assombri par son ombre).
    ring.style.left = (r.left - pad) + 'px';
    ring.style.top = (r.top - pad) + 'px';
    ring.style.width = (r.width + pad * 2) + 'px';
    ring.style.height = (r.height + pad * 2) + 'px';
    ring.style.opacity = '1';

    // Curseur vers le centre de la cible.
    cursor.style.left = (r.left + Math.min(r.width - 10, r.width / 2)) + 'px';
    cursor.style.top = (r.top + r.height / 2) + 'px';

    // Bulle : sous la cible si possible, sinon au-dessus.
    bubble.innerHTML =
      '<div class="lctour-titre">' + esc(s.titre || '') + '</div>' +
      '<div class="lctour-texte">' + esc(s.texte || '') + '</div>' +
      '<div class="lctour-nav">' +
      '<span class="lctour-count">' + (idx + 1) + ' / ' + steps.length + '</span>' +
      (idx > 0 ? '<button type="button" class="lctour-btn lctour-prev">← Précédent</button>' : '') +
      '<button type="button" class="lctour-btn lctour-next">' + (idx === steps.length - 1 ? 'Terminer ✓' : 'Suivant →') + '</button>' +
      '<button type="button" class="lctour-quit" title="Quitter la visite">✕</button>' +
      '</div>';
    bubble.style.opacity = '0';
    bubble.style.display = 'block';
    later(function () {
      var bh = bubble.offsetHeight, bw = Math.min(340, window.innerWidth - 24);
      bubble.style.width = bw + 'px';
      var top = r.bottom + 16;
      if (top + bh > window.innerHeight - 12) { top = Math.max(12, r.top - bh - 16); }
      var left = Math.max(12, Math.min(r.left, window.innerWidth - bw - 12));
      bubble.style.top = top + 'px';
      bubble.style.left = left + 'px';
      bubble.style.opacity = '1';
    }, 60);

    bubble.querySelector('.lctour-next').onclick = function () { run(idx + 1); };
    var prev = bubble.querySelector('.lctour-prev');
    if (prev) { prev.onclick = function () { run(idx - 1); }; }
    bubble.querySelector('.lctour-quit').onclick = function () { stop(); };

    // Actions de démonstration, une fois le curseur arrivé.
    later(function () {
      if (s.cliquer) { pulse(); }
      if (s.choisir !== undefined && target.tagName === 'SELECT') {
        backup(target);
        pulse();
        target.value = s.choisir;
        target.dispatchEvent(new Event('change', { bubbles: true }));
      }
      if (s.taper !== undefined && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA')) {
        typer(target, String(s.taper));
      }
    }, 950);
  }

  function pulse() {
    cursor.classList.remove('lctour-pulse');
    void cursor.offsetWidth; // relance l'animation
    cursor.classList.add('lctour-pulse');
  }

  function backup(target) {
    for (var i = 0; i < backups.length; i++) { if (backups[i].el === target) { return; } }
    backups.push({ el: target, value: target.value });
  }

  function typer(target, texte) {
    backup(target);
    pulse();
    target.focus({ preventScroll: true });
    if (target.type === 'date') { // un champ date ne se tape pas caractère par caractère
      target.value = texte;
      target.dispatchEvent(new Event('change', { bubbles: true }));
      return;
    }
    target.value = '';
    var i = 0;
    (function frappe() {
      if (!actif || i >= texte.length) {
        target.dispatchEvent(new Event('input', { bubbles: true }));
        return;
      }
      target.value += texte.charAt(i++);
      target.dispatchEvent(new Event('input', { bubbles: true }));
      later(frappe, 55 + Math.random() * 45);
    })();
  }

  // ---------- Cycle de vie ----------
  function start() {
    if (actif || !window.LC_TOUR_STEPS || !window.LC_TOUR_STEPS.length) { return; }
    steps = window.LC_TOUR_STEPS;
    actif = true;
    backups = [];
    build();
    run(0);
  }

  function stop(finished) {
    if (!actif) { return; }
    actif = false;
    clearTimers();
    // Restaure les champs modifiés par la démonstration.
    backups.forEach(function (b) {
      b.el.value = b.value;
      b.el.dispatchEvent(new Event('input', { bubbles: true }));
      b.el.dispatchEvent(new Event('change', { bubbles: true }));
    });
    backups = [];
    [overlay, ring, cursor, bubble].forEach(function (n) { if (n && n.parentNode) { n.parentNode.removeChild(n); } });
    document.removeEventListener('keydown', onKey);
    if (finished && window.LC_TOUR_KEY) {
      try { localStorage.setItem('lc_tour_' + window.LC_TOUR_KEY, 'vue'); } catch (e) {}
    }
  }

  function init() {
    if (!window.LC_TOUR_STEPS || !window.LC_TOUR_STEPS.length) { return; }
    // Bouton « Visite guidée » dans la barre du haut.
    var barre = document.querySelector('.topbar-inner');
    if (barre) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'lctour-launch';
      b.textContent = '❓ Visite guidée';
      b.onclick = start;
      barre.appendChild(b);
    }
    // Lancement automatique à la première visite de la page.
    var deja = null;
    try { deja = localStorage.getItem('lc_tour_' + (window.LC_TOUR_KEY || 'page')); } catch (e) {}
    if (!deja) {
      setTimeout(start, 900);
      try { localStorage.setItem('lc_tour_' + (window.LC_TOUR_KEY || 'page'), 'vue'); } catch (e) {}
    }
  }

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  return { start: start, stop: stop, init: init };
})();
