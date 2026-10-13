// Menu mobile : la navigation reste visible si JavaScript est indisponible.
(function () {
  'use strict';

  document.querySelectorAll('[data-header]').forEach(function (header) {
    var button = header.querySelector('[data-header-toggle]');
    var menu = button && document.getElementById(button.getAttribute('aria-controls'));
    if (!button || !menu) return;
    var mobile = window.matchMedia('(max-width: 1024px)');

    function setExpanded(expanded) {
      if (!expanded) {
        if (menu.contains(document.activeElement)) button.focus();
        menu.querySelectorAll('details[open]').forEach(function (details) {
          details.open = false;
        });
      }
      button.setAttribute('aria-expanded', String(expanded));
      menu.hidden = !expanded;
    }

    function syncViewport() {
      var focusOnToggle = document.activeElement === button;
      button.hidden = !mobile.matches;
      setExpanded(!mobile.matches);
      // Le bouton disparaît sur ordinateur : conserver le parcours clavier.
      if (!mobile.matches && focusOnToggle) {
        var firstLink = menu.querySelector('a[href]');
        if (firstLink) firstLink.focus();
      }
    }

    button.addEventListener('click', function () {
      if (mobile.matches) setExpanded(menu.hidden);
    });
    header.addEventListener('keydown', function (event) {
      if (event.key !== 'Escape' || !mobile.matches || menu.hidden) return;
      setExpanded(false);
      button.focus();
    });
    mobile.addEventListener('change', syncViewport);
    syncViewport();
  });
})();
