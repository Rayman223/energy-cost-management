// Graphiques de la page /stats (#8).
//
// L'état serveur arrive par #stats-data : sous la CSP durcie, aucun <script>
// inline n'est autorisé, un bloc application/json est le seul canal disponible
// (il n'est jamais exécuté, donc hors script-src).
//
// chartOptions() est volontairement DUPLIQUÉ depuis dashboard.js plutôt que
// partagé : le projet n'a pas de bundler, dashboard.js n'est pas chargé ici, et
// les deux pages n'ont pas les mêmes axes (horizontal, empilement à 100 %). Un
// fichier commun coûterait une requête et un couplage pour une trentaine de lignes.

const STATS = (() => {
  const el = document.getElementById('stats-data');
  if (!el) return {};
  try {
    return JSON.parse(el.textContent) || {};
  } catch (e) {
    return {};
  }
})();

const STATS_I18N = STATS.i18n || {};

// Reproduit la sémantique de Translator::t() : jetons {name}, substitution en une
// seule passe (une valeur injectée contenant {autre} n'est pas resubstituée).
function tr(key, fallback, params) {
  const message = STATS_I18N[key] || fallback || key;
  if (!params) return message;
  return message.replace(/\{(\w+)\}/g, (ph, name) =>
    Object.prototype.hasOwnProperty.call(params, name) ? String(params[name]) : ph);
}

/** Libellé lisible d'un pays, résolu côté serveur (ext-intl absent du navigateur). */
function countryLabel(iso) {
  return (STATS.countries && STATS.countries[iso]) || iso;
}

const PALETTE = {
  amber: 'rgba(245,166,35,.7)',
  amberLine: 'rgba(245,166,35,.9)',
  blue: 'rgba(65,179,245,.55)',
  blueLine: 'rgba(65,179,245,.8)',
  green: 'rgba(47,213,142,.6)',
  greenLine: 'rgba(47,213,142,.85)',
  grey: 'rgba(120,132,148,.45)',
  greyLine: 'rgba(120,132,148,.7)',
};

function chartOptions({ unit, decimals, horizontal = false, stacked = false, percent = false }) {
  const valueAxis = {
    stacked,
    grid: { color: '#1a1f28' },
    ticks: {
      color: '#556070',
      font: { family: "'Space Mono'", size: 10 },
      callback: v => v + (percent ? ' %' : ' ' + unit),
    },
  };
  const labelAxis = {
    stacked,
    grid: { color: '#1a1f28' },
    ticks: { color: '#556070', font: { family: "'Space Mono'", size: 10 }, maxRotation: 45 },
  };

  return {
    responsive: true,
    maintainAspectRatio: false,
    indexAxis: horizontal ? 'y' : 'x',
    interaction: { mode: 'index', intersect: false },
    plugins: {
      legend: { labels: { color: '#556070', font: { family: "'Space Mono'", size: 11 }, boxWidth: 12 } },
      tooltip: {
        backgroundColor: '#111318', borderColor: '#1f2530', borderWidth: 1,
        titleColor: '#c8d0dc', bodyColor: '#8899a8',
        callbacks: {
          // Un point absent est un TROU (mois sans relevé, ou sous le seuil de
          // k-anonymat), pas une valeur nulle : le tooltip doit le dire.
          label: ctx => {
            const raw = ctx.raw;
            if (raw === null || raw === undefined) {
              return ` ${ctx.dataset.label}: ${tr('stats.chart.no_data', 'no data')}`;
            }
            return ` ${ctx.dataset.label}: ${Number(raw).toFixed(decimals)} ${percent ? '%' : unit}`;
          },
        },
      },
    },
    scales: horizontal ? { x: valueAxis, y: labelAxis } : { x: labelAxis, y: valueAxis },
  };
}

function canvas(id) {
  const el = document.getElementById(id);
  return el ? el.getContext('2d') : null;
}

/** Prix moyen du kWh par pays : TVA empilée sur la part hors taxes. */
function renderPriceChart(rows) {
  const ctx = canvas('priceChart');
  // Le bucket « Autres » n'a pas de prix (devises mélangées) : rien à tracer.
  const data = (rows || []).filter(r => r.ttc_per_kwh !== null);
  if (!ctx || data.length === 0) return;

  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: data.map(r => countryLabel(r.country)),
      datasets: [
        {
          label: tr('stats.chart.htva', 'excl. VAT'),
          data: data.map(r => r.htva_per_kwh),
          backgroundColor: PALETTE.amber, borderColor: PALETTE.amberLine, borderWidth: 1, stack: 'price',
        },
        {
          label: tr('stats.chart.vat', 'VAT'),
          data: data.map(r => Math.max(0, r.ttc_per_kwh - r.htva_per_kwh)),
          backgroundColor: PALETTE.grey, borderColor: PALETTE.greyLine, borderWidth: 1, stack: 'price',
        },
      ],
    },
    options: chartOptions({ unit: STATS.symbol || '', decimals: 4, horizontal: true, stacked: true }),
  });
}

/** Consommation moyenne par foyer. Un graphe par énergie : les unités diffèrent. */
function renderUsageChart(id, rows, unit, color, line) {
  const ctx = canvas(id);
  if (!ctx || !rows || rows.length === 0) return;

  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: rows.map(r => countryLabel(r.country)),
      datasets: [{
        label: tr('stats.chart.per_household', 'per household'),
        data: rows.map(r => r.value),
        backgroundColor: color, borderColor: line, borderWidth: 1,
      }],
    },
    options: chartOptions({ unit, decimals: 0 }),
  });
}

/** Répartition fixe / indexé, en part de 100 % pour comparer des pays inégaux. */
function renderMixChart(rows) {
  const ctx = canvas('mixChart');
  if (!ctx || !rows || rows.length === 0) return;

  const share = (row, key) => {
    const total = row.fixed + row.dynamic;
    return total > 0 ? (row[key] / total) * 100 : 0;
  };

  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: rows.map(r => countryLabel(r.country)),
      datasets: [
        {
          label: tr('stats.chart.fixed', 'fixed'),
          data: rows.map(r => share(r, 'fixed')),
          backgroundColor: PALETTE.blue, borderColor: PALETTE.blueLine, borderWidth: 1, stack: 'mix',
        },
        {
          label: tr('stats.chart.dynamic', 'indexed'),
          data: rows.map(r => share(r, 'dynamic')),
          backgroundColor: PALETTE.green, borderColor: PALETTE.greenLine, borderWidth: 1, stack: 'mix',
        },
      ],
    },
    options: chartOptions({ unit: '%', decimals: 1, stacked: true, percent: true }),
  });
}

/** Prix spot par zone, dans la résolution retenue côté serveur. */
function renderSpotChart(rows) {
  const ctx = canvas('spotChart');
  if (!ctx || !rows || rows.length === 0) return;

  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: rows.map(r => r.zone + ' (' + r.resolution_min + ' min)'),
      datasets: [{
        label: tr('stats.chart.spot', 'spot price'),
        data: rows.map(r => r.avg_price),
        backgroundColor: PALETTE.green, borderColor: PALETTE.greenLine, borderWidth: 1,
      }],
    },
    options: chartOptions({ unit: '€/kWh', decimals: 4, horizontal: true }),
  });
}

/** Ma consommation mois par mois, face à la moyenne de mon pays. */
function renderMonthlyChart(points) {
  const ctx = canvas('monthlyChart');
  if (!ctx || !points || points.length === 0) return;

  new Chart(ctx, {
    type: 'line',
    data: {
      labels: points.map(p => p.month),
      datasets: [
        {
          label: tr('stats.chart.mine', 'me'),
          data: points.map(p => p.mine),
          borderColor: PALETTE.amberLine, backgroundColor: 'rgba(245,166,35,.08)',
          fill: true, tension: 0.3, pointRadius: 2, borderWidth: 2,
          // Un mois sans relevé ne doit pas être relié : le trou est l'information.
          spanGaps: false,
        },
        {
          label: tr('stats.chart.country_average', 'country average'),
          data: points.map(p => p.average),
          borderColor: PALETTE.greyLine, backgroundColor: 'rgba(120,132,148,.05)',
          fill: false, tension: 0.3, pointRadius: 2, borderWidth: 2, borderDash: [4, 3],
          spanGaps: false,
        },
      ],
    },
    options: chartOptions({ unit: 'kWh', decimals: 0 }),
  });
}

/** Comparaison poste par poste, en €/kWh. */
function renderCategoryChart(rows) {
  const ctx = canvas('categoryChart');
  if (!ctx || !rows || rows.length === 0) return;

  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: rows.map(r => tr('stats.chart.category_' + r.category, r.category)),
      datasets: [
        {
          label: tr('stats.chart.mine', 'me'),
          data: rows.map(r => r.mine),
          backgroundColor: PALETTE.amber, borderColor: PALETTE.amberLine, borderWidth: 1,
        },
        {
          label: tr('stats.chart.country_average', 'country average'),
          data: rows.map(r => r.average),
          backgroundColor: PALETTE.grey, borderColor: PALETTE.greyLine, borderWidth: 1,
        },
      ],
    },
    options: chartOptions({ unit: STATS.symbol || '', decimals: 4, horizontal: true }),
  });
}

(function init() {
  if (typeof Chart === 'undefined') return;

  const overview = STATS.overview;
  if (overview) {
    renderPriceChart(overview.prices);
    renderMixChart(overview.mix);
    renderUsageChart('elecChart', overview.electricity, 'kWh', PALETTE.amber, PALETTE.amberLine);
    renderUsageChart('gasChart', overview.gas, 'm³', PALETTE.blue, PALETTE.blueLine);
    renderUsageChart('waterChart', overview.water, 'm³', PALETTE.green, PALETTE.greenLine);
    renderSpotChart(overview.spot);
  }

  const priv = STATS.private;
  if (priv) {
    renderMonthlyChart(priv.monthly);
    renderCategoryChart(priv.categories);
  }
})();
