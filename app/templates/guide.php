<?php

/**
 * Un guide (#85). Le texte vient de `app/content/guides/<slug>/<locale>.php`
 * via {@see \App\View\GuideContent} ; ce template ne décide que de la mise en
 * forme, et échappe tout — le contenu est du texte, jamais du HTML.
 *
 * Page publique et indexable sans condition : elle ne dépend ni de la base ni
 * d'une session. C'est précisément ce qui lui donne sa valeur pour le crawl.
 *
 * @var array{slug: string, title: string, description: string, intro: string,
 *     sections: list<array{h: string, p: list<string>, ul: list<string>}>} $guide
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
    'title'         => $guide['title'] . ' — ' . $this->t('app.title'),
    'css'           => ['assets/css/page-standalone.css', 'assets/css/legal.css', 'assets/css/guides.css', 'assets/css/lang-switcher.css'],
    'adsenseClient' => $adsenseClient ?? null,
    'meta'          => $meta ?? null,
]) ?>
</head>
<body>
<div class="wrap">
  <div class="legal-topbar">
    <a href="<?= $this->e($this->url('guides')) ?>"><?= $this->te('guides.back') ?></a>
    &nbsp;·&nbsp;
    <?= $this->partial('_lang-switcher', ['available' => $available]) ?>
  </div>

  <h1><?= $this->e($guide['title']) ?></h1>
  <?php if ($guide['intro'] !== ''): ?>
  <p class="guide-intro"><?= $this->e($guide['intro']) ?></p>
  <?php endif; ?>

  <?php foreach ($guide['sections'] as $section): ?>
  <h2><?= $this->e($section['h']) ?></h2>
  <?php foreach ($section['p'] as $paragraph): ?>
  <p><?= $this->e($paragraph) ?></p>
  <?php endforeach; ?>
  <?php if ($section['ul'] !== []): ?>
  <ul>
    <?php foreach ($section['ul'] as $item): ?>
    <li><?= $this->e($item) ?></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <?php endforeach; ?>

  <?php // Maillage interne : un guide doit mener quelque part, sans quoi il ne
        // sert ni au lecteur ni au crawl. ?>
  <div class="guide-cta">
    <p><?= $this->te('guides.cta_stats') ?></p>
    <a class="guide-cta-link" href="<?= $this->e($this->url('stats')) ?>"><?= $this->te('nav.stats') ?></a>
  </div>

  <div class="guide-more">
    <h2><?= $this->te('guides.other_title') ?></h2>
    <ul>
      <?php foreach (\App\View\GuideContent::SLUGS as $slug): ?>
      <?php if ($slug === $guide['slug']) { continue; } ?>
      <?php $other = \App\View\GuideContent::load($slug, $this->locale()); ?>
      <?php if ($other === null) { continue; } ?>
      <li><a href="<?= $this->e($this->url('guides/' . $slug)) ?>"><?= $this->e($other['title']) ?></a></li>
      <?php endforeach; ?>
    </ul>
  </div>

  <div class="legal-footer">
    <a href="<?= $this->e($this->url('')) ?>"><?= $this->te('nav.back') ?></a>
  </div>
</div>
<script defer src="<?= \App\Support\Assets::url('assets/js/lang-switcher.js') ?>"></script>
</body>
</html>
