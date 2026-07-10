<?php
/**
 * Template page de connexion (Basic Auth).
 *
 * @var string       $error
 * @var string       $next
 * @var string       $basePath
 * @var list<string> $available
 */
?>
<!doctype html>
<html lang="<?= $this->e($this->locale()) ?>">
<head>
<?= $this->partial('_head', [
    'title' => $this->t('auth.sign_in') . ' — ' . $this->t('app.title'),
    'css'   => ['assets/css/login.css'],
]) ?>
</head>
<body>
  <form class="box" method="post" action="<?= $this->e($basePath . '/login.php') ?>">
    <h1><?= $this->te('auth.heading') ?></h1>

    <input type="hidden" name="next" value="<?= $this->e($next) ?>">
    <?= \App\Security\Csrf::field() ?>

    <label for="username"><?= $this->te('auth.username') ?></label>
    <input id="username" name="username" autocomplete="username" required>

    <label for="password"><?= $this->te('auth.password') ?></label>
    <input id="password" type="password" name="password" autocomplete="current-password" required>

    <button type="submit"><?= $this->te('auth.sign_in') ?></button>

    <?php if ($error !== ''): ?>
      <p class="error"><?= $this->e($error) ?></p>
    <?php endif; ?>

    <p class="login-langs">
      <?php foreach ($available as $loc): ?>
        <a href="?lang=<?= $this->e($loc) ?>&next=<?= rawurlencode($next) ?>"<?= $loc === $this->locale() ? ' class="lang-active"' : '' ?>><?= $this->e(strtoupper($loc)) ?></a>
      <?php endforeach; ?>
    </p>
  </form>
</body>
</html>
