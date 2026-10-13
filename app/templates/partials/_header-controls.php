<?php

declare(strict_types=1);

/**
 * Langue, thème et compte restent visibles quand la navigation mobile est fermée.
 *
 * @var list<string>|null $available
 * @var bool|null $authenticated
 * @var bool|null $isAdmin
 * @var string|null $current
 */
$authenticated = !empty($authenticated);
$accountItems = [
    ['page' => 'account', 'path' => 'account', 'label' => 'nav.account', 'description' => 'nav.description.account'],
];
if ($authenticated && !empty($isAdmin)) {
    $accountItems[] = ['page' => 'admin', 'path' => 'admin', 'label' => 'admin.title', 'description' => 'nav.description.admin'];
}
?>
<div class="header-controls">
  <?= $this->partial('_lang-switcher', ['available' => $available ?? [], 'textual' => true]) ?>
  <button type="button" class="theme-toggle header-action header-theme-switch" id="theme-toggle" role="switch" aria-checked="false"
          data-theme-light="<?= $this->te('common.theme_light') ?>"
          data-theme-dark="<?= $this->te('common.theme_dark') ?>">
    <span><?= $this->te('common.dark_theme') ?></span><span class="header-switch-track" aria-hidden="true"></span>
  </button>
  <?php if ($authenticated): ?>
  <?= $this->partial('_header-group', [
      'group' => 'account', 'label' => 'nav.account', 'current' => $current ?? '',
      'items' => $accountItems, 'logout' => true,
  ]) ?>
  <?php endif; ?>
  <button type="button" class="theme-toggle header-action header-menu-toggle" data-header-toggle aria-controls="header-menu" aria-expanded="true" hidden><?= $this->te('nav.menu') ?></button>
</div>
