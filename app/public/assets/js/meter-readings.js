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
// `params` substitue les jetons `{nom}` du libellé, comme Translator::t() côté
// PHP et tr() dans dashboard.js. Passe unique : une valeur substituée qui
// contiendrait « {autre} » n'est pas resubstituée.
function tr(key, fallback, params) {
  const message = METER_I18N[key] || fallback;
  if (!params) return message;

  return message.replace(/\{(\w+)\}/g, (placeholder, name) => (
    Object.prototype.hasOwnProperty.call(params, name) ? String(params[name]) : placeholder
  ));
}

// Un index de compteur valide : nombre fini et non négatif. `Number.isFinite`
// (et non `!isNaN`) rejette aussi Infinity — que `JSON.stringify` sérialiserait
// silencieusement en `null` vers l'API. Partagé par les trois énergies.
const isValidIndex = (v) => Number.isFinite(v) && v >= 0;

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

// ── Pagination des historiques (#257) ───────────────────────────────────────
// L'API renvoie une page ({ items, total, page, per_page }) : sans elle,
// l'historique électricité était plafonné à 100 relevés côté serveur et les
// index les plus anciens restaient inatteignables.

const PER_PAGE = 25;
const pageState = { electricity: 1, gas: 1, water: 1 };
// Numéro du dernier chargement lancé par fluide : une réponse dont le numéro
// n'est plus le courant est obsolète (cf. loadHistory).
const loadSeq = { electricity: 0, gas: 0, water: 0 };

// Met à jour le libellé et l'état des boutons sous un tableau. `perPage` vient de
// la réponse : c'est le serveur qui borne réellement la taille de page, la
// constante locale n'est qu'un repli. Masqué tant qu'un seul écran de relevés
// existe : le contrôle n'apporterait rien.
function renderPager(prefix, total, perPage) {
  const pager = document.getElementById(`${prefix}-pager`);
  if (!pager) return;
  const size = perPage > 0 ? perPage : PER_PAGE;
  const pages = Math.max(1, Math.ceil(total / size));
  const page = pageState[prefix];

  const status = pager.querySelector('[data-page-status]');
  if (status) status.textContent = tr('pageStatus', 'Page {page} / {pages} ({total})', { page, pages, total });
  const prev = pager.querySelector('[data-page-prev]');
  const next = pager.querySelector('[data-page-next]');
  if (prev) prev.disabled = page <= 1;
  if (next) next.disabled = page >= pages;

  pager.hidden = total <= size;
  keepFocusInPager(prev, next);
}

// Le bouton qu'on vient d'actionner peut devenir `disabled` (dernière page, ou
// retour en page 1) : le focus retomberait sur <body> et la navigation clavier
// serait perdue en plein milieu du parcours. On le déplace sur l'autre bouton,
// qui est forcément actif — un pager dont les deux boutons sont désactivés tient
// sur un seul écran, donc vient d'être masqué.
function keepFocusInPager(prev, next) {
  const active = document.activeElement;
  // `active` peut être null (document en cours de déchargement) et prev/next
  // absents d'un pager tronqué : on ne compare qu'un focus réellement posé sur
  // l'un des deux boutons.
  if (!active || (active !== prev && active !== next)) return;
  if (!active.disabled) return;

  const fallback = active === next ? prev : next;
  if (fallback && !fallback.disabled) fallback.focus();
}

// Charge une page et rend le tableau. `page` par défaut : celle affichée
// (rechargement en place) ; la navigation passe la page visée. Renvoie la
// réponse, ou null si le chargement a échoué ou a été dépassé par un plus récent.
async function loadHistory(prefix, action, emptyLabel, render = renderReadings, page = pageState[prefix]) {
  const seq = ++loadSeq[prefix];
  try {
    const res = await fetch(`api?action=${action}&page=${page}&per_page=${PER_PAGE}`);
    const data = await res.json();
    // Deux clics rapprochés sur « Suivant » lancent deux chargements concurrents :
    // sans ce test, la réponse la plus lente écraserait la plus récente et le
    // tableau afficherait une page en arrière. On ignore tout ce qui n'est plus
    // le dernier chargement demandé — ni rendu, ni mémorisation.
    if (seq !== loadSeq[prefix]) return null;

    // Le serveur ramène une page hors borne à la dernière page non vide (dernier
    // relevé d'une page supprimé, p. ex.) : l'état local se réaligne dessus,
    // sinon la navigation resterait bloquée sur une page fantôme. L'état n'est
    // validé qu'ici : un « Suivant » qui échoue laisse la page courante affichée
    // ET mémorisée, sinon le clic suivant sauterait un cran.
    const served = Number(data.page) || 1;
    const moved  = served !== pageState[prefix];
    pageState[prefix] = served;

    render(`${prefix}-tbody`, Array.isArray(data.items) ? data.items : [], emptyLabel, data);
    renderPager(prefix, Number(data.total) || 0, Number(data.per_page) || PER_PAGE);

    // Le tableau vit dans une boîte scrollable (max-height 420px) : sans remise à
    // zéro, la page suivante s'ouvrirait au milieu de la liste. Uniquement sur
    // changement de page — un rechargement en place garde la position de lecture.
    const box = document.getElementById(`${prefix}-tbody`)?.closest('.gas-history');
    if (moved && box) box.scrollTop = 0;

    return data;
  } catch (e) {
    return null; /* keep placeholder */
  }
}

// Un relevé antidaté n'atterrit pas forcément sur la première page : sans ce
// signal, l'enregistrement paraîtrait sans effet (« ✓ Enregistré » et un tableau
// inchangé). `rows` est la page rechargée après la saisie.
function feedbackForSavedReading(feedbackId, rows, readingAt) {
  if (Array.isArray(rows) && !rows.some((r) => r.reading_at === readingAt)) {
    setFeedback(feedbackId, tr('savedElsewhere', '✓ Saved — the reading is older than this page.'), 'ok');
  }
}

const ELEC_KEYS = ['import_t1', 'import_t2', 'export_t1', 'export_t2', 'production'];

// Calcule en une seule passe le delta de chaque registre par rapport au relevé
// précédent (plus ancien). Les lignes arrivent triées du plus récent au plus
// ancien : on les remonte du plus ancien vers le plus récent en mémorisant la
// dernière valeur non nulle vue par registre. Renvoie un tableau parallèle à
// `rows` où deltas[i][key] vaut le delta (arrondi à 3 déc.) ou null (pas de
// précédent, ou valeur courante nulle). L'arrondi évite qu'un résidu flottant
// affiche un « -0.000 » rouge trompeur.
//
// `previous` (#257) est le relevé immédiatement plus ancien que la page, renvoyé
// hors liste par l'API : il amorce `lastSeen` pour que la dernière ligne d'une
// page affiche son vrai delta plutôt qu'un « — ». Un registre absent de
// `previous` reste sans delta sur cette ligne, comme en tête d'historique.
function elecDeltas(rows, previous) {
  const deltas = rows.map(() => ({}));
  const lastSeen = {};
  if (previous) {
    for (const key of ELEC_KEYS) {
      const seed = previous[key];
      if (seed !== null && seed !== undefined) lastSeen[key] = parseFloat(seed);
    }
  }
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

function renderElectricityReadings(tbodyId, rows, emptyLabel, data) {
  const tbody = document.getElementById(tbodyId);
  if (!tbody) return;
  if (!Array.isArray(rows) || rows.length === 0) {
    tbody.innerHTML = `<tr><td colspan="7" class="td-empty">${emptyLabel}</td></tr>`;
    return;
  }
  const deltas = elecDeltas(rows, data && data.previous);
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

const loadElectricityHistory = (page) =>
  loadHistory('electricity', 'electricity_history', tr('emptyElectricity', 'No electricity reading recorded.'), renderElectricityReadings, page);

async function submitUtility(prefix, action) {
  const btn = document.getElementById(`${prefix}-btn`);
  const { date, value: at } = readingAt(prefix);
  const value = parseFloat(document.getElementById(`${prefix}-value`)?.value || '');
  const feedbackId = `${prefix}-feedback`;

  setFeedback(feedbackId, '');
  if (!date || !isValidIndex(value)) {
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
      // Retour en page 1 : le cas courant est un relevé du jour, donc en tête.
      const reloaded = await RELOADERS[prefix](1);
      feedbackForSavedReading(feedbackId, reloaded && reloaded.items, at);
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
  let invalid = false;

  ELEC_KEYS.forEach((key) => {
    const raw = document.getElementById(`electricity-${key}`)?.value || '';
    if (raw === '') return;
    const value = parseFloat(raw);
    // Symétrique à submitUtility (gaz/eau) : un index négatif ou non fini
    // bloque l'envoi côté client plutôt que de laisser le back-end le rejeter.
    if (!isValidIndex(value)) { invalid = true; return; }
    payload[key] = value;
    hasValue = true;
  });

  setFeedback('electricity-feedback', '');
  // Une valeur saisie mais invalide (négative / non finie) a sa propre erreur,
  // pour ne pas afficher « renseigne au moins un index » alors qu'il y en a un.
  if (invalid) {
    setFeedback('electricity-feedback', tr('invalidUtility', '⚠ Enter a date and a valid value.'), 'err');
    return;
  }
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
      const reloaded = await loadElectricityHistory(1);
      feedbackForSavedReading('electricity-feedback', reloaded && reloaded.items, at);
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

const reloadGas = (page) => loadHistory('gas', 'gas_history', tr('emptyGas', 'No gas reading recorded.'), renderReadings, page);
const reloadWater = (page) => loadHistory('water', 'water_history', tr('emptyWater', 'No water reading recorded.'), renderReadings, page);

const RELOADERS = { electricity: loadElectricityHistory, gas: reloadGas, water: reloadWater };

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
      // L'historique est vidé : toute page au-delà de la première disparaîtrait.
      () => deleteAndReload(action, {}, feedbackId, () => reloadFn(1)));
  });
}

// Navigation entre pages (#257) : délégation sur le conteneur, dont le contenu
// (libellé, boutons désactivés) est réécrit à chaque rechargement.
function wirePager(prefix) {
  const pager = document.getElementById(`${prefix}-pager`);
  if (!pager) return;
  pager.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-page-prev], [data-page-next]');
    if (!btn || btn.disabled) return;
    const target = pageState[prefix] + (btn.hasAttribute('data-page-next') ? 1 : -1);
    if (target < 1) return;
    // La page visée n'est mémorisée qu'une fois la réponse reçue (cf. loadHistory).
    RELOADERS[prefix](target);
  });
}

wireRowDeletion('gas-tbody', 'delete_gas_reading', (btn) => ({ id: parseInt(btn.dataset.id, 10) }), 'gas-del-feedback', reloadGas);
wireRowDeletion('water-tbody', 'delete_water_reading', (btn) => ({ id: parseInt(btn.dataset.id, 10) }), 'water-del-feedback', reloadWater);
wireRowDeletion('electricity-tbody', 'delete_electricity_reading', (btn) => ({ reading_at: btn.dataset.at }), 'electricity-del-feedback', loadElectricityHistory);

wireDeleteAll('gas-delete-all', 'delete_gas_all', 'gas-del-feedback', reloadGas);
wireDeleteAll('water-delete-all', 'delete_water_all', 'water-del-feedback', reloadWater);
wireDeleteAll('electricity-delete-all', 'delete_electricity_meter', 'electricity-del-feedback', loadElectricityHistory);

wirePager('electricity');
wirePager('gas');
wirePager('water');

loadElectricityHistory();
reloadGas();
reloadWater();
