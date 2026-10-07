<?php

/**
 * Template « Administration › Profils de charge » (#101) — état des mois importés
 * et import d'un CSV converti depuis Synergrid.
 *
 * @var string|null $error
 * @var array{code: string, country: string, resolution: int, points: int, first: string, last: string, months: array<string, int>, rejected: int, merged: int, dry_run: bool, written: int}|null $result
 * @var array{code: string, country: string, resolution: int, timezone: string, ts_col: string, value_col: string, dry_run: bool} $form
 * @var list<array{code: string, country: string, months: array<string, float>}> $report
 * @var list<array{code: string, country: string, month: string, coverage_pct: float}> $missing
 * @var list<string> $codes
 * @var string       $downloadUrl
 * @var int          $graceDays
 * @var float        $minCoverage
 * @var list<string> $available
 * @var ?string      $discordUrl
 * @var ?string      $donateUrl
 * @var ?string      $adsenseClient
 */
$csrf = \App\Security\Csrf::field();
?>
<!DOCTYPE html>
<html lang="<?= $this->e($this->locale()) ?>">
<head>
<?= $this->partial('_head', [
    'title' => $this->t('load_profiles.title') . ' — ' . $this->t('app.title'),
    'css'   => ['assets/css/app-header.css', 'assets/css/lang-switcher.css', 'assets/css/backoffice.css', 'assets/css/admin.css'],
    'adsenseClient' => $adsenseClient ?? null,
]) ?>
</head>
<body>
<div class="wrap">
  <?= $this->partial('_header', [
      'subtitle'    => $this->t('load_profiles.subtitle'),
      'current'     => 'admin',
      'isAdmin'     => true, // route réservée aux admins (admin/load-profiles.php)
      'discordUrl'  => $discordUrl ?? null,
      'donateUrl'   => $donateUrl ?? null,
      'available'   => $available,
      'timezone'    => $timezone ?? null,
  ]) ?>

  <p><a href="<?= $this->url('admin') ?>">← <?= $this->te('admin.title') ?></a></p>

  <?php if ($error !== null): ?><div class="banner err"><?= $this->e($error) ?></div><?php endif; ?>

  <?php if ($result !== null): ?>
    <div class="banner <?= $result['dry_run'] ? 'warn' : 'ok' ?>">
      <?= $result['dry_run']
          ? $this->te('load_profiles.result_dry_run')
          : $this->e($this->t('load_profiles.result_written', ['count' => (string) $result['written']])) ?>
      <ul class="lp-summary">
        <li><?= $this->e($this->t('load_profiles.result_points', [
            'count'      => (string) $result['points'],
            'resolution' => (string) $result['resolution'],
            'code'       => $result['code'],
            'country'    => $result['country'],
        ])) ?></li>
        <li><?= $this->e($this->t('load_profiles.result_range', ['first' => $result['first'], 'last' => $result['last']])) ?></li>
        <li><?= $this->te('load_profiles.result_months') ?>
          <?php foreach ($result['months'] as $month => $points): ?>
            <code><?= $this->e($month) ?> : <?= $this->e((string) $points) ?></code>
          <?php endforeach; ?>
        </li>
        <?php if ($result['rejected'] > 0): ?>
          <li><?= $this->e($this->t('load_profiles.result_rejected', ['count' => (string) $result['rejected']])) ?></li>
        <?php endif; ?>
        <?php if ($result['merged'] > 0): ?>
          <li><?= $this->e($this->t('load_profiles.result_merged', ['count' => (string) $result['merged']])) ?></li>
        <?php endif; ?>
      </ul>
    </div>
  <?php endif; ?>

  <!-- ── État : mois exigés par les grilles indexées ─────────────────────── -->
  <div class="card">
    <h2><?= $this->te('load_profiles.status') ?></h2>
    <p class="hint"><?= $this->e($this->t('load_profiles.status_hint', [
        'grace'    => (string) $graceDays,
        'coverage' => (string) (int) $minCoverage,
    ])) ?></p>

    <?php if ($report === []): ?>
      <p class="muted"><?= $this->te('load_profiles.none_in_use') ?></p>
    <?php else: ?>
      <?php if ($missing !== []): ?>
        <div class="banner warn banner--tight"><?= $this->e($this->t('load_profiles.missing_count', ['count' => (string) count($missing)])) ?></div>
      <?php endif; ?>
      <div class="table-scroll">
        <table>
          <thead>
            <tr>
              <th><?= $this->te('load_profiles.col_profile') ?></th>
              <th><?= $this->te('load_profiles.col_month') ?></th>
              <th><?= $this->te('load_profiles.col_coverage') ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($report as $row): ?>
              <?php if ($row['months'] === []): ?>
                <tr>
                  <td><code><?= $this->e($row['code'] . ' / ' . $row['country']) ?></code></td>
                  <td colspan="2" class="muted"><?= $this->te('load_profiles.nothing_required_yet') ?></td>
                </tr>
              <?php endif; ?>
              <?php foreach (array_reverse($row['months'], true) as $month => $pct): ?>
                <tr>
                  <td><code><?= $this->e($row['code'] . ' / ' . $row['country']) ?></code></td>
                  <td><?= $this->e((string) $month) ?></td>
                  <td>
                    <?php if (\App\Service\LoadProfileFreshness::isComplete($pct)): ?>
                      <span class="pill active"><?= $this->te('load_profiles.complete') ?></span>
                    <?php elseif ($pct > 0.0): ?>
                      <span class="pill partial"><?= $this->e($this->t('load_profiles.partial', ['pct' => (string) (int) floor($pct)])) ?></span>
                    <?php else: ?>
                      <span class="pill blocked"><?= $this->te('load_profiles.missing') ?></span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- ── Import ──────────────────────────────────────────────────────────── -->
  <div class="card">
    <h2><?= $this->te('load_profiles.import') ?></h2>
    <p class="hint"><?= $this->te('load_profiles.import_hint') ?>
      <a href="<?= $this->e($downloadUrl) ?>" target="_blank" rel="noopener noreferrer"><?= $this->te('load_profiles.download_link') ?> ↗</a></p>
    <p class="hint">⚠ <?= $this->te('load_profiles.monthly_weights_warning') ?></p>

    <form method="post" enctype="multipart/form-data" action="<?= $this->url('admin/load-profiles') ?>">
      <?= $csrf ?>
      <label for="profile_file"><?= $this->te('load_profiles.file') ?></label>
      <input id="profile_file" type="file" name="profile_file" accept=".csv" required>

      <div class="lp-grid">
        <div>
          <label for="code"><?= $this->te('load_profiles.code') ?></label>
          <input id="code" type="text" name="code" value="<?= $this->e($form['code']) ?>" list="lp-codes" maxlength="32" required>
          <datalist id="lp-codes">
            <?php foreach ($codes as $c): ?><option value="<?= $this->e($c) ?>"></option><?php endforeach; ?>
          </datalist>
        </div>
        <div>
          <label for="country"><?= $this->te('load_profiles.country') ?></label>
          <input id="country" type="text" name="country" value="<?= $this->e($form['country']) ?>" maxlength="2" required>
        </div>
        <div>
          <label for="resolution"><?= $this->te('load_profiles.resolution') ?></label>
          <select id="resolution" name="resolution">
            <?php foreach (\App\Service\Import\LoadProfileCsvParser::RESOLUTIONS as $r): ?>
              <option value="<?= $r ?>"<?= $form['resolution'] === $r ? ' selected' : '' ?>><?= $this->e($this->t('load_profiles.resolution_min', ['min' => (string) $r])) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="timezone"><?= $this->te('load_profiles.timezone') ?></label>
          <input id="timezone" type="text" name="timezone" value="<?= $this->e($form['timezone']) ?>" required>
        </div>
        <div>
          <label for="ts_col"><?= $this->te('load_profiles.ts_col') ?></label>
          <input id="ts_col" type="text" name="ts_col" value="<?= $this->e($form['ts_col']) ?>">
        </div>
        <div>
          <label for="value_col"><?= $this->te('load_profiles.value_col') ?></label>
          <input id="value_col" type="text" name="value_col" value="<?= $this->e($form['value_col']) ?>">
        </div>
      </div>
      <p class="hint mt-10"><?= $this->te('load_profiles.format_hint') ?></p>

      <label class="mt-10"><input type="checkbox" name="dry_run" value="1"<?= $form['dry_run'] ? ' checked' : '' ?>> <?= $this->te('load_profiles.dry_run') ?></label>
      <button type="submit"><?= $this->te('load_profiles.submit') ?></button>
    </form>
  </div>
</div>
<script defer src="<?= \App\Support\Assets::url('assets/js/header.js') ?>"></script>
<script defer src="<?= \App\Support\Assets::url('assets/js/lang-switcher.js') ?>"></script>
</body>
</html>
