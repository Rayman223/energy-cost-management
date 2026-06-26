<?php
/**
 * Template page de connexion.
 *
 * @var string                                                              $lang
 * @var array{title:string,heading:string,username:string,password:string,submit:string,error:string} $t
 * @var string                                                              $error
 * @var string                                                              $next
 * @var string                                                              $basePath
 */
?>
<!doctype html>
<html lang="<?= $this->e($lang) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $this->e($t['title']) ?></title>
  <link rel="stylesheet" href="<?= \App\Support\Assets::url('assets/css/login.css') ?>">
</head>
<body>
  <form class="box" method="post" action="<?= $this->e($basePath . '/login.php') ?>">
    <h1><?= $this->e($t['heading']) ?></h1>

    <input type="hidden" name="next" value="<?= $this->e($next) ?>">
    <input type="hidden" name="lang" value="<?= $this->e($lang) ?>">

    <label for="username"><?= $this->e($t['username']) ?></label>
    <input id="username" name="username" autocomplete="username" required>

    <label for="password"><?= $this->e($t['password']) ?></label>
    <input id="password" type="password" name="password" autocomplete="current-password" required>

    <button type="submit"><?= $this->e($t['submit']) ?></button>

    <?php if ($error !== ''): ?>
      <p class="error"><?= $this->e($error) ?></p>
    <?php endif; ?>
  </form>
</body>
</html>
