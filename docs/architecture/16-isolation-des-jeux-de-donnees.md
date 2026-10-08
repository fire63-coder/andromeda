# 16. Isolation des jeux de données

## Le problème

Le compte d'exécution partagé avait des droits sur **tous** les jeux matérialisés :
- PostgreSQL : `USAGE` et `SELECT` sur chaque schéma `ds…` ;
- MySQL : `SELECT` sur `sbx\_%`.

Une requête d'élève exécutée sur le jeu visible pouvait donc lire un **jeu de test caché** en nommant son schéma ou sa base (`SELECT * FROM "ds1_b9_…".products`), puis y adapter sa réponse. `HiddenDatasetIsolationTest` reproduit cette attaque.

## La correction : une isolation garantie par le serveur

### PostgreSQL : un compte de connexion par build
- **Le compte** : `prepare()` crée, avec le schéma, un rôle `<schéma>_r` (`LOGIN NOINHERIT`, limite de 50 connexions) qui n'a de droits que sur ce schéma, plus `CONNECT` et `TEMPORARY` sur la base.
- **Son mot de passe** : `HMAC-SHA256(APP_KEY, nom du rôle)`. Rien n'est stocké, et il est impossible à deviner sans la clé de l'application.
- **Où il sert** : il exécute les requêtes, les sessions des scénarios de concurrence et les requêtes de contrôle. Le compte `andromeda_runner` partagé ne garde aucun droit sur les données.
- **Limites du serveur** (`statement_timeout`, `idle_in_transaction_session_timeout`, `work_mem`, `temp_file_limit`) : fixées au niveau de la base, donc héritées par chaque compte de build.
- **Prérequis** :
  - le propriétaire reçoit `CREATEROLE`. En **PostgreSQL 16+**, un tel compte ne gère que les rôles qu'il a créés ; `sandbox:setup-pgsql` refuse les versions antérieures ;
  - il reçoit aussi `CONNECT`/`TEMPORARY` `WITH GRANT OPTION` sur la base. Sans cela, PostgreSQL n'accorde rien et se contente d'un avertissement ; le pilote le vérifie.
- **`destroy()`** supprime le schéma puis le compte.

### MySQL : un rôle par base
- **Le rôle** : `prepare()` crée le rôle `<préfixe>r_<md5>` (`SELECT`, `INSERT`, `UPDATE`, `DELETE` sur cette seule base) et l'accorde au compte d'exécution, qui n'a plus aucun droit direct.
- **À l'exécution** : le pilote fait `SET ROLE <rôle de la base>`, qui désactive tous les autres, puis `USE base`. `SET` reste interdit aux élèves (QueryGuard), et les routines ne sont pas disponibles sur MySQL dans la sandbox.
- **Noms de bases sans `_`** (`sbxds1b2v…`) : dans un `GRANT`, `_` est un joker, et un nom échappé (`sbx\_…`) empêche MySQL d'appliquer à `USE` les droits hérités d'un rôle.
- **Droits du propriétaire** : `CREATE ROLE`, `DROP ROLE` (limités aux comptes verrouillés, c'est-à-dire aux rôles), `ROLE_ADMIN`, et ses droits sur `sbx%` avec `GRANT OPTION`.
- **Bases de l'ancien format** (`sbx_ds1_b2_…`) : supprimées par `sandbox:setup-mysql`, puis recréées à la demande.

### SQLite
Déjà isolé : processus séparé avec `open_basedir` limité au fichier de la base, et `ATTACH` interdit.

## Après une mise à jour

Relancer les deux commandes d'installation :
```
php artisan sandbox:setup-pgsql
php artisan sandbox:setup-mysql
```
