<?php

/**
 * Index des guides (#85) : porte d'entrée éditoriale du site, et point de
 * maillage entre la landing, `/stats` et chaque guide.
 *
 * @var list<array{slug: string, title: string, description: string, intro: string,
 *     sections: list<array{h: string, p: list<string>, ul: list<string>}>}> $guides
 * @var list<string>        $available     Locales du sélecteur
 * @var ?string             $discordUrl    Invitation Discord
 * @var ?string             $donateUrl     Soutien au projet
 * @var ?string             $adsenseClient Identifiant éditeur AdSense
 * @var ?\App\Seo\PageMeta  $meta          Métadonnées de référencement (#84)
 */
?>
<!DOCTYPE html>
<html lang="<?= $this->e($this->locale()) ?>">
<head>
<?= $this->partial('_head', [
    'title'         => $this->t('guides.title') . ' — ' . $this->t('app.title'),
    'css'           => ['assets/css/page-standalone.css', 'assets/css/legal.css', 'assets/css/guides.css', 'assets/css/lang-switcher.css'],
    'adsenseClient' => $adsenseClient ?? null,
    'meta'          => $meta ?? null,
]) ?>
</head>
<body>
<div class="wrap">
  <div class="legal-topbar">
    <a href="<?= $this->e($this->url('')) ?>"><?= $this->te('nav.back') ?></a>
    &nbsp;·&nbsp;
    <?= $this->partial('_lang-switcher', ['available' => $available]) ?>
  </div>

  <h1><?= $this->te('guides.title') ?></h1>
  <p class="guide-intro"><?= $this->te('guides.intro') ?></p>

  <div class="guide-list">
    <?php foreach ($guides as $guide): ?>
    <article class="guide-card">
      <h2><a href="<?= $this->e($this->url('guides/' . $guide['slug'])) ?>"><?= $this->e($guide['title']) ?></a></h2>
      <p><?= $this->e($guide['description']) ?></p>
    </article>
    <?php endforeach; ?>
  </div>

  <div class="guide-cta">
    <p><?= $this->te('guides.cta_stats') ?></p>
    <a class="guide-cta-link" href="<?= $this->e($this->url('stats')) ?>"><?= $this->te('nav.stats') ?></a>
  </div>
</div>
<script defer src="<?= \App\Support\Assets::url('assets/js/lang-switcher.js') ?>"></script>
</body>
</html>
