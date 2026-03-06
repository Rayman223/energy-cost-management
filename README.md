# Manage-energy-costs

Refonte en cours du projet historique (`old/`) vers une nouvelle base orientée objet dans `app/`.

- Ingestion horaire des mesures en DB.
- Envoi webhook EnergyID V2 quotidien à 01:15 (première valeur de chaque journée).
- Sources: `Data_Dries` + `Data_Solaire` (`Data_Brusol` en fallback migration).

## Déploiement web (important)

Le **DocumentRoot** du v2 doit pointer vers `app/public/`.

Si ton serveur pointe vers la racine du dépôt (ou vers `app/`) sur l'URL `ip/energyv2/`, tu auras souvent une **403 Forbidden** parce que:

- le dossier ne contient pas de `index.php` à ce niveau;
- le listing de répertoire est généralement désactivé (`Options -Indexes` / `autoindex off`);
- les permissions peuvent aussi bloquer l'accès au dossier.

### Vérifications rapides

1. Vérifier que l'URL `ip/energyv2/` sert bien le dossier `.../Manage-energy-costs/app/public/`.
2. Vérifier les droits Unix (lecture + traversée) pour l'utilisateur du serveur web.
3. Vérifier les logs web (`error.log`) pour confirmer la cause exacte du 403.

Voir aussi `app/docs/energyid-v2-model.md`.


## Sécurisation recommandée

Un mécanisme simple et efficace est intégré côté v2 :

- **allowlist IP** (optionnelle) ;
- **authentification HTTP Basic** (fortement recommandée) ;
- protection appliquée au dashboard **et** à l'API.

Configuration dans `app/config/config.php` (copie depuis `config.example.php`) via la section `web_security`.

Exemple minimal:

```php
'web_security' => [
    'enabled' => true,
    'allowed_ips' => ['192.168.1.0/24'],
    'basic_auth' => [
        'enabled'  => true,
        'username' => 'admin',
        'password' => 'un_mot_de_passe_tres_long',
    ],
],
```

Conseils :
- utiliser un mot de passe long et unique ;
- garder `enabled` à `true` en production ;
- limiter les IP si ton accès est depuis un LAN/VPN connu.
