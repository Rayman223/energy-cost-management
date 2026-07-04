<?php

/**
 * Template « Administration » — gestion des membres (rôle, statut).
 *
 * @var string|null $error
 * @var string|null $success
 * @var list<array{id:int,provider:string,display_name:string,role:string,status:string,created_at:string,last_login_at:?string}> $users
 * @var int          $currentId
 * @var list<string> $available
 */
$csrf = \App\Security\Csrf::field();
?>
<!DOCTYPE html>
<html lang="<?= $this->e($this->locale()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $this->te('admin.title') ?> — <?= $this->te('app.title') ?></title>
<script>(function(){try{var t=localStorage.getItem('theme');if(!t)t=window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';document.documentElement.setAttribute('data-theme',t);}catch(e){document.documentElement.setAttribute('data-theme','light');}})();</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Syne:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= \App\Support\Assets::url('assets/css/tokens.css') ?>">
<style>
  body { font-family: 'Syne', system-ui, sans-serif; background: var(--bg); color: var(--text); margin: 0; }
  .wrap { max-width: 960px; margin: 0 auto; padding: 24px 18px 64px; }
  header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; gap: 12px; flex-wrap: wrap; }
  .logo-text { font-weight: 800; font-size: 1.2rem; }
  .logo-sub { color: var(--muted); font-size: .78rem; }
  a { color: var(--blue); }
  .card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 20px; margin-bottom: 20px; }
  .card h2 { margin: 0 0 4px; font-size: 1.05rem; }
  .card p.hint { color: var(--muted); font-size: .82rem; margin: 0 0 14px; }
  .banner { padding: 10px 14px; border-radius: 8px; margin-bottom: 18px; font-size: .9rem; }
  .banner.ok { background: rgba(40,180,90,.15); color: var(--green, #2b8); }
  .banner.err { background: rgba(210,60,60,.15); color: var(--red, #d43); }
  .table-scroll { overflow-x: auto; }
  table { width: 100%; border-collapse: collapse; font-size: .84rem; min-width: 640px; }
  th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--border); vertical-align: middle; }
  th { color: var(--muted); font-weight: 600; }
  code { font-family: 'Space Mono', monospace; background: var(--bg); padding: 2px 6px; border-radius: 5px; }
  .pill { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: .74rem; font-weight: 700; }
  .pill.admin { background: rgba(80,120,255,.18); color: var(--blue); }
  .pill.blocked { background: rgba(210,60,60,.15); color: var(--red, #d43); }
  .pill.active { background: rgba(40,180,90,.15); color: var(--green, #2b8); }
  .muted { color: var(--muted); }
  button { cursor: pointer; padding: 6px 12px; border-radius: 8px; border: 1px solid var(--border);
    background: transparent; color: var(--text); font-weight: 600; font-size: .78rem; }
  button.danger { border-color: var(--red, #d43); color: var(--red, #d43); }
  form.inline { display: inline; margin: 0; }
  .self { color: var(--muted); font-style: italic; font-size: .78rem; }
  .langs a { margin-left: 8px; font-size: .8rem; }
</style>
</head>
<body>
<div class="wrap">
  <header>
    <div>
      <div class="logo-text">🛡 <?= $this->te('admin.title') ?></div>
      <div class="logo-sub"><?= $this->te('admin.subtitle') ?></div>
    </div>
    <div>
      <span class="langs"><?php foreach ($available as $loc): ?><a href="?lang=<?= $this->e($loc) ?>"<?= $loc === $this->locale() ? ' style="font-weight:700"' : '' ?>><?= $this->e(strtoupper($loc)) ?></a><?php endforeach; ?></span>
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
    <h2><?= $this->te('admin.catalog') ?></h2>
    <p class="hint"><?= $this->te('admin.catalog_hint') ?></p>
    <p><a href="tariffs.php">→ <?= $this->te('nav.tariffs') ?></a></p>
  </div>
</div>
</body>
</html>
