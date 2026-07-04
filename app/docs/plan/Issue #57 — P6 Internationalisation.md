# Issue #57 — P6 Internationalisation (i18n)

## Contexte
Huitième phase de l'épopée #47. Met en place le **système i18n complet** (au-delà de la fondation de P1) et
localise la surface d'authentification / compte / pages légales, avec **sélecteur de langue** et **formatage
localisé**. Lien : https://github.com/Rayman223/Manage-energy-costs/issues/57

## Livré
- **Moteur** : `Formatter` (dates/nombres/devises via ext-intl, repli neutre), `Locale` (résolution `?lang` > profil
  > cookie > Accept-Language > défaut, persistance cookie + profil), `View` étendue (`t`/`te`/`money`/`num`/`dt`/
  `locale`), `ViewFactory`.
- **Catalogues** `fr/en/nl/de` complets pour la surface localisée.
- **Sélecteur de langue** sur les pages localisées ; persistance profil pour les connectés.
- **Pages localisées** : `login` (Basic Auth), `privacy`/`terms` (+ template `legal`), `account` (profil, jetons,
  EnergyID, RGPD) et leurs messages.
- Doc [app/docs/plan/i18n.md](app/docs/plan/i18n.md) (procédure d'ajout de langue). `ext-intl` en `suggest`.

## Restant (documenté, moteur prêt)
`dashboard` (chaînes surtout dans `assets/js/dashboard.js`) et `tariffs` restent en français — **aucune régression**.
Conversion = pass mécanique (`$this->te('clé')` + clés catalogues), à faire dans un incrément suivant de P6.

## Étapes
- [x] Moteur i18n + ViewFactory + Formatter (ext-intl optionnel)
- [x] Catalogues fr/en/nl/de
- [x] Sélecteur de langue + persistance (cookie + profil)
- [x] Localisation login / legal / account
- [x] Tests (Locale, Formatter) + doc
- [ ] *(suite P6)* Localisation dashboard + tariffs

## Vérification
- CI (unit + intégration).
- `?lang=en|nl|de` sur /login.php, /privacy.php, /account.php → UI traduite ; le choix persiste (cookie, et profil si
  connecté). Sans ext-intl : textes traduits, formats neutres ; avec ext-intl : formats localisés.
