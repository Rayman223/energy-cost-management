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

  // ── Ajout d'un champ personnalisé (un bouton par catégorie) ───────────────
  var tpl = document.getElementById('custom-line-row');
  if (tpl) {
    document.querySelectorAll('[data-add-line]').forEach(function (addBtn) {
      var body = addBtn.closest('.line-group-body');
      var addRow = addBtn.closest('.add-field-row');
      var group = addBtn.closest('.line-group');
      // Le nouveau champ est préaffecté à la catégorie de son bloc.
      var category = group ? (group.dataset.group || '') : '';
      addBtn.addEventListener('click', function () {
        var html = tpl.innerHTML.replace(/__IDX__/g, String(nextIndex++));
        var frag = document.createElement('div');
        frag.innerHTML = html.trim();
        var node = frag.firstElementChild;
        if (node && body && addRow) {
          var catSelect = node.querySelector('.line-category-select');
          if (catSelect && category) catSelect.value = category;
          body.insertBefore(node, addRow);
          refreshCounts(node);
          var labelInput = node.querySelector('.line-label-input');
          if (labelInput) labelInput.focus();
        }
      });
    });
  }

  // ── Suppression d'un champ (délégation) ───────────────────────────────────
  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-remove-line]') : null;
    if (!btn) return;
    var row = btn.closest('.line-item');
    if (row) {
      var grp = row.closest('.line-group');
      row.remove();
      if (grp) refreshCounts(grp);
    }
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
  // Les templates génériques (sans pays) sont toujours visibles ; ceux liés à un
  // pays n'apparaissent QUE si ce pays est sélectionné (issue #187). Sans pays
  // choisi, seuls les génériques restent — pas la Belgique ni les autres pays.
  var startCountry = document.querySelector('[data-start-country]');
  var startTemplate = document.querySelector('[data-start-template]');
  if (startCountry && startTemplate) {
    var filterTemplates = function () {
      var c = startCountry.value;
      Array.prototype.forEach.call(startTemplate.querySelectorAll('option'), function (o) {
        var oc = o.dataset.country || '';
        var show = oc === '' || oc === c;
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

  // ── #192 : lisibilité des lignes tarifaires ───────────────────────────────
  // Le libellé, le type et la catégorie d'une ligne s'affichent en texte compact
  // (avec une icône crayon) ; clic → le champ redevient éditable. Le montant, lui,
  // reste toujours un input. Le contrôle masqué reste dans le DOM pour être soumis.
  // Compteur « rempli/total » par catégorie + repli des blocs.
  var linesWrap = document.querySelector('.lines-wrap');
  var editLabel = linesWrap ? (linesWrap.dataset.editField || '') : '';

  function isAmountInput(el) {
    return !!(el && el.matches && el.matches('.line-amount-row input[name$="[amount]"]'));
  }

  // Champs repliables en texte (hors montant) : libellé et type (lignes custom),
  // catégorie (toutes lignes).
  function isCollapsibleField(el) {
    return !!(el && el.matches && el.matches('.line-label-input, .line-kind-select, .line-category-select'));
  }

  // Élément à masquer/ancrer : pour le libellé custom c'est le <label> parent,
  // pour un select c'est le select lui-même.
  function hostOf(el) {
    return el.classList.contains('line-label-input') ? (el.closest('label.form-label') || el) : el;
  }

  function fieldText(el) {
    if (el.tagName === 'SELECT') {
      var opt = el.options[el.selectedIndex];
      return opt ? opt.text.trim() : '';
    }
    return el.value.trim();
  }

  function toFieldDisplay(el) {
    var host = hostOf(el);
    var prev = host.previousElementSibling;
    var btn = (prev && prev.matches && prev.matches('[data-field-display]')) ? prev : null;
    var text = fieldText(el);
    if (text === '') {            // libellé vide : rien à afficher, on laisse éditable
      if (btn) btn.remove();
      host.classList.remove('is-hidden');
      return;
    }
    if (!btn) {
      btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'line-field-display';
      btn.setAttribute('data-field-display', '');
      if (editLabel) btn.setAttribute('title', editLabel);
      host.parentNode.insertBefore(btn, host);
    }
    btn.textContent = '';
    var pencil = document.createElement('span');
    pencil.className = 'line-field-pencil';
    pencil.textContent = '✎';
    btn.appendChild(pencil);
    btn.appendChild(document.createTextNode(text));
    host.classList.add('is-hidden');
  }

  function refreshCounts(el) {
    var grp = el && el.closest ? el.closest('.line-group') : null;
    if (!grp) return;
    var inputs = grp.querySelectorAll('.line-amount-row input[name$="[amount]"]');
    var filled = Array.prototype.filter.call(inputs, function (i) { return i.value.trim() !== ''; }).length;
    var badge = grp.querySelector('[data-group-count]');
    if (badge) badge.textContent = filled + '/' + inputs.length;
  }

  // focusout bulle (contrairement à blur) → couvre aussi les lignes ajoutées.
  document.addEventListener('focusout', function (e) {
    if (isCollapsibleField(e.target)) toFieldDisplay(e.target);
    else if (isAmountInput(e.target)) refreshCounts(e.target);
  });

  // Choisir une option (type / catégorie) replie aussitôt le select en texte.
  document.addEventListener('change', function (e) {
    if (isCollapsibleField(e.target) && e.target.tagName === 'SELECT') toFieldDisplay(e.target);
  });

  document.addEventListener('click', function (e) {
    var disp = e.target.closest ? e.target.closest('[data-field-display]') : null;
    if (disp) {
      var host = disp.nextElementSibling;
      disp.remove();
      if (host) {
        host.classList.remove('is-hidden');
        var ctrl = host.matches('input, select') ? host : host.querySelector('input, select');
        if (ctrl) ctrl.focus();
      }
      return;
    }
    var toggle = e.target.closest ? e.target.closest('[data-toggle-group]') : null;
    if (toggle) {
      var group = toggle.closest('.line-group');
      if (!group) return;
      var collapsed = group.classList.toggle('is-collapsed');
      toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    }
  });

  // État initial : replier en texte les libellés/types/catégories renseignés.
  document.querySelectorAll('.line-label-input, .line-kind-select, .line-category-select').forEach(toFieldDisplay);
})();
