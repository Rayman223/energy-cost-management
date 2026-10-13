<?php

declare(strict_types=1);

/**
 * En-tête applicatif commun : bandeau logo + horloge + navigation textuelle,
 * mutualisé entre toutes les pages de la coquille app (#193).
 *
 * Calqué sur le design du dashboard : logo « ⚡ Energy cost management » cliquable vers le
 * tableau de bord, sous-titre propre à la page, horloge live (alimentée par
 * header.js) et navigation textuelle. La page courante est marquée active.
 * Le lien Admin n'apparaît que pour les administrateurs.
 *
 * @var string            $subtitle    Sous-titre de page, déjà traduit (échappé ici)
 * @var string            $current     Clé de page active : dashboard|tariffs|reconciliation|advances|batteries|meters|admin|account|meter-readings|api-guide|stats
 * @var bool|null         $isAdmin     Affiche le lien Admin (défaut false)
 * @var bool|null         $authenticated Défaut true. À false (visiteur anonyme sur une
 *                                      page publique, cf. /stats #8), la navigation vers
 *                                      les pages privées et le bouton de déconnexion
 *                                      disparaissent au profit d'un lien « Se connecter » :
 *                                      des liens menant tous à /login seraient au mieux
 *                                      inutiles, au pire trompeurs.
 * @var string|null       $discordUrl  URL d'invitation Discord (partial discord-link)
 * @var string|null       $donateUrl   URL de soutien au projet (partial donate-link)
 * @var list<string>|null $available   Locales disponibles pour le sélecteur (défaut [])
 * @var string|null       $timezone    Fuseau IANA du profil pour l'horloge ; null/vide ⇒
 *                                      l'horloge suit le fuseau du navigateur (heure locale
 *                                      du visiteur), plutôt qu'un défaut géographique arbitraire
 */

$current   = $current ?? '';
$isAdmin   = !empty($isAdmin);
$available = $available ?? [];
$timezone  = $timezone ?? '';
// Défaut true : toutes les pages appelantes historiques omettent le paramètre et
// doivent rendre exactement la même sortie qu'avant (#8).
$authenticated = !isset($authenticated) || $authenticated;

/**
 * Rend un lien de navigation textuel, marqué actif sur la page courante.
 */
$navlink = function (string $page, string $url, string $title) use ($current): string {
    $isActive = ($page === $current);
    $class    = 'theme-toggle header-action' . ($isActive ? ' active' : '');
    $aria     = $isActive ? ' aria-current="page"' : '';
    return '<a href="' . $this->e($url) . '" class="' . $class . '"' . $aria
        . '>' . $this->e($title) . '</a>';
};
?>
<header class="site-header" data-header>
  <a class="logo" href="<?= $this->url() ?>">
    <div class="logo-icon">⚡</div>
    <div>
      <div class="logo-text"><?= $this->appName() ?></div>
      <div class="logo-sub"><?= $this->e($subtitle) ?></div>
    </div>
  </a>
  <div class="header-top-tools">
    <?php /* data-timezone vide ⇒ header.js retombe sur le fuseau du navigateur. */ ?>
    <div class="clock" data-timezone="<?= $this->e($timezone) ?>">
      <span class="time" id="clock-time">--:--:--</span>
      <span id="clock-date">--- -- ----</span>
    </div>
    <button type="button" class="theme-toggle header-action header-menu-toggle" data-header-toggle aria-controls="header-menu" aria-expanded="true" hidden><?= $this->te('nav.menu') ?></button>
  </div>
  <div class="header-menu" id="header-menu">
    <nav class="header-nav" aria-label="<?= $this->te('nav.main') ?>">
    <?php if ($authenticated): ?>
    <?= $navlink('dashboard', $this->url(), $this->t('nav.dashboard')) ?>
    <?= $navlink('meter-readings', $this->url('meter-readings'), $this->t('nav.meter_readings')) ?>
    <?php if ($isAdmin): ?><?= $navlink('admin', $this->url('admin'), $this->t('nav.admin')) ?><?php endif; ?>
    <?= $navlink('tariffs', $this->url('tariffs'), $this->t('nav.tariffs')) ?>
    <?= $navlink('reconciliation', $this->url('reconciliation'), $this->t('nav.reconciliation')) ?>
    <?= $navlink('advances', $this->url('advances'), $this->t('nav.advances')) ?>
    <?= $navlink('batteries', $this->url('batteries'), $this->t('nav.batteries')) ?>
    <?= $navlink('meters', $this->url('meters'), $this->t('nav.meters')) ?>
    <?= $navlink('stats', $this->url('stats'), $this->t('nav.stats')) ?>
    <?= $navlink('account', $this->url('account'), $this->t('nav.account')) ?>
    <form method="post" action="<?= $this->url('auth/logout') ?>" class="logout-form"><?= \App\Security\Csrf::field() ?><button type="submit" class="theme-toggle header-action"><?= $this->te('auth.sign_out') ?></button></form>
    <?php else: ?>
    <?= $navlink('stats', $this->url('stats'), $this->t('nav.stats')) ?>
    <a href="<?= $this->e($this->url('login')) ?>" class="theme-toggle header-action"><?= $this->te('auth.sign_in') ?></a>
    <?php endif; ?>
    </nav>
    <?= $this->partial('_header-controls', ['available' => $available, 'discordUrl' => $discordUrl ?? null, 'donateUrl' => $donateUrl ?? null]) ?>
  </div>
</header>
<script defer src="<?= \App\Support\Assets::url('assets/js/header-nav.js') ?>"></script>
