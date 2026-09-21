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

// Chaque énergie a deux zones de retour — une sous le formulaire de saisie
// (`<prefix>-feedback`), une sous le tableau d'historique
// (`<prefix>-del-feedback`) — mais elles forment un seul canal : écrire dans
// l'une efface l'autre. Sinon le message de l'action précédente survit à côté du
// résultat de la nouvelle et se lit comme le sien — « ✓ Supprimé. » restait
// affiché sous le tableau pendant que l'ajout suivant confirmait sous le bouton
// (#58, visible surtout sur la batterie : le plafond d'un relevé par jour oblige
// à supprimer puis ré-ajouter pour corriger la valeur du jour).
function twinFeedbackId(id) {
  return id.endsWith('-del-feedback')
    ? id.replace('-del-feedback', '-feedback')
    : id.replace('-feedback', '-del-feedback');
}

function setFeedback(id, text, cls = '') {
  const feedback = document.getElementById(id);
  if (!feedback) return;
  const twin = document.getElementById(twinFeedbackId(id));
  if (twin) {
    twin.textContent = '';
    twin.className = 'form-feedback';
  }
  feedback.textContent = text;
  feedback.className = `form-feedback ${cls}`.trim();
}

// Compteur visé par une saisie ou une lecture d'historique (#55). Le <select>
// existe toujours (masqué s'il n'y a qu'un compteur) ; vide quand le compte n'en
// a encore aucun, auquel cas l'API résout le compteur par défaut — et le crée au
// premier relevé, comme avant le multi-compteur.
function meterTarget(prefix) {
  return document.getElementById(`${prefix}-meter`)?.value || '';
}

// Le <select> qui porte l'état de fin de vie de la cible. Les trois fluides
// l'appellent `<prefix>-meter` ; la batterie, plus ancienne, `battery-target`.
// Renommer le champ casserait les scripts et les tests qui le visent — la
// disparité se résout ici, une fois.
function closureSelect(prefix) {
  return document.getElementById(prefix === 'battery' ? 'battery-target' : `${prefix}-meter`);
}

// Le compteur (ou la batterie) sélectionné est-il fermé ? L'option reste
// sélectionnable — la borne de fermeture est EXCLUE, un relevé antérieur y a
// toute sa place — mais l'état mérite d'être dit : sans cela, un relevé du jour
// partirait pour revenir en 422.
function selectedMeterClosedOn(prefix) {
  const option = closureSelect(prefix)?.selectedOptions?.[0];

  return option?.getAttribute('data-closed') || null;
}

// La fermeture a-t-elle DÉJÀ pris effet ? Distinct de la question précédente :
// une fermeture programmée refuse les relevés datés d'après elle sans que le
// compteur soit fermé aujourd'hui. Seul l'état déjà acquis se dit au présent.
function selectedMeterAlreadyClosed(prefix) {
  return closureSelect(prefix)?.selectedOptions?.[0]?.hasAttribute('data-closed-now') === true;
}

// Champs de VALEUR d'un fluide — ceux qu'on verrouille quand le relevé serait
// refusé. La date et l'heure restent actives : c'est en les corrigeant que
// l'utilisateur débloque la saisie, les verrouiller l'enfermerait.
function valueFieldsOf(prefix) {
  let ids;
  if (prefix === 'electricity') {
    ids = ELEC_KEYS.map((key) => `electricity-${key}`);
  } else if (prefix === 'battery') {
    // Charge ET décharge : l'API en accepte une seule, mais toutes deux tombent
    // sous le même refus. N'en verrouiller qu'une laisserait un envoi partir.
    ids = BATTERY_KEYS.map((key) => `battery-${key}`);
  } else {
    ids = [`${prefix}-value`];
  }

  return ids.map((id) => document.getElementById(id)).filter(Boolean);
}

// Libellés des deux états, par fluide. Même mécanique, vocabulaire distinct :
// une batterie n'est pas « fermée », elle est hors service (#68).
function closureLabels(prefix) {
  return prefix === 'battery'
    ? {
      blockedKey: 'batteryDecommissionedOn',
      blockedFallback: 'This battery has been out of service since {date}: pick an earlier date.',
      closedKey: 'batteryDecommissioned',
      closedFallback: 'This battery is out of service: only readings dated before its out-of-service date are accepted.',
    }
    : {
      blockedKey: 'meterClosedOn',
      blockedFallback: 'This meter closed on {date}: pick an earlier date.',
      closedKey: 'meterClosed',
      closedFallback: 'This meter is closed: only readings dated before its closing date are accepted.',
    };
}

// Signale la fermeture sans rien bloquer. Le bouton reste actif : c'est le
// serveur qui tranche, et il tranche sur la DATE du relevé, pas sur l'état du
// compteur. Les historiques restent consultables — fermer n'efface rien.
// Verrouille la saisie quand elle SERAIT REFUSÉE, et seulement alors.
//
// Le critère est la DATE saisie, pas l'état du compteur : la fermeture est une
// borne EXCLUE, un relevé antérieur reste valable — c'est même le geste le plus
// courant juste après une fermeture, saisir le dernier index relevé la veille.
// Griser dès qu'un compteur fermé est sélectionné interdirait depuis le web ce
// que le serveur accepte.
//
// Vaut aussi pour la batterie (#68) : `decommissioned_on` lui est opposée depuis
// #55 exactement comme la fermeture au compteur, et le refus remontait jusqu'à
// l'écran en anglais brut, faute de passer par ici.
function syncClosedState(prefix) {
  const closedOn = selectedMeterClosedOn(prefix);
  const date = document.getElementById(`${prefix}-date`)?.value || '';
  // Comparaison lexicographique sur 'YYYY-MM-DD' : équivalente à l'ordre
  // chronologique, et sans fuseau — la borne est une date, pas un instant.
  const blocked = closedOn !== null && date !== '' && date >= closedOn;

  const btn = document.getElementById(`${prefix}-btn`);
  if (btn) {
    btn.disabled = blocked;
  }
  valueFieldsOf(prefix).forEach((field) => {
    field.disabled = blocked;
  });

  const labels = closureLabels(prefix);
  if (blocked) {
    setFeedback(`${prefix}-feedback`, tr(labels.blockedKey, labels.blockedFallback, { date: closedOn }), 'err');
  } else if (closedOn !== null && selectedMeterAlreadyClosed(prefix)) {
    setFeedback(`${prefix}-feedback`, tr(labels.closedKey, labels.closedFallback), '');
  } else {
    setFeedback(`${prefix}-feedback`, '');
  }
}

// Le message « aucun compteur déclaré » est rendu au CHARGEMENT de la page, mais
// la saisie passe par AJAX : la page n'est jamais re-rendue. Dès qu'un relevé
// aboutit, le compteur existe et le message cesse d'être vrai — il faut donc le
// retirer soi-même, sinon il contredit l'action que l'utilisateur vient de faire.
function markMeterDeclared(prefix) {
  const hint = document.getElementById(`${prefix}-meter-hint`);
  if (hint) {
    hint.hidden = true;
  }
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
const pageState = { electricity: 1, gas: 1, water: 1, battery: 1 };
// Numéro du dernier chargement lancé par fluide : une réponse dont le numéro
// n'est plus le courant est obsolète (cf. loadHistory).
const loadSeq = { electricity: 0, gas: 0, water: 0, battery: 0 };

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
async function loadHistory(prefix, action, emptyLabel, render = renderReadings, page = pageState[prefix], extraQuery = '') {
  const seq = ++loadSeq[prefix];
  try {
    const res = await fetch(`api?action=${action}&page=${page}&per_page=${PER_PAGE}${extraQuery}`);
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
  loadHistory(
    'electricity', 'electricity_history', tr('emptyElectricity', 'No electricity reading recorded.'),
    renderElectricityReadings, page, `&meter_id=${meterTarget('electricity')}`
  );

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
      body: JSON.stringify({ counter_m3: value, reading_at: at, meter_id: meterTarget(prefix) }),
    });
    const data = await res.json();
    if (data.ok) {
      setFeedback(feedbackId, tr('saved', '✓ Saved.'), 'ok');
      markMeterDeclared(prefix);
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
  const payload = { reading_at: at, meter_id: meterTarget('electricity') };
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
      markMeterDeclared('electricity');
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

// ── Batteries (#26) ─────────────────────────────────────────────────────────
// Les deux compteurs se relèvent indépendamment : l'un des deux suffit, et une
// cellule vide s'affiche « — » plutôt que zéro — un compteur non relevé n'est
// pas un compteur à zéro.

const BATTERY_KEYS = ['charge', 'discharge'];

// Batterie visée. Le <select> existe toujours (masqué s'il n'y en a qu'une) ;
// son absence signifie que la section entière n'est pas rendue.
function batteryTarget() {
  return document.getElementById('battery-target')?.value || '';
}

function renderBatteryReadings(tbodyId, rows, emptyLabel) {
  const tbody = document.getElementById(tbodyId);
  if (!tbody) return;
  if (!Array.isArray(rows) || rows.length === 0) {
    tbody.innerHTML = `<tr><td colspan="4" class="td-empty">${emptyLabel}</td></tr>`;
    return;
  }

  // Même grammaire que l'électricité : index en valeur, delta « en petit dessous »
  // (bleu, rouge si négatif — un index cumulé qui recule signale une anomalie).
  const cell = (index, delta) => {
    if (index === null || index === undefined) return '<td>—</td>';
    const sub = delta === null || delta === undefined
      ? ''
      : `<span class="td-sub ${delta < 0 ? 'td-delta-neg' : 'td-delta'}">${delta >= 0 ? '+' : ''}${fmtIndex(delta)} kWh</span>`;
    return `<td>${fmtIndex(index)}${sub}</td>`;
  };

  tbody.innerHTML = rows.map(r =>
    `<tr><td>${window.TZ ? window.TZ.formatReadingAt(r.reading_at) : r.reading_at.slice(0, 16)}</td>`
    + cell(r.charge, r.delta_charge)
    + cell(r.discharge, r.delta_discharge)
    + delButton('data-id', r.id) + '</tr>'
  ).join('');
}

async function submitBattery() {
  const btn = document.getElementById('battery-btn');
  const { date, value: at } = readingAt('battery');
  const payload = { reading_at: at, battery_id: batteryTarget() };
  let hasValue = false;
  let invalid = false;

  BATTERY_KEYS.forEach((key) => {
    const raw = document.getElementById(`battery-${key}`)?.value || '';
    if (raw === '') return;
    const value = parseFloat(raw);
    if (!isValidIndex(value)) { invalid = true; return; }
    payload[key] = value;
    hasValue = true;
  });

  setFeedback('battery-feedback', '');
  if (invalid) {
    setFeedback('battery-feedback', tr('invalidUtility', '⚠ Enter a date and a valid value.'), 'err');
    return;
  }
  if (!date || !hasValue) {
    setFeedback('battery-feedback', tr('invalidBattery', '⚠ Enter a date and at least one battery index.'), 'err');
    return;
  }

  btn.disabled = true;
  const oldText = btn.textContent;
  btn.textContent = tr('sending', 'Sending…');
  try {
    const res = await fetch('api?action=battery_entry', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const data = await res.json();
    if (data.ok) {
      setFeedback('battery-feedback', tr('saved', '✓ Saved.'), 'ok');
      BATTERY_KEYS.forEach((key) => {
        document.getElementById(`battery-${key}`).value = '';
      });
      const reloaded = await reloadBattery(1);
      feedbackForSavedReading('battery-feedback', reloaded && reloaded.items, at);
    } else {
      setFeedback('battery-feedback', `✗ ${data.error || tr('unknownError', 'Unknown error.')}`, 'err');
    }
  } catch (e) {
    setFeedback('battery-feedback', tr('networkError', '✗ Network error.'), 'err');
  } finally {
    btn.disabled = false;
    btn.textContent = oldText;
  }
}

document.getElementById('electricity-btn')?.addEventListener('click', submitElectricity);
document.getElementById('gas-btn')?.addEventListener('click', () => submitUtility('gas', 'gas_entry'));
document.getElementById('water-btn')?.addEventListener('click', () => submitUtility('water', 'water_entry'));
document.getElementById('battery-btn')?.addEventListener('click', submitBattery);
// Changer de batterie recharge l'historique (le tableau appartient à la batterie
// sélectionnée) et réévalue le verrou de fin de service : les deux sont câblés
// plus bas, avec les trois fluides.

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

// L'encart « dernier relevé » est rendu par le serveur pour le compteur PAR
// DÉFAUT. Changer de compteur recharge son historique mais ne re-rend pas la
// page : sans cette mise à jour, l'index d'un AUTRE compteur resterait affiché
// comme étant le sien (#55). Une saisie ou une suppression le rafraîchit de la
// même façon, au lieu de laisser l'ancien index en place.
//
// Seule la page 1 porte le relevé le plus récent (liste décroissante) : sur une
// page plus ancienne, l'encart garde ce qu'il affiche déjà, qui reste vrai.
function updateLatest(prefix, data) {
  const box   = document.getElementById(`${prefix}-latest`);
  const value = document.getElementById(`${prefix}-latest-value`);
  if (!box || !value || Number(data?.page) !== 1) return;

  const latest = (Array.isArray(data?.items) ? data.items : [])[0];
  box.hidden = latest === undefined;
  if (latest !== undefined) {
    value.textContent = `${fmtIndex(latest.counter_m3)} m³`;
  }
}

const reloadUtility = async (prefix, action, emptyLabel, page) => {
  const data = await loadHistory(
    prefix, action, emptyLabel, renderReadings, page, `&meter_id=${meterTarget(prefix)}`
  );
  // `null` = chargement obsolète (cf. loadHistory) : ne rien réécrire.
  if (data) updateLatest(prefix, data);

  return data;
};

const reloadGas = (page) => reloadUtility('gas', 'gas_history', tr('emptyGas', 'No gas reading recorded.'), page);
const reloadWater = (page) => reloadUtility('water', 'water_history', tr('emptyWater', 'No water reading recorded.'), page);
const reloadBattery = (page) => loadHistory(
  'battery', 'battery_history', tr('emptyBattery', 'No battery reading recorded.'),
  renderBatteryReadings, page, `&battery_id=${batteryTarget()}`
);

const RELOADERS = { electricity: loadElectricityHistory, gas: reloadGas, water: reloadWater, battery: reloadBattery };

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

// `payloadFn` : la cible est relue au clic, comme pour les suppressions de ligne.
// Sans elle, « tout supprimer » viderait le compteur par défaut quel que soit
// celui affiché à l'écran (#55).
function wireDeleteAll(btnId, action, feedbackId, reloadFn, payloadFn = () => ({})) {
  document.getElementById(btnId)?.addEventListener('click', () => {
    confirmDelete(tr('deleteAllConfirm', 'Delete all readings for this utility?'), tr('deleteAll', 'Delete all'),
      // L'historique est vidé : toute page au-delà de la première disparaîtrait.
      () => deleteAndReload(action, payloadFn(), feedbackId, () => reloadFn(1)));
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

// La cible est relue au moment du clic : elle a pu changer depuis le rendu.
wireRowDeletion('gas-tbody', 'delete_gas_reading', (btn) => ({ id: parseInt(btn.dataset.id, 10), meter_id: meterTarget('gas') }), 'gas-del-feedback', reloadGas);
wireRowDeletion('water-tbody', 'delete_water_reading', (btn) => ({ id: parseInt(btn.dataset.id, 10), meter_id: meterTarget('water') }), 'water-del-feedback', reloadWater);
wireRowDeletion('electricity-tbody', 'delete_electricity_reading', (btn) => ({ reading_at: btn.dataset.at, meter_id: meterTarget('electricity') }), 'electricity-del-feedback', loadElectricityHistory);
// La cible est relue au moment du clic : elle a pu changer depuis le rendu.
wireRowDeletion('battery-tbody', 'delete_battery_reading', (btn) => ({ id: parseInt(btn.dataset.id, 10), battery_id: batteryTarget() }), 'battery-del-feedback', reloadBattery);

wireDeleteAll('gas-delete-all', 'delete_gas_all', 'gas-del-feedback', reloadGas, () => ({ meter_id: meterTarget('gas') }));
wireDeleteAll('water-delete-all', 'delete_water_all', 'water-del-feedback', reloadWater, () => ({ meter_id: meterTarget('water') }));
wireDeleteAll('electricity-delete-all', 'delete_electricity_meter', 'electricity-del-feedback', loadElectricityHistory, () => ({ meter_id: meterTarget('electricity') }));
// « Tout supprimer » ne vide que la batterie sélectionnée : les autres gardent
// leur historique. wireDeleteAll poste un corps vide, d'où le surcharge ici.
document.getElementById('battery-delete-all')?.addEventListener('click', () => {
  confirmDelete(tr('deleteAllConfirm', 'Delete all readings for this utility?'), tr('deleteAll', 'Delete all'),
    () => deleteAndReload('delete_battery_readings_all', { battery_id: batteryTarget() }, 'battery-del-feedback', () => reloadBattery(1)));
});

// Changer de compteur recharge son historique : la liste affichée doit toujours
// être celle de la cible que la saisie et la suppression viseront. La batterie
// suit la même règle — et depuis #68 le même verrou de fin de service, d'où sa
// présence ici plutôt que dans un écouteur à part.
['electricity', 'gas', 'water', 'battery'].forEach((prefix) => {
  closureSelect(prefix)?.addEventListener('change', () => {
    syncClosedState(prefix);
    RELOADERS[prefix](1);
  });
  // La date décide du verrouillage : la changer doit le réévaluer aussitôt,
  // sinon corriger la date laisserait le formulaire grisé.
  ['change', 'input'].forEach((event) => {
    document.getElementById(`${prefix}-date`)?.addEventListener(event, () => syncClosedState(prefix));
  });
  syncClosedState(prefix);
});

wirePager('electricity');
wirePager('gas');
wirePager('water');
wirePager('battery');

loadElectricityHistory();
reloadGas();
reloadWater();
if (document.getElementById('battery-tbody')) reloadBattery();
