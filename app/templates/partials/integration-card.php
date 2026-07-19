<?php
/**
 * Carte d'un connecteur d'export sur la page « Mon compte » (opt-in générique #70).
 *
 * @var \App\View\View $this
 * @var array{key: string, enabled: bool, status: \App\Integration\IntegrationStatus} $integration
 * @var string $csrf
 */
$key     = $integration['key'];
$enabled = $integration['enabled'];
$status  = $integration['status'];
$prefix  = 'integration.' . $key . '.';
?>
<div class="card">
  <h2><?= $this->te($prefix . 'title') ?> <span class="muted"><?= $this->te($prefix . 'region') ?></span></h2>
  <p class="hint"><?= $this->te($prefix . 'hint') ?></p>
  <?php if ($enabled): ?>
    <p><?= $this->te('account.token_status') ?> : <strong><?= $this->te($prefix . 'on') ?></strong></p>
  <?php endif; ?>
  <?php foreach ($status->lines as $line): ?>
    <p class="muted"><?= $this->te($line['key'], $line['params']) ?><?php if ($line['code'] !== null): ?> <code><?= $this->e($line['code']) ?></code><?php endif; ?></p>
  <?php endforeach; ?>
  <?php if ($enabled): ?>
    <form method="post"><?= $csrf ?><input type="hidden" name="action" value="integration_disable"><input type="hidden" name="module_key" value="<?= $this->e($key) ?>"><button type="submit" class="ghost"><?= $this->te($prefix . 'disable') ?></button></form>
  <?php else: ?>
    <form method="post"><?= $csrf ?><input type="hidden" name="action" value="integration_enable"><input type="hidden" name="module_key" value="<?= $this->e($key) ?>"><button type="submit"><?= $this->te($prefix . 'enable') ?></button></form>
  <?php endif; ?>
</div>
