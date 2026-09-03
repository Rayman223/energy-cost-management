// ── Bootstrap : état serveur initial injecté par le template via un data block
// JSON (#dashboard-data, non exécutable → compatible CSP sans nonce). Ré-expose
// les globales window.__* attendues par le reste du fichier. dashboard.js est
// `defer` : le DOM (donc le data block) est déjà parsé à l'exécution. #98
(function () {
  var el = document.getElementById('dashboard-data');
  if (!el) return;
  var d;
  try { d = JSON.parse(el.textContent); } catch (e) { return; }
  window.APP_LOCALE = d.locale;
  window.APP_CURRENCY = d.currency;
  window.APP_TIMEZONE = d.timezone;
  window.__INIT_COST__ = d.initCost;
  window.__INIT_YEAR__ = d.initYear;
  window.__INIT_MONTH__ = d.initMonth;
  window.__INIT_GAS_COST__ = d.initGasCost;
  window.__INIT_GAS_YEAR__ = d.initGasYear;
  window.__INIT_GAS_MONTH__ = d.initGasMonth;
  window.__INIT_WATER_COST__ = d.initWaterCost;
  window.__INIT_WATER_YEAR__ = d.initWaterYear;
  window.__INIT_WATER_MONTH__ = d.initWaterMonth;
  window.__TARIFF_LINE_LABELS__ = d.tariffLineLabels;
  window.__TARIFF_GROUP_LABELS__ = d.tariffGroupLabels;
  window.__I18N__ = d.i18n;
})();

// ── Traduction des libellés rendus côté client (#223) ───────────────────────
// Le serveur sérialise le sous-catalogue `dash.*` / `common.*` dans le data
// block. Même convention de paramètres que Translator::t() côté PHP : `{name}`.
// Nommée `tr` et non `t` : plusieurs fonctions du fichier utilisent déjà `t`
// comme variable locale (total, delta), qui masquerait la fonction globale.
//
// La valeur retournée est interpolée telle quelle dans des templates `innerHTML` :
// aucune valeur de catalogue ne doit contenir de HTML, et toute donnée utilisateur
// passée en paramètre doit l'être via escapeHtml().
const I18N = (typeof window !== 'undefined' && window.__I18N__) || {};
function tr(key, params) {
  const message = Object.prototype.hasOwnProperty.call(I18N, key) ? I18N[key] : key;
  if (!params) return message;

  // Passe unique : une valeur substituée qui contiendrait « {autre} » (un nom de
  // grille saisi par l'utilisateur, p. ex.) ne doit pas être resubstituée ensuite.
  return message.replace(/\{(\w+)\}/g, (placeholder, name) => (
    Object.prototype.hasOwnProperty.call(params, name) ? String(params[name]) : placeholder
  ));
}

// ── Formatage monétaire localisé (devise + séparateurs du profil) ───────────
// Remplace les `toFixed(2) + ' €'` : le symbole et le format s'adaptent à la
// devise/locale du profil (APP_CURRENCY/APP_LOCALE).
const APP_LOCALE   = (typeof window !== 'undefined' && window.APP_LOCALE)   ? window.APP_LOCALE   : 'fr';
const APP_CURRENCY = (typeof window !== 'undefined' && window.APP_CURRENCY) ? window.APP_CURRENCY : 'EUR';
const _moneyFmt = new Intl.NumberFormat(APP_LOCALE, { style: 'currency', currency: APP_CURRENCY });
function formatMoney(v) {
  return _moneyFmt.format(Number(v) || 0);
}
// Symbole seul, pour les unités composées (€/kWh, €/j…) où le montant n'est pas
// formaté par Intl : dérivé de la devise du profil plutôt que codé en dur (#223).
const CURRENCY_SYMBOL = (function () {
  const part = _moneyFmt.formatToParts(0).find((p) => p.type === 'currency');
  return part ? part.value : APP_CURRENCY;
})();
// Prix unitaire du kWh : 2 décimales (le format monétaire) noieraient l'écart entre
// deux tarifs, d'où les 4 décimales — les séparateurs restent ceux de la locale.
const _rateFmt = new Intl.NumberFormat(APP_LOCALE, { minimumFractionDigits: 4, maximumFractionDigits: 4 });
function formatRate(v) {
  return `${_rateFmt.format(Number(v) || 0)} ${CURRENCY_SYMBOL}/kWh`;
}

// Le catalogue est plat (pas de pluralisation intégrée côté Translator) : on
// choisit la forme via Intl.PluralRules, qui applique les règles de la locale
// (le français range 0 et 1 dans « one », l'anglais seulement 1).
const _pluralRules = new Intl.PluralRules(APP_LOCALE);
function daysLabel(days) {
  const n = Number(days) || 0;
  const form = _pluralRules.select(n) === 'one' ? 'one' : 'other';
  return tr('dash.meta.days_' + form, { days: n });
}
// Divergence assumée entre les blocs : gaz et eau affichent la plage `from → to`
// juste avant le compteur, qui se suffit alors à lui-même (« 31 jours »). Le bloc
// électricité n'affiche pas de plage, d'où le préfixe `dash.meta.period`
// (« Période : 31 jours ») qui porte seul le contexte temporel.

// ── Rendu générique du détail de coût (piloté par cost.lines / kinds) ───────
// Le moteur de calcul renvoie une liste de lignes typées {key,kind,group,label,
// quantity,unit,rate,amount}. On les regroupe par nature — indépendant du pays.
const COST_GROUP_LABELS = (typeof window !== 'undefined' && window.__TARIFF_GROUP_LABELS__) || {};
const COST_LINE_LABELS = (typeof window !== 'undefined' && window.__TARIFF_LINE_LABELS__) || {};
const COST_GROUP_ORDER = ['energy', 'distribution', 'fixed', 'taxes', 'injection'];
// Un crédit se reconnaît à son kind (injection), indépendamment de la catégorie
// d'affichage désormais choisie librement par l'utilisateur.
const CREDIT_KINDS = ['injection_t1', 'injection_t2'];
function isCreditLine(ln) {
  return CREDIT_KINDS.indexOf(ln.kind) !== -1;
}

function costLineLabel(ln) {
  return ln.label || COST_LINE_LABELS[ln.key] || ln.key;
}
function costLineDetail(ln) {
  const rate = ln.rate;
  if (rate == null || Number(rate) === 0) return '';
  const q = Number(ln.quantity) || 0;
  if (ln.kind === 'fixed_annual') {
    return tr('dash.detail.fixed_annual', { days: q, rate: (Number(rate) / 365).toFixed(5), cur: CURRENCY_SYMBOL })
      + ` <span class="t-dim">${tr('dash.detail.fixed_annual_note', { rate: Number(rate).toFixed(2), cur: CURRENCY_SYMBOL })}</span>`;
  }
  // La quantité de mois peut être fractionnaire quand la période est répartie
  // entre plusieurs grilles successives (proration multi-grilles).
  if (ln.kind === 'fixed_monthly') {
    return tr('dash.detail.fixed_monthly', {
      months: Number.isInteger(q) ? q : q.toFixed(2),
      rate: Number(rate).toFixed(2),
      cur: CURRENCY_SYMBOL,
    });
  }
  return `${q.toFixed(2)} × ${Number(rate).toFixed(7)} ${ln.unit || ''}`;
}
// Génère les lignes groupées à partir de cost.lines, avec la fonction `row` locale.
function costLinesHtml(c, row) {
  let html = '';
  COST_GROUP_ORDER.forEach((g) => {
    const lines = (c.lines || []).filter((l) => l.group === g && (Number(l.amount) || Number(l.rate)));
    if (!lines.length) return;
    const allCredit = lines.every(isCreditLine);
    html += `<div class="cost-group-label ${allCredit ? 'cost-group-label--credit' : ''}">${COST_GROUP_LABELS[g] || g}</div>`;
    lines.forEach((l) => { html += row(costLineLabel(l), costLineDetail(l), l.amount, isCreditLine(l) ? 'credit' : ''); });
  });
  return html;
}
// Lignes Total / HTVA / TVA à partir du taux de la grille (cost.vat_rate).
// vat_rate est null quand la période couvre plusieurs grilles de taux différents :
// le détail HTVA reste la somme exacte des sous-périodes, mais aucun taux unique
// n'est affichable.
function costVatRows(c, row) {
  const mixedVat = (c.vat_rate == null);
  const vr       = mixedVat ? null : c.vat_rate;
  const factor   = mixedVat ? '' : `÷ ${(1 + vr / 100).toFixed(2)}`;
  return row(tr('dash.cost.total_incl_vat'), '', c.total, 'total')
    + row(tr('dash.cost.of_which_excl_vat'), factor, c.htva, 'vat')
    + row(mixedVat ? tr('dash.cost.of_which_vat') : tr('dash.cost.of_which_vat_rate', { rate: vr }),
          `${formatMoney(Math.abs(c.total))} − ${formatMoney(Math.abs(c.htva))}`,
          c.vat_included, 'vat vat-highlight');
}

// Échappement HTML des valeurs interpolées dans les templates `innerHTML`.
// Les noms de grilles sont saisis par l'utilisateur, et ceux du catalogue partagé
// (user_id NULL) sont diffusés à tous les comptes : jamais injectés bruts.
function escapeHtml(v) {
  return String(v ?? '').replace(/[&<>"']/g, (ch) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  })[ch]);
}

// Lignes « meta » détaillant les sous-périodes tarifaires d'une période couverte
// par plusieurs grilles successives. Vide quand une seule grille s'applique.
function tariffSegmentsMeta(data) {
  const segs = data.tariff_segments || [];
  if (segs.length < 2) return '';
  const _d = (iso) => String(iso).slice(8, 10) + '/' + String(iso).slice(5, 7);
  return segs.map((s) => `<span>${tr('dash.meta.segment', {
    name: escapeHtml(s.name),
    from: _d(s.from),
    to: _d(s.to),
    days: s.days,
    total: formatMoney(s.total),
  })}</span>`).join('');
}

// Motif d'indisponibilité : `reason_key` est une clé de catalogue posée par le
// serveur (traduite ici), `reason` le texte technique historique — échappé, car
// il peut relayer une donnée saisie. `fallbackKey` couvre les réponses sans motif.
function reasonHtml(data, fallbackKey) {
  if (data && data.reason_key) return tr(data.reason_key);
  if (data && data.reason) return escapeHtml(data.reason);
  return tr(fallbackKey);
}

// Avertissement de couverture tarifaire incomplète (#6) : le montant affiché
// prolonge la dernière grille connue sur les jours qu'aucune grille ne couvre.
// Vide — donc invisible — dès que la période est entièrement tarifée.
function tariffGapHtml(data) {
  const gap = data && data.tariff_gap;
  if (!gap) return '';

  const _d = (iso) => String(iso).slice(8, 10) + '/' + String(iso).slice(5, 7);
  const message = gap.days >= gap.total_days
    ? tr('dash.warn.no_tariff')
    : tr('dash.warn.tariff_gap', { days: daysLabel(gap.days), from: _d(gap.from), to: _d(gap.to) });

  return `<div class="tariff-warn">⚠ ${message} ${tr('dash.warn.tariff_gap_hint')}</div>`;
}

// ── Electricity cost navigation ────────────────────────────────────────────
(function () {
  const _APP_LOC = (typeof window !== 'undefined' && window.APP_LOCALE) ? window.APP_LOCALE : 'fr';
  const _cap = (str) => str.charAt(0).toUpperCase() + str.slice(1);
  const MONTHS_SHORT = Array.from({ length: 12 }, (_, i) => _cap(new Date(2000, i, 1).toLocaleDateString(_APP_LOC, { month: 'short' })));
  const MONTH_NAMES = Array.from({ length: 12 }, (_, i) => _cap(new Date(2000, i, 1).toLocaleDateString(_APP_LOC, { month: 'long' })));

  let navYear  = window.__INIT_YEAR__;
  let navMonth = window.__INIT_MONTH__;
  let navMode  = 'month';
  const NOW_YEAR  = window.__INIT_YEAR__;
  const NOW_MONTH = window.__INIT_MONTH__;

  function fmtEur(v) {
    if (v === null || v === undefined) return '<span class="t-muted">—</span>';
    return formatMoney(v);
  }

  function renderCostContent(data) {
    const el = document.getElementById('cost-content');
    if (!data || !data.available) {
      el.innerHTML = `<div class="no-tariff">
        <strong>${tr('dash.empty.electricity')}</strong>
        ${reasonHtml(data, 'dash.empty.electricity_reason')}
      </div>`;
      return;
    }
    const c = data.cost;
    const d = data.deltas || {};
    const t1 = +(d.prelev_jour ?? 0);
    const t2 = +(d.prelev_nuit ?? 0);
    const e1 = +(d.injec_jour  ?? 0);
    const e2 = +(d.injec_nuit  ?? 0);

    function row(label, detailStr, amount, cls = '') {
      const amtHtml = amount === null || amount === undefined
        ? '<span class="t-muted">—</span>'
        : formatMoney(amount);
      return `<div class="cost-line ${cls}">
        <span class="cl-label">${label}</span>
        <span class="cl-detail">${detailStr}</span>
        <span class="cl-amount">${amtHtml}</span>
      </div>`;
    }
    // Informational row displaying a kWh value (no € amount column)
    function solarKwhRow(label, kwhVal) {
      const kwhStr = kwhVal != null ? Number(kwhVal).toFixed(2) + ' kWh' : '—';
      return `<div class="cost-line">
        <span class="cl-label">${label}</span>
        <span class="cl-detail"></span>
        <span class="cl-amount t-green">${kwhStr}</span>
      </div>`;
    }

    const isCurrentMonth = (navYear === NOW_YEAR && navMonth === NOW_MONTH);
    const periodLabel    = isCurrentMonth ? tr('dash.period.current_month') : `${MONTHS_SHORT[navMonth-1]} ${navYear}`;

    el.innerHTML = tariffGapHtml(data) + `<div class="cost-wrap">
      <div class="cost-lines">
        ${costLinesHtml(c, row)}

        ${c.solar_produced != null ? `
        <div class="cost-group-label cost-group-label--solar">☀ ${tr('dash.solar.group')}</div>
        ${solarKwhRow(tr('dash.solar.produced'), c.solar_produced)}
        ${solarKwhRow(
            c.self_consumption_pct != null
              ? tr('dash.solar.self_consumed_pct', { pct: Number(c.self_consumption_pct).toFixed(1) })
              : tr('dash.solar.self_consumed'),
            c.solar_consumed
        )}
        ${row(tr('dash.solar.savings'),
              tr('dash.solar.savings_detail', {
                kwh: Number(c.solar_consumed).toFixed(2),
                rate: Number(c.solar_savings_rate).toFixed(6),
                cur: CURRENCY_SYMBOL,
              }),
              c.solar_savings, 'credit')}
        ` : ''}

        <div class="cost-group-sep"></div>
        ${costVatRows(c, row)}
      </div>
      <div class="cost-total-card">
        <div>
          <div class="cost-total-label">${periodLabel}</div>
          <div class="cost-total-amount">${formatMoney(c.total)}</div>
          ${c.net_cost_per_kwh != null ? `
          <div class="cost-total-unit" title="${escapeHtml(tr('dash.total.net_per_kwh_hint'))}">${tr('dash.total.net_per_kwh', { rate: formatRate(c.net_cost_per_kwh) })}</div>
          ` : ''}
        </div>
        <div class="cost-total-meta">
          <span>${tr('dash.meta.tariff', { name: escapeHtml(data.tariff_name ?? '—') })}</span>
          ${tariffSegmentsMeta(data)}
          <span>${tr('dash.meta.import_t1', { kwh: t1.toFixed(2) })}</span>
          <span>${tr('dash.meta.import_t2', { kwh: t2.toFixed(2) })}</span>
          <span>${tr('dash.meta.export_t1', { kwh: e1.toFixed(2) })}</span>
          <span>${tr('dash.meta.export_t2', { kwh: e2.toFixed(2) })}</span>
          ${c.solar_produced != null ? `
          <span class="t-green">☀ ${tr('dash.meta.solar_produced', { kwh: Number(c.solar_produced).toFixed(2) })}</span>
          <span class="t-green">${tr('dash.meta.self_consumed', { kwh: Number(c.solar_consumed).toFixed(2) })}${c.self_consumption_pct != null ? ` (${Number(c.self_consumption_pct).toFixed(1)} %)` : ''}</span>
          <span class="t-green">${tr('dash.meta.savings', { amount: formatMoney(c.solar_savings) })}</span>
          ` : (d.solar != null ? `<span>${tr('dash.meta.solar', { kwh: Number(d.solar).toFixed(2) })}</span>` : '')}
          <span>${tr('dash.meta.period', { days: daysLabel(data.days) })}</span>
        </div>
      </div>
    </div>`;

    // ── Tarif dynamique (day-ahead) ───────────────────────────────────────
    if (data.dynamic) {
      el.innerHTML += renderDynamicSection(data.dynamic, c, data);
    }
  }

  // `main` est la réponse de facturation : elle dit quelles sous-périodes du mois
  // sont RÉELLEMENT indexées (#245), là où `dyn` est toujours une projection
  // tout-dynamique. Sans aucun jour indexé, la section devient un comparatif
  // « et si ? » — informatif, donc replié plutôt qu'affiché comme un coût dû.
  function renderDynamicSection(dyn, classic, main) {
    const dynamicDays = main ? (main.dynamic_days || 0) : 0;
    const simulated = dyn ? (dyn.is_simulation === true || (!dyn.available && dynamicDays === 0)) : true;
    const head = simulated ? '' : `<div class="cost-group-label cost-group-label--solar mt-8">⚡ ${tr('dash.dynamic.group')}</div>`;

    // Mois à cheval sur un changement de contrat : dire combien de jours ont été
    // facturés au prix de marché, sinon l'écart avec le total classique paraît faux.
    const mixedNote = (main && main.is_mixed && dynamicDays > 0)
      ? `<div class="cost-formula-note">${tr('dash.dynamic.mixed_period', { dynamic: dynamicDays, total: main.days })}</div>`
      : '';

    const wrap = (inner) => {
      const body = `<div class="cost-wrap mt-18"><div class="cost-lines">${inner}</div></div>`;
      return simulated
        ? `<details class="dynamic-simulation mt-18"><summary>⚡ ${tr('dash.dynamic.simulation_title')}</summary>${body}</details>`
        : body;
    };

    if (!dyn || !dyn.available) {
      return wrap(`${head}
        <div class="no-tariff mt-10">${reasonHtml(dyn, 'dash.dynamic.unavailable')}</div>`);
    }

    const dc            = dyn.cost || {};
    const classicTotal  = classic ? classic.total : null;
    const classicEnergy = classic ? (classic.energy_total || 0) : null;
    const dynTotal      = dc.total;
    const diff          = (classicTotal != null && dynTotal != null) ? (dynTotal - classicTotal) : null;
    const diffPct       = (diff != null && classicTotal) ? (diff / Math.abs(classicTotal) * 100) : null;

    const eur = (v) => {
      if (v == null) return '<span class="t-muted">—</span>';
      return formatMoney(v);
    };
    const line = (label, detailStr, amount, cls = '') => `<div class="cost-line ${cls}">
        <span class="cl-label">${label}</span>
        <span class="cl-detail">${detailStr}</span>
        <span class="cl-amount">${eur(amount)}</span>
      </div>`;

    let dailyRows = '';
    (dyn.daily || []).forEach(d => {
      dailyRows += `<tr>
        <td>${d.day}</td>
        <td>${Number(d.import_kwh).toFixed(2)} kWh</td>
        <td>${formatMoney(d.energy_dynamic)}</td></tr>`;
    });
    const dailyTable = dailyRows ? `<div class="cost-group-label">${tr('dash.dynamic.daily_title')}</div>
      <div class="daily-scroll">
        <table class="daily-table">
          <thead><tr>
            <th>${tr('dash.dynamic.col_day')}</th>
            <th>${tr('dash.dynamic.col_import')}</th>
            <th>${tr('dash.dynamic.col_energy', { cur: CURRENCY_SYMBOL })}</th>
          </tr></thead>
          <tbody>${dailyRows}</tbody>
        </table>
      </div>` : '';

    const diffLabel = diff == null ? '' : (diff <= 0 ? tr('dash.dynamic.saving_vs') : tr('dash.dynamic.extra_vs'));

    // Formule d'indexation réellement appliquée au prix de marché (#228). Le prix spot
    // n'est pas ce que facture un fournisseur : rendre coefficient et marge visibles
    // évite de croire à un écart de calcul. Deux cas méritent un avertissement plutôt
    // qu'une note neutre — un coefficient rejeté (la formule ne correspond à rien de
    // saisi) et une marge reprise du profil faute de ligne en grille.
    // Résolution effective du calcul (#230) : en mode 15 min, l'utilisateur doit
    // pouvoir distinguer un calcul réellement quart-horaire d'un repli à l'heure, et
    // savoir ce qui a manqué. En mode horaire assumé, pas de note — rien à signaler.
    let resolutionNote = '';
    if (dyn.resolution === 'quarter') {
      resolutionNote = `<div class="cost-formula-note">${tr('dash.dynamic.resolution_quarter')}</div>`;
    } else if (dyn.resolution_requested === 'quarter') {
      resolutionNote = `<div class="cost-formula-note cost-formula-note--fallback">${
        dyn.resolution_fallback === 'no_quarter_prices'
          ? tr('dash.dynamic.resolution_fallback_prices')
          : tr('dash.dynamic.resolution_fallback_readings')}</div>`;
    }

    const f = dyn.formula;
    let formulaKey = 'dash.dynamic.formula';
    if (f && f.coefficient_rejected) formulaKey = 'dash.dynamic.formula_rejected';
    else if (f && f.offset_source === 'profile') formulaKey = 'dash.dynamic.formula_fallback';

    const formulaNote = f ? `<div class="cost-formula-note${formulaKey === 'dash.dynamic.formula' ? '' : ' cost-formula-note--fallback'}">${
      tr(formulaKey, {
        coef: Number(f.spot_coefficient ?? 1).toFixed(4),
        offset: Number(f.spot_offset_ttc ?? 0).toFixed(5),
        vat: Number(f.vat_rate ?? 0).toFixed(2),
        cur: CURRENCY_SYMBOL,
      })}</div>` : '';

    return wrap(`
      ${head}
      ${mixedNote}
      ${line(tr('dash.dynamic.energy_dynamic'),
            tr('dash.dynamic.energy_detail', {
              kwh: Number(dyn.matched_kwh ?? 0).toFixed(2),
              rate: dyn.avg_price_kwh != null ? Number(dyn.avg_price_kwh).toFixed(5) : '—',
              cur: CURRENCY_SYMBOL,
              coverage: Number(dyn.coverage_pct ?? 0).toFixed(0),
            }),
            dyn.energy_dynamic)}
      ${resolutionNote}
      ${formulaNote}
      ${line(tr('dash.dynamic.energy_classic'), '', classicEnergy)}
      <div class="cost-group-sep"></div>
      ${line(tr('dash.dynamic.total_dynamic'), '', dynTotal, 'total')}
      ${line(tr('dash.dynamic.total_classic'), '', classicTotal)}
      ${diff != null ? line(diffLabel, diffPct != null ? `${diffPct > 0 ? '+' : ''}${diffPct.toFixed(1)} %` : '', diff, diff <= 0 ? 'credit' : '') : ''}
      ${dailyTable}`);
  }

  async function loadMonthCost(year, month) {
    const el = document.getElementById('cost-content');
    el.innerHTML = `<div class="async-note">${tr('common.loading')}</div>`;
    if (year === NOW_YEAR && month === NOW_MONTH && window.__INIT_COST__) {
      renderCostContent(window.__INIT_COST__);
      return;
    }
    try {
      const res  = await fetch(`api?action=month_cost&year=${year}&month=${month}`);
      const data = await res.json();
      renderCostContent(data);
    } catch (e) {
      el.innerHTML = `<div class="async-note async-note--error">${tr('dash.load_error')}</div>`;
    }
  }

  const yearCache = {};

  async function loadYearOverview(year) {
    const grid = document.getElementById('year-overview-grid');
    grid.innerHTML = '';
    const curY = NOW_YEAR, curM = NOW_MONTH;

    for (let m = 1; m <= 12; m++) {
      const isFuture = (year > curY || (year === curY && m > curM));
      const card = document.createElement('div');
      card.className = 'year-month-card' + (m === navMonth && year === navYear ? ' active' : '');
      card.dataset.month = m;
      card.innerHTML = `<div class="ymc-label">${MONTH_NAMES[m-1]}</div>
        <div class="ymc-cost ymc-loading">${isFuture ? '<span class="ymc-nd">—</span>' : '…'}</div>
        <div class="ymc-kwh"></div>
        <div class="ymc-savings"></div>`;
      card.addEventListener('click', () => {
        navMonth = m;
        setNavMode('month');
        updateNav();
        loadMonthCost(navYear, navMonth);
      });
      grid.appendChild(card);

      if (!isFuture) {
        const key = `${year}-${m}`;
        if (yearCache[key]) {
          updateYearCard(card, yearCache[key]);
        } else {
          (async (card, m) => {
            try {
              const d = year === curY && m === curM && window.__INIT_COST__
                ? window.__INIT_COST__
                : await (await fetch(`api?action=month_cost&year=${year}&month=${m}`)).json();
              yearCache[key] = d;
              updateYearCard(card, d);
            } catch { card.querySelector('.ymc-cost').innerHTML = `<span class="ymc-nd">${tr('dash.err_short')}</span>`; }
          })(card, m);
        }
      }
    }
  }

  function updateYearCard(card, data) {
    const costEl    = card.querySelector('.ymc-cost');
    const kwhEl     = card.querySelector('.ymc-kwh');
    const savingsEl = card.querySelector('.ymc-savings');
    costEl.classList.remove('ymc-loading');
    if (!data || !data.available) {
      costEl.innerHTML = '<span class="ymc-nd">—</span>';
    } else {
      const t       = data.cost?.total;
      const savings = data.cost?.solar_savings;
      const d       = data.deltas || {};
      const kwh     = (d.prelev_jour ?? 0) + (d.prelev_nuit ?? 0);
      costEl.textContent = t != null ? formatMoney(t) : '—';
      kwhEl.textContent  = kwh ? kwh.toFixed(1) + ' kWh' : '';
      if (savingsEl) {
        savingsEl.textContent = savings != null && savings > 0 ? '☀ −' + formatMoney(savings) : '';
      }
    }
  }

  function updateNav() {
    const label = document.getElementById('nav-label');
    if (navMode === 'year') {
      label.textContent = String(navYear);
    } else {
      label.textContent = MONTH_NAMES[navMonth - 1] + ' ' + navYear;
    }
    const nextBtn = document.getElementById('nav-next');
    if (navMode === 'month') {
      nextBtn.disabled = (navYear === NOW_YEAR && navMonth === NOW_MONTH);
    } else {
      nextBtn.disabled = (navYear >= NOW_YEAR);
    }
    nextBtn.style.opacity = nextBtn.disabled ? '.3' : '';
  }

  document.getElementById('nav-prev').addEventListener('click', () => {
    if (navMode === 'month') {
      navMonth--;
      if (navMonth < 1) { navMonth = 12; navYear--; }
      loadMonthCost(navYear, navMonth);
    } else {
      navYear--;
      loadYearOverview(navYear);
    }
    updateNav();
  });

  document.getElementById('nav-next').addEventListener('click', () => {
    if (navMode === 'month') {
      if (navYear === NOW_YEAR && navMonth === NOW_MONTH) return;
      navMonth++;
      if (navMonth > 12) { navMonth = 1; navYear++; }
      loadMonthCost(navYear, navMonth);
    } else {
      if (navYear >= NOW_YEAR) return;
      navYear++;
      loadYearOverview(navYear);
    }
    updateNav();
  });

  window.setNavMode = function (mode) {
    navMode = mode;
    document.getElementById('mode-month').classList.toggle('active', mode === 'month');
    document.getElementById('mode-year').classList.toggle('active', mode === 'year');
    document.getElementById('year-overview-wrap').classList.toggle('is-hidden', mode !== 'year');
    document.getElementById('cost-content').classList.toggle('is-hidden', mode !== 'month');
    if (mode === 'year') loadYearOverview(navYear);
    updateNav();
  };

  // Init
  updateNav();
  renderCostContent(window.__INIT_COST__);
})();

// ── Récapitulatif annuel toutes énergies (#41) ─────────────────────────────
// Un tableau : registres électricité un par un, gaz en m³ ET en kWh, eau en m³,
// chaque énergie close par son coût annuel. Chargé APRÈS le rendu de la page
// (le serveur ne transmet que l'année amorcée) : l'estimation électricité sur
// 365 jours est trop lourde pour le chemin synchrone du dashboard.
(function () {
  const label   = document.getElementById('annual-nav-label');
  const content = document.getElementById('annual-content');
  const prevBtn = document.getElementById('annual-nav-prev');
  const nextBtn = document.getElementById('annual-nav-next');
  if (!label || !content || !prevBtn || !nextBtn) return;

  // Année amorcée par le serveur (année courante UTC), déjà transmise pour la
  // navigation des coûts : la redemander sous un second nom la ferait diverger.
  const NOW_YEAR = window.__INIT_YEAR__ || new Date().getUTCFullYear();
  // Plancher de navigation, aligné sur AnnualConsumptionService::MIN_YEAR : sans
  // lui, la flèche « ← » descend indéfiniment sur des requêtes vouées au 422.
  const MIN_YEAR = 2000;
  let annualYear = NOW_YEAR;

  // Volumes : deux décimales, séparateurs de la locale du profil — même exigence
  // que formatMoney(), les `toFixed()` bruts affichant un point décimal partout.
  const _qtyFmt = new Intl.NumberFormat(APP_LOCALE, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  // Cache par année : revenir sur une année déjà consultée ne redéclenche pas
  // trois estimations annuelles côté serveur. Même rôle que `yearCache`.
  const annualCache = {};

  // Libellés des registres en clés LITTÉRALES et non concaténées : une clé
  // construite (`'dash.annual.' + key`) échapperait à DashboardJsCatalogTest, qui
  // est le seul garde-fou contre une traduction manquante rendue à l'écran.
  const REGISTER_LABELS = {
    import_t1:  () => tr('dash.annual.import_t1'),
    import_t2:  () => tr('dash.annual.import_t2'),
    export_t1:  () => tr('dash.annual.export_t1'),
    export_t2:  () => tr('dash.annual.export_t2'),
    production: () => tr('dash.annual.production'),
  };

  // Une valeur absente se lit « — », jamais « 0 » : sans relevé, on ne sait pas
  // que la consommation était nulle.
  function qty(value, unit) {
    if (value === null || value === undefined) return '<span class="t-muted">—</span>';
    return `${_qtyFmt.format(Number(value))} ${unit}`;
  }

  function money(value) {
    return value === null || value === undefined ? '<span class="t-muted">—</span>' : formatMoney(value);
  }

  function row(item, total, cost, cls) {
    return `<tr class="${cls || ''}">
      <td class="at-item">${item}</td>
      <td class="at-total">${total}</td>
      <td class="at-cost">${cost || ''}</td>
    </tr>`;
  }

  function groupRow(title) {
    return `<tr class="annual-group"><th colspan="3">${title}</th></tr>`;
  }

  /** Ligne « — » motivée, pour une énergie sans données ou sans tarif. */
  function unavailableRow(block) {
    return row(`<span class="t-muted">${reasonHtml(block, 'dash.annual.no_data')}</span>`, '', '');
  }

  function electricityRows(block) {
    let html = groupRow(tr('dash.annual.electricity'));
    if (!block || !block.available) return html + unavailableRow(block);

    (block.registers || []).forEach((r) => {
      const labelFn = REGISTER_LABELS[r.key];
      html += row(labelFn ? labelFn() : escapeHtml(r.key), qty(r.kwh, 'kWh'), '');
    });

    return html + row(tr('dash.annual.cost_total'), '', money(block.cost), 'annual-subtotal');
  }

  function gasRows(block) {
    let html = groupRow(tr('dash.gas'));
    if (!block || (!block.available && block.m3 == null)) return html + unavailableRow(block);

    html += row(tr('dash.annual.volume'), qty(block.m3, 'm³'), '');

    // kWh absent = aucune grille gaz sur l'année : le volume reste affiché, et le
    // motif porté par la réponse dit pourquoi la conversion manque.
    const pcs = block.pcs != null ? ` <span class="at-note">${tr('dash.annual.pcs', { value: _qtyFmt.format(Number(block.pcs)) })}</span>` : '';
    html += row(tr('dash.annual.energy'), qty(block.kwh, 'kWh') + (block.kwh != null ? pcs : ''), '');

    if (block.kwh == null && block.reason_key) {
      html += row(`<span class="t-muted">${tr(block.reason_key)}</span>`, '', '');
    }

    return html + row(tr('dash.annual.cost_total'), '', money(block.cost), 'annual-subtotal');
  }

  function waterRows(block) {
    let html = groupRow(tr('dash.water'));
    if (!block || !block.available) return html + unavailableRow(block);

    return html
      + row(tr('dash.annual.volume'), qty(block.m3, 'm³'), '')
      + row(tr('dash.annual.cost_total'), '', money(block.cost), 'annual-subtotal');
  }

  /**
   * Bandeau « année incomplète » : l'année en cours, mais aussi une année passée
   * dont le flux de relevés s'est arrêté avant le 31/12 — dans les deux cas les
   * totaux ne couvrent pas douze mois, ce que le tableau seul ne dirait pas.
   */
  function partialHtml(data) {
    if (!data.partial) return '';

    // Fin la PLUS PRÉCOCE, et non la première trouvée : le bandeau doit annoncer
    // la date jusqu'à laquelle TOUT le tableau est mesuré. Les bornes égales à la
    // fin d'année demandée ne disent rien — le gaz et l'eau y prolongent leur
    // dernière pente, donc leur `period_to` vaut toujours `data.to`.
    const ends = [data.electricity?.data_to, data.gas?.period_to, data.water?.period_to]
      .filter((e) => e)
      .map((e) => String(e).slice(0, 10))
      .filter((e) => e < data.to)
      .sort();

    // Aucune borne mesurée exploitable (gaz/eau seuls, tous deux extrapolés) :
    // le bandeau reste, sans date — l'annoncer au 31/12 serait un mensonge.
    if (ends.length === 0) return `<div class="annual-partial">${tr('dash.annual.partial_open')}</div>`;

    return `<div class="annual-partial">${tr('dash.annual.partial', { date: escapeHtml(ends[0]) })}</div>`;
  }

  function render(data) {
    if (!data) {
      content.innerHTML = `<div class="async-note async-note--error">${tr('dash.load_error')}</div>`;
      return;
    }

    content.innerHTML = partialHtml(data) + `<div class="annual-wrap">
      <table class="annual-table">
        <thead>
          <tr>
            <th class="at-item">${tr('dash.annual.col_item')}</th>
            <th class="at-total">${tr('dash.annual.col_total')}</th>
            <th class="at-cost">${tr('dash.annual.col_cost')}</th>
          </tr>
        </thead>
        <tbody>
          ${electricityRows(data.electricity)}
          ${gasRows(data.gas)}
          ${waterRows(data.water)}
        </tbody>
      </table>
    </div>`;
  }

  async function load(year) {
    updateNav();
    if (annualCache[year]) {
      render(annualCache[year]);
      return;
    }

    content.innerHTML = `<div class="async-note">${tr('common.loading')}</div>`;
    try {
      const res = await fetch(`api?action=annual_consumption&year=${year}`);
      // Une erreur d'API (422 sur l'année, 500) répond un JSON `{error: …}` que
      // `render()` prendrait pour un récapitulatif vide : trois lignes « aucune
      // donnée » au lieu du message d'erreur, et mises en cache par-dessus le marché.
      if (!res.ok) throw new Error(String(res.status));
      const data = await res.json();
      annualCache[year] = data;
      // L'année a pu changer pendant la requête : ne rendre que la réponse
      // attendue, sinon un aller-retour rapide affiche l'année précédente.
      if (year === annualYear) render(data);
    } catch (e) {
      if (year === annualYear) render(null);
    }
  }

  function updateNav() {
    label.textContent = String(annualYear);
    prevBtn.disabled = (annualYear <= MIN_YEAR);
    prevBtn.style.opacity = prevBtn.disabled ? '.3' : '';
    nextBtn.disabled = (annualYear >= NOW_YEAR);
    nextBtn.style.opacity = nextBtn.disabled ? '.3' : '';
  }

  prevBtn.addEventListener('click', () => {
    if (annualYear <= MIN_YEAR) return;
    annualYear -= 1;
    load(annualYear);
  });
  nextBtn.addEventListener('click', () => {
    if (annualYear >= NOW_YEAR) return;
    annualYear += 1;
    load(annualYear);
  });

  // Init
  load(annualYear);
})();

// ── Gas cost navigation ────────────────────────────────────────────────────
(function () {
  const _APP_LOC = (typeof window !== 'undefined' && window.APP_LOCALE) ? window.APP_LOCALE : 'fr';
  const _cap = (str) => str.charAt(0).toUpperCase() + str.slice(1);
  const MONTHS_SHORT = Array.from({ length: 12 }, (_, i) => _cap(new Date(2000, i, 1).toLocaleDateString(_APP_LOC, { month: 'short' })));
  const MONTH_NAMES = Array.from({ length: 12 }, (_, i) => _cap(new Date(2000, i, 1).toLocaleDateString(_APP_LOC, { month: 'long' })));

  let gasNavYear  = window.__INIT_GAS_YEAR__;
  let gasNavMonth = window.__INIT_GAS_MONTH__;
  let gasNavMode  = 'month';

  const NOW_YEAR  = window.__INIT_YEAR__;
  const NOW_MONTH = window.__INIT_MONTH__;

  function fmtE(v) {
    if (v === null || v === undefined) return '<span class="t-muted">—</span>';
    return formatMoney(v);
  }
  function row(label, detailStr, amount, cls = '') {
    return `<div class="cost-line ${cls}">
      <span class="cl-label">${label}</span>
      <span class="cl-detail">${detailStr}</span>
      <span class="cl-amount">${fmtE(amount)}</span>
    </div>`;
  }
  function renderGasCostContent(data) {
    const el = document.getElementById('gas-cost-content');
    if (!el) return;
    if (!data || !data.available) {
      el.innerHTML = `<div class="no-tariff">
        <strong>${tr('dash.empty.gas')}</strong>
        ${reasonHtml(data, 'dash.empty.gas_reason')}
      </div>`;
      return;
    }

    const c           = data.cost;
    const kwh         = +(data.kwh ?? 0);
    const _day        = (v) => window.TZ ? window.TZ.formatReadingAt(v).slice(0, 10) : v.slice(0, 10);
    const from        = data.period_from ? _day(data.period_from) : '—';
    const to          = data.period_to   ? _day(data.period_to)   : '—';

    const isCurrentPeriod = (gasNavYear === NOW_YEAR && gasNavMonth === NOW_MONTH);
    const periodLabel     = isCurrentPeriod ? tr('dash.period.current_period') : `${MONTHS_SHORT[gasNavMonth-1]} ${gasNavYear}`;

    el.innerHTML = tariffGapHtml(data) + `<div class="cost-wrap">
      <div class="cost-lines">
        ${costLinesHtml(c, row)}
        <div class="cost-group-sep"></div>
        ${costVatRows(c, row)}
      </div>
      <div class="cost-total-card">
        <div>
          <div class="cost-total-label">${periodLabel}</div>
          <div class="cost-total-amount">${fmtE(c.total)}</div>
        </div>
        <div class="cost-total-meta">
          <span>${tr('dash.meta.tariff', { name: escapeHtml(data.tariff_name ?? '—') })}</span>
          ${tariffSegmentsMeta(data)}
          <span>${from} → ${to}</span>
          <span>${daysLabel(data.days)}</span>
          <span>${Number(data.delta_m3).toFixed(3)} m³ → ${Number(kwh).toFixed(1)} kWh</span>
          <span>${tr('dash.meta.pcs', { value: data.pcs_coefficient })}</span>
        </div>
      </div>
    </div>`;
  }

  async function loadMonthGasCost(year, month) {
    const el = document.getElementById('gas-cost-content');
    el.innerHTML = `<div class="async-note">${tr('common.loading')}</div>`;
    if (year === window.__INIT_GAS_YEAR__ && month === window.__INIT_GAS_MONTH__ && window.__INIT_GAS_COST__) {
      renderGasCostContent(window.__INIT_GAS_COST__);
      return;
    }
    try {
      const res  = await fetch(`api?action=gas_month_cost&year=${year}&month=${month}`);
      const data = await res.json();
      renderGasCostContent(data);
    } catch (e) {
      el.innerHTML = `<div class="async-note async-note--error">${tr('dash.load_error')}</div>`;
    }
  }

  const gasYearCache = {};

  async function loadGasYearOverview(year) {
    const grid = document.getElementById('gas-year-overview-grid');
    grid.innerHTML = '';
    const curY = NOW_YEAR, curM = NOW_MONTH;

    for (let m = 1; m <= 12; m++) {
      const isFuture = (year > curY || (year === curY && m > curM));
      const card     = document.createElement('div');
      card.className = 'year-month-card' + (m === gasNavMonth && year === gasNavYear ? ' active' : '');
      card.dataset.month = m;
      card.innerHTML = `<div class="ymc-label">${MONTH_NAMES[m-1]}</div>
        <div class="ymc-cost ymc-loading">${isFuture ? '<span class="ymc-nd">—</span>' : '…'}</div>
        <div class="ymc-kwh"></div>`;

      card.addEventListener('click', () => {
        gasNavMonth = m;
        setGasNavMode('month');
        updateGasNav();
        loadMonthGasCost(gasNavYear, gasNavMonth);
      });
      grid.appendChild(card);

      if (!isFuture) {
        const key = `${year}-${m}`;
        if (gasYearCache[key]) {
          updateGasYearCard(card, gasYearCache[key]);
        } else {
          (async (card, m) => {
            try {
              const d = (year === window.__INIT_GAS_YEAR__ && m === window.__INIT_GAS_MONTH__ && window.__INIT_GAS_COST__)
                ? window.__INIT_GAS_COST__
                : await (await fetch(`api?action=gas_month_cost&year=${year}&month=${m}`)).json();
              gasYearCache[key] = d;
              updateGasYearCard(card, d);
            } catch {
              card.querySelector('.ymc-cost').innerHTML = `<span class="ymc-nd">${tr('dash.err_short')}</span>`;
            }
          })(card, m);
        }
      }
    }
  }

  function updateGasYearCard(card, data) {
    const costEl = card.querySelector('.ymc-cost');
    const kwhEl  = card.querySelector('.ymc-kwh');
    costEl.classList.remove('ymc-loading');
    if (!data || !data.available) {
      costEl.innerHTML = '<span class="ymc-nd">—</span>';
    } else {
      const t = data.cost?.total;
      costEl.textContent = t != null ? formatMoney(t) : '—';
      kwhEl.textContent  = data.kwh ? Number(data.kwh).toFixed(1) + ' kWh' : '';
    }
  }

  function updateGasNav() {
    const label = document.getElementById('gas-nav-label');
    if (gasNavMode === 'year') {
      label.textContent = String(gasNavYear);
    } else {
      label.textContent = MONTH_NAMES[gasNavMonth - 1] + ' ' + gasNavYear;
    }
    const nextBtn = document.getElementById('gas-nav-next');
    if (gasNavMode === 'month') {
      nextBtn.disabled = (gasNavYear === NOW_YEAR && gasNavMonth === NOW_MONTH);
    } else {
      nextBtn.disabled = (gasNavYear >= NOW_YEAR);
    }
    nextBtn.style.opacity = nextBtn.disabled ? '.3' : '';
  }

  document.getElementById('gas-nav-prev').addEventListener('click', () => {
    if (gasNavMode === 'month') {
      gasNavMonth--;
      if (gasNavMonth < 1) { gasNavMonth = 12; gasNavYear--; }
      loadMonthGasCost(gasNavYear, gasNavMonth);
    } else {
      gasNavYear--;
      loadGasYearOverview(gasNavYear);
    }
    updateGasNav();
  });

  document.getElementById('gas-nav-next').addEventListener('click', () => {
    if (gasNavMode === 'month') {
      if (gasNavYear === NOW_YEAR && gasNavMonth === NOW_MONTH) return;
      gasNavMonth++;
      if (gasNavMonth > 12) { gasNavMonth = 1; gasNavYear++; }
      loadMonthGasCost(gasNavYear, gasNavMonth);
    } else {
      if (gasNavYear >= NOW_YEAR) return;
      gasNavYear++;
      loadGasYearOverview(gasNavYear);
    }
    updateGasNav();
  });

  window.setGasNavMode = function (mode) {
    gasNavMode = mode;
    document.getElementById('gas-mode-month').classList.toggle('active', mode === 'month');
    document.getElementById('gas-mode-year').classList.toggle('active', mode === 'year');
    document.getElementById('gas-year-overview-wrap').classList.toggle('is-hidden', mode !== 'year');
    document.getElementById('gas-cost-content').classList.toggle('is-hidden', mode !== 'month');
    if (mode === 'year') loadGasYearOverview(gasNavYear);
    updateGasNav();
  };

  // Init
  updateGasNav();
  renderGasCostContent(window.__INIT_GAS_COST__ ?? { available: false, reason: tr('dash.not_loaded') });
})();

// ── Water consumption navigation (volume m³, sans coût) ────────────────────
(function () {
  const _APP_LOC = (typeof window !== 'undefined' && window.APP_LOCALE) ? window.APP_LOCALE : 'fr';
  const _cap = (str) => str.charAt(0).toUpperCase() + str.slice(1);
  const MONTHS_SHORT = Array.from({ length: 12 }, (_, i) => _cap(new Date(2000, i, 1).toLocaleDateString(_APP_LOC, { month: 'short' })));
  const MONTH_NAMES = Array.from({ length: 12 }, (_, i) => _cap(new Date(2000, i, 1).toLocaleDateString(_APP_LOC, { month: 'long' })));

  let wNavYear  = window.__INIT_WATER_YEAR__;
  let wNavMonth = window.__INIT_WATER_MONTH__;
  let wNavMode  = 'month';

  const NOW_YEAR  = window.__INIT_YEAR__;
  const NOW_MONTH = window.__INIT_MONTH__;

  function renderWaterContent(data) {
    const el = document.getElementById('water-cost-content');
    if (!el) return;
    if (!data || !data.available) {
      el.innerHTML = `<div class="no-tariff">
        <strong>${tr('dash.empty.water')}</strong>
        ${reasonHtml(data, 'dash.empty.water_reason')}
      </div>`;
      return;
    }

    const _day = (v) => window.TZ ? window.TZ.formatReadingAt(v).slice(0, 10) : v.slice(0, 10);
    const from = data.period_from ? _day(data.period_from) : '—';
    const to   = data.period_to   ? _day(data.period_to)   : '—';
    const isCurrent = (wNavYear === NOW_YEAR && wNavMonth === NOW_MONTH);
    const label = isCurrent ? tr('dash.period.current_month') : `${MONTHS_SHORT[wNavMonth-1]} ${wNavYear}`;
    const projNote = data.is_projection ? ` <span class="t-dim">${tr('dash.water.projection')}</span>` : '';

    const c = data.cost;

    // Sans tarif eau configuré → volume seul (rétrocompat).
    if (!c) {
      el.innerHTML = tariffGapHtml(data) + `<div class="cost-total-card">
        <div>
          <div class="cost-total-label">${label}</div>
          <div class="cost-total-amount">${Number(data.delta_m3).toFixed(3)} m³</div>
        </div>
        <div class="cost-total-meta">
          <span>${from} → ${to}${projNote}</span>
          <span>${daysLabel(data.days)}</span>
        </div>
      </div>`;
      return;
    }

    // Avec tarif eau → détail de coût générique (piloté par cost.lines).
    const row = (lbl, det, amt, cls = '') => `<div class="cost-line ${cls}">
        <span class="cl-label">${lbl}</span>
        <span class="cl-detail">${det}</span>
        <span class="cl-amount">${amt == null ? '<span class="t-muted">—</span>' : formatMoney(amt)}</span>
      </div>`;

    el.innerHTML = tariffGapHtml(data) + `<div class="cost-wrap">
      <div class="cost-lines">
        ${costLinesHtml(c, row)}
        <div class="cost-group-sep"></div>
        ${costVatRows(c, row)}
      </div>
      <div class="cost-total-card">
        <div>
          <div class="cost-total-label">${label}</div>
          <div class="cost-total-amount">${formatMoney(c.total)}</div>
        </div>
        <div class="cost-total-meta">
          <span>${tr('dash.meta.tariff', { name: escapeHtml(data.tariff_name ?? '—') })}</span>
          ${tariffSegmentsMeta(data)}
          <span>${from} → ${to}${projNote}</span>
          <span>${daysLabel(data.days)}</span>
          <span>${Number(data.delta_m3).toFixed(3)} m³</span>
        </div>
      </div>
    </div>`;
  }

  async function loadMonthWater(year, month) {
    const el = document.getElementById('water-cost-content');
    el.innerHTML = `<div class="async-note">${tr('common.loading')}</div>`;
    if (year === window.__INIT_WATER_YEAR__ && month === window.__INIT_WATER_MONTH__ && window.__INIT_WATER_COST__) {
      renderWaterContent(window.__INIT_WATER_COST__);
      return;
    }
    try {
      const res  = await fetch(`api?action=water_month_cost&year=${year}&month=${month}`);
      renderWaterContent(await res.json());
    } catch (e) {
      el.innerHTML = `<div class="async-note async-note--error">${tr('dash.load_error')}</div>`;
    }
  }

  const waterYearCache = {};

  async function loadWaterYearOverview(year) {
    const grid = document.getElementById('water-year-overview-grid');
    grid.innerHTML = '';
    const curY = NOW_YEAR, curM = NOW_MONTH;

    for (let m = 1; m <= 12; m++) {
      const isFuture = (year > curY || (year === curY && m > curM));
      const card     = document.createElement('div');
      card.className = 'year-month-card' + (m === wNavMonth && year === wNavYear ? ' active' : '');
      card.dataset.month = m;
      card.innerHTML = `<div class="ymc-label">${MONTH_NAMES[m-1]}</div>
        <div class="ymc-cost ymc-loading">${isFuture ? '<span class="ymc-nd">—</span>' : '…'}</div>
        <div class="ymc-kwh"></div>`;

      card.addEventListener('click', () => {
        wNavMonth = m;
        setWaterNavMode('month');
        updateWaterNav();
        loadMonthWater(wNavYear, wNavMonth);
      });
      grid.appendChild(card);

      if (!isFuture) {
        const key = `${year}-${m}`;
        if (waterYearCache[key]) {
          updateWaterYearCard(card, waterYearCache[key]);
        } else {
          (async (card, m) => {
            try {
              const d = (year === window.__INIT_WATER_YEAR__ && m === window.__INIT_WATER_MONTH__ && window.__INIT_WATER_COST__)
                ? window.__INIT_WATER_COST__
                : await (await fetch(`api?action=water_month_cost&year=${year}&month=${m}`)).json();
              waterYearCache[key] = d;
              updateWaterYearCard(card, d);
            } catch {
              card.querySelector('.ymc-cost').innerHTML = `<span class="ymc-nd">${tr('dash.err_short')}</span>`;
            }
          })(card, m);
        }
      }
    }
  }

  function updateWaterYearCard(card, data) {
    const costEl = card.querySelector('.ymc-cost');
    costEl.classList.remove('ymc-loading');
    if (!data || !data.available) {
      costEl.innerHTML = '<span class="ymc-nd">—</span>';
    } else if (data.cost) {
      costEl.textContent = formatMoney(data.cost.total);
    } else {
      costEl.textContent = Number(data.delta_m3).toFixed(2) + ' m³';
    }
  }

  function updateWaterNav() {
    const label = document.getElementById('water-nav-label');
    label.textContent = wNavMode === 'year'
      ? String(wNavYear)
      : MONTH_NAMES[wNavMonth - 1] + ' ' + wNavYear;
    const nextBtn = document.getElementById('water-nav-next');
    nextBtn.disabled = wNavMode === 'month'
      ? (wNavYear === NOW_YEAR && wNavMonth === NOW_MONTH)
      : (wNavYear >= NOW_YEAR);
    nextBtn.style.opacity = nextBtn.disabled ? '.3' : '';
  }

  document.getElementById('water-nav-prev').addEventListener('click', () => {
    if (wNavMode === 'month') {
      wNavMonth--;
      if (wNavMonth < 1) { wNavMonth = 12; wNavYear--; }
      loadMonthWater(wNavYear, wNavMonth);
    } else {
      wNavYear--;
      loadWaterYearOverview(wNavYear);
    }
    updateWaterNav();
  });

  document.getElementById('water-nav-next').addEventListener('click', () => {
    if (wNavMode === 'month') {
      if (wNavYear === NOW_YEAR && wNavMonth === NOW_MONTH) return;
      wNavMonth++;
      if (wNavMonth > 12) { wNavMonth = 1; wNavYear++; }
      loadMonthWater(wNavYear, wNavMonth);
    } else {
      if (wNavYear >= NOW_YEAR) return;
      wNavYear++;
      loadWaterYearOverview(wNavYear);
    }
    updateWaterNav();
  });

  window.setWaterNavMode = function (mode) {
    wNavMode = mode;
    document.getElementById('water-mode-month').classList.toggle('active', mode === 'month');
    document.getElementById('water-mode-year').classList.toggle('active', mode === 'year');
    document.getElementById('water-year-overview-wrap').classList.toggle('is-hidden', mode !== 'year');
    document.getElementById('water-cost-content').classList.toggle('is-hidden', mode !== 'month');
    if (mode === 'year') loadWaterYearOverview(wNavYear);
    updateWaterNav();
  };

  // Init
  updateWaterNav();
  renderWaterContent(window.__INIT_WATER_COST__ ?? { available: false, reason: tr('dash.not_loaded') });
})();

// ── Horloge ─────────────────────────────────────────────────────────────────
// Déplacée dans assets/js/header.js (bandeau commun, #193), chargé sur toutes
// les pages app.

// ── Chart ──────────────────────────────────────────────────────────────────
let chart = null;

// Libellé d'un mois 'YYYY-MM' → « juil. 26 » (locale de l'utilisateur). Même
// motif que les MONTHS_SHORT des blocs de navigation, mais en portée fichier :
// les graphes mensuels (#238) en ont besoin hors de leurs IIFE.
function monthLabel(ym) {
  const [y, m] = String(ym).split('-').map(Number);
  if (!y || !m) return String(ym);
  const loc = (typeof window !== 'undefined' && window.APP_LOCALE) ? window.APP_LOCALE : 'fr';
  const txt = new Date(y, m - 1, 1).toLocaleDateString(loc, { month: 'short', year: '2-digit' });
  return txt.charAt(0).toUpperCase() + txt.slice(1);
}

async function loadChart(days = 30) {
  ['btn-30','btn-365'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.style.color = '';
  });
  const btn = document.getElementById('btn-' + days);
  if (btn) btn.style.color = 'var(--amber)';

  try {
    // Vue « 1 an » : 12 points MENSUELS (#238). 365 barres journalières étaient
    // illisibles, et l'agrégation mensuelle est calculée côté serveur avec la
    // même interpolation à minuit que les cards de coût → chiffres cohérents.
    if (days >= 365) {
      const res  = await fetch('api?action=electricity_monthly_series&months=12');
      renderChart(await res.json(), true);
      return;
    }
    const res  = await fetch(`api?action=chart_data&days=${days}`);
    const data = await res.json();
    renderChart(data);
  } catch (e) {
    console.warn('Chart load failed:', e);
  }
}

// Options Chart.js communes (thème sombre, typo Space Mono). Seuls l'unité
// affichée (kWh / m³), le nombre de décimales du tooltip et l'empilement de
// l'axe X varient d'un graphe à l'autre — factorisés ici pour éviter la
// divergence de thème entre le graphe électricité et les graphes volume.
function chartOptions({ unit, decimals, stackedX = false }) {
  return {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
      legend: { labels: { color: '#556070', font: { family: "'Space Mono'", size: 11 }, boxWidth: 12 } },
      tooltip: {
        backgroundColor: '#111318', borderColor: '#1f2530', borderWidth: 1,
        titleColor: '#c8d0dc', bodyColor: '#8899a8',
        callbacks: { label: ctx => ` ${ctx.dataset.label}: ${(ctx.raw || 0).toFixed(decimals)} ${unit}` },
      },
    },
    scales: {
      x: { stacked: stackedX, grid: { color: '#1a1f28' }, ticks: { color: '#556070', font: { family: "'Space Mono'", size: 10 }, maxRotation: 45 } },
      y: { stacked: false, grid: { color: '#1a1f28' }, ticks: { color: '#556070', font: { family: "'Space Mono'", size: 10 }, callback: v => v + ' ' + unit } },
    },
  };
}

function renderChart(data, monthly = false) {
  const labels    = data.map(d => (monthly ? monthLabel(d.month) : d.day));
  const importT1  = data.map(d => d.import_t1);
  const importT2  = data.map(d => d.import_t2);
  const exportAll = data.map(d => (d.export_t1 || 0) + (d.export_t2 || 0));
  const solar     = data.map(d => d.solar);

  // Vue mensuelle : les mois PARTIELS (mois en cours arrêté au dernier relevé,
  // premier mois entamé après le premier relevé) sont atténués — sans quoi une
  // barre tronquée se lit comme une vraie chute de consommation.
  const shade = (full, muted) => (monthly ? data.map(d => (d.partial ? muted : full)) : full);

  const ctx = document.getElementById('energyChart').getContext('2d');
  if (chart) chart.destroy();

  chart = new Chart(ctx, {
    type: 'bar',
    data: {
      labels,
      datasets: [
        { label: tr('dash.chart.import_t1'), data: importT1,  backgroundColor: shade('rgba(245,166,35,.7)', 'rgba(245,166,35,.28)'), borderColor: 'rgba(245,166,35,.9)', borderWidth: 1, stack: 'import' },
        { label: tr('dash.chart.import_t2'), data: importT2,  backgroundColor: shade('rgba(245,166,35,.3)', 'rgba(245,166,35,.12)'), borderColor: 'rgba(245,166,35,.5)', borderWidth: 1, stack: 'import' },
        { label: tr('dash.chart.export'),    data: exportAll, backgroundColor: shade('rgba(65,179,245,.55)', 'rgba(65,179,245,.22)'), borderColor: 'rgba(65,179,245,.8)', borderWidth: 1, stack: 'export' },
        { label: tr('dash.chart.solar'), data: solar, type: 'line', borderColor: 'rgba(47,213,142,.8)', backgroundColor: 'rgba(47,213,142,.08)', fill: true, tension: .3, pointRadius: 2, borderWidth: 2 },
      ],
    },
    options: chartOptions({ unit: 'kWh', decimals: 2, stackedX: true }),
  });
}

// ── Charts volume (gaz / eau) ───────────────────────────────────────────────
// Une barre PAR MOIS calendaire (#238) : les relevés gaz/eau sont manuels et
// clairsemés, une barre par relevé donnait une lecture fausse (deux relevés dans
// le même mois → deux barres, un relevé couvrant cinq mois → une barre géante).
// Le serveur ventile la consommation sur les mois avec la même interpolation à
// minuit que les cards de coût. Le mois en cours est PARTIEL (conso réelle à ce
// jour, aucune projection) : barre en teinte atténuée.
const utilCharts = { gas: null, water: null };
const utilData   = { gas: {}, water: {} }; // kind → { [months]: rows }

function renderVolumeChart(canvasId, kind, rows, label, color) {
  const labels = rows.map(r => monthLabel(r.month));
  const values = rows.map(r => Math.max(0, r.delta_m3 || 0));

  const ctx = document.getElementById(canvasId).getContext('2d');
  if (utilCharts[kind]) utilCharts[kind].destroy();

  utilCharts[kind] = new Chart(ctx, {
    type: 'bar',
    data: {
      labels,
      datasets: [
        {
          label,
          data: values,
          backgroundColor: rows.map(r => (r.partial ? color.partial : color.fill)),
          borderColor: color.line,
          borderWidth: 1,
        },
      ],
    },
    options: chartOptions({ unit: 'm³', decimals: 3 }),
  });
}

const UTIL_META = {
  gas: {
    action: 'gas_monthly_series', canvas: 'gasChart', label: tr('dash.chart.gas'),
    color: { fill: 'rgba(245,166,35,.55)', partial: 'rgba(245,166,35,.22)', line: 'rgba(245,166,35,.8)' },
    btnPrefix: 'gas-btn-',
  },
  water: {
    action: 'water_monthly_series', canvas: 'waterChart', label: tr('dash.chart.water'),
    color: { fill: 'rgba(65,179,245,.55)', partial: 'rgba(65,179,245,.22)', line: 'rgba(65,179,245,.8)' },
    btnPrefix: 'water-btn-',
  },
};

async function loadUtilChart(kind, months = 12) {
  const meta = UTIL_META[kind];

  [12, 24].forEach(m => {
    const el = document.getElementById(meta.btnPrefix + m);
    if (el) el.style.color = '';
  });
  const btn = document.getElementById(meta.btnPrefix + months);
  if (btn) btn.style.color = 'var(--amber)';

  if (!utilData[kind][months]) {
    try {
      const res = await fetch(`api?action=${meta.action}&months=${months}`);
      utilData[kind][months] = await res.json();
    } catch (e) {
      // On NE met PAS l'échec en cache : un clic ultérieur sur 12/24 mois
      // retentera le fetch. Sinon un incident réseau au chargement figerait le
      // graphe vide jusqu'au rechargement complet de la page.
      console.warn(`Chart ${kind} load failed:`, e);
    }
  }

  renderVolumeChart(meta.canvas, kind, utilData[kind][months] || [], meta.label, meta.color);
}

// Défaut « 12 mois » : les relevés gaz/eau sont souvent mensuels, une fenêtre
// plus courte afficherait fréquemment un graphe quasi vide.
loadUtilChart('gas', 12);
loadUtilChart('water', 12);

// ── Live dongle polling ────────────────────────────────────────────────────
// Retiré (P4, #47) : le serveur communautaire ne peut pas atteindre les
// compteurs sur le LAN des membres. Les index arrivent par push (agent → API).

loadChart(30);

// ── Câblage des interactions (ex-handlers inline, retirés pour la CSP enforced) ──
// Les attributs onclick ont été remplacés par des data-attributes + addEventListener
// (un attribut on* n'est pas couvert par le nonce CSP). Voir #73.
document.querySelectorAll('[data-nav-mode]').forEach(el =>
  el.addEventListener('click', () => setNavMode(el.dataset.navMode)));
document.querySelectorAll('[data-gas-nav-mode]').forEach(el =>
  el.addEventListener('click', () => setGasNavMode(el.dataset.gasNavMode)));
document.querySelectorAll('[data-water-nav-mode]').forEach(el =>
  el.addEventListener('click', () => setWaterNavMode(el.dataset.waterNavMode)));
document.querySelectorAll('[data-chart-days]').forEach(el =>
  el.addEventListener('click', () => loadChart(parseInt(el.dataset.chartDays, 10))));
document.querySelectorAll('[data-gas-chart-months]').forEach(el =>
  el.addEventListener('click', () => loadUtilChart('gas', parseInt(el.dataset.gasChartMonths, 10))));
document.querySelectorAll('[data-water-chart-months]').forEach(el =>
  el.addEventListener('click', () => loadUtilChart('water', parseInt(el.dataset.waterChartMonths, 10))));

// ── Relevés d'index gaz / eau ────────────────────────────────────────────────
// Retirés du dashboard (#194) : les index bruts sont consultables et éditables
// sur la page meter-readings. Le dashboard ne conserve que les blocs de coûts.
