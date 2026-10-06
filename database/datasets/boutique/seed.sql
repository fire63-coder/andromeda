INSERT INTO customers (id, name, email, city, created_at) VALUES
    (1, 'Alice Martin', 'alice@example.com', 'Lyon', '2024-01-12'),
    (2, 'Bruno Petit', 'bruno@example.com', 'Paris', '2024-02-03'),
    (3, 'Chloé Durand', 'chloe@example.com', 'Lyon', '2024-02-20'),
    (4, 'David Leroy', 'david@example.com', 'Marseille', '2024-03-15'),
    (5, 'Emma Moreau', 'emma@example.com', 'Paris', '2024-04-01'),
    (6, 'Farid Benali', 'farid@example.com', 'Lille', '2024-04-18'),
    (7, 'Gaëlle Roux', 'gaelle@example.com', 'Lyon', '2024-05-09');

INSERT INTO products (id, name, category, price, stock) VALUES
    (1, 'SQL pour les nuls', 'Livres', 24.90, 40),
    (2, 'Clavier mécanique', 'Informatique', 89.00, 15),
    (3, 'Souris sans fil', 'Informatique', 29.50, 60),
    (4, 'Bases de données relationnelles', 'Livres', 39.00, 25),
    (5, 'Écran 27 pouces', 'Informatique', 249.00, 8),
    (6, 'Mug « SELECT * »', 'Goodies', 12.00, 120),
    (7, 'T-shirt « JOIN us »', 'Goodies', 19.90, 70),
    (8, 'PostgreSQL avancé', 'Livres', 45.00, 12);

INSERT INTO orders (id, customer_id, ordered_at, status) VALUES
    (1, 1, '2024-06-01', 'livrée'),
    (2, 2, '2024-06-03', 'livrée'),
    (3, 1, '2024-06-10', 'expédiée'),
    (4, 3, '2024-06-12', 'livrée'),
    (5, 4, '2024-06-15', 'annulée'),
    (6, 5, '2024-06-20', 'livrée'),
    (7, 2, '2024-07-01', 'en préparation'),
    (8, 3, '2024-07-04', 'livrée');

INSERT INTO order_items (id, order_id, product_id, quantity, unit_price) VALUES
    (1, 1, 1, 2, 24.90),
    (2, 1, 6, 1, 12.00),
    (3, 2, 2, 1, 89.00),
    (4, 2, 3, 1, 29.50),
    (5, 3, 5, 1, 249.00),
    (6, 4, 4, 1, 39.00),
    (7, 4, 8, 1, 45.00),
    (8, 5, 7, 3, 19.90),
    (9, 6, 1, 1, 24.90),
    (10, 6, 3, 2, 29.50),
    (11, 7, 6, 4, 12.00),
    (12, 8, 2, 1, 89.00),
    (13, 8, 7, 2, 19.90);
