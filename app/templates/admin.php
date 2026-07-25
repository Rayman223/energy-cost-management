<?php

/**
 * Template « Administration » — gestion des membres (rôle, statut).
 *
 * @var string|null $error
 * @var string|null $success
 * @var list<array{id:int,provider:string,display_name:string,role:string,status:string,created_at:string,last_login_at:?string}> $users
 * @var int          $currentId
 * @var list<string> $available
 * @var ?string      $discordUrl
 * @var ?string      $adsenseClient Identifiant éditeur AdSense (#185), null si publicité désactivée.
 */
$csrf = \App\Security\Csrf::field();
?>
<!DOCTYPE html>
<html lang="<?= $this->e($this->locale()) ?>">
<head>
<?= $this->partial('_head', [
    'title' => $this->t('admin.title') . ' — ' . $this->t('app.title'),
    'css'   => ['assets/css/app-header.css', 'assets/css/backoffice.css', 'assets/css/admin.css'],
    'adsenseClient' => $adsenseClient ?? null,
]) ?>
</head>
<body>
<div class="wrap">
  <?= $this->partial('_header', [
      'subtitle'    => $this->t('admin.subtitle'),
      'current'     => 'admin',
      'isAdmin'     => true, // route réservée aux admins (admin.php)
      'discordUrl'  => $discordUrl ?? null,
      'available'   => $available,
      'timezone'    => $timezone ?? null,
  ]) ?>

  <?php if ($error !== null): ?><div class="banner err"><?= $this->e($error) ?></div><?php endif; ?>
  <?php if ($success !== null): ?><div class="banner ok"><?= $this->e($success) ?></div><?php endif; ?>

  <div class="card">
    <h2><?= $this->te('admin.members') ?></h2>
    <p class="hint"><?= $this->te('admin.members_hint') ?></p>
    <div class="table-scroll">
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th><?= $this->te('admin.col_name') ?></th>
            <th><?= $this->te('admin.col_provider') ?></th>
            <th><?= $this->te('admin.col_role') ?></th>
            <th><?= $this->te('admin.col_status') ?></th>
            <th><?= $this->te('admin.col_last_login') ?></th>
            <th><?= $this->te('admin.col_actions') ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u): ?>
            <?php $isSelf = $u['id'] === $currentId; ?>
            <tr>
              <td><code><?= $this->e((string) $u['id']) ?></code></td>
              <td><?= $this->e($u['display_name'] !== '' ? $u['display_name'] : '—') ?></td>
              <td class="muted"><?= $this->e($u['provider'] !== '' ? $u['provider'] : '—') ?></td>
              <td>
                <?php if ($u['role'] === 'admin'): ?><span class="pill admin"><?= $this->te('admin.role_admin') ?></span>
                <?php else: ?><span class="muted"><?= $this->te('admin.role_user') ?></span><?php endif; ?>
              </td>
              <td>
                <?php if ($u['status'] === 'blocked'): ?><span class="pill blocked"><?= $this->te('admin.status_blocked') ?></span>
                <?php else: ?><span class="pill active"><?= $this->te('admin.status_active') ?></span><?php endif; ?>
              </td>
              <td class="muted"><?= $this->localDateTime($u['last_login_at'] ?? null, $timezone ?? 'UTC') ?></td>
              <td>
                <?php if ($isSelf): ?>
                  <span class="self"><?= $this->te('admin.you') ?></span>
                <?php else: ?>
                  <form class="inline" method="post" action="<?= $this->url('admin') ?>">
                    <?= $csrf ?>
                    <input type="hidden" name="action" value="set_role">
                    <input type="hidden" name="user_id" value="<?= $this->e((string) $u['id']) ?>">
                    <input type="hidden" name="role" value="<?= $u['role'] === 'admin' ? 'user' : 'admin' ?>">
                    <button type="submit"><?= $u['role'] === 'admin' ? $this->te('admin.demote') : $this->te('admin.promote') ?></button>
                  </form>
                  <form class="inline" method="post" action="<?= $this->url('admin') ?>">
                    <?= $csrf ?>
                    <input type="hidden" name="action" value="set_status">
                    <input type="hidden" name="user_id" value="<?= $this->e((string) $u['id']) ?>">
                    <input type="hidden" name="status" value="<?= $u['status'] === 'blocked' ? 'active' : 'blocked' ?>">
                    <button type="submit" class="<?= $u['status'] === 'blocked' ? '' : 'danger' ?>"><?= $u['status'] === 'blocked' ? $this->te('admin.unblock') : $this->te('admin.block') ?></button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <h2><?= $this->te('admin.catalog') ?></h2>
    <p class="hint"><?= $this->te('admin.catalog_hint') ?></p>
    <p><a href="<?= $this->url('tariffs') ?>">→ <?= $this->te('nav.tariffs') ?></a></p>
  </div>
</div>
<script defer src="<?= \App\Support\Assets::url('assets/js/header.js') ?>"></script>
</body>
</html>
