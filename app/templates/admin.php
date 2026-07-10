<?php

/**
 * Template « Administration » — gestion des membres (rôle, statut).
 *
 * @var string|null $error
 * @var string|null $success
 * @var list<array{id:int,provider:string,display_name:string,role:string,status:string,created_at:string,last_login_at:?string}> $users
 * @var int          $currentId
 * @var list<string> $available
 * @var \App\Service\Import\ImportReport|null $importReport
 */
$csrf = \App\Security\Csrf::field();
?>
<!DOCTYPE html>
<html lang="<?= $this->e($this->locale()) ?>">
<head>
<?= $this->partial('_head', [
    'title' => $this->t('admin.title') . ' — ' . $this->t('app.title'),
    'css'   => ['assets/css/backoffice.css', 'assets/css/admin.css'],
]) ?>
</head>
<body>
<div class="wrap">
  <header>
    <div>
      <div class="logo-text">🛡 <?= $this->te('admin.title') ?></div>
      <div class="logo-sub"><?= $this->te('admin.subtitle') ?></div>
    </div>
    <div>
      <span class="langs"><?php foreach ($available as $loc): ?><a href="?lang=<?= $this->e($loc) ?>"<?= $loc === $this->locale() ? ' class="lang-active"' : '' ?>><?= $this->e(strtoupper($loc)) ?></a><?php endforeach; ?></span>
      &nbsp; <a href="tariffs.php"><?= $this->te('nav.tariffs') ?></a>
      &nbsp; <a href="index.php"><?= $this->te('nav.back_dashboard') ?></a>
    </div>
  </header>

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
              <td class="muted"><?= $this->e($u['last_login_at'] ?? '—') ?></td>
              <td>
                <?php if ($isSelf): ?>
                  <span class="self"><?= $this->te('admin.you') ?></span>
                <?php else: ?>
                  <form class="inline" method="post" action="admin.php">
                    <?= $csrf ?>
                    <input type="hidden" name="action" value="set_role">
                    <input type="hidden" name="user_id" value="<?= $this->e((string) $u['id']) ?>">
                    <input type="hidden" name="role" value="<?= $u['role'] === 'admin' ? 'user' : 'admin' ?>">
                    <button type="submit"><?= $u['role'] === 'admin' ? $this->te('admin.demote') : $this->te('admin.promote') ?></button>
                  </form>
                  <form class="inline" method="post" action="admin.php">
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
    <h2><?= $this->te('import.admin_title') ?></h2>
    <p class="hint"><?= $this->te('import.admin_hint') ?></p>

    <?= $this->partial('_import_report', ['importReport' => $importReport]) ?>

    <form method="post" action="admin.php" enctype="multipart/form-data">
      <?= $csrf ?>
      <input type="hidden" name="action" value="import">
      <label for="target_user_id"><?= $this->te('import.target_user') ?></label>
      <select id="target_user_id" name="target_user_id">
        <?php foreach ($users as $u): ?>
          <option value="<?= $this->e((string) $u['id']) ?>"<?= $u['id'] === $currentId ? ' selected' : '' ?>>
            #<?= $this->e((string) $u['id']) ?> — <?= $this->e($u['display_name'] !== '' ? $u['display_name'] : '—') ?><?= $u['id'] === $currentId ? ' ' . $this->te('admin.you') : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
      <label for="a_energy_type" class="mt-10"><?= $this->te('import.energy_type') ?></label>
      <select id="a_energy_type" name="energy_type">
        <option value="electricity"><?= $this->te('import.type_electricity') ?></option>
        <option value="gas"><?= $this->te('import.type_gas') ?></option>
        <option value="water"><?= $this->te('import.type_water') ?></option>
      </select>
      <label for="a_import_file" class="mt-10"><?= $this->te('import.file') ?></label>
      <input id="a_import_file" type="file" name="import_file" accept=".csv,.json" required>
      <label class="mt-10"><input type="checkbox" name="dry_run" value="1" checked> <?= $this->te('import.dry_run') ?></label>
      <button type="submit"><?= $this->te('import.submit') ?></button>
    </form>
  </div>

  <div class="card">
    <h2><?= $this->te('admin.catalog') ?></h2>
    <p class="hint"><?= $this->te('admin.catalog_hint') ?></p>
    <p><a href="tariffs.php">→ <?= $this->te('nav.tariffs') ?></a></p>
  </div>
</div>
</body>
</html>
