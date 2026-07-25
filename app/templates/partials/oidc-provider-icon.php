<?php
/**
 * Icône du bouton de connexion, choisie par la clé de fournisseur. Un IdP
 * inconnu retombe sur une icône « clé » neutre (couleur du texte courant).
 *
 * @var string $key Clé du fournisseur (google, microsoft, authentik…).
 */
?>
<?php if ($key === 'google'): ?>
<?php // « microsoft » (forme providers) comme « microsoftonline » (forme plate héritée) ?>
<svg class="btn-provider-icon" width="18" height="18" viewBox="0 0 18 18" aria-hidden="true" focusable="false">
  <path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.92c1.71-1.57 2.68-3.88 2.68-6.62z"/>
  <path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.92-2.26c-.81.54-1.84.86-3.04.86-2.34 0-4.32-1.58-5.03-3.7H.96v2.33A9 9 0 0 0 9 18z"/>
  <path fill="#FBBC05" d="M3.97 10.72a5.4 5.4 0 0 1 0-3.44V4.95H.96a9 9 0 0 0 0 8.1l3.01-2.33z"/>
  <path fill="#EA4335" d="M9 3.58c1.32 0 2.5.45 3.44 1.35l2.58-2.58C13.47.9 11.43 0 9 0A9 9 0 0 0 .96 4.95l3.01 2.33C4.68 5.16 6.66 3.58 9 3.58z"/>
</svg>
<?php elseif ($key === 'microsoft' || $key === 'microsoftonline'): ?>
<svg class="btn-provider-icon" width="18" height="18" viewBox="0 0 18 18" aria-hidden="true" focusable="false">
  <rect width="8.5" height="8.5" fill="#F25022"/>
  <rect x="9.5" width="8.5" height="8.5" fill="#7FBA00"/>
  <rect y="9.5" width="8.5" height="8.5" fill="#00A4EF"/>
  <rect x="9.5" y="9.5" width="8.5" height="8.5" fill="#FFB900"/>
</svg>
<?php elseif ($key === 'authentik'): ?>
<?php // Badge aux couleurs de marque authentik : pas de logo SVG officiel réutilisable. ?>
<svg class="btn-provider-icon" width="18" height="18" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
  <rect width="24" height="24" rx="5" fill="#FD4B2D"/>
  <path fill="#fff" transform="translate(12 12) scale(.7) translate(-12 -12)"
        d="M14 2a6 6 0 0 0-5.83 7.42L2 15.59V20h4v-2h2v-2h2l1.59-1.59A6 6 0 1 0 14 2zm2.5 5.5a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3z"/>
</svg>
<?php else: ?>
<svg class="btn-provider-icon" width="18" height="18" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
  <path fill="currentColor" d="M14 2a6 6 0 0 0-5.83 7.42L2 15.59V20h4v-2h2v-2h2l1.59-1.59A6 6 0 1 0 14 2zm2.5 5.5a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3z"/>
</svg>
<?php endif; ?>
