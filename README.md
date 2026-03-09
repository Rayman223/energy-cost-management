# Manage-energy-costs

## 1 To Do

### 1.1 Ajout compteur d'eau

Ajouter l'enregistrement du compteur d'eau (manuel comme le gaz) ainsi que les 2 compteurs annexe du studio.

Ces 2 compteurs annexe sont sur le même réseau que le compteur principal d'eau, ils servent à connaitre la consommation du studio séparément au reste de la maison.

### 1.4 Design : nombre décimales

Contribution énergie a besoin d'une décimale en plus. valeur actuel à 0.0020417 €/kWh

### 1.6 Feat : ajout lien vers API HomeWizard

Lorsque je clique sur une icone dans les card de puissance en live (consommation réseau) et (production solaire), je veux ouvrir la page vers l'API (http://192.168.1.5/api/v1/data).

### 1.7 Bug : Distribution (fixe) c€/kWh = annuel

La Distribution (fixe) c€/kWh pour le gaz est un montant annuel donc €/an

### 1.8 Bug : PCS plus de décimale

il y a 2 décimales pour le PCS, il faut augmenter à 4 décimales

### 1.9 Bug : Consommation réseau enlever absolu

la valeur live de Consommation réseau est en valeur absolue. Il faut enlever cet absolu pour indiquer si on prend du réseau (positif) ou si on donne au réseau (négatif) donc prendre la valeur telquel sans transformation.

