<?php

use App\Domain\User;

/**
 * Template « Mon compte ».
 *
 * @var string|null $error
 * @var string|null $success
 * @var string|null $freshToken
 * @var User|null   $user
 * @var array{country:?string,timezone:string,currency:string,bidding_zone:?string,pricing_mode:string,locale:string} $profile
 * @var list<array{id:int,name:string,prefix:string,scopes:string,last_used_at:?string,created_at:string,revoked_at:?string}> $tokens
 * @var array{enabled:bool,device_id:string,claimed_at:?string}|null $energyId
 * @var string       $deviceId
 * @var list<string> $available
 * @var \App\Service\Import\ImportReport|null $importReport
 * @var list<array{id:string,label:string}> $timezoneOptions
 */
$csrf = \App\Security\Csrf::field();
?>
<!DOCTYPE html>
<html lang="<?= $this->e($this->locale()) ?>" data-confirm-title="<?= $this->e($this->t('common.confirm_title')) ?>" data-confirm-ok="<?= $this->e($this->t('common.confirm')) ?>" data-confirm-cancel="<?= $this->e($this->t('common.cancel')) ?>">
<head>
<?= $this->partial('_head', [
    'title' => $this->t('account.title') . ' — ' . $this->t('app.title'),
    'css'   => ['assets/css/confirm.css', 'assets/css/backoffice.css', 'assets/css/account.css'],
]) ?>
</head>
<body>
<div class="wrap">
  <header>
    <div>
      <div class="logo-text">⚡ <?= $this->te('account.title') ?></div>
      <div class="logo-sub"><?= $this->e($user?->displayName ?? '') ?><?= $user?->isAdmin() ? ' · ' . $this->te('account.admin') : '' ?></div>
    </div>
    <div>
      <span class="langs"><?php foreach ($available as $loc): ?><a href="?lang=<?= $this->e($loc) ?>"<?= $loc === $this->locale() ? ' class="lang-active"' : '' ?>><?= $this->e(\App\I18n\Locale::displayName($loc)) ?></a><?php endforeach; ?></span>
      &nbsp; <a href="<?= $this->url() ?>"><?= $this->te('nav.back_dashboard') ?></a>
    </div>
  </header>

  <?php if ($success !== null): ?><div class="banner ok"><?= $this->e($success) ?></div><?php endif; ?>
  <?php if ($error !== null): ?><div class="banner err"><?= $this->e($error) ?></div><?php endif; ?>

  <?php if ($freshToken !== null): ?>
  <div class="card">
    <h2><?= $this->te('account.token_fresh_title') ?></h2>
    <p class="hint"><?= $this->te('account.token_fresh_hint') ?></p>
    <div class="token-secret"><code><?= $this->e($freshToken) ?></code></div>
  </div>
  <?php endif; ?>

  <!-- ── Profil ─────────────────────────────────────────────────────────── -->
  <div class="card">
    <h2><?= $this->te('account.profile') ?></h2>
    <p class="hint"><?= $this->te('account.profile_hint') ?></p>
    <form method="post">
      <?= $csrf ?>
      <input type="hidden" name="action" value="save_profile">
      <div class="row">
        <div><label><?= $this->te('account.country') ?></label><input type="text" name="country" maxlength="2" value="<?= $this->e($profile['country'] ?? '') ?>" placeholder="BE"></div>
        <div><label><?= $this->te('account.currency') ?></label><input type="text" name="currency" maxlength="3" value="<?= $this->e($profile['currency']) ?>" placeholder="EUR"></div>
      </div>
      <div class="row">
        <div>
          <label><?= $this->te('account.timezone') ?></label>
          <select name="timezone">
            <?php foreach ($timezoneOptions as $timezoneOption): ?>
              <?php $tzId = $timezoneOption['id']; ?>
              <option value="<?= $this->e($tzId) ?>"<?= $tzId === $profile['timezone'] ? ' selected' : '' ?>><?= $this->e($timezoneOption['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label><?= $this->te('account.locale') ?></label>
          <?php
          // Garantit que la locale courante reste sélectionnable même si elle
          // n'est plus dans la liste disponible, sinon le <select> retomberait
          // silencieusement sur la 1re option au prochain enregistrement.
          $localeOptions = $available;
          if (!in_array($profile['locale'], $localeOptions, true)) {
              $localeOptions[] = $profile['locale'];
          }
          ?>
          <select name="locale">
            <?php foreach ($localeOptions as $loc): ?>
              <option value="<?= $this->e($loc) ?>"<?= $loc === $profile['locale'] ? ' selected' : '' ?>><?= $this->e(\App\I18n\Locale::displayName($loc)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <label><?= $this->te('account.bidding_zone') ?></label>
      <input type="text" name="bidding_zone" value="<?= $this->e($profile['bidding_zone'] ?? '') ?>" placeholder="10YBE----------2">
      <?php $pricingMode = $profile['pricing_mode'] ?? 'fixed'; ?>
      <label><?= $this->te('account.pricing_mode') ?></label>
      <select name="pricing_mode">
        <option value="fixed"<?= $pricingMode === 'fixed' ? ' selected' : '' ?>><?= $this->te('account.pricing_mode_fixed') ?></option>
        <option value="dynamic_hourly"<?= $pricingMode === 'dynamic_hourly' ? ' selected' : '' ?>><?= $this->te('account.pricing_mode_dynamic_hourly') ?></option>
        <option value="dynamic_quarter"<?= $pricingMode === 'dynamic_quarter' ? ' selected' : '' ?>><?= $this->te('account.pricing_mode_dynamic_quarter') ?></option>
      </select>
      <p class="hint"><?= $this->te('account.pricing_mode_hint') ?></p>
      <button type="submit"><?= $this->te('account.save_profile') ?></button>
    </form>
  </div>

  <!-- ── Jetons API ─────────────────────────────────────────────────────── -->
  <div class="card">
    <h2><?= $this->te('account.tokens') ?></h2>
    <p class="hint"><?= $this->te('account.tokens_hint') ?></p>
    <?php if ($tokens === []): ?>
      <p class="muted"><?= $this->te('account.tokens_none') ?></p>
    <?php else: ?>
      <table>
        <tr><th><?= $this->te('account.token_name') ?></th><th><?= $this->te('account.token_prefix') ?></th><th><?= $this->te('account.token_last') ?></th><th><?= $this->te('account.token_status') ?></th><th></th></tr>
        <?php foreach ($tokens as $t): ?>
        <tr>
          <td><?= $this->e($t['name']) ?></td>
          <td><code><?= $this->e($t['prefix']) ?>…</code></td>
          <td class="muted"><?= $this->e($t['last_used_at'] ?? '—') ?></td>
          <td><?= $t['revoked_at'] !== null ? '<span class="muted">' . $this->te('account.token_revoked') . '</span>' : $this->te('account.token_active') ?></td>
          <td><?php if ($t['revoked_at'] === null): ?>
            <form method="post" class="inline" data-confirm="<?= $this->e($this->t('account.token_revoke_confirm')) ?>" data-confirm-ok="<?= $this->e($this->t('account.token_revoke')) ?>" data-confirm-danger>
              <?= $csrf ?>
              <input type="hidden" name="action" value="token_revoke">
              <input type="hidden" name="token_id" value="<?= $t['id'] ?>">
              <button type="submit" class="ghost btn-compact"><?= $this->te('account.token_revoke') ?></button>
            </form>
          <?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
    <form method="post">
      <?= $csrf ?>
      <input type="hidden" name="action" value="token_create">
      <label><?= $this->te('account.token_new') ?></label>
      <input type="text" name="token_name" placeholder="Agent HomeWizard" required>
      <button type="submit"><?= $this->te('account.token_create') ?></button>
    </form>
  </div>

  <!-- ── EnergyID (opt-in BE/NL) ────────────────────────────────────────── -->
  <div class="card">
    <h2><?= $this->te('account.energyid') ?> <span class="muted"><?= $this->te('account.energyid_region') ?></span></h2>
    <p class="hint"><?= $this->te('account.energyid_hint') ?></p>
    <?php if ($energyId !== null && $energyId['enabled']): ?>
      <p><?= $this->te('account.token_status') ?> : <strong><?= $this->te('account.energyid_on') ?></strong>
        <?php if ($energyId['claimed_at'] !== null): ?>
          · <?= $this->te('account.energyid_claimed', ['date' => $energyId['claimed_at']]) ?>
        <?php else: ?>
          · <span class="muted"><?= $this->te('account.energyid_pending') ?></span>
        <?php endif; ?>
      </p>
      <p class="muted"><?= $this->te('account.energyid_device') ?> : <code><?= $this->e($energyId['device_id']) ?></code></p>
      <form method="post"><?= $csrf ?><input type="hidden" name="action" value="energyid_disable"><button type="submit" class="ghost"><?= $this->te('account.energyid_disable') ?></button></form>
    <?php else: ?>
      <p class="muted"><?= $this->te('account.energyid_off_hint') ?> <code><?= $this->e($deviceId) ?></code></p>
      <form method="post"><?= $csrf ?><input type="hidden" name="action" value="energyid_enable"><button type="submit"><?= $this->te('account.energyid_enable') ?></button></form>
    <?php endif; ?>
  </div>

  <!-- ── Import en masse (self-service : mes propres données) ───────────── -->
  <div class="card">
    <h2><?= $this->te('import.title') ?></h2>
    <p class="hint"><?= $this->te('import.hint') ?></p>

    <?= $this->partial('_import_report', ['importReport' => $importReport]) ?>

    <form method="post" enctype="multipart/form-data">
      <?= $csrf ?>
      <input type="hidden" name="action" value="import">
      <div class="row">
        <div>
          <label for="energy_type"><?= $this->te('import.energy_type') ?></label>
          <select id="energy_type" name="energy_type">
            <option value="electricity"><?= $this->te('import.type_electricity') ?></option>
            <option value="gas"><?= $this->te('import.type_gas') ?></option>
            <option value="water"><?= $this->te('import.type_water') ?></option>
          </select>
        </div>
        <div>
          <label for="import_file"><?= $this->te('import.file') ?></label>
          <input id="import_file" type="file" name="import_file" accept=".csv,.json" required>
        </div>
      </div>
      <div class="row">
        <div>
          <label for="ts_col"><?= $this->te('import.ts_col') ?></label>
          <input id="ts_col" type="text" name="ts_col" placeholder="timestamp">
        </div>
        <div>
          <label for="value_col"><?= $this->te('import.value_col') ?></label>
          <input id="value_col" type="text" name="value_col" placeholder="counter_m3">
        </div>
      </div>
      <label class="mt-12"><input type="checkbox" name="dry_run" value="1" checked> <?= $this->te('import.dry_run') ?></label>
      <button type="submit"><?= $this->te('import.submit') ?></button>
    </form>
  </div>

  <!-- ── Données personnelles (RGPD) ────────────────────────────────────── -->
  <div class="card">
    <h2><?= $this->te('account.rgpd') ?></h2>
    <p class="hint"><?= $this->te('account.rgpd_hint') ?>
       (<a href="<?= $this->url('terms') ?>"><?= $this->te('legal.terms') ?></a> · <a href="<?= $this->url('privacy') ?>"><?= $this->te('legal.privacy') ?></a>)</p>
    <p><a href="<?= $this->url('account') ?>?export=1"><?= $this->te('account.export') ?></a></p>
    <form method="post" data-confirm="<?= $this->e($this->t('account.delete_js_confirm')) ?>" data-confirm-ok="<?= $this->e($this->t('account.delete')) ?>" data-confirm-danger>
      <?= $csrf ?>
      <input type="hidden" name="action" value="delete_account">
      <label><?= $this->te('account.delete_confirm_label', ['keyword' => $this->t('account.delete_keyword')]) ?></label>
      <input type="text" name="confirm" placeholder="<?= $this->e($this->t('account.delete_keyword')) ?>">
      <button type="submit" class="danger"><?= $this->te('account.delete') ?></button>
      <p class="hint"><?= $this->te('account.delete_public_templates_kept') ?></p>
    </form>
  </div>
</div>
<script defer src="<?= \App\Support\Assets::url('assets/js/confirm.js') ?>"></script>
</body>
</html>
