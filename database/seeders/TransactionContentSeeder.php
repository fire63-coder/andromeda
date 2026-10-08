<?php

namespace Database\Seeders;

use App\Enums\ExerciseType;
use App\Enums\ValidationStrategy;
use App\Models\Course;
use App\Models\Dataset;
use App\Models\Level;
use App\Models\SqlDialect;
use App\Services\Datasets\SchemaIntrospector;

/**
 * Cours « Transactions et concurrence » (niveau 4, PostgreSQL) : ACID, niveaux d'isolation,
 * verrous et interblocages, avec des scénarios à plusieurs sessions (-- A / -- B).
 */
class TransactionContentSeeder extends DemoContentSeeder
{
    public function run(SchemaIntrospector $introspector): void
    {
        $shop = Dataset::where('slug', 'boutique')->firstOrFail();
        $hidden = Dataset::where('slug', 'boutique-tests')->firstOrFail();
        $level = Level::where('position', 4)->firstOrFail();
        $pgsql = SqlDialect::where('slug', 'pgsql')->firstOrFail();
        $summary = 'Transactions ACID, niveaux d\'isolation, verrous et interblocages : plusieurs sessions s\'exécutent en même temps sous vos yeux.';

        $acid = $this->lesson($level, 'transactions-et-concurrence', 'Transactions et concurrence', 'transactions', 'Transactions', 'transactions-acid', 'Transactions et propriétés ACID', $shop, <<<'MD'
            # Transactions et propriétés ACID

            Une **transaction** regroupe plusieurs instructions qui réussissent ou échouent **ensemble** :

            - **Atomicité** : tout ou rien ;
            - **Cohérence** : les contraintes sont respectées à la fin ;
            - **Isolation** : les transactions simultanées ne se voient pas à moitié ;
            - **Durabilité** : une fois validée (`COMMIT`), la modification survit à une panne.

            ## Tout ou rien

            Dans les exemples de ce cours, chaque étape commence par `-- A` ou `-- B` : la **session** (le client
            connecté) qui l'exécute. Ici, une seule session enregistre une vente puis change d'avis :

            ```sql runnable
            -- A
            BEGIN;
            UPDATE products SET stock = stock - 2 WHERE id = 1;
            INSERT INTO orders (id, customer_id, ordered_at, status) VALUES (100, 1, '2024-08-01', 'en préparation');
            SELECT id, stock FROM products WHERE id = 1;
            -- A
            ROLLBACK;
            SELECT id, stock FROM products WHERE id = 1;
            ```

            Après le `ROLLBACK`, le stock et les commandes sont revenus à leur état initial.

            ## Ce que voient les autres sessions

            Tant que A n'a pas validé, B lit l'**ancienne** valeur : PostgreSQL ne permet jamais de « lecture sale ».

            ```sql runnable
            -- A
            BEGIN;
            UPDATE products SET stock = 0 WHERE id = 1;
            -- B
            SELECT stock FROM products WHERE id = 1;
            -- A
            COMMIT;
            -- B
            SELECT stock FROM products WHERE id = 1;
            ```

            ## Points de sauvegarde

            `SAVEPOINT` permet d'annuler une partie seulement de la transaction :

            ```sql runnable
            -- A
            BEGIN;
            UPDATE products SET price = price * 1.10 WHERE category = 'Livres';
            SAVEPOINT avant_goodies;
            UPDATE products SET price = 0 WHERE category = 'Goodies';
            ROLLBACK TO SAVEPOINT avant_goodies;
            COMMIT;
            SELECT name, category, price FROM products ORDER BY id;
            ```

            MD, $summary);

        $isolation = $this->lesson($level, 'transactions-et-concurrence', 'Transactions et concurrence', 'transactions', 'Transactions', 'niveaux-isolation', 'Niveaux d\'isolation et anomalies', $shop, <<<'MD'
            # Niveaux d'isolation et anomalies

            Quand deux transactions s'entrelacent, des **anomalies** peuvent apparaître. Le niveau d'isolation
            choisi au `BEGIN` détermine celles qui sont évitées :

            | Anomalie | READ COMMITTED (défaut) | REPEATABLE READ | SERIALIZABLE |
            |---|---|---|---|
            | Lecture sale | impossible | impossible | impossible |
            | Lecture non répétable | **possible** | impossible | impossible |
            | Lecture fantôme | **possible** | impossible (PostgreSQL) | impossible |
            | Anomalie de sérialisation | **possible** | **possible** | impossible |

            ## Lecture non répétable

            A lit deux fois le même stock ; entre les deux, B le modifie et valide :

            ```sql runnable
            -- A
            BEGIN;
            SELECT stock FROM products WHERE id = 1;
            -- B
            UPDATE products SET stock = stock - 5 WHERE id = 1;
            -- A
            SELECT stock FROM products WHERE id = 1;
            COMMIT;
            ```

            Remplacez `BEGIN;` par `BEGIN ISOLATION LEVEL REPEATABLE READ;` : A travaille alors sur un
            **instantané** pris à sa première lecture, et lit deux fois la même valeur.

            ## La mise à jour perdue

            Deux vendeurs lisent le stock (40), chacun calcule « 40 − 1 » dans son application et écrit 39 :
            une vente a disparu.

            ```sql runnable
            -- A
            BEGIN;
            SELECT stock FROM products WHERE id = 1;
            -- B
            BEGIN;
            SELECT stock FROM products WHERE id = 1;
            -- A
            UPDATE products SET stock = 39 WHERE id = 1;
            COMMIT;
            -- B
            UPDATE products SET stock = 39 WHERE id = 1;
            COMMIT;
            ```

            ## L'échec de sérialisation

            En `REPEATABLE READ`, PostgreSQL refuse de modifier une ligne changée par une autre transaction depuis
            l'instantané : l'application reçoit l'erreur **40001** et doit **rejouer** sa transaction.

            ```sql runnable
            -- A
            BEGIN ISOLATION LEVEL REPEATABLE READ;
            SELECT stock FROM products WHERE id = 1;
            -- B
            UPDATE products SET stock = stock - 1 WHERE id = 1;
            -- A
            UPDATE products SET stock = stock - 1 WHERE id = 1;
            -- A
            ROLLBACK;
            ```

            MD, $summary, lessonPosition: 1);

        $locks = $this->lesson($level, 'transactions-et-concurrence', 'Transactions et concurrence', 'transactions', 'Transactions', 'verrous-et-interblocages', 'Verrous et interblocages', $shop, <<<'MD'
            # Verrous et interblocages

            ## Verrouiller ce qu'on va modifier

            `SELECT … FOR UPDATE` pose un verrou sur les lignes lues : une autre session qui veut les verrouiller
            ou les modifier **attend** la fin de la transaction.

            ```sql runnable
            -- A
            BEGIN;
            SELECT stock FROM products WHERE id = 1 FOR UPDATE;
            -- B
            BEGIN;
            SELECT stock FROM products WHERE id = 1 FOR UPDATE;
            -- A
            UPDATE products SET stock = stock - 1 WHERE id = 1;
            COMMIT;
            -- B
            UPDATE products SET stock = stock - 1 WHERE id = 1;
            COMMIT;
            ```

            B reste bloquée jusqu'au `COMMIT` de A, puis lit la valeur à jour : aucune vente perdue.

            ## L'interblocage

            A verrouille le produit 1 puis veut le 2 ; B verrouille le 2 puis veut le 1. Chacune attend l'autre :
            PostgreSQL détecte l'**interblocage** (erreur **40P01**) et annule l'une des deux.

            ```sql runnable
            -- A
            BEGIN;
            UPDATE products SET stock = stock - 1 WHERE id = 1;
            -- B
            BEGIN;
            UPDATE products SET stock = stock - 1 WHERE id = 2;
            -- A
            UPDATE products SET stock = stock - 1 WHERE id = 2;
            -- B
            UPDATE products SET stock = stock - 1 WHERE id = 1;
            -- A
            COMMIT;
            -- B
            COMMIT;
            ```

            La règle pour l'éviter : **toutes les transactions verrouillent les ressources dans le même ordre**
            (par exemple par identifiant croissant).

            MD, $summary, lessonPosition: 2);

        Course::where('slug', 'transactions-et-concurrence')->update(['sql_dialect_id' => $pgsql->id]);

        $datasets = [$shop, $hidden];
        $postgresOnly = ['sql_dialect_id' => $pgsql->id];

        $this->exercise($isolation, $level, $datasets, ['transactions'], [
            ...$postgresOnly,
            'slug' => 'mise-a-jour-perdue',
            'title' => 'Concurrence : la vente perdue',
            'type' => ExerciseType::BugFix,
            'statement' => "Deux vendeurs (sessions A et B) vendent en même temps un exemplaire du produit n° 1. À la fin, le stock doit avoir baissé de **2**… mais une vente est perdue.\n\nCorrigez le scénario **sans changer l'ordre des étapes** : quel que soit le stock de départ, les deux ventes doivent être comptées.",
            'starter_sql' => "-- A\nBEGIN;\nSELECT stock FROM products WHERE id = 1;\n-- B\nBEGIN;\nSELECT stock FROM products WHERE id = 1;\n-- A\nUPDATE products SET stock = 39 WHERE id = 1;\nCOMMIT;\n-- B\nUPDATE products SET stock = 39 WHERE id = 1;\nCOMMIT;",
            'solution_sql' => "-- A\nBEGIN;\nSELECT stock FROM products WHERE id = 1;\n-- B\nBEGIN;\nSELECT stock FROM products WHERE id = 1;\n-- A\nUPDATE products SET stock = stock - 1 WHERE id = 1;\nCOMMIT;\n-- B\nUPDATE products SET stock = stock - 1 WHERE id = 1;\nCOMMIT;",
            'validation_strategy' => ValidationStrategy::Concurrency,
            'validation_options' => [
                'no_errors' => true,
                'check_queries' => ['products' => 'SELECT id, stock FROM products ORDER BY id'],
            ],
            'hints' => [
                ['text' => 'La valeur 39 a été calculée à partir d\'une lecture devenue fausse entre-temps.', 'xp_penalty' => 5],
                ['text' => 'Laissez la base calculer : `UPDATE products SET stock = stock - 1 …` (ou verrouillez la ligne lue avec `FOR UPDATE`).', 'xp_penalty' => 10],
            ],
            'difficulty' => 3,
            'xp_reward' => 60,
            'position' => 1,
        ]);

        $this->exercise($isolation, $level, $datasets, ['transactions'], [
            ...$postgresOnly,
            'slug' => 'inventaire-coherent',
            'title' => 'Concurrence : un inventaire cohérent',
            'type' => ExerciseType::QueryWrite,
            'statement' => "La session A calcule deux fois le stock total des livres pendant un inventaire ; entre-temps, la session B reçoit une livraison.\n\nModifiez **uniquement la session A** pour que ses deux lectures (étapes 1 et 3) donnent le **même** total, sans empêcher la livraison de B d'être enregistrée.",
            'starter_sql' => "-- A\nBEGIN;\nSELECT SUM(stock) AS total FROM products WHERE category = 'Livres';\n-- B\nUPDATE products SET stock = stock + 10 WHERE category = 'Livres';\n-- A\nSELECT SUM(stock) AS total FROM products WHERE category = 'Livres';\nCOMMIT;",
            'solution_sql' => "-- A\nBEGIN ISOLATION LEVEL REPEATABLE READ;\nSELECT SUM(stock) AS total FROM products WHERE category = 'Livres';\n-- B\nUPDATE products SET stock = stock + 10 WHERE category = 'Livres';\n-- A\nSELECT SUM(stock) AS total FROM products WHERE category = 'Livres';\nCOMMIT;",
            'validation_strategy' => ValidationStrategy::Concurrency,
            'validation_options' => [
                'compare_steps' => [1, 3],
                'check_queries' => ['products' => 'SELECT id, stock FROM products ORDER BY id'],
            ],
            'hints' => [
                ['text' => 'En READ COMMITTED (le défaut), chaque instruction voit les dernières données validées.', 'xp_penalty' => 5],
                ['text' => 'Ouvrez la transaction de A avec `BEGIN ISOLATION LEVEL REPEATABLE READ;`.', 'xp_penalty' => 10],
            ],
            'difficulty' => 3,
            'xp_reward' => 60,
            'position' => 2,
        ]);

        $mcqDirty = $this->exercise($isolation, $level, [], ['transactions'], [
            'slug' => 'qcm-read-uncommitted-postgresql',
            'title' => 'QCM : READ UNCOMMITTED en PostgreSQL',
            'type' => ExerciseType::MultipleChoice,
            'statement' => 'En PostgreSQL, une transaction ouverte avec `BEGIN ISOLATION LEVEL READ UNCOMMITTED` peut-elle lire des modifications **non validées** par une autre session ?',
            'validation_strategy' => ValidationStrategy::Choices,
            'difficulty' => 2,
            'xp_reward' => 15,
            'position' => 3,
        ]);
        $mcqDirty->choices()->delete();
        $mcqDirty->choices()->createMany([
            ['body' => 'Non : PostgreSQL traite READ UNCOMMITTED comme READ COMMITTED', 'is_correct' => true, 'explanation' => 'Grâce au MVCC, PostgreSQL ne montre jamais de données non validées, quel que soit le niveau.', 'position' => 1],
            ['body' => 'Oui, c\'est le principe de ce niveau', 'is_correct' => false, 'explanation' => 'C\'est vrai dans la norme SQL et pour d\'autres moteurs, mais pas en PostgreSQL.', 'position' => 2],
            ['body' => 'Oui, mais seulement pour les SELECT … FOR UPDATE', 'is_correct' => false, 'explanation' => 'FOR UPDATE verrouille des lignes validées ; il ne lit pas de données non validées.', 'position' => 3],
        ]);

        $this->exercise($locks, $level, $datasets, ['transactions'], [
            ...$postgresOnly,
            'slug' => 'interblocage-transfert',
            'title' => 'Concurrence : le transfert qui s\'interbloque',
            'type' => ExerciseType::BugFix,
            'statement' => "La session A transfère 5 unités de stock du produit 1 vers le produit 2 ; la session B en transfère 3 du produit 2 vers le produit 1. Exécutées en même temps, elles s'interbloquent et l'une est annulée.\n\nCorrigez le scénario, **sans changer l'ordre des étapes**, pour que les deux transferts aboutissent.",
            'starter_sql' => "-- A\nBEGIN;\nUPDATE products SET stock = stock - 5 WHERE id = 1;\n-- B\nBEGIN;\nUPDATE products SET stock = stock - 3 WHERE id = 2;\n-- A\nUPDATE products SET stock = stock + 5 WHERE id = 2;\n-- B\nUPDATE products SET stock = stock + 3 WHERE id = 1;\n-- A\nCOMMIT;\n-- B\nCOMMIT;",
            'solution_sql' => "-- A\nBEGIN;\nUPDATE products SET stock = stock - 5 WHERE id = 1;\n-- B\nBEGIN;\nUPDATE products SET stock = stock + 3 WHERE id = 1;\n-- A\nUPDATE products SET stock = stock + 5 WHERE id = 2;\n-- B\nUPDATE products SET stock = stock - 3 WHERE id = 2;\n-- A\nCOMMIT;\n-- B\nCOMMIT;",
            'validation_strategy' => ValidationStrategy::Concurrency,
            'validation_options' => [
                'no_errors' => true,
                'check_queries' => ['products' => 'SELECT id, stock FROM products ORDER BY id'],
            ],
            'hints' => [
                ['text' => 'A verrouille 1 puis 2, B verrouille 2 puis 1 : chacune attend le verrou de l\'autre.', 'xp_penalty' => 5],
                ['text' => 'Faites verrouiller les produits dans le même ordre par les deux sessions : B commence par le produit 1.', 'xp_penalty' => 10],
            ],
            'difficulty' => 4,
            'xp_reward' => 80,
            'position' => 1,
        ]);

        $mcqRetry = $this->exercise($locks, $level, [], ['transactions'], [
            'slug' => 'qcm-erreur-40001',
            'title' => 'QCM : réagir à une erreur 40001',
            'type' => ExerciseType::MultipleChoice,
            'statement' => 'Votre application reçoit l\'erreur `40001 could not serialize access due to concurrent update`. Que doit-elle faire ?',
            'validation_strategy' => ValidationStrategy::Choices,
            'difficulty' => 2,
            'xp_reward' => 15,
            'position' => 2,
        ]);
        $mcqRetry->choices()->delete();
        $mcqRetry->choices()->createMany([
            ['body' => 'Annuler (ROLLBACK) puis rejouer toute la transaction', 'is_correct' => true, 'explanation' => 'La transaction a été annulée pour préserver l\'isolation ; la rejouer depuis le début relit des données à jour.', 'position' => 1],
            ['body' => 'Répéter seulement l\'instruction qui a échoué', 'is_correct' => false, 'explanation' => 'La transaction est dans un état d\'échec : toute instruction est refusée jusqu\'au ROLLBACK.', 'position' => 2],
            ['body' => 'Passer en READ UNCOMMITTED pour ne plus avoir l\'erreur', 'is_correct' => false, 'explanation' => 'Baisser l\'isolation réintroduit les anomalies que l\'erreur évitait.', 'position' => 3],
            ['body' => 'Ignorer l\'erreur : les données sont déjà enregistrées', 'is_correct' => false, 'explanation' => 'Rien n\'est enregistré : la transaction est annulée.', 'position' => 4],
        ]);
    }
}
