<?php
/**
 * Page d'accueil publique (landing) — présentée aux visiteurs anonymes en mode
 * OIDC. Explique le concept et invite à se connecter. Le bouton « Se connecter »
 * mène à la page /login (bouton fournisseur brandé, #124 ; point d'extension
 * multi-fournisseurs de #122). Voir #127.
 *
 * @var list<string> $available Locales proposées dans le sélecteur de langue.
 * @var ?string      $discordUrl URL d'invitation Discord, ou null si non configurée.
 * @var ?string      $adsenseClient Identifiant éditeur AdSense (#185), null si publicité désactivée.
 */
?>
<!doctype html>
<html lang="<?= $this->e($this->locale()) ?>">
<head>
<?= $this->partial('_head', [
    'title' => $this->t('app.title'),
    'css'   => ['assets/css/welcome.css', 'assets/css/lang-switcher.css'],
    'adsenseClient' => $adsenseClient ?? null,
]) ?>
</head>
<body>
<div class="landing">

  <header class="landing-header">
    <div class="logo">
      <div class="logo-icon">⚡</div>
      <div>
        <div class="logo-text">Manage Energy</div>
        <div class="logo-sub"><?= $this->te('app.title') ?></div>
      </div>
    </div>
    <div class="landing-header-right">
      <?= $this->partial('discord-link', ['url' => $discordUrl ?? null]) ?>
      <?= $this->partial('github-link') ?>
      <?= $this->partial('_lang-switcher', ['available' => $available]) ?>
      <button type="button" class="theme-toggle" id="theme-toggle" aria-label="<?= $this->te('common.theme') ?>">🌙</button>
    </div>
  </header>

  <main>
    <section class="hero">
      <h1><?= $this->te('landing.hero_title') ?></h1>
      <p class="hero-subtitle"><?= $this->te('landing.hero_subtitle') ?></p>
      <a class="cta" href="<?= $this->e($this->url('login')) ?>"><?= $this->te('landing.cta') ?></a>
    </section>

    <section class="features">
      <h2><?= $this->te('landing.features_title') ?></h2>
      <div class="feature-grid">
        <?php
        $features = [
            ['icon' => '🔌', 'title' => 'landing.feature1_title', 'desc' => 'landing.feature1_desc'],
            ['icon' => '💶', 'title' => 'landing.feature2_title', 'desc' => 'landing.feature2_desc'],
            ['icon' => '📊', 'title' => 'landing.feature3_title', 'desc' => 'landing.feature3_desc'],
            ['icon' => '🔒', 'title' => 'landing.feature4_title', 'desc' => 'landing.feature4_desc'],
        ];
        foreach ($features as $feature): ?>
        <article class="feature">
          <div class="feature-icon" aria-hidden="true"><?= $feature['icon'] ?></div>
          <h3><?= $this->te($feature['title']) ?></h3>
          <p><?= $this->te($feature['desc']) ?></p>
        </article>
        <?php endforeach; ?>
      </div>
    </section>
  </main>

  <footer class="landing-footer">
    <a href="<?= $this->e($this->url('terms')) ?>"><?= $this->te('legal.terms') ?></a>
    &middot;
    <a href="<?= $this->e($this->url('privacy')) ?>"><?= $this->te('legal.privacy') ?></a>
    &middot;
    <a href="<?= $this->e($this->url('cookies')) ?>"><?= $this->te('legal.cookies') ?></a>
    &middot;
    <a href="<?= $this->e($this->url('legal-notice')) ?>"><?= $this->te('legal.notice') ?></a>
  </footer>

</div>
<script defer src="<?= \App\Support\Assets::url('assets/js/lang-switcher.js') ?>"></script>
</body>
</html>
