<?php
/**
 * Template page de connexion OpenID Connect : bouton « Se connecter avec
 * <fournisseur> » qui pointe sur la route /auth/login (laquelle redirige vers
 * l'IdP). Aucun mot de passe saisi ici.
 *
 * @var string       $next      Cible interne post-connexion (déjà validée).
 * @var string       $provider  Libellé court du fournisseur (ex. « google »).
 * @var string       $loginUrl  URL de la route /auth/login (préfixée racine app).
 * @var list<string> $available Locales proposées dans le sélecteur de langue.
 */

$providerLabel = $provider === '' ? 'OpenID Connect' : ucfirst($provider);
$signInHref = $loginUrl . '?next=' . rawurlencode($next);
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

    <a class="btn-provider" href="<?= $this->e($signInHref) ?>">
      <?php if ($provider === 'google'): ?>
        <svg class="btn-provider-icon" width="18" height="18" viewBox="0 0 18 18" aria-hidden="true" focusable="false">
          <path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.92c1.71-1.57 2.68-3.88 2.68-6.62z"/>
          <path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.92-2.26c-.81.54-1.84.86-3.04.86-2.34 0-4.32-1.58-5.03-3.7H.96v2.33A9 9 0 0 0 9 18z"/>
          <path fill="#FBBC05" d="M3.97 10.72a5.4 5.4 0 0 1 0-3.44V4.95H.96a9 9 0 0 0 0 8.1l3.01-2.33z"/>
          <path fill="#EA4335" d="M9 3.58c1.32 0 2.5.45 3.44 1.35l2.58-2.58C13.47.9 11.43 0 9 0A9 9 0 0 0 .96 4.95l3.01 2.33C4.68 5.16 6.66 3.58 9 3.58z"/>
        </svg>
      <?php endif; ?>
      <span><?= $this->te('auth.signin_with', ['provider' => $providerLabel]) ?></span>
    </a>

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
