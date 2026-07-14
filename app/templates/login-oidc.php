<?php
/**
 * Template page de connexion OpenID Connect : un bouton « Se connecter avec
 * <fournisseur> » par IdP activé, chacun pointant sur /auth/login?provider=…
 * (laquelle redirige vers l'IdP). Aucun mot de passe saisi ici.
 *
 * @var string                                          $next      Cible interne post-connexion (déjà validée).
 * @var list<array{key: string, label: string, href: string}> $providers Fournisseurs activés (clé, libellé, URL).
 * @var list<string>                                    $available Locales proposées dans le sélecteur de langue.
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
  <div class="box">
    <h1><?= $this->te('auth.heading') ?></h1>

    <?php if ($providers === []): ?>
      <p><?= $this->te('auth.no_provider') ?></p>
    <?php else: ?>
      <?php if (count($providers) > 1): ?>
        <p class="login-choose"><?= $this->te('auth.choose_provider') ?></p>
      <?php endif; ?>
      <?php foreach ($providers as $p): ?>
        <a class="btn-provider" href="<?= $this->e($p['href']) ?>">
          <?= $this->partial('oidc-provider-icon', ['key' => $p['key']]) ?>
          <span><?= $this->te('auth.signin_with', ['provider' => $p['label']]) ?></span>
        </a>
      <?php endforeach; ?>
    <?php endif; ?>

    <p class="login-legal">
      <?= $this->te('account.rgpd_hint') ?>
      <br>
      <a href="<?= $this->e($this->url('terms')) ?>"><?= $this->te('legal.terms') ?></a>
      &middot;
      <a href="<?= $this->e($this->url('privacy')) ?>"><?= $this->te('legal.privacy') ?></a>
    </p>

    <p class="login-langs">
      <?php foreach ($available as $loc): ?>
        <a href="?lang=<?= $this->e($loc) ?>&amp;next=<?= rawurlencode($next) ?>"<?= $loc === $this->locale() ? ' class="lang-active"' : '' ?>><?= $this->e(strtoupper($loc)) ?></a>
      <?php endforeach; ?>
    </p>
  </div>
</body>
</html>
