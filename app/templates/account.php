<?php

use App\Domain\User;

/**
 * Template « Mon compte ».
 *
 * @var string|null $error
 * @var string|null $success
 * @var string|null $freshToken
 * @var User|null   $user
 * @var array{country:?string,timezone:string,currency:string,bidding_zone:?string,locale:string} $profile
 * @var list<array{id:int,name:string,prefix:string,scopes:string,last_used_at:?string,created_at:string,revoked_at:?string}> $tokens
 * @var array{enabled:bool,device_id:string,claimed_at:?string}|null $energyId
 * @var string       $deviceId
 * @var list<string> $available
 * @var \App\Service\Import\ImportReport|null $importReport
 */
$csrf = \App\Security\Csrf::field();
?>
<!DOCTYPE html>
<html lang="<?= $this->e($this->locale()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $this->te('account.title') ?> — <?= $this->te('app.title') ?></title>
<script nonce="<?= $this->e(\App\Http\SecurityHeaders::nonce()) ?>">(function(){try{var t=localStorage.getItem('theme');if(!t)t=window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';document.documentElement.setAttribute('data-theme',t);}catch(e){document.documentElement.setAttribute('data-theme','light');}})();</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Syne:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= \App\Support\Assets::url('assets/css/tokens.css') ?>">
<style>
  body { font-family: 'Syne', system-ui, sans-serif; background: var(--bg); color: var(--text); margin: 0; }
  .wrap { max-width: 860px; margin: 0 auto; padding: 24px 18px 64px; }
  header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
  .logo-text { font-weight: 800; font-size: 1.2rem; }
  .logo-sub { color: var(--muted); font-size: .78rem; }
  a { color: var(--blue); }
  .card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 20px; margin-bottom: 20px; }
  .card h2 { margin: 0 0 4px; font-size: 1.05rem; }
  .card p.hint { color: var(--muted); font-size: .82rem; margin: 0 0 14px; }
  label { display: block; font-size: .82rem; color: var(--muted); margin: 10px 0 4px; }
  input[type=text], select { width: 100%; box-sizing: border-box; padding: 8px 10px; border-radius: 8px;
    border: 1px solid var(--border); background: var(--bg); color: var(--text); font-family: 'Space Mono', monospace; }
  .row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
  button { cursor: pointer; padding: 9px 16px; border-radius: 8px; border: 1px solid var(--border);
    background: var(--blue); color: #fff; font-weight: 600; margin-top: 14px; }
  button.ghost { background: transparent; color: var(--text); }
  button.danger { background: var(--red, #d43); }
  .banner { padding: 10px 14px; border-radius: 8px; margin-bottom: 18px; font-size: .9rem; }
  .banner.ok { background: rgba(40,180,90,.15); color: var(--green, #2b8); }
  .banner.err { background: rgba(210,60,60,.15); color: var(--red, #d43); }
  table { width: 100%; border-collapse: collapse; font-size: .84rem; }
  th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid var(--border); }
  code { font-family: 'Space Mono', monospace; background: var(--bg); padding: 2px 6px; border-radius: 5px; }
  .token-secret { word-break: break-all; padding: 12px; border: 1px dashed var(--blue); border-radius: 8px; }
  .muted { color: var(--muted); }
  .inline { display: inline; }
  .langs a { margin-left: 8px; font-size: .8rem; }
</style>
</head>
<body>
<div class="wrap">
  <header>
    <div>
      <div class="logo-text">⚡ <?= $this->te('account.title') ?></div>
      <div class="logo-sub"><?= $this->e($user?->displayName ?? '') ?><?= $user?->isAdmin() ? ' · ' . $this->te('account.admin') : '' ?></div>
    </div>
    <div>
      <span class="langs"><?php foreach ($available as $loc): ?><a href="?lang=<?= $this->e($loc) ?>"<?= $loc === $this->locale() ? ' style="font-weight:700"' : '' ?>><?= $this->e(strtoupper($loc)) ?></a><?php endforeach; ?></span>
      &nbsp; <a href="index.php"><?= $this->te('nav.back_dashboard') ?></a>
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
        <div><label><?= $this->te('account.timezone') ?></label><input type="text" name="timezone" value="<?= $this->e($profile['timezone']) ?>" placeholder="Europe/Brussels"></div>
        <div><label><?= $this->te('account.locale') ?></label><input type="text" name="locale" maxlength="5" value="<?= $this->e($profile['locale']) ?>" placeholder="fr"></div>
      </div>
      <label><?= $this->te('account.bidding_zone') ?></label>
      <input type="text" name="bidding_zone" value="<?= $this->e($profile['bidding_zone'] ?? '') ?>" placeholder="10YBE----------2">
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
            <form method="post" class="inline" data-confirm="<?= $this->e($this->t('account.token_revoke_confirm')) ?>">
              <?= $csrf ?>
              <input type="hidden" name="action" value="token_revoke">
              <input type="hidden" name="token_id" value="<?= $t['id'] ?>">
              <button type="submit" class="ghost" style="margin:0;padding:4px 10px"><?= $this->te('account.token_revoke') ?></button>
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
      <label style="margin-top:12px"><input type="checkbox" name="dry_run" value="1" checked> <?= $this->te('import.dry_run') ?></label>
      <button type="submit"><?= $this->te('import.submit') ?></button>
    </form>
  </div>

  <!-- ── Données personnelles (RGPD) ────────────────────────────────────── -->
  <div class="card">
    <h2><?= $this->te('account.rgpd') ?></h2>
    <p class="hint"><?= $this->te('account.rgpd_hint') ?>
       (<a href="terms.php"><?= $this->te('legal.terms') ?></a> · <a href="privacy.php"><?= $this->te('legal.privacy') ?></a>)</p>
    <p><a href="account.php?export=1"><?= $this->te('account.export') ?></a></p>
    <form method="post" data-confirm="<?= $this->e($this->t('account.delete_js_confirm')) ?>">
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
