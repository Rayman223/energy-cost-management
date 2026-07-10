<?php

declare(strict_types=1);

/**
 * Bannière de bilan d'import (partagée par « Mon compte » et « Administration »).
 *
 * @var \App\Service\Import\ImportReport|null $importReport
 */

if (($importReport ?? null) === null) {
    return;
}
?>
<div class="banner ok banner--tight">
  <?= $this->te('import.imported') ?> : <strong><?= $this->e((string) $importReport->imported()) ?></strong> ·
  <?= $this->te('import.duplicates') ?> : <strong><?= $this->e((string) $importReport->duplicates()) ?></strong> ·
  <?= $this->te('import.errors') ?> : <strong><?= $this->e((string) $importReport->errors()) ?></strong>
</div>
<?php if ($importReport->truncated()): ?>
  <div class="banner warn banner--tight"><?= $this->te('import.truncated') ?></div>
<?php endif; ?>
<?php if ($importReport->errorSamples() !== []): ?>
  <ul class="muted import-errors">
    <?php foreach ($importReport->errorSamples() as $msg): ?><li><?= $this->e($msg) ?></li><?php endforeach; ?>
  </ul>
<?php endif; ?>
