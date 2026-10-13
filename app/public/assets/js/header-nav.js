// Menus natifs par catégorie : navigation et langue restent utilisables sans JS.
(function () {
  'use strict';

  document.querySelectorAll('[data-header]').forEach(function (header) {
    var button = header.querySelector('[data-header-toggle]');
    var menu = button && document.getElementById(button.getAttribute('aria-controls'));
    if (!button || !menu) return;
    var mobile = window.matchMedia('(max-width: 1024px)');
    var disclosures = header.querySelectorAll('details.header-dropdown, details.lang-menu');

    function closeDisclosure(disclosure, restoreFocus) {
      if (restoreFocus && disclosure.contains(document.activeElement)) {
        disclosure.querySelector('summary').focus();
      }
      disclosure.open = false;
    }

    disclosures.forEach(function (disclosure) {
      disclosure.addEventListener('toggle', function () {
        if (!disclosure.open) return;
        disclosures.forEach(function (other) {
          if (other !== disclosure) closeDisclosure(other, true);
        });
      });
    });

    function setExpanded(expanded) {
      if (!expanded) {
        if (menu.contains(document.activeElement)) button.focus();
        menu.querySelectorAll('details').forEach(function (disclosure) {
          closeDisclosure(disclosure, false);
        });
      }
      button.setAttribute('aria-expanded', String(expanded));
      menu.hidden = !expanded;
    }

    function syncViewport() {
      var focusOnToggle = document.activeElement === button;
      button.hidden = !mobile.matches;
      disclosures.forEach(function (disclosure) { closeDisclosure(disclosure, true); });
      setExpanded(!mobile.matches);
      if (!mobile.matches && focusOnToggle) {
        var firstLink = menu.querySelector('a[href]');
        if (firstLink) firstLink.focus();
      }
    }

    button.addEventListener('click', function () {
      if (mobile.matches) setExpanded(menu.hidden);
    });
    document.addEventListener('click', function (event) {
      disclosures.forEach(function (disclosure) {
        if (disclosure.open && !disclosure.contains(event.target)) closeDisclosure(disclosure, true);
      });
    });
    header.addEventListener('keydown', function (event) {
      if (event.key !== 'Escape') return;
      var openDisclosure = Array.from(disclosures).find(function (disclosure) { return disclosure.open; });
      if (openDisclosure) {
        openDisclosure.open = false;
        openDisclosure.querySelector('summary').focus();
      } else if (mobile.matches && !menu.hidden) {
        setExpanded(false);
        button.focus();
      } else {
        return;
      }
      event.preventDefault();
      event.stopPropagation();
    });
    mobile.addEventListener('change', syncViewport);
    syncViewport();
  });
})();
