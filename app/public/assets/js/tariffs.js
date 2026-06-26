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

function switchTab(type, event) {
  document.getElementById('energy_type_field').value = type;
  document.querySelectorAll('.form-tab').forEach(t => t.classList.remove('active'));
  event.target.classList.add('active');
  document.getElementById('elec-lines').style.display = type === 'electricity' ? '' : 'none';
  document.getElementById('gas-lines').style.display  = type === 'gas'         ? '' : 'none';
  document.getElementById('pcs-row').style.display    = type === 'gas'         ? '' : 'none';
  syncTariffInputs(type);
}

function toggleLines(id) {
  document.getElementById(id).classList.toggle('open');
}

syncTariffInputs(document.getElementById('energy_type_field').value);
