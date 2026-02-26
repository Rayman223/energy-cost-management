# Document de suivi — Refonte énergétique

## État actuel (fait)
- Nouveau squelette orienté objet créé dans `app/`.
- Suppression de toute dépendance CSV dans le nouveau flux.
- Flux horaire prévu:
  1. ingestion des données en base,
  2. envoi JSON vers webhook EnergyID,
  3. marquage des mesures publiées.
- Base préparée pour:
  - encodage manuel du gaz,
  - stockage d'une grille tarifaire détaillée.

## Questions à valider (bloquantes)
1. **Source des données électriques**: quelle API exacte doit être appelée (URL locale, authentification, format JSON)?
2. **Granularité**: envoi EnergyID toutes les heures, ou regroupement journalier?
3. **Webhook EnergyID**: 1 webhook unique pour tout, ou un webhook par métrique/site?
4. **Format attendu EnergyID**: confirmez-vous les métriques/codes exacts (`gridImport`, unité, intervalle)?
5. **Gestion gaz**:
   - fréquence d'encodage manuel attendue (journalier, hebdomadaire, mensuel)?
   - faut-il transformer m³ en kWh via coefficient PCS configurable?
6. **Grille tarifaire**:
   - faut-il gérer HP/HC (jour/nuit), mois, saisons, plages horaires?
   - quelles composantes inclure (énergie, distribution, transport, taxes, TVA, prosumer, fixe)?
7. **Historisation**: souhaitez-vous recalculer rétroactivement les coûts quand un tarif change?
8. **Tolérance incidents**:
   - combien de tentatives webhook avant abandon?
   - faut-il une file de retry dédiée?
9. **Multi-site / multi-compteur**: y a-t-il plusieurs logements/sites à gérer?
10. **Sécurité**: stockage du webhook et DB en variables d'environnement obligatoire?

## Plan de suite proposé
### Phase 1 — Stabilisation ingestion
- Connecter `EnergyIngestionService` à la vraie API compteur.
- Ajouter validation des valeurs (anti-retour arrière index, bornes).
- Ajouter logs structurés et code retour cron robuste.

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
