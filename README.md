# Manage-energy-costs

Refonte en cours du projet historique (`old/`) vers une nouvelle base orientée objet dans `app/`.

- Ingestion horaire des mesures en DB.
- Envoi webhook EnergyID V2 quotidien à 01:15 (première valeur de chaque journée).
- Sources: `Data_Dries` + `Data_Solaire` (`Data_Brusol` en fallback migration).

Voir `app/README.md` et `app/docs/energyid-v2-model.md`.
