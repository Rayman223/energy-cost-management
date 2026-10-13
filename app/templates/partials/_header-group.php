<?php

declare(strict_types=1);

/**
 * Menu natif partagé entre les catégories et le compte, utilisable sans JS.
 *
 * @var string $group
 * @var string $label Clé de traduction du titre.
 * @var list<array{page: string, path: string, label: string, description: string}> $items
 * @var string|null $current
 * @var bool|null $logout
 */
$current = $current ?? '';
$active = in_array($current, array_column($items, 'page'), true);
?>
<details class="header-dropdown<?= $active ? ' current' : '' ?><?= $group === 'account' ? ' header-account' : '' ?>" data-header-group="<?= $this->e($group) ?>">
  <summary><?= $this->te($label) ?></summary>
  <div class="header-dropdown-panel">
    <?php foreach ($items as $item): ?>
    <a class="theme-toggle header-action<?= $item['page'] === $current ? ' active' : '' ?>" href="<?= $this->e($this->url($item['path'])) ?>"<?= $item['page'] === $current ? ' aria-current="page"' : '' ?>>
      <span><?= $this->te($item['label']) ?></span>
      <small><?= $this->te($item['description']) ?></small>
    </a>
    <?php endforeach; ?>
    <?php if (!empty($logout)): ?>
    <form method="post" action="<?= $this->e($this->url('auth/logout')) ?>" class="logout-form">
      <?= \App\Security\Csrf::field() ?>
      <button type="submit" class="theme-toggle header-action header-signout"><?= $this->te('auth.sign_out') ?></button>
    </form>
    <?php endif; ?>
  </div>
</details>
