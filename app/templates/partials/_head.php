<?php

/**
 * En-tête HTML commun : contenu du <head> mutualisé entre toutes les pages.
 *
 * Émet le CONTENU du <head> (les balises <html lang> / <head> et le doctype
 * restent propres à chaque template). Centralise charset, viewport, titre,
 * anti-FOUC (theme-init.js, bloquant), polices Google, tokens.css, les CSS de
 * page et theme.js (defer). Voir #98.
 *
 * @var string            $title         Titre d'onglet complet, déjà traduit (échappé ici)
 * @var list<string>|null $css           Feuilles de page (ex. 'assets/css/dashboard.css'), après tokens.css
 * @var bool|null         $fonts         Polices Google Syne + Space Mono (défaut true ; false pour error)
 * @var list<string>|null $preconnects   Origines supplémentaires à préconnecter (ex. https://cdn.jsdelivr.net)
 * @var ?\App\Seo\PageMeta $meta      Métadonnées de référencement (#84). Omis ⇒ la page
 *                                        est rendue `noindex` : le défaut est fermé, une page
 *                                        privée ne s'indexe pas par oubli.
 * @var ?string           $adsenseClient Identifiant éditeur AdSense (#185), fourni par
 *                                        {@see \App\Support\Adsense::clientId()} ; null ⇒ aucun
 *                                        script publicitaire. Volontairement non transmis par les
 *                                        pages d'erreur et de connexion (règles du programme :
 *                                        pas d'annonces sur les pages sans contenu).
 */

$css           = $css ?? [];
$fonts         = $fonts ?? true;
$preconnects   = $preconnects ?? [];
$adsenseClient = $adsenseClient ?? null;
$meta          = $meta ?? null;
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $this->e($title) ?></title>
<?php if ($meta !== null): ?>
<meta name="robots" content="<?= $this->e($meta->robots) ?>">
<?php if ($meta->description !== ''): ?>
<meta name="description" content="<?= $this->e($meta->description) ?>">
<?php endif; ?>
<?php if ($meta->canonical !== null): ?>
<!-- Canonical auto-référent, `?lang=` compris (#84) : les 4 locales sont servies
     sur le même chemin, il faut donc une URL stable par langue, reliée aux autres
     par les hreflang ci-dessous. -->
<link rel="canonical" href="<?= $this->e($meta->canonical) ?>">
<?php endif; ?>
<?php foreach ($meta->alternates as $alternate): ?>
<link rel="alternate" hreflang="<?= $this->e($alternate['hreflang']) ?>" href="<?= $this->e($alternate['href']) ?>">
<?php endforeach; ?>
<?php if ($meta->canonical !== null): ?>
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= $this->appName() ?>">
<meta property="og:title" content="<?= $this->e($title) ?>">
<?php if ($meta->description !== ''): ?>
<meta property="og:description" content="<?= $this->e($meta->description) ?>">
<?php endif; ?>
<meta property="og:url" content="<?= $this->e($meta->canonical) ?>">
<meta property="og:locale" content="<?= $this->e($meta->ogLocale) ?>">
<?php if ($meta->imageUrl !== null): ?>
<meta property="og:image" content="<?= $this->e($meta->imageUrl) ?>">
<?php endif; ?>
<meta name="twitter:card" content="summary">
<?php endif; ?>
<?php if ($meta->verification !== null): ?>
<meta name="google-site-verification" content="<?= $this->e($meta->verification) ?>">
<?php endif; ?>
<?php else: ?>
<!-- Aucune métadonnée fournie ⇒ page privée ou technique : hors index (#84). -->
<meta name="robots" content="noindex, follow">
<?php endif; ?>
<!-- Favicon (#195) : .ico multi-résolution (16/32/48), apple-touch pour iOS, manifest PWA.
     Chemins relatifs (comme le reste du partial) pour rester corrects en déploiement sous-répertoire. -->
<link rel="icon" href="<?= \App\Support\Assets::url('favicon.ico') ?>" sizes="16x16 32x32 48x48">
<link rel="apple-touch-icon" href="<?= \App\Support\Assets::url('apple-touch-icon.png') ?>">
<link rel="manifest" href="<?= \App\Support\Assets::url('site.webmanifest') ?>">
<meta name="theme-color" content="#b8650a"><?php /* aligne le chrome navigateur sur theme_color du manifest */ ?>
<!-- Anti-FOUC : pose le thème avant le 1er rendu. Bloquant volontairement (pas de defer). -->
<script src="<?= \App\Support\Assets::url('assets/js/theme-init.js') ?>"></script>
<?php foreach ($preconnects as $origin): ?>
<link rel="preconnect" href="<?= $this->e($origin) ?>" crossorigin>
<?php endforeach; ?>
<?php if ($fonts): ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:ital,wght@0,400;0,700;1,400&family=Syne:wght@400;600;700;800&display=swap" rel="stylesheet">
<?php endif; ?>
<link rel="stylesheet" href="<?= \App\Support\Assets::url('assets/css/tokens.css') ?>">
<?php foreach ($css as $sheet): ?>
<link rel="stylesheet" href="<?= \App\Support\Assets::url($sheet) ?>">
<?php endforeach; ?>
<script defer src="<?= \App\Support\Assets::url('assets/js/theme.js') ?>"></script>
<?php if ($adsenseClient !== null): ?>
<!-- Google AdSense (#185), format « Auto ads » : Google choisit et place les
     emplacements, aucun <script> inline n'est nécessaire (la CSP l'interdit).
     Le consentement aux cookies publicitaires est recueilli par le CMP Google
     (IAB TCF), activé côté console AdSense. Voir /cookies. -->
<link rel="preconnect" href="https://pagead2.googlesyndication.com" crossorigin>
<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=<?= $this->e($adsenseClient) ?>" crossorigin="anonymous"></script>
<?php endif; ?>
