/**
 * Bascule de thème clair/sombre.
 *
 * Le thème initial est posé AVANT le premier rendu par un snippet inline en
 * <head> (anti-FOUC) : il lit localStorage['theme'], sinon prefers-color-scheme.
 * Ce script gère ensuite le bouton de bascule et le suivi du thème système tant
 * qu'aucun choix manuel n'a été mémorisé.
 */
(function () {
  'use strict';

  var STORAGE_KEY = 'theme';
  var root = document.documentElement;
  var media = window.matchMedia('(prefers-color-scheme: dark)');

  function current() {
    return root.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
  }

  function apply(theme) {
    root.setAttribute('data-theme', theme === 'dark' ? 'dark' : 'light');
    syncButton();
  }

  function syncButton() {
    var btn = document.getElementById('theme-toggle');
    if (!btn) return;
    var dark = current() === 'dark';
    // Le texte visible reste stable ; le nom accessible décrit aussi l'action.
    var action = btn.getAttribute(dark ? 'data-theme-light' : 'data-theme-dark');
    if (!action) return;
    btn.setAttribute('aria-label', btn.textContent.trim() + ' : ' + action);
    btn.setAttribute('title', action);
  }

  // Suivi du thème système tant que l'utilisateur n'a pas choisi explicitement.
  media.addEventListener('change', function (e) {
    if (localStorage.getItem(STORAGE_KEY)) return;
    apply(e.matches ? 'dark' : 'light');
  });

  document.addEventListener('DOMContentLoaded', function () {
    syncButton();
    var btn = document.getElementById('theme-toggle');
    if (!btn) return;
    btn.addEventListener('click', function () {
      var next = current() === 'dark' ? 'light' : 'dark';
      try { localStorage.setItem(STORAGE_KEY, next); } catch (err) { /* stockage indisponible */ }
      apply(next);
    });
  });
})();
