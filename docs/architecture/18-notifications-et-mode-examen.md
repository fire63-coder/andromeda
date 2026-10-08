# Notifications et mode examen

## Centre de notifications

Les notifications sont stockées dans la table `notifications` de Laravel (canal `database`). Chaque
notification enregistre un contenu commun, `toArray()`, que l'interface affiche tel quel :

| Champ   | Rôle                                                       |
|---------|------------------------------------------------------------|
| `kind`  | famille (`assignment`, `certification`, `review`…)         |
| `icon`  | emoji affiché à gauche                                     |
| `title` | texte principal                                            |
| `body`  | détail facultatif                                          |
| `url`   | **chemin interne** ouvert au clic (`route(..., absolute: false)`) |

| Notification              | Destinataires                                | Canaux                                  |
|---------------------------|----------------------------------------------|-----------------------------------------|
| `AssignmentPublished`     | élèves actifs de l'organisation              | application + e-mail (si accepté)       |
| `AssignmentDueSoon`       | élèves actifs qui n'ont pas terminé          | application + e-mail (si accepté)       |
| `CertificationCompleted`  | le candidat, à la clôture de l'épreuve       | application                             |
| `ExerciseAwaitingReview`  | administrateurs actifs (sauf le demandeur)   | application                             |
| `ExercisePublished`       | l'auteur de l'exercice, s'il n'a pas publié lui-même | application                     |

La case « e-mails des devoirs » du profil ne coupe que l'e-mail : la notification dans l'application
est toujours créée.

### Interface

- **Cloche** (`Notifications\NotificationBell`) dans la barre de navigation : nombre de non lues, les
  six dernières, « Tout marquer comme lu ». Elle se rafraîchit toutes les 60 s quand l'onglet est
  visible (`wire:poll.60s.visible`).
- **Page `/notifications`** (`Notifications\NotificationCenter`) : filtre « non lues seulement »,
  marquer lue ou non lue, supprimer, supprimer les lues, pagination.
- Ouvrir une notification la marque comme lue et suit son lien, **seulement si c'est un chemin
  interne** (`/…` mais pas `//…`). Toutes les actions passent par `auth()->user()->notifications()`,
  donc la notification d'un autre utilisateur donne une 404.
- `notifications:prune` (planifiée chaque nuit) supprime les notifications **lues** depuis plus de
  90 jours (`--days=` pour changer ce délai).

## Mode examen des certifications

Activé par certification (`certifications.exam_mode`), avec un nombre d'incidents tolérés
(`max_incidents`, vide = l'épreuve n'est jamais close pour cette raison).

### Sujet aléatoire

- **Tirage équilibré** (`StartCertificationAttempt::draw()`) : les questions sont tirées au hasard,
  mais chaque niveau de difficulté du pool reçoit une part du sujet proportionnelle à sa taille
  (méthode du plus fort reste). Deux candidats ont donc des sujets différents mais de difficulté
  comparable. Le sujet est figé dans la tentative, ce qui ne change pas.
- **Ordre des réponses des QCM** propre à chaque tentative : tri par `xxh3("{tentative}:{réponse}")`.
  Il est stable d'un affichage à l'autre, et ne demande ni colonne ni état supplémentaire.

Le tirage équilibré et le mélange des QCM s'appliquent à toutes les certifications, en mode examen ou
non.

### Surveillance côté navigateur (`resources/js/exam/exam-guard.js`)

- **Plein écran exigé** : tant qu'il n'est pas actif, un écran opaque masque l'épreuve.
- **Bloqués** : copier, couper, coller (y compris dans CodeMirror, intercepté en phase de capture,
  et `beforeinput` de type `insertFromPaste`/`insertFromDrop`), glisser-déposer et menu contextuel.
  La saisie au clavier reste normale.
- **Incidents comptés**, signalés au serveur par `CertificationRunner::reportIncident()` :

  | Incident          | Déclencheur                                         |
  |-------------------|-----------------------------------------------------|
  | `fullscreen_exit` | sortie du plein écran                               |
  | `tab_hidden`      | autre onglet ou fenêtre réduite                     |
  | `window_blur`     | fenêtre quittée plus de 3 s (autre application)     |
  | `page_reload`     | page rechargée ou rouverte (détecté côté serveur au montage) |

- **Incidents journalisés sans pénalité** : `copy_blocked`, `paste_blocked`, `drop_blocked`,
  `context_menu` et `opened`. Le navigateur en envoie au plus un toutes les 10 s par type.

### Côté serveur (`RecordExamIncident`)

- **Types acceptés** : seuls les types connus ; les autres sont ignorés.
- **Rafales** : un même geste déclenche souvent plusieurs événements, par exemple quitter le plein
  écran puis changer d'onglet. Deux incidents comptés à moins de 3 s n'en font donc qu'un.
- **Journal** : il est conservé avec la tentative (`incidents`, 200 entrées au plus) et
  `incidents_count` en donne le total.
- **Clôture** : au-delà de `max_incidents`, l'épreuve est close avec les réponses déjà données
  (`closed_reason = incidents`). Les autres valeurs sont `submitted` (bouton « Terminer ») et
  `timeout` (fin du temps).
- **Reste de l'application fermé** : `LockAppDuringExam` (`exam.lock`) s'applique aux routes
  authentifiées et à celles de Jetstream. Toute page ouverte pendant une épreuve surveillée renvoie à
  l'épreuve, et la page d'épreuve n'affiche pas la barre de navigation.
- **Sandbox réservée à l'épreuve** : un onglet d'entraînement ouvert avant l'épreuve ne peut plus
  exécuter de requête (`ExercisePlayer::throttle()`).
- **Fin de l'épreuve** : le journal est affiché au candidat avec la correction. Le formateur voit le
  nombre d'incidents, et si l'épreuve a été close pour cette raison, dans la fiche de l'élève.

### Limites assumées

- **Le navigateur appartient au candidat.** Ces mesures dissuadent et laissent une trace, mais un
  second appareil, une machine virtuelle ou des outils de développement modifiés les contournent.
  Pour un examen à fort enjeu, il faut les compléter par une surveillance humaine ou un navigateur
  verrouillé (Safe Exam Browser, par exemple).
- **Le contenu reste dans la page.** L'écran opaque masque l'épreuve sans la retirer du code de la
  page : il empêche une lecture « confortable » hors plein écran, pas une extraction volontaire.
- **Incidents involontaires.** Une coupure réseau ou un rechargement accidentel compte comme un
  incident. Le seuil doit en tenir compte : 2 ou 3 est un bon point de départ.
- **Petits pools.** Le tirage équilibré n'a d'effet que si le pool est nettement plus grand que le
  nombre de questions.
