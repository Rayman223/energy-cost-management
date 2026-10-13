<?php

declare(strict_types=1);

/**
 * Lien Discord, rendu uniquement si une invitation est configurée.
 * @var ?string $url URL validée, ou null pour ne rien afficher.
 */
?>
<?php if (($url ?? null) === null) { return; } ?>
<a class="theme-toggle header-action" href="<?= $this->e($url) ?>" target="_blank" rel="noopener noreferrer"><?= $this->te('common.discord') ?></a>
