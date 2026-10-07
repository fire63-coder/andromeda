INSERT INTO departments (id, name, city) VALUES
    (1, 'Direction', 'Paris'),
    (2, 'Informatique', 'Lyon'),
    (3, 'Ventes', 'Paris'),
    (4, 'Ressources humaines', 'Lille');

INSERT INTO employees (id, name, department_id, manager_id, job_title, salary, hired_at) VALUES
    (1, 'Hélène Garnier', 1, NULL, 'PDG', 9500.00, '2015-03-02'),
    (2, 'Marc Lemoine', 2, 1, 'DSI', 7200.00, '2016-06-15'),
    (3, 'Sophie Bernard', 3, 1, 'Directrice commerciale', 6800.00, '2017-01-09'),
    (4, 'Julien Faure', 4, 1, 'DRH', 6100.00, '2018-09-03'),
    (5, 'Inès Haddad', 2, 2, 'Architecte logiciel', 5400.00, '2019-02-11'),
    (6, 'Thomas Girard', 2, 5, 'Développeur', 3900.00, '2021-04-19'),
    (7, 'Léa Fontaine', 2, 5, 'Développeuse', 4100.00, '2020-10-05'),
    (8, 'Hugo Perrin', 2, 2, 'Administrateur système', 3900.00, '2022-01-17'),
    (9, 'Camille Roche', 3, 3, 'Commerciale', 3600.00, '2019-07-01'),
    (10, 'Nicolas Blanc', 3, 3, 'Commercial', 3400.00, '2023-03-13'),
    (11, 'Manon Dupuis', 3, 9, 'Assistante commerciale', 2500.00, '2024-01-08'),
    (12, 'Yanis Mercier', 4, 4, 'Chargé de recrutement', 3200.00, '2021-11-22'),
    (13, 'Zoé Lambert', 2, 7, 'Développeuse junior', 2900.00, '2024-09-02');
