const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { execFileSync } = require('node:child_process');
const { resolve } = require('node:path');
const vm = require('node:vm');

const root = resolve(__dirname, '../..');
// Exécuter l'initialisation et le rendu électricité, sans les autres widgets et leurs fetch.
const source = readFileSync(resolve(root, 'app/public/assets/js/dashboard.js'), 'utf8');
const boundary = source.indexOf('// ── Récapitulatif annuel');
assert.ok(boundary > 0);
const electricitySource = source.slice(0, boundary);

function render(locale, weighting = 'standard_profile', comparison = {}) {
  const catalog = JSON.parse(execFileSync('php', ['-r',
    'echo json_encode(require $argv[1]);', resolve(root, `app/translations/${locale}.php`),
  ], { encoding: 'utf8' }));
  const nodes = new Map();
  for (const id of ['cost-content', 'nav-label', 'nav-prev', 'nav-next']) {
    nodes.set(id, { innerHTML: '', style: {}, addEventListener() {} });
  }
  const data = {
    available: true, days: 1, dynamic_days: 1, resolution: 'monthly',
    tariff_name: 'Belpex', load_weighting: weighting, monthly_partial: true,
    deltas: { prelev_jour: 4 },
    monthly_prices: [{
      month: '2026-06', weighting, partial: true,
      profile_code: weighting === 'standard_profile' ? 'RLP0N' : null,
    }],
    cost: {
      total: 2.16, energy_total: 2.16, htva: 2, vat_rate: 6,
      lines: [{ key: 'energy_dynamic', group: 'energy', kind: 'energy_flat', amount: 2.16, quantity: 4, rate: 0.54 }],
    },
    dynamic: {
      available: true, is_simulation: true,
      resolution: 'quarter', resolution_requested: 'quarter',
      cost: { total: 1.31 }, energy_dynamic: 1.31, matched_kwh: 4, coverage_pct: 100,
      ...comparison,
    },
  };
  const context = {
    document: { getElementById: id => nodes.get(id) || null },
    window: {
      APP_LOCALE: locale, __I18N__: catalog,
      __INIT_COST__: data, __INIT_YEAR__: 2026, __INIT_MONTH__: 6,
    },
  };
  vm.runInNewContext(electricitySource, context);
  const html = nodes.get('cost-content').innerHTML;
  const split = html.indexOf('<details class="dynamic-simulation');
  assert.ok(split > 0, 'Le comparatif doit être une simulation repliée');
  return { contract: html.slice(0, split), simulation: html.slice(split), catalog };
}

for (const locale of ['fr', 'en', 'de', 'nl']) {
  test(`${locale}: RLP et mois provisoire auprès du variable, résolution auprès de la simulation`, () => {
    const { contract, simulation, catalog } = render(locale);
    assert.ok(contract.includes(catalog['dash.cost.energy_variable']));
    assert.ok(contract.includes(catalog['dash.dynamic.weighting_standard_profile']));
    assert.ok(contract.includes(catalog['dash.dynamic.monthly_provisional']));
    assert.ok(contract.includes('RLP0N'));
    assert.ok(!simulation.includes('RLP0N'));
    assert.ok(simulation.includes(catalog['dash.dynamic.resolution_quarter']));
    assert.ok(!contract.includes('energy_dynamic'));
    assert.doesNotMatch(simulation, /<details\b[^>]*\bopen(?:\s|=|>)/);
  });

  for (const weighting of ['actual_load', 'baseload']) {
    test(`${locale}: repli mensuel ${weighting} explicitement estimé`, () => {
      const { contract, simulation, catalog } = render(locale, weighting);
      assert.ok(contract.includes(catalog[`dash.dynamic.weighting_${weighting}`]));
      assert.ok(contract.includes('cost-formula-note--fallback'));
      assert.ok(!simulation.includes(catalog[`dash.dynamic.weighting_${weighting}`]));
    });
  }

  for (const [reason, key] of [
    ['readings_not_quarter', 'dash.dynamic.resolution_fallback_readings'],
    ['no_quarter_prices', 'dash.dynamic.resolution_fallback_prices'],
  ]) {
    test(`${locale}: motif de repli horaire ${reason}`, () => {
      const { contract, simulation, catalog } = render(locale, 'standard_profile', {
        resolution: 'hourly', resolution_fallback: reason,
      });
      assert.ok(simulation.includes(catalog[key]));
      assert.ok(!contract.includes(catalog[key]));
    });
  }

  test(`${locale}: comparatif indisponible conservé comme simulation`, () => {
    const { simulation, catalog } = render(locale, 'standard_profile', {
      available: false, reason_key: 'dash.reason.dynamic_not_configured',
    });
    assert.ok(simulation.includes(catalog['dash.dynamic.simulation_title']));
    assert.ok(simulation.includes(catalog['dash.reason.dynamic_not_configured']));
    assert.ok(!simulation.includes('RLP0N'));
  });
}
