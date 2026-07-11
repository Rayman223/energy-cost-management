const METER_I18N = (typeof window !== 'undefined' && window.METER_I18N) || {};
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
  return { date, value: `${date} ${time}:00` };
}

// Formate un index de compteur (m³ ou kWh) : 3 décimales, séparateur de milliers.
function fmtIndex(v) {
  const parts = parseFloat(v).toFixed(3).split('.');
  return parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + '.' + parts[1];
}

function renderReadings(tbodyId, rows, emptyLabel) {
  const tbody = document.getElementById(tbodyId);
  if (!tbody) return;
  if (!Array.isArray(rows) || rows.length === 0) {
    tbody.innerHTML = `<tr><td colspan="3" class="td-empty">${emptyLabel}</td></tr>`;
    return;
  }
  tbody.innerHTML = rows.map(r =>
    `<tr><td>${r.reading_at.slice(0, 16)}</td><td>${fmtIndex(r.counter_m3)}</td><td class="td-delta">${r.delta_m3 !== null ? '+' + fmtIndex(r.delta_m3) + ' m³' : '—'}</td></tr>`
  ).join('');
}

async function loadHistory(action, tbodyId, emptyLabel, render = renderReadings) {
  try {
    const res = await fetch(`api?action=${action}`);
    render(tbodyId, await res.json(), emptyLabel);
  } catch (e) { /* keep placeholder */ }
}

const ELEC_KEYS = ['import_t1', 'import_t2', 'export_t1', 'export_t2', 'production'];

function renderElectricityReadings(tbodyId, rows, emptyLabel) {
  const tbody = document.getElementById(tbodyId);
  if (!tbody) return;
  if (!Array.isArray(rows) || rows.length === 0) {
    tbody.innerHTML = `<tr><td colspan="6" class="td-empty">${emptyLabel}</td></tr>`;
    return;
  }
  const cell = (v) => (v === null || v === undefined) ? '—' : fmtIndex(v);
  tbody.innerHTML = rows.map(r =>
    `<tr><td>${r.reading_at.slice(0, 16)}</td>${ELEC_KEYS.map(k => `<td>${cell(r[k])}</td>`).join('')}</tr>`
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
  if (!date || isNaN(value) || value <= 0) {
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
loadElectricityHistory();
loadHistory('gas_history', 'gas-tbody', tr('emptyGas', 'No gas reading recorded.'));
loadHistory('water_history', 'water-tbody', tr('emptyWater', 'No water reading recorded.'));
