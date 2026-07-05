// Interactions de la page tarifs. Aucune fonction globale ni handler inline :
// tout est câblé par addEventListener sur des data-attributes (CSP enforced, #73).
(function () {
  function syncTariffInputs(type) {
    const isElectricity = type === 'electricity';

    document.querySelectorAll('#elec-lines input').forEach((input) => {
      input.disabled = !isElectricity;
    });

    document.querySelectorAll('#gas-lines input').forEach((input) => {
      input.disabled = isElectricity;
    });

    document.querySelectorAll('#pcs-row input').forEach((input) => {
      input.disabled = isElectricity;
    });
  }

  function switchTab(type, tabEl) {
    document.getElementById('energy_type_field').value = type;
    document.querySelectorAll('.form-tab').forEach(t => t.classList.remove('active'));
    tabEl.classList.add('active');
    document.getElementById('elec-lines').style.display = type === 'electricity' ? '' : 'none';
    document.getElementById('gas-lines').style.display  = type === 'gas'         ? '' : 'none';
    document.getElementById('pcs-row').style.display    = type === 'gas'         ? '' : 'none';
    syncTariffInputs(type);
  }

  function toggleLines(id) {
    document.getElementById(id).classList.toggle('open');
  }

  // Câblage (ex-handlers inline onclick).
  document.querySelectorAll('[data-tab]').forEach(el =>
    el.addEventListener('click', () => switchTab(el.dataset.tab, el)));
  document.querySelectorAll('[data-toggle-lines]').forEach(el =>
    el.addEventListener('click', () => toggleLines(el.dataset.toggleLines)));

  const typeField = document.getElementById('energy_type_field');
  if (typeField) {
    syncTariffInputs(typeField.value);
  }
})();
