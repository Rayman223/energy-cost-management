<?php

declare(strict_types=1);

// Modèle de surcharge privée. Copiez ce fichier vers config.local.php,
// ignoré par Git, puis renseignez uniquement les valeurs propres au déploiement.
return [
    'support' => [
        // Passez à true seulement quand le Payment Link et le webhook sont prêts.
        'enabled' => false,
        'stripe_payment_link' => '',
        // Identifiant du Payment Link Stripe (plink_...), distinct de son URL.
        'stripe_payment_link_id' => '',
        'stripe_mode' => 'test', // test ou live
        'stripe_webhook_secret' => '', // Secret de signature whsec_...
        // Clé aléatoire propre à cette instance, distincte du secret webhook.
        'reference_signing_key' => '',
    ],
];
