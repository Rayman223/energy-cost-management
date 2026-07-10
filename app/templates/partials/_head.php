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
