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
        ${data?.reason ?? tr('dash.empty.electricity_reason')}
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

    el.innerHTML = `<div class="cost-wrap">
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

    // ── Comparaison tarif dynamique (day-ahead) ───────────────────────────
    if (data.dynamic) {
      el.innerHTML += renderDynamicSection(data.dynamic, c);
    }
  }

  function renderDynamicSection(dyn, classic) {
    const head = `<div class="cost-group-label cost-group-label--solar mt-8">⚡ ${tr('dash.dynamic.group')}</div>`;

    if (!dyn || !dyn.available) {
      return `<div class="cost-wrap mt-18"><div class="cost-lines">
        ${head}
        <div class="no-tariff mt-10">${(dyn && dyn.reason) ? dyn.reason : tr('dash.dynamic.unavailable')}</div>
      </div></div>`;
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

    return `<div class="cost-wrap mt-18"><div class="cost-lines">
      ${head}
      ${line(tr('dash.dynamic.energy_dynamic'),
            tr('dash.dynamic.energy_detail', {
              kwh: Number(dyn.matched_kwh ?? 0).toFixed(2),
              rate: dyn.avg_price_kwh != null ? Number(dyn.avg_price_kwh).toFixed(5) : '—',
              cur: CURRENCY_SYMBOL,
              coverage: Number(dyn.coverage_pct ?? 0).toFixed(0),
            }),
            dyn.energy_dynamic)}
      ${formulaNote}
      ${line(tr('dash.dynamic.energy_classic'), '', classicEnergy)}
      <div class="cost-group-sep"></div>
      ${line(tr('dash.dynamic.total_dynamic'), '', dynTotal, 'total')}
      ${line(tr('dash.dynamic.total_classic'), '', classicTotal)}
      ${diff != null ? line(diffLabel, diffPct != null ? `${diffPct > 0 ? '+' : ''}${diffPct.toFixed(1)} %` : '', diff, diff <= 0 ? 'credit' : '') : ''}
      ${dailyTable}
    </div></div>`;
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
        ${data?.reason ?? tr('dash.empty.gas_reason')}
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

    el.innerHTML = `<div class="cost-wrap">
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
        ${data?.reason ?? tr('dash.empty.water_reason')}
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
      el.innerHTML = `<div class="cost-total-card">
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

    el.innerHTML = `<div class="cost-wrap">
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

async function loadChart(days = 30) {
  ['btn-30','btn-365'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.style.color = '';
  });
  const btn = document.getElementById('btn-' + days);
  if (btn) btn.style.color = 'var(--amber)';

  try {
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

function renderChart(data) {
  const labels    = data.map(d => d.day);
  const importT1  = data.map(d => d.import_t1);
  const importT2  = data.map(d => d.import_t2);
  const exportAll = data.map(d => (d.export_t1 || 0) + (d.export_t2 || 0));
  const solar     = data.map(d => d.solar);

  const ctx = document.getElementById('energyChart').getContext('2d');
  if (chart) chart.destroy();

  chart = new Chart(ctx, {
    type: 'bar',
    data: {
      labels,
      datasets: [
        { label: tr('dash.chart.import_t1'), data: importT1,  backgroundColor: 'rgba(245,166,35,.7)', borderColor: 'rgba(245,166,35,.9)', borderWidth: 1, stack: 'import' },
        { label: tr('dash.chart.import_t2'), data: importT2,  backgroundColor: 'rgba(245,166,35,.3)', borderColor: 'rgba(245,166,35,.5)', borderWidth: 1, stack: 'import' },
        { label: tr('dash.chart.export'),    data: exportAll, backgroundColor: 'rgba(65,179,245,.55)', borderColor: 'rgba(65,179,245,.8)', borderWidth: 1, stack: 'export' },
        { label: tr('dash.chart.solar'), data: solar, type: 'line', borderColor: 'rgba(47,213,142,.8)', backgroundColor: 'rgba(47,213,142,.08)', fill: true, tension: .3, pointRadius: 2, borderWidth: 2 },
      ],
    },
    options: chartOptions({ unit: 'kWh', decimals: 2, stackedX: true }),
  });
}

// ── Charts volume (gaz / eau) ───────────────────────────────────────────────
// Même principe que le graphe électricité, mais une barre PAR RELEVÉ (les relevés
// gaz/eau sont manuels et clairsemés : un bucket journalier serait quasi vide).
// La valeur est le delta_m3 déjà calculé par getAllReadings ; la plage 30j/1an se
// fait par filtrage client de la série (fetch unique, peu de points).
const utilCharts = { gas: null, water: null };
const utilData   = { gas: null, water: null };

function renderVolumeChart(canvasId, kind, rows, label, color) {
  const labels = rows.map(r => (window.TZ ? window.TZ.formatReadingAt(r.reading_at).slice(0, 10) : String(r.reading_at).slice(0, 10)));
  const values = rows.map(r => Math.max(0, r.delta_m3 || 0));

  const ctx = document.getElementById(canvasId).getContext('2d');
  if (utilCharts[kind]) utilCharts[kind].destroy();

  utilCharts[kind] = new Chart(ctx, {
    type: 'bar',
    data: {
      labels,
      datasets: [
        { label, data: values, backgroundColor: color.fill, borderColor: color.line, borderWidth: 1 },
      ],
    },
    options: chartOptions({ unit: 'm³', decimals: 3 }),
  });
}

const UTIL_META = {
  gas:   { action: 'gas_history',   canvas: 'gasChart',   label: tr('dash.chart.gas'),   color: { fill: 'rgba(245,166,35,.55)', line: 'rgba(245,166,35,.8)' }, btnPrefix: 'gas-btn-' },
  water: { action: 'water_history', canvas: 'waterChart', label: tr('dash.chart.water'), color: { fill: 'rgba(65,179,245,.55)', line: 'rgba(65,179,245,.8)' }, btnPrefix: 'water-btn-' },
};

function filterAndRenderUtil(kind, days) {
  const meta = UTIL_META[kind];
  ['30','365'].forEach(d => {
    const el = document.getElementById(meta.btnPrefix + d);
    if (el) el.style.color = '';
  });
  const btn = document.getElementById(meta.btnPrefix + days);
  if (btn) btn.style.color = 'var(--amber)';

  const rows   = utilData[kind] || [];
  const cutoff = Date.now() - days * 86400000;
  const kept   = rows
    // Le relevé le plus ancien a delta_m3 === null (aucune conso à afficher) :
    // l'écarter évite une barre à 0 avec un label d'axe inutile.
    .filter(r => r.delta_m3 != null)
    .filter(r => {
      const d = window.TZ ? window.TZ.dateFromDbUtc(r.reading_at) : new Date(r.reading_at);
      return !isNaN(d.getTime()) && d.getTime() >= cutoff;
    })
    .sort((a, b) => String(a.reading_at).localeCompare(String(b.reading_at)));

  renderVolumeChart(meta.canvas, kind, kept, meta.label, meta.color);
}

async function loadUtilChart(kind, days = 365) {
  const meta = UTIL_META[kind];
  if (utilData[kind] === null) {
    try {
      const res = await fetch(`api?action=${meta.action}`);
      utilData[kind] = await res.json();
    } catch (e) {
      // On NE met PAS l'échec en cache (utilData reste null) : un clic ultérieur
      // sur 30j/1an retentera le fetch. Sinon un incident réseau au chargement
      // figerait le graphe vide jusqu'au rechargement complet de la page.
      console.warn(`Chart ${kind} load failed:`, e);
    }
  }
  filterAndRenderUtil(kind, days);
}

// Défaut « 1 an » pour gaz/eau : les relevés sont clairsemés (souvent mensuels),
// une fenêtre 30j par défaut afficherait fréquemment un graphe vide.
loadUtilChart('gas', 365);
loadUtilChart('water', 365);

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
document.querySelectorAll('[data-gas-chart-days]').forEach(el =>
  el.addEventListener('click', () => loadUtilChart('gas', parseInt(el.dataset.gasChartDays, 10))));
document.querySelectorAll('[data-water-chart-days]').forEach(el =>
  el.addEventListener('click', () => loadUtilChart('water', parseInt(el.dataset.waterChartDays, 10))));

// ── Relevés d'index gaz / eau ────────────────────────────────────────────────
// Retirés du dashboard (#194) : les index bruts sont consultables et éditables
// sur la page meter-readings. Le dashboard ne conserve que les blocs de coûts.
