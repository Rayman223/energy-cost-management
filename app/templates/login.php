<?php
/**
 * Template page de connexion (Basic Auth).
 *
 * @var string       $error
 * @var string       $next
 * @var list<string> $available
 */
?>
<!doctype html>
<html lang="<?= $this->e($this->locale()) ?>">
<head>
<?= $this->partial('_head', [
    'title' => $this->t('auth.sign_in') . ' — ' . $this->t('app.title'),
    'css'   => ['assets/css/login.css', 'assets/css/lang-switcher.css'],
]) ?>
</head>
<body>
  <form class="box" method="post" action="<?= $this->e($this->url('login')) ?>">
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

    <?php /* <div> et non <p> : <details> n'est pas du contenu de phrasé, un <p> serait auto-fermé avant. */ ?>
    <?php /* `next` explicite : après un POST raté il vient de $_POST, pas de l'URL. */ ?>
    <div class="login-langs"><?= $this->partial('_lang-switcher', [
        'available' => $available,
        'direction' => 'up',
        'params'    => ['next' => $next],
    ]) ?></div>
  </form>
<script defer src="<?= \App\Support\Assets::url('assets/js/lang-switcher.js') ?>"></script>
</body>
</html>
