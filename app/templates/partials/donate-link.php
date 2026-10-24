<?php

declare(strict_types=1);

/**
 * Lien de soutien vers la page interne, affiché uniquement sur instance activée.
 * @var ?string $url
 */
?>
<?php if (($url ?? null) === null) { return; } ?>
<a class="theme-toggle header-action" href="<?= $this->e($url) ?>"><?= $this->te('common.donate') ?></a>
