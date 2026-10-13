<?php

declare(strict_types=1);

/**
 * Lien de soutien, rendu uniquement si une URL de don est configurée.
 * @var ?string $url URL validée, ou null pour ne rien afficher.
 */
?>
<?php if (($url ?? null) === null) { return; } ?>
<a class="theme-toggle header-action" href="<?= $this->e($url) ?>" target="_blank" rel="noopener noreferrer"><?= $this->te('common.donate') ?></a>
