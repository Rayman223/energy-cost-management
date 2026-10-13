<?php

declare(strict_types=1);

/**
 * Commandes communes aux en-têtes applicatif et public.
 *
 * @var list<string> $available
 * @var ?string $discordUrl
 * @var ?string $donateUrl
 */
?>
<div class="header-tools">
  <?= $this->partial('discord-link', ['url' => $discordUrl ?? null]) ?>
  <?= $this->partial('github-link') ?>
  <?= $this->partial('donate-link', ['url' => $donateUrl ?? null]) ?>
  <?= $this->partial('_lang-switcher', ['available' => $available ?? [], 'textual' => true]) ?>
  <button type="button" class="theme-toggle header-action" id="theme-toggle"
          data-theme-light="<?= $this->te('common.theme_light') ?>"
          data-theme-dark="<?= $this->te('common.theme_dark') ?>"><?= $this->te('common.theme') ?></button>
</div>
