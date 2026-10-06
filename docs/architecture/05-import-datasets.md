# 05 — Import de jeux de données

Back-office : `/admin/datasets`, ouvert aux rôles `admin` et `trainer` (middleware `role`, `DatasetPolicy`).

## Parcours

1. **Assistant** (`Admin\Datasets\DatasetImportWizard`) :
   - saisie du nom, de la description et du domaine, choix du format, envoi des fichiers ;
   - **Analyser** : les fichiers sont lus et assemblés, sans rien enregistrer, puis un aperçu s'affiche
     (tables, types déduits, clés, 5 premières lignes, avertissements) ;
   - les tables peuvent être renommées, puis on lance l'import.
2. **Job** `ImportDatasetJob`, mis en file d'attente, qui appelle `DatasetImporter::run()` :
   - le jeu canonique est traduit en SQL pour SQLite, PostgreSQL et MySQL (`Writers\SqlWriter`) ;
   - un build est créé pour chaque moteur. Pour les moteurs actifs, la base sandbox est construite tout de suite,
     ce qui fait apparaître une éventuelle erreur dès l'import ;
   - le jeu enregistre ses `tables_meta`, son diagramme Mermaid, sa volumétrie et son historique d'import.
3. **Fiche** (`Admin\Datasets\DatasetShow`) :
   - état de l'import et des moteurs, rafraîchi automatiquement pendant l'import ;
   - aperçu des données, lu dans la sandbox ;
   - liaison aux exercices, comme jeu visible ou comme jeu de test caché.

## Formats

| Format | Règle |
|---|---|
| CSV | Un fichier par table, nommée d'après le fichier. Séparateur détecté (`,` `;` tabulation `\|`), BOM retiré, Windows-1252 converti, cellule vide = `NULL`. |
| JSON | `[ {…}, … ]` donne une table ; `{ "table": [ {…} ], … }` en donne plusieurs. Les valeurs imbriquées sont stockées en texte JSON. |
| Dump SQL | SQL standard ou SQLite. Il est validé par le garde-fou, **exécuté dans le processus SQLite isolé**, puis relu : les types déclarés et les clés sont conservés. |

## Déductions (`DatasetAssembler`)

- **Noms** : snake_case ASCII, sans chiffre en tête. Les mots réservés reçoivent le suffixe `_value` et les doublons
  sont numérotés. Chaque renommage produit un avertissement.
- **Types** : on retient le plus étroit compatible avec toutes les valeurs, parmi booléen (`oui`/`non` acceptés),
  entier, décimal (virgule acceptée), date, horodatage et texte. Les zéros non significatifs (`06000`) restent du texte.
- **Clé primaire** : celle du dump si elle existe, sinon une colonne `id` entière, unique et non nulle.
- **Clé étrangère** : une colonne `xxx_id` qui pointe vers une table `xxx` ou `xxxs` dotée d'une clé primaire entière.
  Elle n'est retenue que si **toutes** les valeurs existent dans la table cible. Les références circulaires sont retirées.
- **Limites** : 30 tables, 100 colonnes par table, 200 000 lignes, 20 Mo par fichier.

## Limites connues
- Les dumps propres à MySQL ou PostgreSQL (`AUTO_INCREMENT`, `SERIAL`, `COPY ... FROM stdin`…) ne sont pas encore
  traduits. Il faut les exporter en SQL standard, ou en CSV.
- Une contrainte en échec (clé étrangère incohérente) est retirée avec un avertissement, sans bloquer l'import.
