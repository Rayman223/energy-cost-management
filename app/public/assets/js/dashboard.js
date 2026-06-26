// ── Electricity cost navigation ────────────────────────────────────────────
(function () {
  const MONTHS_FR   = ['Jan','Fév','Mar','Avr','Mai','Jun','Jul','Aoû','Sep','Oct','Nov','Déc'];
  const MONTH_NAMES = ['Janvier','Février','Mars','Avril','Mai','Juin',
                       'Juillet','Août','Septembre','Octobre','Novembre','Décembre'];

  let navYear  = window.__INIT_YEAR__;
  let navMonth = window.__INIT_MONTH__;
  let navMode  = 'month';
  const NOW_YEAR  = window.__INIT_YEAR__;
  const NOW_MONTH = window.__INIT_MONTH__;

  function fmtEur(v) {
    if (v === null || v === undefined) return '<span style="color:var(--muted)">—</span>';
    const sign = v < 0 ? '−' : '';
    return sign + Math.abs(v).toFixed(2) + ' €';
  }

  function renderCostContent(data) {
    const el = document.getElementById('cost-content');
    if (!data || !data.available) {
      el.innerHTML = `<div class="no-tariff">
        <strong style="color:var(--text);margin-bottom:6px;display:block">Aucune donnée disponible</strong>
        ${data?.reason ?? 'Pas de tarif ou de données pour cette période.'}
      </div>`;
      return;
    }
    const c = data.cost;
    const d = data.deltas || {};
    const r = data.tariff_rates || {};
    const t1 = +(d.prelev_jour ?? 0);
    const t2 = +(d.prelev_nuit ?? 0);
    const e1 = +(d.injec_jour  ?? 0);
    const e2 = +(d.injec_nuit  ?? 0);
    const totalKwh    = t1 + t2;
    const wholeMonths = Math.max(1, Math.round(data.days / 30.4375));

    function detail(qty, qtyUnit, rate, rateUnit) {
      const qFmt = Number(qty).toFixed(2);
      const rFmt = (rate != null && rate !== 0) ? Number(rate).toFixed(7) : null;
      return rFmt ? `${qFmt} ${qtyUnit} × ${rFmt} €/${rateUnit}` : `${qFmt} ${qtyUnit}`;
    }
    function detailAnnual(days, annualRate) {
      if (!annualRate) return `${days} j`;
      const daily = annualRate / 365;
      return `${days} j × ${daily.toFixed(5)} €/j <span style="opacity:.55">(${Number(annualRate).toFixed(2)} €/an)</span>`;
    }
    function row(label, detailStr, amount, cls = '') {
      const amtHtml = amount === null || amount === undefined
        ? '<span style="color:var(--muted)">—</span>'
        : (() => { const s = amount < 0 ? '−' : ''; return `${s}${Math.abs(amount).toFixed(2)} €`; })();
      return `<div class="cost-line ${cls}">
        <span class="cl-label">${label}</span>
        <span class="cl-detail">${detailStr}</span>
        <span class="cl-amount">${amtHtml}</span>
      </div>`;
    }
    function maybeRow(label, detailStr, amount, cls = '') {
      if (!amount || amount === 0) return '';
      return row(label, detailStr, amount, cls);
    }
    // Informational row displaying a kWh value (no € amount column)
    function solarKwhRow(label, kwhVal) {
      const kwhStr = kwhVal != null ? Number(kwhVal).toFixed(2) + ' kWh' : '—';
      return `<div class="cost-line">
        <span class="cl-label">${label}</span>
        <span class="cl-detail"></span>
        <span class="cl-amount" style="color:var(--green)">${kwhStr}</span>
      </div>`;
    }

    const isCurrentMonth = (navYear === NOW_YEAR && navMonth === NOW_MONTH);
    const periodLabel    = isCurrentMonth ? 'Estimation mois en cours' : `${MONTHS_FR[navMonth-1]} ${navYear}`;

    el.innerHTML = `<div class="cost-wrap">
      <div class="cost-lines">
        <div class="cost-group-label">Fournisseur</div>
        ${maybeRow('Énergie T1 — jour',             detail(t1,       'kWh', r.energy_t1,           'kWh'), c.energy_t1)}
        ${maybeRow('Énergie T2 — nuit',             detail(t2,       'kWh', r.energy_t2,           'kWh'), c.energy_t2)}
        ${row('Abonnement fournisseur', `${wholeMonths} mois × ${r.subscription != null ? Number(r.subscription).toFixed(2) + ' €/mois' : '—'}`, c.subscription ?? 0)}

        <div class="cost-group-label">Distribution (Sibelga)</div>
        ${maybeRow('Distribution T1 — jour',  detail(t1,       'kWh', r.distribution_t1, 'kWh'), c.distribution_t1)}
        ${maybeRow('Distribution T2 — nuit',  detail(t2,       'kWh', r.distribution_t2, 'kWh'), c.distribution_t2)}
        ${row('Transport',                    detail(totalKwh, 'kWh', r.transport,        'kWh'), c.transport ?? 0)}
        ${row('Gestion (fixe annuel)',         detailAnnual(data.days, r.management_annual), c.management_fee ?? 0)}

        <div class="cost-group-label">Taxes &amp; contributions</div>
        ${row('Taxe prosumer BRUGEL',           detailAnnual(data.days, r.prosumer_annual),                 c.prosumer_fee ?? 0)}
        ${row("Droit d'accise spécial",         detail(totalKwh, 'kWh', r.excise_duty,           'kWh'), c.excise_duty ?? 0)}
        ${row('Contribution énergie',           detail(totalKwh, 'kWh', r.energy_contribution,   'kWh'), c.energy_contribution ?? 0)}
        ${row('Contribution verte &amp; cogén.',detail(totalKwh, 'kWh', r.green_contribution,    'kWh'), c.green_contribution ?? 0)}
        ${row('Obligations de service public',  detailAnnual(data.days, r.public_service_annual),           c.public_service_fee ?? 0)}

        <div class="cost-group-label cost-group-label--credit">Injections (revenus)</div>
        ${row('Crédit injection T1', detail(e1, 'kWh', r.injection_t1, 'kWh'), c.injection_t1, 'credit')}
        ${row('Crédit injection T2', detail(e2, 'kWh', r.injection_t2, 'kWh'), c.injection_t2, 'credit')}

        ${c.solar_produced != null ? `
        <div class="cost-group-label cost-group-label--solar">☀ Solaire (auto-consommation)</div>
        ${solarKwhRow('Production PV totale', c.solar_produced)}
        ${solarKwhRow(
            c.self_consumption_pct != null
              ? `Auto-consommée — ${Number(c.self_consumption_pct).toFixed(1)} %`
              : 'Auto-consommée (non exportée)',
            c.solar_consumed
        )}
        ${row('Économies réalisées (tarif T1 TTC)',
              `${Number(c.solar_consumed).toFixed(2)} kWh × ${Number(c.solar_savings_rate).toFixed(6)} €/kWh`,
              c.solar_savings, 'credit')}
        ` : ''}

        <div class="cost-group-sep"></div>
        ${row('Total TTC', '',                                                                              c.total,       'total')}
        ${row('dont HTVA', '÷ 1.21',                                                                        c.htva,        'vat')}
        ${row('dont TVA 21% incluse', `${Math.abs(c.total).toFixed(2)} − ${Math.abs(c.htva).toFixed(2)} €`, c.vat_included,'vat vat-highlight')}
      </div>
      <div class="cost-total-card">
        <div>
          <div class="cost-total-label">${periodLabel}</div>
          <div class="cost-total-amount">${(() => { const s = c.total < 0 ? '−' : ''; return `${s}${Math.abs(c.total).toFixed(2)} €`; })()}</div>
        </div>
        <div class="cost-total-meta">
          <span>Tarif : ${data.tariff_name ?? '—'}</span>
          <span>Import T1 : ${t1.toFixed(2)} kWh</span>
          <span>Import T2 : ${t2.toFixed(2)} kWh</span>
          <span>Export T1 : ${e1.toFixed(2)} kWh</span>
          <span>Export T2 : ${e2.toFixed(2)} kWh</span>
          ${c.solar_produced != null ? `
          <span style="color:var(--green)">☀ Prod. PV : ${Number(c.solar_produced).toFixed(2)} kWh</span>
          <span style="color:var(--green)">Auto-conso : ${Number(c.solar_consumed).toFixed(2)} kWh${c.self_consumption_pct != null ? ` (${Number(c.self_consumption_pct).toFixed(1)} %)` : ''}</span>
          <span style="color:var(--green)">Économies : +${Number(c.solar_savings).toFixed(2)} €</span>
          ` : (d.solar != null ? `<span>Solaire : ${Number(d.solar).toFixed(2)} kWh</span>` : '')}
          <span>Période : ${data.days} jours</span>
        </div>
      </div>
    </div>`;
  }

  async function loadMonthCost(year, month) {
    const el = document.getElementById('cost-content');
    el.innerHTML = '<div style="font-family:var(--mono);font-size:.85rem;color:var(--muted);padding:24px 0">Chargement…</div>';
    if (year === NOW_YEAR && month === NOW_MONTH && window.__INIT_COST__) {
      renderCostContent(window.__INIT_COST__);
      return;
    }
    try {
      const res  = await fetch(`api.php?action=month_cost&year=${year}&month=${month}`);
      const data = await res.json();
      renderCostContent(data);
    } catch (e) {
      el.innerHTML = '<div style="color:var(--red);font-family:var(--mono);font-size:.85rem;padding:24px 0">Erreur de chargement.</div>';
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
                : await (await fetch(`api.php?action=month_cost&year=${year}&month=${m}`)).json();
              yearCache[key] = d;
              updateYearCard(card, d);
            } catch { card.querySelector('.ymc-cost').innerHTML = '<span class="ymc-nd">err</span>'; }
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
      costEl.textContent = t != null ? (t < 0 ? '−' : '') + Math.abs(t).toFixed(2) + ' €' : '—';
      kwhEl.textContent  = kwh ? kwh.toFixed(1) + ' kWh' : '';
      if (savingsEl) {
        savingsEl.textContent = savings != null && savings > 0 ? '☀ −' + savings.toFixed(2) + ' €' : '';
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
    document.getElementById('year-overview-wrap').style.display = mode === 'year'  ? 'block' : 'none';
    document.getElementById('cost-content').style.display       = mode === 'month' ? 'block' : 'none';
    if (mode === 'year') loadYearOverview(navYear);
    updateNav();
  };

  // Init
  updateNav();
  renderCostContent(window.__INIT_COST__);
})();

// ── Gas cost navigation ────────────────────────────────────────────────────
(function () {
  const MONTHS_FR   = ['Jan','Fév','Mar','Avr','Mai','Jun','Jul','Aoû','Sep','Oct','Nov','Déc'];
  const MONTH_NAMES = ['Janvier','Février','Mars','Avril','Mai','Juin',
                       'Juillet','Août','Septembre','Octobre','Novembre','Décembre'];

  let gasNavYear  = window.__INIT_GAS_YEAR__;
  let gasNavMonth = window.__INIT_GAS_MONTH__;
  let gasNavMode  = 'month';

  const NOW_YEAR  = window.__INIT_YEAR__;
  const NOW_MONTH = window.__INIT_MONTH__;

  function fmtE(v) {
    if (v === null || v === undefined) return '<span style="color:var(--muted)">—</span>';
    const s = v < 0 ? '−' : '';
    return `${s}${Math.abs(v).toFixed(2)} €`;
  }
  function detail(qty, qtyUnit, rate, rateUnit) {
    const qFmt = Number(qty).toFixed(3);
    const rFmt = (rate != null && rate !== 0) ? Number(rate).toFixed(7) : null;
    return rFmt ? `${qFmt} ${qtyUnit} × ${rFmt} €/${rateUnit}` : `${qFmt} ${qtyUnit}`;
  }
  function detailAnnual(days, annualRate) {
    if (!annualRate) return `${days} j`;
    const daily = annualRate / 365;
    return `${days} j × ${daily.toFixed(5)} €/j <span style="opacity:.55">(${Number(annualRate).toFixed(2)} €/an)</span>`;
  }
  function row(label, detailStr, amount, cls = '') {
    return `<div class="cost-line ${cls}">
      <span class="cl-label">${label}</span>
      <span class="cl-detail">${detailStr}</span>
      <span class="cl-amount">${fmtE(amount)}</span>
    </div>`;
  }
  function maybeRow(label, detailStr, amount, cls = '') {
    if (!amount || amount === 0) return '';
    return row(label, detailStr, amount, cls);
  }

  function renderGasCostContent(data) {
    const el = document.getElementById('gas-cost-content');
    if (!el) return;
    if (!data || !data.available) {
      el.innerHTML = `<div class="no-tariff">
        <strong style="color:var(--text);margin-bottom:6px;display:block">Aucune donnée gaz disponible</strong>
        ${data?.reason ?? 'Enregistrez au moins deux relevés et configurez un tarif gaz.'}
      </div>`;
      return;
    }

    const c           = data.cost;
    const r           = data.tariff_rates ?? {};
    const kwh         = +(data.kwh ?? 0);
    const wholeMonths = Math.max(1, Math.round(data.days / 30.4375));
    const from        = data.period_from ? data.period_from.slice(0, 10) : '—';
    const to          = data.period_to   ? data.period_to.slice(0, 10)   : '—';

    const isCurrentPeriod = (gasNavYear === NOW_YEAR && gasNavMonth === NOW_MONTH);
    const periodLabel     = isCurrentPeriod ? 'Estimation période en cours' : `${MONTHS_FR[gasNavMonth-1]} ${gasNavYear}`;

    el.innerHTML = `<div class="cost-wrap">
      <div class="cost-lines">
        <div class="cost-group-label">Fournisseur</div>
        ${maybeRow('Énergie fournisseur',    detail(kwh, 'kWh', r.energy,       'kWh'), c.energy)}
        ${row(     'Abonnement fournisseur', `${wholeMonths} mois × ${r.subscription != null ? Number(r.subscription).toFixed(2) + ' €/mois' : '—'}`, c.subscription ?? 0)}

        <div class="cost-group-label">Distribution &amp; transport (Sibelga)</div>
        ${maybeRow('Distribution variable', detail(kwh,      'kWh', r.distribution,    'kWh'), c.distribution)}
        ${maybeRow('Distribution fixe',     detailAnnual(data.days, r.distribution_fixed),           c.distribution_fixed)}
        ${maybeRow('Transport',             detail(kwh,      'kWh', r.transport,        'kWh'), c.transport)}
        ${maybeRow('Relevé de compteur',    detailAnnual(data.days, r.meter_reading_annual),      c.meter_reading)}

        <div class="cost-group-label">Taxes &amp; contributions</div>
        ${maybeRow('Contribution énergie',          detail(kwh, 'kWh', r.energy_contribution, 'kWh'), c.energy_contribution)}
        ${maybeRow('Accise fédérale',               detail(kwh, 'kWh', r.federal_excise,       'kWh'), c.federal_excise)}
        ${maybeRow('Redevance de raccordement',     detail(kwh, 'kWh', r.connection_fee_kwh,   'kWh'), c.connection_fee)}
        ${maybeRow('Obligations de service public', detailAnnual(data.days, r.public_service_annual),   c.public_service)}

        <div class="cost-group-sep"></div>
        ${row('Total TTC',           '',                                                                       c.total,       'total')}
        ${row('dont HTVA',           '÷ 1.21',                                                                 c.htva,        'vat')}
        ${row('dont TVA 21% incluse', `${Math.abs(c.total).toFixed(2)} − ${Math.abs(c.htva).toFixed(2)} €`,   c.vat_included,'vat vat-highlight')}
      </div>
      <div class="cost-total-card">
        <div>
          <div class="cost-total-label">${periodLabel}</div>
          <div class="cost-total-amount">${fmtE(c.total)}</div>
        </div>
        <div class="cost-total-meta">
          <span>Tarif : ${data.tariff_name ?? '—'}</span>
          <span>${from} → ${to}</span>
          <span>${data.days} jour${data.days > 1 ? 's' : ''}</span>
          <span>${Number(data.delta_m3).toFixed(3)} m³ → ${Number(kwh).toFixed(1)} kWh</span>
          <span>PCS ${data.pcs_coefficient} kWh/m³</span>
        </div>
      </div>
    </div>`;
  }

  async function loadMonthGasCost(year, month) {
    const el = document.getElementById('gas-cost-content');
    el.innerHTML = '<div style="font-family:var(--mono);font-size:.85rem;color:var(--muted);padding:24px 0">Chargement…</div>';
    if (year === window.__INIT_GAS_YEAR__ && month === window.__INIT_GAS_MONTH__ && window.__INIT_GAS_COST__) {
      renderGasCostContent(window.__INIT_GAS_COST__);
      return;
    }
    try {
      const res  = await fetch(`api.php?action=gas_month_cost&year=${year}&month=${month}`);
      const data = await res.json();
      renderGasCostContent(data);
    } catch (e) {
      el.innerHTML = '<div style="color:var(--red);font-family:var(--mono);font-size:.85rem;padding:24px 0">Erreur de chargement.</div>';
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
                : await (await fetch(`api.php?action=gas_month_cost&year=${year}&month=${m}`)).json();
              gasYearCache[key] = d;
              updateGasYearCard(card, d);
            } catch {
              card.querySelector('.ymc-cost').innerHTML = '<span class="ymc-nd">err</span>';
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
      costEl.textContent = t != null ? (t < 0 ? '−' : '') + Math.abs(t).toFixed(2) + ' €' : '—';
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
    document.getElementById('gas-year-overview-wrap').style.display = mode === 'year'  ? 'block' : 'none';
    document.getElementById('gas-cost-content').style.display       = mode === 'month' ? 'block' : 'none';
    if (mode === 'year') loadGasYearOverview(gasNavYear);
    updateGasNav();
  };

  // Init
  updateGasNav();
  renderGasCostContent(window.__INIT_GAS_COST__ ?? { available: false, reason: 'Données non chargées' });

  // Refresh after a new gas reading is saved
  document.addEventListener('gas-entry-saved', async function () {
    try {
      const data = await (await fetch(`api.php?action=gas_month_cost&year=${gasNavYear}&month=${gasNavMonth}`)).json();
      delete gasYearCache[`${gasNavYear}-${gasNavMonth}`];
      renderGasCostContent(data);
    } catch { /* silent */ }
  });
})();

// ── Clock ──────────────────────────────────────────────────────────────────
(function clock() {
  const timeEl = document.getElementById('clock-time');
  const dateEl = document.getElementById('clock-date');
  const DAYS   = ['Dim','Lun','Mar','Mer','Jeu','Ven','Sam'];
  const MONTHS = ['jan','fév','mar','avr','mai','jun','jul','aoû','sep','oct','nov','déc'];
  function tick() {
    const now = new Date();
    timeEl.textContent = now.toTimeString().slice(0, 8);
    dateEl.textContent = `${DAYS[now.getDay()]} ${now.getDate()} ${MONTHS[now.getMonth()]} ${now.getFullYear()}`;
  }
  tick();
  setInterval(tick, 1000);
})();

// ── Chart ──────────────────────────────────────────────────────────────────
let chart = null;

async function loadChart(days = 30) {
  ['btn-30','btn-60','btn-90'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.style.color = '';
  });
  const btn = document.getElementById('btn-' + days);
  if (btn) btn.style.color = 'var(--amber)';

  try {
    const res  = await fetch(`api.php?action=chart_data&days=${days}`);
    const data = await res.json();
    renderChart(data);
  } catch (e) {
    console.warn('Chart load failed:', e);
  }
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
        { label: 'Import T1 (jour)',   data: importT1,  backgroundColor: 'rgba(245,166,35,.7)', borderColor: 'rgba(245,166,35,.9)', borderWidth: 1, stack: 'import' },
        { label: 'Import T2 (nuit)',   data: importT2,  backgroundColor: 'rgba(245,166,35,.3)', borderColor: 'rgba(245,166,35,.5)', borderWidth: 1, stack: 'import' },
        { label: 'Export (injection)', data: exportAll, backgroundColor: 'rgba(65,179,245,.55)', borderColor: 'rgba(65,179,245,.8)', borderWidth: 1, stack: 'export' },
        { label: 'Production PV', data: solar, type: 'line', borderColor: 'rgba(47,213,142,.8)', backgroundColor: 'rgba(47,213,142,.08)', fill: true, tension: .3, pointRadius: 2, borderWidth: 2 },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { labels: { color: '#556070', font: { family: "'Space Mono'", size: 11 }, boxWidth: 12 } },
        tooltip: {
          backgroundColor: '#111318', borderColor: '#1f2530', borderWidth: 1,
          titleColor: '#c8d0dc', bodyColor: '#8899a8',
          callbacks: { label: ctx => ` ${ctx.dataset.label}: ${(ctx.raw || 0).toFixed(2)} kWh` },
        },
      },
      scales: {
        x: { stacked: true, grid: { color: '#1a1f28' }, ticks: { color: '#556070', font: { family: "'Space Mono'", size: 10 }, maxRotation: 45 } },
        y: { stacked: false, grid: { color: '#1a1f28' }, ticks: { color: '#556070', font: { family: "'Space Mono'", size: 10 }, callback: v => v + ' kWh' } },
      },
    },
  });
}

// ── Live dongle polling ────────────────────────────────────────────────────
const LIVE_INTERVAL = 2_000;

function fmtWatt(v) {
  if (v === undefined || v === null) return '<span class="nd">—</span>';
  const w = parseFloat(v);
  if (w >= 1000 || w <= -1000) {
    return '<span class="val">' + (w / 1000).toLocaleString('fr-BE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '</span> <span class="unit">kW</span>';
  }
  return '<span class="val">' + w.toLocaleString('fr-BE', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) + '</span> <span class="unit">W</span>';
}

async function fetchLive() {
  const syncDot = document.querySelector('.sync-dot');
  try {
    const res  = await fetch('api.php?action=live');
    const data = await res.json();

    document.getElementById('live-ts').textContent = data.timestamp
      ? data.timestamp.slice(0, 19).replace('T', ' ')
      : '—';

    document.getElementById('live-dries-w').innerHTML = data.dries_error
      ? '<span class="nd" title="' + data.dries_error + '">err</span>'
      : fmtWatt(data.dries_w);

    document.getElementById('live-solar-w').innerHTML = data.solar_error
      ? '<span class="nd" title="' + data.solar_error + '">err</span>'
      : fmtWatt(Math.abs(data.solar_w));

    if (syncDot) syncDot.className = (data.dries_error && data.solar_error) ? 'sync-dot error' : 'sync-dot';
  } catch (e) {
    if (syncDot) syncDot.className = 'sync-dot error';
    console.warn('Live fetch failed:', e);
  }
}

loadChart(30);
fetchLive();
setInterval(fetchLive, LIVE_INTERVAL);
loadGasHistory();
loadWaterHistory();

// ── Meter reading tables (gas / water) ───────────────────────────────────────
// Rendu désormais piloté par l'API (api.php?action=gas_history|water_history) au
// chargement et après chaque saisie : un seul chemin de rendu, plus de duplication
// avec le serveur, et la page ne dépend plus de la BDD pour ces tables.
function fmtM3(v) {
  // Aligné sur number_format($x, 3, '.', ' ') côté serveur :
  // décimale point, séparateur de milliers espace (8523.456 -> "8 523.456").
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
    `<tr>
      <td>${r.reading_at.slice(0, 16)}</td>
      <td>${fmtM3(r.counter_m3)}</td>
      <td class="td-delta">${r.delta_m3 !== null ? '+' + fmtM3(r.delta_m3) + ' m³' : '—'}</td>
    </tr>`
  ).join('');
}

async function loadGasHistory() {
  try {
    const res = await fetch('api.php?action=gas_history');
    renderReadings('gas-tbody', await res.json(), 'Aucune entrée gaz enregistrée.');
  } catch (e) { /* la table reste vide en cas d'erreur réseau */ }
}

async function loadWaterHistory() {
  try {
    const res = await fetch('api.php?action=water_history');
    renderReadings('water-tbody', await res.json(), 'Aucune entrée eau enregistrée.');
  } catch (e) { /* idem */ }
}

// ── Gas entry ──────────────────────────────────────────────────────────────
async function submitGas() {
  const btn      = document.getElementById('gas-btn');
  const feedback = document.getElementById('gas-feedback');
  const date     = document.getElementById('gas-date').value;
  const time     = document.getElementById('gas-time').value || '00:00';
  const value    = parseFloat(document.getElementById('gas-value').value);

  feedback.textContent = '';
  feedback.className   = 'form-feedback';

  if (!date || isNaN(value) || value <= 0) {
    feedback.textContent = '⚠ Renseigne une date et une valeur valide.';
    feedback.className   = 'form-feedback err';
    return;
  }

  btn.disabled    = true;
  btn.textContent = 'Envoi…';

  try {
    const res  = await fetch('api.php?action=gas_entry', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ counter_m3: value, reading_at: `${date} ${time}:00` }),
    });
    const data = await res.json();

    if (data.ok) {
      feedback.textContent = `✓ Enregistré — ${data.counter_m3} m³ le ${data.saved_at.slice(0, 10)}`;
      feedback.className   = 'form-feedback ok';
      document.getElementById('gas-value').value = '';
      // Reload gas table
      await loadGasHistory();
      // Refresh gas cost block
      document.dispatchEvent(new Event('gas-entry-saved'));
    } else {
      feedback.textContent = '✗ ' + (data.error || 'Erreur inconnue.');
      feedback.className   = 'form-feedback err';
    }
  } catch (e) {
    feedback.textContent = '✗ Erreur réseau.';
    feedback.className   = 'form-feedback err';
  } finally {
    btn.disabled    = false;
    btn.textContent = 'Enregistrer';
  }
}

// ── Water entry ────────────────────────────────────────────────────────────
async function submitWater() {
  const btn      = document.getElementById('water-btn');
  const feedback = document.getElementById('water-feedback');
  const date     = document.getElementById('water-date').value;
  const time     = document.getElementById('water-time').value || '00:00';
  const value    = parseFloat(document.getElementById('water-value').value);

  feedback.textContent = '';
  feedback.className   = 'form-feedback';

  if (!date || isNaN(value) || value <= 0) {
    feedback.textContent = '⚠ Renseigne une date et une valeur valide.';
    feedback.className   = 'form-feedback err';
    return;
  }

  btn.disabled    = true;
  btn.textContent = 'Envoi…';

  try {
    const res  = await fetch('api.php?action=water_entry', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ counter_m3: value, reading_at: `${date} ${time}:00` }),
    });
    const data = await res.json();

    if (data.ok) {
      feedback.textContent = `✓ Enregistré — ${data.counter_m3} m³ le ${data.saved_at.slice(0, 10)}`;
      feedback.className   = 'form-feedback ok';
      document.getElementById('water-value').value = '';
      // Reload water table
      await loadWaterHistory();
    } else {
      feedback.textContent = '✗ ' + (data.error || 'Erreur inconnue.');
      feedback.className   = 'form-feedback err';
    }
  } catch (e) {
    feedback.textContent = '✗ Erreur réseau.';
    feedback.className   = 'form-feedback err';
  } finally {
    btn.disabled    = false;
    btn.textContent = 'Enregistrer';
  }
}
