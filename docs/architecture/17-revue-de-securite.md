# 17. Revue de sécurité de la sandbox (octobre 2026)

Chaque faille a été confirmée sur les vrais moteurs avant d'être corrigée. Toutes ont un test de non-régression :
- `QueryGuardTest` ;
- `HiddenDatasetIsolationTest` ;
- `PostgresSandboxTest` ;
- `MysqlSandboxTest`.

## Failles trouvées et corrigées

| # | Gravité | Faille confirmée | Correction |
|---|---|---|---|
| 1 | Élevée | **Triche à la correction** : `CREATE TEMP TABLE salary_audit AS SELECT …` masquait la table lue par les requêtes de contrôle. L'exercice du trigger était jugé correct sans trigger, jeux cachés compris. | Exercices corrigés : tables et vues temporaires refusées, tables du jeu protégées contre la suppression et le renommage (`protected_tables`, sauf option `allow_table_replacement`). PostgreSQL : `search_path = schéma, pg_temp`, donc les tables temporaires ne sont jamais lues en premier. |
| 2 | Élevée | **Le lexer et le moteur lisaient la requête différemment** : 8 contournements faisaient exécuter une fonction interdite. MySQL : `'a\''`, `"a\""`, `--x`, `#`, `/*! … */`. PostgreSQL : commentaires imbriqués, `E'…\''`, `U&"pg\005fsleep"`. | `QueryGuard::checkAmbiguities()` refuse toute écriture ambiguë. Voir la liste détaillée ci-dessous. |
| 3 | Moyenne | **Lecture des réponses des autres élèves** : `SELECT INFO FROM information_schema.PROCESSLIST` montrait les requêtes en cours (MySQL) ; `pg_stat_activity` aussi, pour un même jeu de données (PostgreSQL). | Mots refusés : `PROCESSLIST`, `PG_STAT_ACTIVITY`, `PG_STAT_GET_ACTIVITY`. PostgreSQL : `SELECT` sur `pg_stat_activity` retiré à PUBLIC. |
| 4 | Moyenne | **Verrous sur les tables partagées** : un `UPDATE` ou un `SELECT … FOR UPDATE` d'un élève bloquait les modifications des autres sur les mêmes lignes, jusqu'à la fin de son exécution. | Modifications et lectures verrouillantes sur des **copies privées** : tables temporaires de session, sur PostgreSQL comme sur MySQL. Ne s'applique qu'aux jeux d'au plus 50 000 lignes (`SANDBOX_PRIVATE_COPY_MAX_ROWS`). |
| 5 | Faible | `pg_sleep` et `pg_sleep_until` n'étaient pas interdits : un délai de 5 s au plus, mais facile à répéter. | Ajoutés aux mots interdits. |
| 6 | Faible | Une valeur géante (par exemple `repeat('x', 10^8)`) partait dans l'aperçu envoyé au navigateur et enregistré avec la soumission. | Les valeurs des aperçus sont tronquées à 2 000 caractères. |
| 7 | Élevée | *(étape précédente)* Les jeux de test cachés étaient lisibles depuis n'importe quel exercice. | Un compte par jeu (PostgreSQL), un rôle par base (MySQL). Voir la fiche 16. |

### Écritures refusées par `checkAmbiguities()`

- un antislash devant un guillemet ou une apostrophe ;
- les commentaires imbriqués, `/*! */` et `/*+ */` ;
- `--` non suivi d'un espace ;
- le caractère `#` ;
- `U&` ;
- les chaînes ou commentaires non refermés ;
- `$$` hors d'un corps de fonction.

Pour les scripts de jeux de données, la règle de l'antislash ne s'applique qu'à MySQL.

## Points vérifiés sans problème

- **Affichage** : résultats et erreurs sont échappés (`{{ }}`). Markdown est rendu avec le HTML brut retiré et les liens dangereux refusés. Mermaid fonctionne en `securityLevel: strict`.
- **Import CSV/JSON** : chaînes doublées (et antislashs doublés pour MySQL), identifiants nettoyés puis délimités. Le script final repasse par le garde.
- **Composant d'exercice** : exercice, mode, contexte, indices révélés et début du chrono sont verrouillés (`#[Locked]`). La solution et les bonnes réponses du QCM ne sont jamais envoyées au navigateur.
- **SQLite** : processus séparé, `open_basedir` limité au fichier de la base, `ATTACH`, `PRAGMA` et `VACUUM` refusés, `load_extension` indisponible.
- **PostgreSQL** :
  - toute exécution se termine par un ROLLBACK, avec `statement_timeout` et `lock_timeout` ;
  - le code stocké est surveillé par le chien de garde ;
  - `set_config`, `pg_cancel_backend` et `pg_terminate_backend` sont retirés à PUBLIC ;
  - un compte par jeu de données.
- **Scénarios de concurrence** : chaque étape passe le garde, le contrôle de transaction n'est accepté que sous des formes exactes, et le tout tourne sur une copie jetable.

## Risques résiduels connus

- **Gros jeux de données** : au-delà de 50 000 lignes, les modifications travaillent sur les tables partagées. Les verrous restent bornés par le délai d'exécution, au plus 5 s.
- **Exercices de DDL** : l'option `allow_table_replacement` désactive la protection des tables. À réserver aux exercices dont la correction ne lit pas les tables concernées.
- **Barrière lexicale** : elle reste une première défense. Les vraies protections sont les droits du serveur (comptes sans privilèges, fonctions retirées) et les délais.
