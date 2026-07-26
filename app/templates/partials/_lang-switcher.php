<?php

/**
 * Sélecteur de langue commun : menu déroulant natif `<details>` (sans JS
 * obligatoire), mutualisé entre l'en-tête applicatif et les pages hors coquille
 * app (connexion, accueil public, pages légales).
 *
 * Les liens reconduisent l'état de page présent dans l'URL
 * ({@see \App\I18n\Locale::switchUrl()}) ; `$params` sert au reste.
 *
 * @var list<string>|null           $available Locales proposées (défaut [])
 * @var string|null                 $direction 'down' (défaut) ou 'up' : sens d'ouverture
 *                                             de la liste. 'up' pour les blocs placés en
 *                                             bas de page, sinon coupés par la fenêtre.
 * @var array<string, string>|null  $params    Paramètres de query à reconduire en plus
 *                                             (ex. ['next' => …] sur les pages de connexion,
 *                                             où la cible peut venir d'un POST).
 */

$available = $available ?? [];
$direction = ($direction ?? 'down') === 'up' ? ' lang-menu-up' : '';
$params    = $params ?? [];
?>
<details class="lang-menu<?= $direction ?>">
  <summary class="lang-summary" title="<?= $this->te('common.language') ?>" aria-label="<?= $this->te('common.language') ?>">
    <span class="lang-globe" aria-hidden="true">🌐</span>
    <span class="lang-current"><?= $this->e(strtoupper($this->locale())) ?></span>
    <span class="lang-caret" aria-hidden="true">▾</span>
  </summary>
  <div class="lang-menu-list">
    <?php foreach ($available as $loc): ?>
    <a href="<?= $this->e(\App\I18n\Locale::switchUrl($loc, $params)) ?>" class="lang-item<?= $loc === $this->locale() ? ' lang-active' : '' ?>"><?= $this->e(\App\I18n\Locale::displayName($loc)) ?></a>
    <?php endforeach; ?>
  </div>
</details>
