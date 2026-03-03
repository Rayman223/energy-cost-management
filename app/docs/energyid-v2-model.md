# Modèle d'intégration — EnergyID Incoming Webhooks V2

## 1) Provisioning / connexion initiale
- **Endpoint**: `POST https://hooks.energyid.eu/hello`
- **Headers requis**:
  - `X-Provisioning-Key: <provisioning key>`
  - `X-Provisioning-Secret: <provisioning secret>`
- **Body JSON**:
  - `deviceId` (obligatoire)
  - `deviceName` (obligatoire)
  - `firmwareVersion` (optionnel)
  - `ipAddress` (optionnel)
  - `macAddress` (optionnel)
  - `localDeviceUrl` (optionnel)

### Réponse si appareil non claimé
```json
{
  "claimCode": "...",
  "claimUrl": "...",
  "exp": 600
}
```

### Réponse après claim
```json
{
  "webhookUrl": "https://hooks.energyid.eu/webhook-in",
  "headers": {
    "authorization": "Bearer <token>",
    "x-twin-id": "<device id>"
  },
  "webhookPolicy": {
    "uploadInterval": 60
  }
}
```

## 2) Envoi des données
- `POST` vers `webhookUrl` retourné par `/hello`.
- Inclure **exactement** les headers retournés (`authorization`, `x-twin-id`, etc.).
- Body JSON: objet unique ou tableau d'objets.
- Chaque objet doit contenir `ts` (timestamp Unix en secondes).
- Les autres clés sont des métriques numériques.

## 3) Règles payload implémentées dans ce repo
- Envoi quotidien à 01:15.
- Sources:
  - `Data_Dries` (kWh) → clés: `el.t1`, `el.t2`, `el-i.t1`, `el-i.t2`
  - `Data_Solaire.production` (Wh) converti en kWh → clé: `pv`
  - fallback `Data_Brusol` tant que migration incomplète.
- Sélection: **première valeur du jour** pour chaque métrique.
- Politique d'erreur: 1 retry max, puis skip métrique.

## 4) Clés métriques prédéfinies (EnergyID)
- `el`, `el-i`, `pwr`, `pwr-i`, `gas`, `pv`, `wind`, `chp`, `dh`, `dc`, `sol`, `ev`, `ev-i`, `bat`, `bat-i`, `bat-soc`, `heat`, `dw`
- Suffixes autorisés via point: ex. `el.t1`, `el-i.t2`, `pwr.1`

## 5) Gestion des erreurs
- `400`: payload invalide → corriger mapping/format.
- `401`: token expiré → refaire `/hello`, puis retry.
- `403`: webhook désactivé → log + skip.
- `404`: webhook invalide/expiré → refaire `/hello`, puis retry.
- `429`: respecter `Retry-After` avant retry.

## 6) Renouvellement token
- Token valide ~48h.
- `/hello` est appelé à chaque run quotidien (>= toutes les 24h), conforme à la recommandation de refresh.
