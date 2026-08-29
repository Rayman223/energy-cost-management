<?php

/**
 * Statistiques communautaires (#8) : agrégats publics par pays, enrichis d'une
 * comparaison personnelle quand un utilisateur est connecté.
 *
 * Seule page du site accessible à un visiteur anonyme tout en ayant un rendu
 * connecté : le header bascule via `authenticated`, et le bloc privé n'existe
 * simplement pas quand `$private` est null.
 *
 * Les libellés vivent ici et non dans le service : `TemplateCatalogTest` exige
 * qu'une clé référencée depuis app/src existe dans les quatre catalogues, et le
 * service renvoie donc des identifiants neutres ('energy', 'ZZ', 'electricity')
 * que cette vue traduit.
 *
 * @var ?string                $dbError       Message d'erreur base, ou null
 * @var array<string,mixed>|null $overview    Agrégats publics ({@see \App\Service\StatisticsService})
 * @var array<string,mixed>|null $private     Comparaison du foyer, ou null (anonyme / sans pays)
 * @var bool                   $authenticated Un utilisateur actif est connecté
 * @var bool                   $isAdmin       Affiche l'icône Admin
 * @var string                 $currency      Devise du profil, repli d'affichage
 * @var ?string                $clockTimezone Fuseau de l'horloge du header
 * @var list<string>           $available     Locales du sélecteur
 * @var ?string                $discordUrl    Invitation Discord
 * @var ?string                $adsenseClient Identifiant éditeur AdSense
 */

$overview = $overview ?? null;
$private  = $private ?? null;
$k        = (int) ($overview['k'] ?? 5);

/** Nom localisé d'un pays ; le bucket résiduel a son propre libellé. */
$countryName = function (string $iso): string {
    return $iso === \App\Repository\Contract\StatisticsRepositoryInterface::OTHER_BUCKET
        ? $this->t('stats.country_other')
        : \App\Domain\EuropeanCountries::name($iso, $this->locale());
};

/** Valeur numérique ou tiret : une donnée absente ne doit jamais se lire « 0 ». */
$or_dash = function (?float $value, int $decimals = 2, string $suffix = ''): string {
    return $value === null ? '—' : $this->e($this->num($value, $decimals)) . $suffix;
};

/** Écart en % coloré selon son signe (au-dessus de la moyenne = défavorable). */
$delta = function (?float $pct): string {
    if ($pct === null) {
        return '<span class="stat-delta stat-delta--none">—</span>';
    }
    $class = $pct > 0 ? 'stat-delta--up' : ($pct < 0 ? 'stat-delta--down' : 'stat-delta--flat');
    $sign  = $pct > 0 ? '+' : '';

    return '<span class="stat-delta ' . $class . '">' . $sign . $this->e($this->num($pct, 1)) . ' %</span>';
};

$symbol = \App\Domain\Currency::symbol($currency ?? 'EUR');
?>
<!DOCTYPE html>
<html lang="<?= $this->e($this->locale()) ?>">
<head>
<?= $this->partial('_head', [
    'title'         => $this->t('stats.title') . ' — ' . \App\Support\AppName::NAME,
    'css'           => ['assets/css/app-header.css', 'assets/css/lang-switcher.css', 'assets/css/stats.css'],
    'preconnects'   => ['https://cdn.jsdelivr.net'],
    'adsenseClient' => $adsenseClient ?? null,
]) ?>
<!-- defer : ne bloque pas le parsing et s'exécute avant stats.js (ordre du document préservé). -->
<script defer src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body>
<div class="wrap">

  <?= $this->partial('_header', [
      'subtitle'      => $this->t('stats.subtitle'),
      'current'       => 'stats',
      'isAdmin'       => $isAdmin ?? false,
      'discordUrl'    => $discordUrl ?? null,
      'available'     => $available,
      'timezone'      => $clockTimezone ?? null,
      'authenticated' => $authenticated ?? false,
  ]) ?>

  <?php if ($dbError): ?>
  <div class="error-banner">
    <strong>⚠ <?= $this->te('stats.db_offline') ?></strong>
    <?= $this->e($dbError) ?>
  </div>
  <?php endif; ?>

  <?php // ── Bloc privé : ce que l'utilisateur connecté vient chercher ────── ?>
  <?php if ($private !== null): ?>
  <div class="section-header">
    <span class="section-title"><?= $this->te('stats.me_title', ['country' => $countryName($private['country'])]) ?></span>
    <span class="section-line"></span>
  </div>

  <?php if ($private['opted_out']): ?>
  <p class="hint"><?= $this->te('stats.opted_out_notice') ?></p>
  <?php endif; ?>

  <div class="stat-cards">
    <div class="stat-card">
      <div class="stat-label"><?= $this->te('stats.me_rate') ?></div>
      <div class="stat-value"><?= $or_dash($private['my_rate'], 4, ' ' . $this->e($symbol) . '/kWh') ?></div>
      <div class="stat-ref">
        <?= $this->te('stats.me_vs_average') ?> <?= $or_dash($private['country_rate'], 4) ?>
        <?= $delta($private['rate_delta_pct']) ?>
      </div>
    </div>

    <div class="stat-card">
      <div class="stat-label"><?= $this->te('stats.me_usage') ?></div>
      <div class="stat-value"><?= $or_dash($private['my_usage'], 0, ' kWh') ?></div>
      <div class="stat-ref">
        <?= $this->te('stats.me_vs_average') ?> <?= $or_dash($private['country_usage'], 0) ?>
        <?= $delta($private['usage_delta_pct']) ?>
      </div>
    </div>

    <?php if ($private['rate_percentile'] !== null): ?>
    <div class="stat-card">
      <div class="stat-label"><?= $this->te('stats.me_percentile') ?></div>
      <div class="stat-value"><?= $this->e($this->num((float) $private['rate_percentile'], 0)) ?> %</div>
      <div class="stat-ref"><?= $this->te('stats.me_percentile_hint', ['n' => $private['households']]) ?></div>
    </div>
    <?php endif; ?>

    <?php // Volontairement SANS comparaison : ce coût inclut abonnement et taxes
          // fixes, la moyenne du pays non. Les mettre côte à côte ferait paraître
          // tout petit consommateur bien plus cher qu'il ne l'est. ?>
    <div class="stat-card stat-card--muted">
      <div class="stat-label"><?= $this->te('stats.me_net_cost') ?></div>
      <div class="stat-value"><?= $or_dash($private['my_net_cost'], 4, ' ' . $this->e($symbol) . '/kWh') ?></div>
      <div class="stat-ref"><?= $this->te('stats.me_net_cost_hint') ?></div>
    </div>
  </div>

  <div class="chart-grid">
    <div class="chart-box">
      <h3><?= $this->te('stats.me_monthly_title') ?></h3>
      <canvas id="monthlyChart" height="220"></canvas>
    </div>
    <div class="chart-box">
      <h3><?= $this->te('stats.me_categories_title') ?></h3>
      <canvas id="categoryChart" height="220"></canvas>
      <p class="hint"><?= $this->te('stats.me_categories_hint') ?></p>
    </div>
  </div>
  <?php elseif ($authenticated): ?>
  <?php // Connecté mais sans pays au profil : rien à quoi se comparer. ?>
  <div class="cta-box">
    <p><?= $this->te('stats.no_country') ?></p>
    <a class="cta" href="<?= $this->e($this->url('account')) ?>"><?= $this->te('stats.no_country_cta') ?></a>
  </div>
  <?php else: ?>
  <div class="cta-box">
    <p><?= $this->te('stats.anonymous_hint') ?></p>
    <a class="cta" href="<?= $this->e($this->url('login')) ?>"><?= $this->te('stats.anonymous_cta') ?></a>
  </div>
  <?php endif; ?>

  <?php // ── Bloc public ──────────────────────────────────────────────────── ?>
  <div class="section-header">
    <span class="section-title"><?= $this->te('stats.public_title') ?></span>
    <span class="section-line"></span>
    <?php if ($overview !== null): ?>
    <span class="deltas-range"><?= $this->e($overview['window']['from']) ?> → <?= $this->e($overview['window']['to']) ?></span>
    <?php endif; ?>
  </div>

  <?php if ($overview === null || !$overview['has_data']): ?>
  <?php // Corpus insuffisant : c'est le comportement attendu d'un site jeune sous
        // seuil de k-anonymat, et il mérite une explication plutôt qu'un tableau vide. ?>
  <div class="cta-box">
    <p><?= $this->te('stats.not_enough_data', ['k' => $k]) ?></p>
  </div>
  <?php else: ?>

  <div class="stat-cards">
    <div class="stat-card">
      <div class="stat-label"><?= $this->te('stats.coverage_households') ?></div>
      <div class="stat-value"><?= $this->e($this->num((float) $overview['coverage']['households'], 0)) ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label"><?= $this->te('stats.coverage_countries') ?></div>
      <div class="stat-value"><?= $this->e($this->num((float) $overview['coverage']['countries'], 0)) ?></div>
    </div>
  </div>

  <div class="chart-grid">
    <div class="chart-box">
      <h3><?= $this->te('stats.price_title') ?></h3>
      <canvas id="priceChart" height="240"></canvas>
    </div>
    <div class="chart-box">
      <h3><?= $this->te('stats.mix_title') ?></h3>
      <canvas id="mixChart" height="240"></canvas>
    </div>
    <div class="chart-box">
      <h3><?= $this->te('stats.usage_electricity') ?></h3>
      <canvas id="elecChart" height="240"></canvas>
    </div>
    <div class="chart-box">
      <h3><?= $this->te('stats.usage_gas') ?></h3>
      <canvas id="gasChart" height="240"></canvas>
    </div>
    <div class="chart-box">
      <h3><?= $this->te('stats.usage_water') ?></h3>
      <canvas id="waterChart" height="240"></canvas>
    </div>
    <?php if ($overview['spot'] !== []): ?>
    <div class="chart-box">
      <h3><?= $this->te('stats.spot_title') ?></h3>
      <canvas id="spotChart" height="240"></canvas>
      <p class="hint"><?= $this->te('stats.spot_hint') ?></p>
    </div>
    <?php endif; ?>
  </div>

  <?php // Tableau des prix : lisible sans JavaScript et accessible aux lecteurs
        // d'écran, là où un canvas ne l'est pas. ?>
  <div class="table-scroll">
    <table class="stats-table">
      <thead>
        <tr>
          <th><?= $this->te('stats.country') ?></th>
          <th><?= $this->te('stats.households') ?></th>
          <th><?= $this->te('stats.price_ttc') ?></th>
          <th><?= $this->te('stats.price_htva') ?></th>
          <th><?= $this->te('stats.price_fixed_year') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($overview['prices'] as $row): ?>
        <tr<?= $row['is_other'] ? ' class="row-other"' : '' ?>>
          <td><?= $this->e($countryName($row['country'])) ?></td>
          <td><?= $this->e($this->num((float) $row['households'], 0)) ?></td>
          <?php $sym = $row['currency'] !== '' ? \App\Domain\Currency::symbol($row['currency']) : ''; ?>
          <td><?= $or_dash($row['ttc_per_kwh'], 4, ' ' . $this->e($sym)) ?></td>
          <td><?= $or_dash($row['htva_per_kwh'], 4, ' ' . $this->e($sym)) ?></td>
          <td><?= $or_dash($row['fixed_year_ttc'], 0, ' ' . $this->e($sym)) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <?php // ── Note méthodologique : ces chiffres n'ont de sens qu'expliqués ── ?>
  <div class="section-header">
    <span class="section-title"><?= $this->te('stats.method_title') ?></span>
    <span class="section-line"></span>
  </div>
  <ul class="method-notes">
    <li><?= $this->te('stats.method_window', ['months' => (int) ($overview['window']['months'] ?? 12)]) ?></li>
    <li><?= $this->te('stats.method_k', ['k' => $k]) ?></li>
    <li><?= $this->te('stats.method_mix') ?></li>
    <li><?= $this->te('stats.method_ttc') ?></li>
    <li><?= $this->te('stats.method_dynamic_excluded') ?></li>
    <li><?= $this->te('stats.method_gas_m3') ?></li>
    <li><?= $this->te('stats.method_spot_htva') ?></li>
    <li>
      <?= $this->te('stats.method_privacy') ?>
      <a href="<?= $this->e($this->url('privacy')) ?>"><?= $this->te('legal.privacy') ?></a>
      <?php if ($authenticated): ?>
      · <a href="<?= $this->e($this->url('account')) ?>"><?= $this->te('stats.method_optout_link') ?></a>
      <?php endif; ?>
    </li>
  </ul>

  <?php
    // <script type="application/json"> n'est jamais exécuté : hors script-src,
    // donc compatible avec la CSP durcie sans nonce (#98). Les noms de pays sont
    // résolus ici — ext-intl n'existe pas côté navigateur.
    $countryLabels = [];
    foreach (['prices', 'electricity', 'gas', 'water', 'mix'] as $section) {
        foreach (($overview[$section] ?? []) as $row) {
            $countryLabels[$row['country']] = $countryName($row['country']);
        }
    }
    if ($private !== null) {
        $countryLabels[$private['country']] = $countryName($private['country']);
    }

    $statsData = [
        'locale'    => $this->locale(),
        'currency'  => $currency ?? 'EUR',
        'symbol'    => $symbol,
        'countries' => $countryLabels,
        'overview'  => $overview,
        'private'   => $private,
        'i18n'      => $this->translations('stats.chart.', 'common.'),
    ];
  ?>
  <script type="application/json" id="stats-data"><?= json_encode($statsData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>

  <script defer src="<?= \App\Support\Assets::url('assets/js/header.js') ?>"></script>
  <script defer src="<?= \App\Support\Assets::url('assets/js/lang-switcher.js') ?>"></script>
  <script defer src="<?= \App\Support\Assets::url('assets/js/stats.js') ?>"></script>
</div>
</body>
</html>
