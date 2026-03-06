# Manage-energy-costs

## 1 To Do

### 1.1 Ajout compteur d'eau

Ajouter l'enregistrement du compteur d'eau (manuel comme le gaz) ainsi que les 2 compteurs annexe du studio.

Ces 2 compteurs annexe sont sur le même réseau que le compteur principal d'eau, ils servent à connaitre la consommation du studio séparément au reste de la maison.

### 1.2 graphique 30j activé par défaut

Actuellement, par défaut il n'y a pas de graphique. Je dois manuellement cliquer sur 30j pour afficher le graphique. Il faudrait que le 30j soit sélectionné par défaut.

### 1.3 BUG : plus d'affichage des valeurs de puissance en live

Suite à la dernière mise à jour de sécurité, il y a au moins un bug : plus de live.

Le graphique ne fonctionne plus également.

Il faut faire une vérification complète du code.

### 1.4 Design : police trop petite

Le texte est petit et peu lisible. Il faut améliorer la visibilité du texte.

Contribution énergie a besoin d'une décimale en plus. valeur actuel à 0.0020417 €/kWh

### 1.5 BUG : Ajout noueau tariff

Erreur : Unknown named parameter $pcsCoefficient

### 1.6 Feat : ajout lien vers API HomeWizard

Lorsque je clique sur une icone dans les card de puissance en live (consommation réseau) et (production solaire), je veux ouvrir la page vers l'API (http://192.168.1.5/api/v1/data).

