<?php
/**
 * Lien de soutien au projet, affiché dans l'en-tête des pages (#3). Rendu
 * uniquement si une URL est configurée (`donate.url`) : voir
 * {@see \App\Support\DonateLink::url()}, qui fournit `$url`.
 *
 * L'icône est un cœur SVG en ligne plutôt qu'un logo de plateforme : l'URL est
 * configurable (Buy Me a Coffee, Ko-fi, GitHub Sponsors…), aucune marque ne peut
 * donc être présumée. `currentColor` fait suivre le thème clair/sombre, et la
 * CSP interdit de toute façon les images distantes.
 *
 * @var ?string $url URL de don validée, ou null pour ne rien afficher.
 */
?>
<?php if (($url ?? null) === null) { return; } ?>
<a class="theme-toggle" href="<?= $this->e($url) ?>" target="_blank" rel="noopener noreferrer" title="<?= $this->te('common.donate') ?>" aria-label="<?= $this->te('common.donate') ?>">
  <svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
    <path fill="currentColor" d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/>
  </svg>
</a>
