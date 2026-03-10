# Manage-energy-costs

## 1 To Do

### 1.1 Ajout compteur d'eau

Ajouter l'enregistrement du compteur d'eau (manuel comme le gaz) ainsi que les 2 compteurs annexe du studio.

Ces 2 compteurs annexe sont sur le même réseau que le compteur principal d'eau, ils servent à connaitre la consommation du studio séparément au reste de la maison.

### 1.11 Feat: ajouter proportion consommé / solaire

Il faut ajouter la proportion solaire consommé avec la quantité prise du grid pour connaitre sa proportion d'auto-consommation et les économies faites.
Indique dans "Estimation coûts" la production solaire, la production solaire consommée (donc non exportée sur le grid) et les économies faites.

### 1.12 Feat: calculer rentabiliter entre mono-horaire ou bi-horaire

Calcul par mois et année (à placer dans "Estimation coûts") ce qui serait le plus rentable entre un bi-horaire (le calcul actuel) et le mono-horaire (en utilisant le cout simple qui n'est pas encore utilisé dans le calcul).

### 1.14 debug: utilité des class dans src/domain/

### 1.15 bug: manque champ gaz

Il manque les champs suivant dans le tariff gaz : Redevance de raccordement (€/kWh) et Obligations de service publique (€/an).