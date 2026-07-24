<?php

/**
 * En-tête HTML commun : contenu du <head> mutualisé entre toutes les pages.
 *
 * Émet le CONTENU du <head> (les balises <html lang> / <head> et le doctype
 * restent propres à chaque template). Centralise charset, viewport, titre,
 * anti-FOUC (theme-init.js, bloquant), polices Google, tokens.css, les CSS de
 * page et theme.js (defer). Voir #98.
 *
 * @var string            $title       Titre d'onglet complet, déjà traduit (échappé ici)
 * @var list<string>|null $css         Feuilles de page (ex. 'assets/css/dashboard.css'), après tokens.css
 * @var bool|null         $fonts       Polices Google Syne + Space Mono (défaut true ; false pour error)
 * @var list<string>|null $preconnects Origines supplémentaires à préconnecter (ex. https://cdn.jsdelivr.net)
 */

$css         = $css ?? [];
$fonts       = $fonts ?? true;
$preconnects = $preconnects ?? [];
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $this->e($title) ?></title>
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
