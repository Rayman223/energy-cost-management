<?php

/**
 * En-tête applicatif commun : bandeau logo + horloge + navigation en icônes,
 * mutualisé entre toutes les pages de la coquille app (#193).
 *
 * Calqué sur le design du dashboard : logo « ⚡ Manage Energy » cliquable vers le
 * tableau de bord, sous-titre propre à la page, horloge live (alimentée par
 * header.js) et navigation en icônes. L'icône de la page courante est marquée
 * active. L'icône Admin n'apparaît que pour les administrateurs.
 *
 * @var string            $subtitle    Sous-titre de page, déjà traduit (échappé ici)
 * @var string            $current     Clé de page active : dashboard|tariffs|reconciliation|advances|admin|account|meter-readings|api-guide
 * @var bool|null         $isAdmin     Affiche l'icône Admin (défaut false)
 * @var string|null       $discordUrl  URL d'invitation Discord (partial discord-link)
 * @var list<string>|null $available   Locales disponibles pour le sélecteur (défaut [])
 * @var string|null       $timezone    Fuseau IANA du profil pour l'horloge ; null/vide ⇒
 *                                      l'horloge suit le fuseau du navigateur (heure locale
 *                                      du visiteur), plutôt qu'un défaut géographique arbitraire
 */

$current   = $current ?? '';
$isAdmin   = !empty($isAdmin);
$available = $available ?? [];
$timezone  = $timezone ?? '';

/**
 * Rend un lien de navigation en icône, marqué actif sur la page courante.
 */
$navlink = function (string $page, string $url, string $icon, string $title) use ($current): string {
    $isActive = ($page === $current);
    $class    = 'theme-toggle' . ($isActive ? ' active' : '');
    $aria     = $isActive ? ' aria-current="page"' : '';
    return '<a href="' . $this->e($url) . '" class="' . $class . '"' . $aria
        . ' title="' . $this->e($title) . '">' . $icon . '</a>';
};
?>
<header>
  <a class="logo" href="<?= $this->url() ?>">
    <div class="logo-icon">⚡</div>
    <div>
      <div class="logo-text">Manage Energy</div>
      <div class="logo-sub"><?= $this->e($subtitle) ?></div>
    </div>
  </a>
  <div class="header-right">
    <?php /* data-timezone vide ⇒ header.js retombe sur le fuseau du navigateur. */ ?>
    <div class="clock" data-timezone="<?= $this->e($timezone) ?>">
      <span class="time" id="clock-time">--:--:--</span>
      <span id="clock-date">--- -- ----</span>
    </div>
    <?= $navlink('meter-readings', $this->url('meter-readings'), '📝', $this->t('nav.meter_readings')) ?>
    <?php if ($isAdmin): ?><?= $navlink('admin', $this->url('admin'), '🛡', $this->t('admin.title')) ?><?php endif; ?>
    <?= $navlink('tariffs', $this->url('tariffs'), '€', $this->t('nav.tariffs')) ?>
    <?= $navlink('reconciliation', $this->url('reconciliation'), '🧾', $this->t('nav.reconciliation')) ?>
    <?= $navlink('advances', $this->url('advances'), '🏦', $this->t('nav.advances')) ?>
    <?= $navlink('account', $this->url('account'), '👤', $this->t('nav.account')) ?>
    <form method="post" action="<?= $this->url('auth/logout') ?>" class="logout-form"><?= \App\Security\Csrf::field() ?><button type="submit" class="theme-toggle" title="<?= $this->te('auth.sign_out') ?>">🚪</button></form>
    <?= $this->partial('discord-link', ['url' => $discordUrl ?? null]) ?>
    <?= $this->partial('_lang-switcher', ['available' => $available]) ?>
    <button type="button" class="theme-toggle" id="theme-toggle" aria-label="<?= $this->te('common.theme') ?>">🌙</button>
  </div>
</header>
