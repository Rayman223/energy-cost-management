// Bascule des unités du formulaire d'import selon le type d'énergie choisi.
// Les options sont fournies par un bloc <script type="application/json"> (motif
// CSP-safe, aucune donnée en inline exécutable).
(function () {
  'use strict';

  var typeSelect = document.getElementById('energy_type');
  var unitSelect = document.getElementById('unit');
  var dataEl = document.getElementById('import-units');
  if (!typeSelect || !unitSelect || !dataEl) {
    return;
  }

  var units;
  try {
    units = JSON.parse(dataEl.textContent || '{}');
  } catch (e) {
    return;
  }

  function refresh() {
    var options = units[typeSelect.value] || [];
    unitSelect.textContent = '';
    options.forEach(function (pair) {
      var opt = document.createElement('option');
      opt.value = pair[0];
      opt.textContent = pair[1];
      unitSelect.appendChild(opt);
    });
    // La 1re option (unité canonique) est sélectionnée par défaut.
  }

  typeSelect.addEventListener('change', refresh);
  refresh();
})();
