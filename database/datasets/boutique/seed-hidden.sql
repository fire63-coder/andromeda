-- Données de test cachées : même schéma, valeurs différentes (anti « résultat codé en dur »).
INSERT INTO customers (id, name, email, city, created_at) VALUES
    (1, 'Zoé Blanc', 'zoe@example.com', 'Lyon', '2024-01-05'),
    (2, 'Inès Garnier', 'ines@example.com', 'Nantes', '2024-01-22'),
    (3, 'Jules Faure', 'jules@example.com', 'Paris', '2024-03-02'),
    (4, 'Karima Haddad', 'karima@example.com', 'Lyon', '2024-03-30'),
    (5, 'Léo Mercier', 'leo@example.com', 'Bordeaux', '2024-05-11');

INSERT INTO products (id, name, category, price, stock) VALUES
    (1, 'Apprendre SQL', 'Livres', 29.00, 10),
    (2, 'Casque audio', 'Informatique', 59.90, 30),
    (3, 'Stickers SQL', 'Goodies', 4.50, 500),
    (4, 'Data Modeling', 'Livres', 52.00, 5),
    (5, 'Hub USB-C', 'Informatique', 34.90, 44),
    (6, 'Livre de requêtes', 'Livres', 18.00, 33);

INSERT INTO orders (id, customer_id, ordered_at, status) VALUES
    (1, 2, '2024-06-02', 'livrée'),
    (2, 4, '2024-06-08', 'livrée'),
    (3, 1, '2024-06-11', 'annulée'),
    (4, 4, '2024-06-25', 'livrée'),
    (5, 3, '2024-07-02', 'expédiée');

INSERT INTO order_items (id, order_id, product_id, quantity, unit_price) VALUES
    (1, 1, 4, 2, 52.00),
    (2, 1, 3, 10, 4.50),
    (3, 2, 2, 1, 59.90),
    (4, 3, 1, 1, 29.00),
    (5, 4, 5, 3, 34.90),
    (6, 4, 6, 1, 18.00),
    (7, 5, 2, 2, 59.90);
