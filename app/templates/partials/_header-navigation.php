<?php

declare(strict_types=1);

/**
 * Navigation commune aux pages applicatives et à l'accueil public.
 *
 * @var bool|null $authenticated
 * @var string|null $current
 * @var ?string $discordUrl
 * @var ?string $donateUrl
 */
$authenticated = !empty($authenticated);
$current = $current ?? '';
?>
<nav class="header-menu header-nav" id="header-menu" aria-label="<?= $this->te('nav.main') ?>">
  <?php if ($authenticated): ?>
  <a class="theme-toggle header-action header-direct<?= $current === 'dashboard' ? ' active' : '' ?>" href="<?= $this->e($this->url()) ?>"<?= $current === 'dashboard' ? ' aria-current="page"' : '' ?>><?= $this->te('nav.dashboard') ?></a>
  <?= $this->partial('_header-group', [
      'group' => 'followup', 'label' => 'nav.followup', 'current' => $current,
      'items' => [
          ['page' => 'meters', 'path' => 'meters', 'label' => 'nav.meters', 'description' => 'nav.description.meters'],
          ['page' => 'batteries', 'path' => 'batteries', 'label' => 'nav.batteries', 'description' => 'nav.description.batteries'],
          ['page' => 'stats', 'path' => 'stats', 'label' => 'nav.stats', 'description' => 'nav.description.stats'],
      ],
  ]) ?>
  <?= $this->partial('_header-group', [
      'group' => 'budget', 'label' => 'nav.budget', 'current' => $current,
      'items' => [
          ['page' => 'tariffs', 'path' => 'tariffs', 'label' => 'nav.tariffs', 'description' => 'nav.description.tariffs'],
          ['page' => 'advances', 'path' => 'advances', 'label' => 'nav.advances', 'description' => 'nav.description.advances'],
          ['page' => 'reconciliation', 'path' => 'reconciliation', 'label' => 'nav.reconciliation', 'description' => 'nav.description.reconciliation'],
      ],
  ]) ?>
  <?php else: ?>
  <a class="theme-toggle header-action header-direct<?= $current === 'stats' ? ' active' : '' ?>" href="<?= $this->e($this->url('stats')) ?>"<?= $current === 'stats' ? ' aria-current="page"' : '' ?>><?= $this->te('nav.stats') ?></a>
  <?php endif; ?>
  <details class="header-dropdown" data-header-group="project">
    <summary><?= $this->te('nav.project') ?></summary>
    <div class="header-dropdown-panel">
      <?= $this->partial('discord-link', ['url' => $discordUrl ?? null]) ?>
      <?= $this->partial('github-link') ?>
      <?= $this->partial('donate-link', ['url' => $donateUrl ?? null]) ?>
    </div>
  </details>
  <?php if ($authenticated): ?>
  <a class="theme-toggle header-action header-cta<?= $current === 'meter-readings' ? ' active' : '' ?>" href="<?= $this->e($this->url('meter-readings')) ?>"<?= $current === 'meter-readings' ? ' aria-current="page"' : '' ?>><?= $this->te('nav.meter_readings') ?></a>
  <?php else: ?>
  <a class="theme-toggle header-action header-cta" href="<?= $this->e($this->url('login')) ?>"><?= $this->te('auth.sign_in') ?></a>
  <?php endif; ?>
</nav>
