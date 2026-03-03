# Document de suivi — Refonte énergétique

## État actuel (fait)
- Nouveau squelette orienté objet créé dans `app/`.
- Suppression de toute dépendance CSV dans le nouveau flux.
- Flux en 2 temps:
  1. ingestion horaire en DB,
  2. envoi quotidien webhook EnergyID V2 (01:15).
- Envoi quotidien basé sur **la première valeur de la journée** (import/export + solaire).
- Sources:
  - `Data_Dries` (kWh),
  - `Data_Solaire.production` (Wh, converti kWh pour `pv`),
  - fallback `Data_Brusol` sans date de fin imposée.
- Gestion erreur webhook:
  - 1 retry par métrique,
  - si échec après retry: skip métrique, reprise au prochain run.
- Provisioning V2 `/hello` intégré avant envoi.

## Points ouverts
1. **Claim device**: confirmer que l'appareil est bien claimé côté EnergyID (sinon `/hello` renverra `claimCode`/`claimUrl`).
2. **Mapping métier**: confirmer définitivement `el.t1/el.t2/el-i.t1/el-i.t2/pv`.
3. **Gaz manuel**:
   - fréquence d'encodage attendue,
   - conversion m³ -> kWh (coefficient PCS) fixe ou variable par période?
4. **Tarifaire**:
   - faut-il gérer HP/HC, saisonnalité, coûts fixes, TVA, prosumer, et taxes régionales distinctes?

## Plan de suite proposé
### Phase 1 — Stabilisation webhook V2
- Vérifier run réel avec provisioning keys.
- Ajouter logs structurés (latence, taille payload, statut).
- Ajouter tests d'intégration avec mock HTTP.

### Phase 2 — Tarifaire complet
- Écran/API d'administration des grilles tarifaires.
- Moteur de calcul par période, composantes et type énergie.
- Simulation comparative fournisseur / contrat.

### Phase 3 — Gaz et précision coûts
- Workflow d'encodage manuel gaz (avec contrôle d'écarts).
- Conversion m³ → kWh (coefficient configurable par période).
- Intégration des coûts gaz dans reporting global.

### Phase 4 — Qualité et exploitation
- Tests unitaires services critiques.
- Rejeu des envois webhook en erreur.
- Dashboard de suivi (dernier cron, volume envoyé, erreurs).
