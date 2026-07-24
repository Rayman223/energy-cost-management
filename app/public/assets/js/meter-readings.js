// Libellés i18n lus depuis le data block JSON (#meter-data, non exécutable →
// compatible CSP sans nonce). Voir meter_readings.php / #98.
const METER_I18N = (() => {
  const el = document.getElementById('meter-data');
  if (!el) return {};
  let d;
  try { d = JSON.parse(el.textContent); } catch (e) { return {}; }
  // Fuseau d'affichage/saisie de l'utilisateur, consommé par window.TZ (tz.js).
  if (d && d.timezone) window.APP_TIMEZONE = d.timezone;
  return (d && d.i18n) || {};
})();
const tr = (key, fallback) => METER_I18N[key] || fallback;

function setFeedback(id, text, cls = '') {
  const feedback = document.getElementById(id);
  if (!feedback) return;
  feedback.textContent = text;
  feedback.className = `form-feedback ${cls}`.trim();
}

function readingAt(prefix) {
  const date = document.getElementById(`${prefix}-date`)?.value || '';
  const time = document.getElementById(`${prefix}-time`)?.value || '00:00';
  // La saisie est une heure murale dans le fuseau de l'utilisateur ; on la
  // convertit en UTC (fuseau de stockage) avant l'envoi à l'API. `date` reste
  // en local pour la validation d'affichage.
  const value = window.TZ ? window.TZ.localInputToDbUtc(date, time) : `${date} ${time}:00`;
  return { date, value };
}

// Formate un index de compteur (m³ ou kWh) : 3 décimales, séparateur de milliers.
function fmtIndex(v) {
  const parts = parseFloat(v).toFixed(3).split('.');
  return parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + '.' + parts[1];
}

// Bouton « supprimer » (icône corbeille) porté par chaque ligne d'historique.
// L'attribut data-* (data-id pour gaz/eau, data-at pour l'électricité) transporte
// la cible ; la suppression est déléguée au <tbody> (voir wireRowDeletion).
function delButton(attr, value) {
  const label = tr('delete', 'Delete');
  return `<td class="td-actions"><button type="button" class="btn btn-red btn-sm" data-del ${attr}="${value}" aria-label="${label}" title="${label}">🗑</button></td>`;
}

function renderReadings(tbodyId, rows, emptyLabel) {
  const tbody = document.getElementById(tbodyId);
  if (!tbody) return;
  if (!Array.isArray(rows) || rows.length === 0) {
    tbody.innerHTML = `<tr><td colspan="4" class="td-empty">${emptyLabel}</td></tr>`;
    return;
  }
  tbody.innerHTML = rows.map(r =>
    `<tr><td>${window.TZ ? window.TZ.formatReadingAt(r.reading_at) : r.reading_at.slice(0, 16)}</td><td>${fmtIndex(r.counter_m3)}</td><td class="td-delta">${r.delta_m3 !== null ? '+' + fmtIndex(r.delta_m3) + ' m³' : '—'}</td>${delButton('data-id', r.id)}</tr>`
  ).join('');
}

async function loadHistory(action, tbodyId, emptyLabel, render = renderReadings) {
  try {
    const res = await fetch(`api?action=${action}`);
    render(tbodyId, await res.json(), emptyLabel);
  } catch (e) { /* keep placeholder */ }
}

const ELEC_KEYS = ['import_t1', 'import_t2', 'export_t1', 'export_t2', 'production'];

// Calcule en une seule passe le delta de chaque registre par rapport au relevé
// précédent (plus ancien). Les lignes arrivent triées du plus récent au plus
// ancien : on les remonte du plus ancien vers le plus récent en mémorisant la
// dernière valeur non nulle vue par registre. Renvoie un tableau parallèle à
// `rows` où deltas[i][key] vaut le delta (arrondi à 3 déc.) ou null (pas de
// précédent, ou valeur courante nulle). L'arrondi évite qu'un résidu flottant
// affiche un « -0.000 » rouge trompeur.
function elecDeltas(rows) {
  const deltas = rows.map(() => ({}));
  const lastSeen = {};
  for (let i = rows.length - 1; i >= 0; i--) {
    for (const key of ELEC_KEYS) {
      const cur = rows[i][key];
      if (cur === null || cur === undefined) { deltas[i][key] = null; continue; }
      const curNum = parseFloat(cur);
      deltas[i][key] = key in lastSeen ? Math.round((curNum - lastSeen[key]) * 1000) / 1000 : null;
      lastSeen[key] = curNum;
    }
  }
  return deltas;
}

function renderElectricityReadings(tbodyId, rows, emptyLabel) {
  const tbody = document.getElementById(tbodyId);
  if (!tbody) return;
  if (!Array.isArray(rows) || rows.length === 0) {
    tbody.innerHTML = `<tr><td colspan="7" class="td-empty">${emptyLabel}</td></tr>`;
    return;
  }
  const deltas = elecDeltas(rows);
  const fmtDelta = (d) => `${d >= 0 ? '+' : ''}${fmtIndex(d)} kWh`;
  // Cellule = valeur d'index + delta « en petit dessous » (bleu, rouge si négatif).
  const cell = (i, key) => {
    const v = rows[i][key];
    if (v === null || v === undefined) return '—';
    const d = deltas[i][key];
    const sub = d === null ? '' :
      `<span class="td-sub ${d < 0 ? 'td-delta-neg' : 'td-delta'}">${fmtDelta(d)}</span>`;
    return `${fmtIndex(v)}${sub}`;
  };
  tbody.innerHTML = rows.map((r, i) =>
    `<tr><td>${window.TZ ? window.TZ.formatReadingAt(r.reading_at) : r.reading_at.slice(0, 16)}</td>${ELEC_KEYS.map(k => `<td>${cell(i, k)}</td>`).join('')}${delButton('data-at', r.reading_at)}</tr>`
  ).join('');
}

const loadElectricityHistory = () =>
  loadHistory('electricity_history', 'electricity-tbody', tr('emptyElectricity', 'No electricity reading recorded.'), renderElectricityReadings);

async function submitUtility(prefix, action) {
  const btn = document.getElementById(`${prefix}-btn`);
  const { date, value: at } = readingAt(prefix);
  const value = parseFloat(document.getElementById(`${prefix}-value`)?.value || '');
  const feedbackId = `${prefix}-feedback`;

  setFeedback(feedbackId, '');
  if (!date || isNaN(value) || value < 0) {
    setFeedback(feedbackId, tr('invalidUtility', '⚠ Enter a date and a valid value.'), 'err');
    return;
  }

  btn.disabled = true;
  const oldText = btn.textContent;
  btn.textContent = tr('sending', 'Sending…');
  try {
    const res = await fetch(`api?action=${action}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ counter_m3: value, reading_at: at }),
    });
    const data = await res.json();
    if (data.ok) {
      setFeedback(feedbackId, tr('saved', '✓ Saved.'), 'ok');
      document.getElementById(`${prefix}-value`).value = '';
      await loadHistory(`${prefix}_history`, `${prefix}-tbody`, prefix === 'gas' ? tr('emptyGas', 'No gas reading recorded.') : tr('emptyWater', 'No water reading recorded.'));
    } else {
      setFeedback(feedbackId, `✗ ${data.error || tr('unknownError', 'Unknown error.')}`, 'err');
    }
  } catch (e) {
    setFeedback(feedbackId, tr('networkError', '✗ Network error.'), 'err');
  } finally {
    btn.disabled = false;
    btn.textContent = oldText;
  }
}

async function submitElectricity() {
  const btn = document.getElementById('electricity-btn');
  const { date, value: at } = readingAt('electricity');
  const payload = { reading_at: at };
  let hasValue = false;

  ELEC_KEYS.forEach((key) => {
    const raw = document.getElementById(`electricity-${key}`)?.value || '';
    if (raw === '') return;
    const value = parseFloat(raw);
    if (!isNaN(value)) {
      payload[key] = value;
      hasValue = true;
    }
  });

  setFeedback('electricity-feedback', '');
  if (!date || !hasValue) {
    setFeedback('electricity-feedback', tr('invalidElectricity', '⚠ Enter a date and at least one electricity index.'), 'err');
    return;
  }

  btn.disabled = true;
  const oldText = btn.textContent;
  btn.textContent = tr('sending', 'Sending…');
  try {
    const res = await fetch('api?action=electricity_entry', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const data = await res.json();
    if (data.ok) {
      setFeedback('electricity-feedback', tr('saved', '✓ Saved.'), 'ok');
      ELEC_KEYS.forEach((key) => {
        document.getElementById(`electricity-${key}`).value = '';
      });
      await loadElectricityHistory();
    } else {
      setFeedback('electricity-feedback', `✗ ${data.error || tr('unknownError', 'Unknown error.')}`, 'err');
    }
  } catch (e) {
    setFeedback('electricity-feedback', tr('networkError', '✗ Network error.'), 'err');
  } finally {
    btn.disabled = false;
    btn.textContent = oldText;
  }
}

document.getElementById('electricity-btn')?.addEventListener('click', submitElectricity);
document.getElementById('gas-btn')?.addEventListener('click', () => submitUtility('gas', 'gas_entry'));
document.getElementById('water-btn')?.addEventListener('click', () => submitUtility('water', 'water_entry'));

// ── Suppression de relevés ──────────────────────────────────────────────────

// Ouvre la modale de confirmation au design du site (confirm.js). Repli sur le
// confirm() natif si le script n'est pas chargé, pour ne jamais supprimer sans
// confirmation.
function confirmDelete(message, confirmText, onConfirm) {
  if (typeof window.siteConfirm === 'function') {
    window.siteConfirm(message, onConfirm, { danger: true, confirmText });
  } else if (window.confirm(message)) {
    onConfirm();
  }
}

async function deleteAndReload(action, payload, feedbackId, reloadFn) {
  setFeedback(feedbackId, '');
  try {
    const res = await fetch(`api?action=${action}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || '');
    // deleted:0 → rien n'a été supprimé (relevé déjà retiré ailleurs) : message
    // neutre plutôt qu'une fausse confirmation, puis rechargement pour refléter
    // l'état réel du tableau.
    if (data.deleted === 0) {
      setFeedback(feedbackId, tr('deleteNone', 'No reading deleted.'), '');
    } else {
      setFeedback(feedbackId, tr('deleted', '✓ Deleted.'), 'ok');
    }
    await reloadFn();
  } catch (e) {
    setFeedback(feedbackId, `✗ ${tr('deleteError', 'Deletion failed.')}`, 'err');
  }
}

const reloadGas = () => loadHistory('gas_history', 'gas-tbody', tr('emptyGas', 'No gas reading recorded.'));
const reloadWater = () => loadHistory('water_history', 'water-tbody', tr('emptyWater', 'No water reading recorded.'));

// Délégation sur le <tbody> : les lignes sont re-rendues à chaque rechargement,
// mais le tbody persiste, donc un seul écouteur suffit.
function wireRowDeletion(tbodyId, action, payloadFromBtn, feedbackId, reloadFn) {
  const tbody = document.getElementById(tbodyId);
  if (!tbody) return;
  tbody.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-del]');
    if (!btn) return;
    confirmDelete(tr('deleteConfirm', 'Delete this reading?'), tr('delete', 'Delete'),
      () => deleteAndReload(action, payloadFromBtn(btn), feedbackId, reloadFn));
  });
}

function wireDeleteAll(btnId, action, feedbackId, reloadFn) {
  document.getElementById(btnId)?.addEventListener('click', () => {
    confirmDelete(tr('deleteAllConfirm', 'Delete all readings for this utility?'), tr('deleteAll', 'Delete all'),
      () => deleteAndReload(action, {}, feedbackId, reloadFn));
  });
}

wireRowDeletion('gas-tbody', 'delete_gas_reading', (btn) => ({ id: parseInt(btn.dataset.id, 10) }), 'gas-del-feedback', reloadGas);
wireRowDeletion('water-tbody', 'delete_water_reading', (btn) => ({ id: parseInt(btn.dataset.id, 10) }), 'water-del-feedback', reloadWater);
wireRowDeletion('electricity-tbody', 'delete_electricity_reading', (btn) => ({ reading_at: btn.dataset.at }), 'electricity-del-feedback', loadElectricityHistory);

wireDeleteAll('gas-delete-all', 'delete_gas_all', 'gas-del-feedback', reloadGas);
wireDeleteAll('water-delete-all', 'delete_water_all', 'water-del-feedback', reloadWater);
wireDeleteAll('electricity-delete-all', 'delete_electricity_meter', 'electricity-del-feedback', loadElectricityHistory);

loadElectricityHistory();
reloadGas();
reloadWater();
