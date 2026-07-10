/**
 * Anti-FOUC : pose le thème (clair/sombre) sur <html> AVANT le premier rendu.
 *
 * Chargé de façon bloquante en <head> (pas de defer) pour éviter tout flash de
 * thème incorrect. Lit localStorage['theme'], sinon la préférence système
 * (prefers-color-scheme). Le bouton de bascule et le suivi live du thème
 * système sont gérés ensuite par theme.js.
 */
(function () {
  try {
    var t = localStorage.getItem('theme');
    if (!t) t = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    document.documentElement.setAttribute('data-theme', t);
  } catch (e) {
    document.documentElement.setAttribute('data-theme', 'light');
  }
})();
