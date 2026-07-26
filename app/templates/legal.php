<?php

/**
 * Pages légales publiques (#185) : confidentialité, conditions d'utilisation,
 * cookies et mentions légales. Les quatre partagent ce template, assemblé par
 * {@see \App\View\LegalPage}.
 *
 * Tout le texte vient des catalogues de traduction (`legal.*`) : ces pages
 * doivent être lisibles dans la langue de l'utilisateur pour être opposables
 * (RGPD art. 12 — information « concise, transparente, compréhensible »).
 * Contenu rédigé de bonne foi, à faire relire juridiquement avant ouverture
 * publique réelle.
 *
 * @var string                $page          'privacy' | 'terms' | 'cookies' | 'legal-notice'
 * @var list<string>          $available     Locales proposées dans le sélecteur
 * @var array<string,?string> $legal         Identité de l'éditeur ({@see \App\Support\LegalIdentity})
 * @var bool                  $adsEnabled    Vrai si la régie publicitaire est réellement active
 * @var ?string               $adsenseClient Identifiant éditeur AdSense (#185), null si publicité désactivée.
 */

$titleKeys = [
    'privacy'      => 'legal.privacy',
    'terms'        => 'legal.terms',
    'cookies'      => 'legal.cookies',
    'legal-notice' => 'legal.notice',
];
$title = $this->t($titleKeys[$page] ?? 'legal.privacy');

// Substituts d'interpolation : une donnée d'identité non configurée est
// signalée en clair, un texte opposable ne doit pas comporter de blanc.
$missing = $this->t('legal.unset');
$params  = [
    'publisher' => $legal['publisher'] ?? $missing,
    'email'     => $legal['contact_email'] ?? $missing,
];

/** Titre de section. */
$h2 = fn (string $key): string => '<h2>' . $this->te($key) . '</h2>';

/**
 * Paragraphes traduits et échappés. Les catalogues ne portent jamais de HTML :
 * les liens sont rendus séparément par le template.
 *
 * @param list<string> $keys
 */
$paras = function (array $keys) use ($params): string {
    $html = '';
    foreach ($keys as $key) {
        $html .= '<p>' . $this->te($key, $params) . '</p>' . "\n";
    }

    return $html;
};

/**
 * Liste à puces traduite.
 *
 * @param list<string> $keys
 */
$items = function (array $keys) use ($params): string {
    $html = "<ul>\n";
    foreach ($keys as $key) {
        $html .= '  <li>' . $this->te($key, $params) . "</li>\n";
    }

    return $html . "</ul>\n";
};

/** Lien sortant vers un service tiers de paramétrage publicitaire. */
$outbound = fn (string $url, string $labelKey): string =>
    '  <li><a href="' . $this->e($url) . '" target="_blank" rel="noopener noreferrer">'
    . $this->te($labelKey) . "</a></li>\n";
?>
<!DOCTYPE html>
<html lang="<?= $this->e($this->locale()) ?>">
<head>
<?= $this->partial('_head', [
    'title' => $title . ' — ' . $this->t('app.title'),
    'css'   => ['assets/css/page-standalone.css', 'assets/css/legal.css', 'assets/css/lang-switcher.css'],
    'adsenseClient' => $adsenseClient ?? null,
]) ?>
</head>
<body>
<div class="wrap">
  <?php /* <div> et non <p> : <details> n'est pas du contenu de phrasé, un <p> serait auto-fermé avant. */ ?>
  <div class="legal-topbar"><a href="<?= $this->url() ?>"><?= $this->te('nav.back') ?></a>
     &nbsp;·&nbsp;
     <?= $this->partial('_lang-switcher', ['available' => $available]) ?>
  </div>
  <h1><?= $this->e($title) ?></h1>
  <p class="muted"><?= $this->te('legal.updated', ['date' => date('Y-m-d')]) ?></p>

  <?php if ($page === 'privacy'): ?>

    <?= $paras(['legal.privacy.intro']) ?>

    <?= $h2('legal.privacy.controller_title') ?>
    <?= $paras(['legal.privacy.controller_body']) ?>

    <?= $h2('legal.privacy.data_title') ?>
    <?= $paras(['legal.privacy.data_intro']) ?>
    <?= $items([
        'legal.privacy.data_identity',
        'legal.privacy.data_readings',
        'legal.privacy.data_profile',
        'legal.privacy.data_tokens',
        'legal.privacy.data_technical',
    ]) ?>
    <?= $paras(['legal.privacy.data_none']) ?>

    <?= $h2('legal.privacy.purposes_title') ?>
    <?= $items([
        'legal.privacy.purpose_service',
        'legal.privacy.purpose_security',
    ]) ?>
    <?php if ($adsEnabled): ?>
      <?= $items(['legal.privacy.purpose_ads']) ?>
    <?php endif; ?>

    <?= $h2('legal.privacy.recipients_title') ?>
    <?= $paras(['legal.privacy.recipients_body']) ?>
    <?php if ($adsEnabled): ?>
      <?= $paras(['legal.privacy.recipients_ads']) ?>
    <?php endif; ?>

    <?= $h2('legal.privacy.transfers_title') ?>
    <?= $paras(['legal.privacy.transfers_body']) ?>

    <?= $h2('legal.privacy.retention_title') ?>
    <?= $paras(['legal.privacy.retention_body']) ?>

    <?= $h2('legal.privacy.security_title') ?>
    <?= $paras(['legal.privacy.security_body']) ?>

    <?= $h2('legal.privacy.rights_title') ?>
    <?= $paras(['legal.privacy.rights_intro']) ?>
    <?= $items([
        'legal.privacy.right_access',
        'legal.privacy.right_rectify',
        'legal.privacy.right_erase',
        'legal.privacy.right_portability',
        'legal.privacy.right_object',
        'legal.privacy.right_withdraw',
        'legal.privacy.right_complaint',
    ]) ?>
    <p><?= $this->te('legal.privacy.rights_self_service') ?>
       <a href="<?= $this->url('account') ?>"><?= $this->te('legal.my_account') ?></a>.</p>

  <?php elseif ($page === 'cookies'): ?>

    <?= $paras(['legal.cookies.intro']) ?>

    <?= $h2('legal.cookies.essential_title') ?>
    <?= $paras(['legal.cookies.essential_body']) ?>
    <?= $items([
        'legal.cookies.essential_session',
        'legal.cookies.essential_csrf',
        'legal.cookies.essential_prefs',
    ]) ?>

    <?= $h2('legal.cookies.ads_title') ?>
    <?php if ($adsEnabled): ?>
      <?= $paras([
          'legal.cookies.ads_body',
          'legal.cookies.ads_consent',
          'legal.cookies.ads_withdraw',
      ]) ?>
      <p><?= $this->te('legal.cookies.ads_links') ?></p>
      <ul>
<?= $outbound('https://policies.google.com/technologies/partner-sites', 'legal.cookies.link_google_partners') ?>
<?= $outbound('https://myadcenter.google.com/', 'legal.cookies.link_google_settings') ?>
<?= $outbound('https://www.youronlinechoices.com/', 'legal.cookies.link_your_online_choices') ?>
<?= $outbound('https://www.aboutads.info/choices/', 'legal.cookies.link_aboutads') ?>
      </ul>
    <?php else: ?>
      <?= $paras(['legal.cookies.ads_disabled']) ?>
    <?php endif; ?>

    <?= $h2('legal.cookies.manage_title') ?>
    <?= $paras(['legal.cookies.manage_body']) ?>

  <?php elseif ($page === 'legal-notice'): ?>

    <?= $paras(['legal.notice.intro']) ?>
    <dl class="legal-identity">
      <?php foreach (\App\Support\LegalIdentity::FIELDS as $field): ?>
      <dt><?= $this->te('legal.notice.' . $field) ?></dt>
      <dd<?= $legal[$field] === null ? ' class="muted"' : '' ?>><?php
        if ($field === 'contact_email' && $legal[$field] !== null): ?><a href="mailto:<?= $this->e($legal[$field]) ?>"><?= $this->e($legal[$field]) ?></a><?php
        else: ?><?= $this->e($legal[$field] ?? $missing) ?><?php
        endif; ?></dd>
      <?php endforeach; ?>
    </dl>
    <?= $paras(['legal.notice.contact_body']) ?>

  <?php else: ?>

    <?= $paras(['legal.terms.intro']) ?>

    <?= $h2('legal.terms.service_title') ?>
    <?= $paras(['legal.terms.service_body', 'legal.terms.accuracy_body']) ?>

    <?= $h2('legal.terms.account_title') ?>
    <?= $paras(['legal.terms.account_body', 'legal.terms.tokens_body']) ?>

    <?php if ($adsEnabled): ?>
    <?= $h2('legal.terms.ads_title') ?>
    <?= $paras(['legal.terms.ads_body', 'legal.terms.ads_clicks']) ?>
    <?php endif; ?>

    <?= $h2('legal.terms.data_title') ?>
    <p><?= $this->te('legal.terms.data_body') ?>
       <a href="<?= $this->url('account') ?>"><?= $this->te('legal.my_account') ?></a>.</p>

    <?= $h2('legal.terms.liability_title') ?>
    <?= $paras(['legal.terms.liability_body', 'legal.terms.law_body']) ?>

  <?php endif; ?>

  <p class="legal-nav">
    <a href="<?= $this->url('account') ?>"><?= $this->te('legal.my_account') ?></a>
    <?php foreach ($titleKeys as $slug => $key): ?><?php if ($slug !== $page): ?> · <a href="<?= $this->url($slug) ?>"><?= $this->te($key) ?></a><?php endif; ?><?php endforeach; ?>
  </p>
</div>
<script defer src="<?= \App\Support\Assets::url('assets/js/lang-switcher.js') ?>"></script>
</body>
</html>
