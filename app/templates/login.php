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
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $this->te('auth.sign_in') ?> — <?= $this->te('app.title') ?></title>
  <!-- Anti-FOUC : pose le thème (clair/sombre) avant le 1er rendu (localStorage > système). -->
  <script nonce="<?= $this->e(\App\Http\SecurityHeaders::nonce()) ?>">(function(){try{var t=localStorage.getItem('theme');if(!t)t=window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';document.documentElement.setAttribute('data-theme',t);}catch(e){document.documentElement.setAttribute('data-theme','light');}})();</script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= \App\Support\Assets::url('assets/css/tokens.css') ?>">
  <link rel="stylesheet" href="<?= \App\Support\Assets::url('assets/css/login.css') ?>">
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

    <p style="text-align:center;margin-top:14px;font-size:.8rem">
      <?php foreach ($available as $loc): ?>
        <a href="?lang=<?= $this->e($loc) ?>&next=<?= rawurlencode($next) ?>"<?= $loc === $this->locale() ? ' style="font-weight:700"' : '' ?>><?= $this->e(strtoupper($loc)) ?></a>
      <?php endforeach; ?>
    </p>
  </form>
</body>
</html>
