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
<?php elseif ($key === 'discord'): ?>
<?php // Logo officiel Discord en « Blurple » (#5865F2) — même path que partials/discord-link.php. ?>
<svg class="btn-provider-icon" width="18" height="18" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
  <path fill="#5865F2" d="M20.317 4.37a19.79 19.79 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.865-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.028C.533 9.046-.32 13.58.099 18.058a.082.082 0 0 0 .031.056 19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028c.462-.63.874-1.295 1.226-1.994a.076.076 0 0 0-.041-.106 13.1 13.1 0 0 1-1.872-.892.077.077 0 0 1-.008-.128c.126-.094.252-.192.372-.291a.074.074 0 0 1 .078-.011c3.928 1.793 8.18 1.793 12.061 0a.074.074 0 0 1 .079.01c.12.099.246.198.373.292a.077.077 0 0 1-.006.128c-.598.349-1.22.65-1.873.891a.077.077 0 0 0-.041.107c.36.698.772 1.363 1.225 1.993a.076.076 0 0 0 .084.029 19.84 19.84 0 0 0 6.002-3.03.077.077 0 0 0 .032-.055c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.029zM8.02 15.331c-1.183 0-2.157-1.086-2.157-2.419s.956-2.419 2.157-2.419c1.211 0 2.176 1.095 2.157 2.419 0 1.333-.956 2.419-2.157 2.419zm7.975 0c-1.183 0-2.157-1.086-2.157-2.419s.955-2.419 2.157-2.419c1.21 0 2.176 1.095 2.157 2.419 0 1.333-.946 2.419-2.157 2.419z"/>
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
