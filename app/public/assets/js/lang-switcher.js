// ── Sélecteur de langue : fermeture au clic extérieur / Échap ────────────────
// Amélioration progressive des <details class="lang-menu"> rendus par le partial
// _lang-switcher.php : sans ce code le menu reste pleinement fonctionnel
// (ouverture/fermeture au clic sur le résumé). Chargé en `defer`. Défensif : ne
// fait rien si la page n'a pas de sélecteur, et gère le cas où le partial est
// rendu plusieurs fois (en-tête + bloc de bas de page).
(function langMenu() {
  const menus = document.querySelectorAll('details.lang-menu');
  if (menus.length === 0) return;
  document.addEventListener('click', (e) => {
    menus.forEach((menu) => {
      if (menu.open && !menu.contains(e.target)) menu.open = false;
    });
  });
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    menus.forEach((menu) => {
      if (menu.open) menu.open = false;
    });
  });
})();
