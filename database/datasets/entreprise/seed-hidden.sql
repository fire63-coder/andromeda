-- Jeu de test caché : ex aequo sur les meilleurs salaires, hiérarchie plus profonde, départements sans manager intermédiaire.
INSERT INTO departments (id, name, city) VALUES
    (1, 'Direction', 'Nantes'),
    (2, 'Informatique', 'Rennes'),
    (3, 'Ventes', 'Nantes'),
    (4, 'Logistique', 'Angers'),
    (5, 'Ressources humaines', 'Nantes');

INSERT INTO employees (id, name, department_id, manager_id, job_title, salary, hired_at) VALUES
    (1, 'Paul Renaud', 1, NULL, 'PDG', 8800.00, '2012-05-14'),
    (2, 'Alix Moreau', 2, 1, 'DSI', 6900.00, '2014-02-03'),
    (3, 'Bilal Kader', 2, 2, 'Architecte logiciel', 5100.00, '2015-09-21'),
    (4, 'Clara Simon', 2, 3, 'Responsable d''équipe', 4700.00, '2017-03-06'),
    (5, 'Damien Roy', 2, 4, 'Développeur', 3800.00, '2019-11-12'),
    (6, 'Elsa Vidal', 2, 5, 'Développeuse junior', 2700.00, '2023-06-26'),
    (7, 'Fabien Colin', 3, 1, 'Directeur commercial', 5600.00, '2016-08-29'),
    (8, 'Gina Lopez', 3, 7, 'Commerciale', 5600.00, '2018-04-16'),
    (9, 'Henri Marchal', 3, 7, 'Commercial', 3300.00, '2022-10-03'),
    (10, 'Iris Caron', 4, 1, 'Responsable logistique', 4300.00, '2013-01-28'),
    (11, 'Jules Masson', 4, 10, 'Magasinier', 2400.00, '2020-02-17'),
    (12, 'Karima Fabre', 4, 10, 'Magasinière', 2400.00, '2021-07-05'),
    (13, 'Loïc Brun', 5, 1, 'Responsable RH', 4500.00, '2018-12-10'),
    (14, 'Maëlle Noël', 2, 4, 'Développeuse', 3800.00, '2024-03-18');
