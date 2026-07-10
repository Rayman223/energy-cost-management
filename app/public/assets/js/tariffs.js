// Interactions de la page tarifs. Aucune fonction globale ni handler inline :
// tout est câblé par addEventListener sur des data-attributes (CSP enforced, #73).
(function () {
  // Compteur d'index pour les nouvelles lignes (poursuit ceux rendus côté serveur).
  var scriptEl = document.querySelector('script[data-next-index]');
  var nextIndex = scriptEl ? parseInt(scriptEl.dataset.nextIndex, 10) || 0 : 0;

  // ── Détail des grilles ────────────────────────────────────────────────────
  document.querySelectorAll('[data-toggle-lines]').forEach(function (el) {
    el.addEventListener('click', function () {
      var target = document.getElementById(el.dataset.toggleLines);
      if (target) target.classList.toggle('open');
    });
  });

  // ── Ajout d'un champ personnalisé ─────────────────────────────────────────
  var tpl = document.getElementById('custom-line-row');
  var addBtn = document.querySelector('[data-add-line]');
  if (tpl && addBtn) {
    var taxesBody = addBtn.closest('.line-group-body');
    var addRow = addBtn.closest('.add-field-row');
    addBtn.addEventListener('click', function () {
      var html = tpl.innerHTML.replace(/__IDX__/g, String(nextIndex++));
      var frag = document.createElement('div');
      frag.innerHTML = html.trim();
      var node = frag.firstElementChild;
      if (node && taxesBody && addRow) {
        taxesBody.insertBefore(node, addRow);
        var labelInput = node.querySelector('.line-label-input');
        if (labelInput) labelInput.focus();
      }
    });
  }

  // ── Suppression d'un champ (délégation) ───────────────────────────────────
  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-remove-line]') : null;
    if (!btn) return;
    var row = btn.closest('.line-item');
    if (row) row.remove();
  });

  // ── Pays → préremplit TVA & devise (formulaire principal) ─────────────────
  var countrySelect = document.querySelector('[data-country-select]');
  if (countrySelect) {
    countrySelect.addEventListener('change', function () {
      var opt = countrySelect.options[countrySelect.selectedIndex];
      if (!opt) return;
      var vat = opt.dataset.vat;
      var cur = opt.dataset.currency;
      var vatInput = document.querySelector('[data-vat-input]');
      if (vat && vatInput) vatInput.value = vat;
      var curSelect = document.querySelector('[data-currency-select]');
      if (cur && curSelect) {
        var found = Array.prototype.some.call(curSelect.options, function (o) { return o.value === cur; });
        if (!found) curSelect.add(new Option(cur, cur));
        curSelect.value = cur;
      }
    });
  }

  // ── Panneau « Point de départ » : filtre les templates selon le pays ──────
  var startCountry = document.querySelector('[data-start-country]');
  var startTemplate = document.querySelector('[data-start-template]');
  if (startCountry && startTemplate) {
    var filterTemplates = function () {
      var c = startCountry.value;
      Array.prototype.forEach.call(startTemplate.querySelectorAll('option'), function (o) {
        var oc = o.dataset.country || '';
        // Génériques (sans pays) toujours visibles ; sinon match pays sélectionné.
        var show = oc === '' || c === '' || oc === c;
        o.hidden = !show;
        o.disabled = !show;
      });
      var sel = startTemplate.options[startTemplate.selectedIndex];
      if (sel && sel.hidden) {
        var firstVisible = Array.prototype.find.call(startTemplate.querySelectorAll('option'), function (o) { return !o.hidden; });
        if (firstVisible) startTemplate.value = firstVisible.value;
      }
    };
    startCountry.addEventListener('change', filterTemplates);
    filterTemplates();
  }

  // ── « Sauver comme template » : révèle le champ nom + la visibilité ───────
  var saveTplToggle = document.querySelector('[data-save-tpl-toggle]');
  if (saveTplToggle) {
    var nameInput = document.querySelector('.save-tpl-name');
    var visibilityBox = document.querySelector('.save-tpl-visibility');
    saveTplToggle.addEventListener('change', function () {
      if (nameInput) nameInput.classList.toggle('is-hidden', !saveTplToggle.checked);
      if (visibilityBox) visibilityBox.classList.toggle('is-hidden', !saveTplToggle.checked);
    });
  }
})();
