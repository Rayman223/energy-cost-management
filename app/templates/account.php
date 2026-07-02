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
 * @var string      $deviceId
 */
$csrf = \App\Security\Csrf::field();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mon compte — Manage Energy</title>
<script>(function(){try{var t=localStorage.getItem('theme');if(!t)t=window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';document.documentElement.setAttribute('data-theme',t);}catch(e){document.documentElement.setAttribute('data-theme','light');}})();</script>
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
</style>
</head>
<body>
<div class="wrap">
  <header>
    <div>
      <div class="logo-text">⚡ Mon compte</div>
      <div class="logo-sub"><?= $this->e($user?->displayName ?? 'Utilisateur') ?><?= $user?->isAdmin() ? ' · admin' : '' ?></div>
    </div>
    <div><a href="index.php">← Tableau de bord</a></div>
  </header>

  <?php if ($success !== null): ?><div class="banner ok"><?= $this->e($success) ?></div><?php endif; ?>
  <?php if ($error !== null): ?><div class="banner err"><?= $this->e($error) ?></div><?php endif; ?>

  <?php if ($freshToken !== null): ?>
  <div class="card">
    <h2>Nouveau jeton API</h2>
    <p class="hint">Copiez-le maintenant : il ne sera plus jamais affiché.</p>
    <div class="token-secret"><code><?= $this->e($freshToken) ?></code></div>
  </div>
  <?php endif; ?>

  <!-- ── Profil ─────────────────────────────────────────────────────────── -->
  <div class="card">
    <h2>Profil</h2>
    <p class="hint">Pays, devise, fuseau et zone de marché servent aux tarifs et aux prix dynamiques.</p>
    <form method="post">
      <?= $csrf ?>
      <input type="hidden" name="action" value="save_profile">
      <div class="row">
        <div><label>Pays (ISO-2)</label><input type="text" name="country" maxlength="2" value="<?= $this->e($profile['country'] ?? '') ?>" placeholder="BE"></div>
        <div><label>Devise (ISO 4217)</label><input type="text" name="currency" maxlength="3" value="<?= $this->e($profile['currency']) ?>" placeholder="EUR"></div>
      </div>
      <div class="row">
        <div><label>Fuseau horaire</label><input type="text" name="timezone" value="<?= $this->e($profile['timezone']) ?>" placeholder="Europe/Brussels"></div>
        <div><label>Langue</label><input type="text" name="locale" maxlength="5" value="<?= $this->e($profile['locale']) ?>" placeholder="fr"></div>
      </div>
      <label>Zone de marché ENTSO-E (prix dynamiques)</label>
      <input type="text" name="bidding_zone" value="<?= $this->e($profile['bidding_zone'] ?? '') ?>" placeholder="10YBE----------2">
      <button type="submit">Enregistrer le profil</button>
    </form>
  </div>

  <!-- ── Jetons API ─────────────────────────────────────────────────────── -->
  <div class="card">
    <h2>Jetons API</h2>
    <p class="hint">Pour l'envoi automatique des index par un agent (scope <code>ingest</code> uniquement).</p>
    <?php if ($tokens === []): ?>
      <p class="muted">Aucun jeton.</p>
    <?php else: ?>
      <table>
        <tr><th>Nom</th><th>Préfixe</th><th>Dernier usage</th><th>Statut</th><th></th></tr>
        <?php foreach ($tokens as $t): ?>
        <tr>
          <td><?= $this->e($t['name']) ?></td>
          <td><code><?= $this->e($t['prefix']) ?>…</code></td>
          <td class="muted"><?= $this->e($t['last_used_at'] ?? '—') ?></td>
          <td><?= $t['revoked_at'] !== null ? '<span class="muted">révoqué</span>' : 'actif' ?></td>
          <td><?php if ($t['revoked_at'] === null): ?>
            <form method="post" class="inline" onsubmit="return confirm('Révoquer ce jeton ?')">
              <?= $csrf ?>
              <input type="hidden" name="action" value="token_revoke">
              <input type="hidden" name="token_id" value="<?= $t['id'] ?>">
              <button type="submit" class="ghost" style="margin:0;padding:4px 10px">Révoquer</button>
            </form>
          <?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
    <form method="post">
      <?= $csrf ?>
      <input type="hidden" name="action" value="token_create">
      <label>Nouveau jeton — nom</label>
      <input type="text" name="token_name" placeholder="Agent HomeWizard" required>
      <button type="submit">Créer un jeton</button>
    </form>
  </div>

  <!-- ── EnergyID (opt-in BE/NL) ────────────────────────────────────────── -->
  <div class="card">
    <h2>EnergyID <span class="muted">— Belgique / Pays-Bas</span></h2>
    <p class="hint">Synchronise automatiquement vos index vers votre compte EnergyID (facultatif).</p>
    <?php if ($energyId !== null && $energyId['enabled']): ?>
      <p>État : <strong>activé</strong>
        <?php if ($energyId['claimed_at'] !== null): ?>
          · device réclamé le <?= $this->e($energyId['claimed_at']) ?>
        <?php else: ?>
          · <span class="muted">en attente de claim (le code apparaît dans le log de la sync quotidienne)</span>
        <?php endif; ?>
      </p>
      <p class="muted">Device : <code><?= $this->e($energyId['device_id']) ?></code></p>
      <form method="post"><?= $csrf ?><input type="hidden" name="action" value="energyid_disable"><button type="submit" class="ghost">Désactiver</button></form>
    <?php else: ?>
      <p class="muted">Non activé. Device qui sera utilisé : <code><?= $this->e($deviceId) ?></code></p>
      <form method="post"><?= $csrf ?><input type="hidden" name="action" value="energyid_enable"><button type="submit">Activer EnergyID</button></form>
    <?php endif; ?>
  </div>

  <!-- ── Données personnelles (RGPD) ────────────────────────────────────── -->
  <div class="card">
    <h2>Mes données (RGPD)</h2>
    <p class="hint">En utilisant le service, vous acceptez les <a href="terms.php">CGU</a> et la
      <a href="privacy.php">politique de confidentialité</a>.</p>
    <p><a href="account.php?export=1">⬇ Exporter toutes mes données (JSON)</a></p>
    <form method="post" onsubmit="return confirm('Supprimer définitivement votre compte et toutes vos données ?')">
      <?= $csrf ?>
      <input type="hidden" name="action" value="delete_account">
      <label>Pour confirmer, tapez <code>SUPPRIMER</code></label>
      <input type="text" name="confirm" placeholder="SUPPRIMER">
      <button type="submit" class="danger">Supprimer mon compte</button>
    </form>
  </div>
</div>
</body>
</html>
