# Manage-energy-costs

## 1 To Do

### 1.1 Ajout compteur d'eau

Ajouter l'enregistrement du compteur d'eau (manuel comme le gaz) ainsi que les 2 compteurs annexe du studio.

Ces 2 compteurs annexe sont sur le même réseau que le compteur principal d'eau, ils servent à connaitre la consommation du studio séparément au reste de la maison.

### 1.7 Bug : Distribution (fixe) c€/kWh = annuel

La Distribution (fixe) c€/kWh pour le gaz est un montant annuel donc €/an

### 1.8 Bug : PCS plus de décimale

il y a 2 décimales pour le PCS, il faut augmenter à 4 décimales

### 1.10 Bug : mauvais range de date

Pour les taxe mensuel ("Gestion (fixe annuel)", "Taxe prosumer BRUGEL" et "Obligations de service public"), il faut prendre que le mois en cours. actuellement le jour du mois suivant est inclus mais doit être exclu.
le seul moment où le code actuel fonctionne c'est pour le mois en cours puisqu'il faut prendre le jour d'aujourd'hui même s'il n'est pas terminé.

### 1.11 Feat: ajouter proportion consommé / solaire

Il faut ajouter la proportion solaire consommé avec la quantité prise du grid pour connaitre sa proportion d'auto-consommation et les économies faites.
Indique dans "Estimation coûts" la production solaire, la production solaire consommée (donc non exportée sur le grid) et les économies faites.

### 1.12 Feat: calculer rentabiliter entre mono-horaire ou bi-horaire

Calcul par mois et année (à placer dans "Estimation coûts") ce qui serait le plus rentable entre un bi-horaire (le calcul actuel) et le mono-horaire (en utilisant le cout simple qui n'est pas encore utilisé dans le calcul).

