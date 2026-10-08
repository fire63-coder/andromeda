# 13. Suivi pédagogique

## Accès

- `/admin/organisations/{organisation}/suivi` : le tableau de bord du groupe.
- `/suivi/{élève}` : la fiche d'un membre.
- `/suivi/export` : l'export CSV.

Tout est réservé aux **administrateurs** et aux **responsables de l'organisation** (`OrganizationPolicy::update`). La fiche d'un non-membre renvoie 404. Le lien « Suivi pédagogique → » est sur la page de l'organisation.

## Indicateurs (`App\Services\Analytics\GroupAnalytics`)

Tous les calculs portent sur les seuls membres de l'organisation.

| Bloc | Contenu |
|---|---|
| Indicateurs clés | membres, actifs sur 7 jours, soumissions et taux de réussite sur 7 jours, couples (élève, exercice) résolus, XP cumulée |
| Activité | soumissions par jour sur 30 jours, jours vides compris (graphique en barres, survol, vue tableau) |
| Élèves | XP, rang, exercices résolus, taux de réussite, leçons terminées, certifications réussies, dernière activité (« inactif » au-delà de 14 jours). Recherche, et tris : nom, XP, résolus, activité, « en difficulté d'abord » |
| Exercices qui bloquent | exercices essayés par des élèves qui ne les ont pas tous réussis, classés du moins réussi au plus réussi. Pour chacun, les **3 messages d'échec les plus fréquents** (erreur SQL, verdict du correcteur, plan d'exécution) |
| Maîtrise par compétence | part des couples (élève, exercice d'entraînement publié de la compétence) résolus, de la plus faible à la plus forte |

**Fiche élève** : cours commencés (pourcentage), certifications, badges, et les 25 dernières soumissions avec leur message et la requête dépliable.

**Export CSV** : séparateur `;` et BOM UTF-8, pour une ouverture directe dans Excel. Une ligne par membre, avec les colonnes du tableau « Élèves ».

## Graphique d'activité

- **Une seule série** : pas de légende, le titre la nomme.
- **Barres** : au plus 18 px de large, extrémité arrondie de 4 px, base carrée.
- **Survol** : zone sur toute la hauteur du créneau, plus large que la barre. L'infobulle donne le jour, le nombre de soumissions et de réussies.
- **Dates** : une par semaine, alignées sur « aujourd'hui ».
- **Couleur** : bleu `#2a78d6` en clair et `#3987e5` en sombre, contraste vérifié (≥ 3:1). Une vue tableau est dépliable sous le graphique.
