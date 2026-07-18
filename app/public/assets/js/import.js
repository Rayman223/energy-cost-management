// Aide au paramétrage du formulaire d'import (#135 unités, #134 mapping) :
//  - bascule les unités et les champs pertinents selon le type d'énergie ;
//  - lit l'en-tête du CSV choisi pour proposer ses colonnes en autocomplétion
//    et pré-remplir les correspondances évidentes.
// Les options d'unité viennent d'un bloc <script type="application/json"> (motif
// CSP-safe, aucune donnée en inline exécutable). Tout est facultatif : sans JS,
// le formulaire reste utilisable en saisie libre.
(function () {
  'use strict';

  // Avertissement : « Remplacer les valeurs existantes » (overwrite) n'a aucun
  // effet en simulation (dry-run), qui annule toute écriture. On le signale dès
  // que les deux cases sont cochées. Bloc indépendant du reste (qui peut sortir
  // tôt si le formulaire d'unités est absent).
  (function wireOverwriteWarning() {
    var overwrite = document.querySelector('input[name="overwrite"]');
    var dryRun = document.querySelector('input[name="dry_run"]');
    var warning = document.getElementById('import-overwrite-warning');
    if (!overwrite || !dryRun || !warning) {
      return;
    }
    function sync() {
      warning.hidden = !(overwrite.checked && dryRun.checked);
    }
    overwrite.addEventListener('change', sync);
    dryRun.addEventListener('change', sync);
    sync();
  })();

  // Doit rester aligné sur RowSource::DELIMITERS (même ordre : à égalité, le
  // premier gagne, donc la virgule — le comportement historique).
  var DELIMITERS = [',', ';', '\t'];
  var HEADER_BYTES = 65536;

  var typeSelect = document.getElementById('energy_type');
  var unitSelect = document.getElementById('unit');
  var dataEl = document.getElementById('import-units');
  if (!typeSelect || !unitSelect || !dataEl) {
    return;
  }

  var fileInput = document.getElementById('import_file');
  var columnList = document.getElementById('import-columns');
  var registersBox = document.getElementById('import-registers');
  var valueColBox = document.getElementById('import-value-col');

  var units;
  try {
    units = JSON.parse(dataEl.textContent || '{}');
  } catch (e) {
    return;
  }

  // ── Bascule selon le type d'énergie ───────────────────────────────────────

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

    // Électricité → mapping par index ; gaz/eau → colonne valeur unique.
    var isElectricity = typeSelect.value === 'electricity';
    if (registersBox) {
      registersBox.hidden = !isElectricity;
    }
    if (valueColBox) {
      valueColBox.hidden = isElectricity;
    }
  }

  // ── Colonnes du fichier choisi ────────────────────────────────────────────

  // Découpe une ligne CSV en respectant les champs quotés ("" = guillemet
  // littéral). Équivalent JS de str_getcsv(escape: '') côté PHP.
  function splitCsvLine(line, delimiter) {
    var fields = [];
    var field = '';
    var inQuotes = false;

    for (var i = 0; i < line.length; i++) {
      var ch = line.charAt(i);
      if (inQuotes) {
        if (ch !== '"') {
          field += ch;
        } else if (line.charAt(i + 1) === '"') {
          field += '"';
          i++;
        } else {
          inQuotes = false;
        }
      } else if (ch === '"') {
        inQuotes = true;
      } else if (ch === delimiter) {
        fields.push(field);
        field = '';
      } else {
        field += ch;
      }
    }
    fields.push(field);

    return fields;
  }

  // Même heuristique que RowSource::sniffDelimiter() : le délimiteur qui découpe
  // le plus de colonnes gagne.
  function headerColumns(text) {
    // BOM des exports tableur : ne doit pas coller au 1er nom de colonne.
    var line = text.replace(/^\uFEFF/, '').split(/\r?\n/)[0] || '';
    if (line === '') {
      return [];
    }

    var best = [];
    DELIMITERS.forEach(function (delimiter) {
      var fields = splitCsvLine(line, delimiter);
      if (fields.length > best.length) {
        best = fields;
      }
    });

    return best.map(function (col) {
      return col.trim().toLowerCase();
    });
  }

  // Index pré-remplis par nos soins (id => valeur posée), pour pouvoir les
  // reprendre sans jamais toucher à ce que l'utilisateur a saisi lui-même.
  var autoFilled = {};

  // Retire les pré-remplissages du fichier précédent : ses noms de colonnes
  // n'existent pas forcément dans le nouveau, et un résidu invisible ferait
  // échouer l'import (« aucun registre renseigné ») sans raison apparente. Un
  // champ modifié depuis est considéré comme saisi à la main : on le laisse.
  function clearAutoFilled() {
    Object.keys(autoFilled).forEach(function (id) {
      var field = document.getElementById(id);
      if (field && field.value === autoFilled[id]) {
        field.value = '';
      }
    });
    autoFilled = {};
  }

  function applyColumns(columns) {
    if (columnList) {
      columnList.textContent = '';
      columns.forEach(function (col) {
        if (col === '') {
          return;
        }
        var opt = document.createElement('option');
        opt.value = col;
        columnList.appendChild(opt);
      });
    }

    // Pré-remplissage : uniquement quand une colonne porte exactement le nom de
    // l'index (le défaut côté serveur), et sans écraser une saisie en cours. On
    // n'essaie pas de deviner au-delà : une correspondance fausse serait pire
    // qu'un champ vide, l'autocomplétion suffit pour le reste.
    columns.forEach(function (col) {
      var field = document.getElementById('reg_' + col);
      if (field && field.value === '') {
        field.value = col;
        autoFilled[field.id] = col;
      }
    });
  }

  function readHeader() {
    var file = fileInput.files && fileInput.files[0];
    if (columnList) {
      columnList.textContent = '';
    }
    clearAutoFilled();
    // JSON : pas d'en-tête à lire, on laisse l'autocomplétion vide.
    if (!file || !/\.csv$/i.test(file.name) || !window.FileReader) {
      return;
    }

    var reader = new FileReader();
    reader.onload = function () {
      applyColumns(headerColumns(String(reader.result || '')));
    };
    // Lecture impossible : l'aide est facultative, la saisie manuelle reste.
    reader.onerror = function () {};
    reader.readAsText(file.slice(0, HEADER_BYTES));
  }

  typeSelect.addEventListener('change', refresh);
  if (fileInput) {
    fileInput.addEventListener('change', readHeader);
  }
  refresh();
})();
