-- Jeu de données « Entreprise » : SQL portable (SQLite, PostgreSQL, MySQL).
CREATE TABLE departments (
    id INTEGER PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    city VARCHAR(80) NOT NULL
);

CREATE TABLE employees (
    id INTEGER PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    department_id INTEGER NOT NULL REFERENCES departments (id),
    manager_id INTEGER REFERENCES employees (id),
    job_title VARCHAR(80) NOT NULL,
    salary NUMERIC(10, 2) NOT NULL,
    hired_at DATE NOT NULL
);

-- Historique des changements de salaire, alimenté par un trigger (niveau Expert).
CREATE TABLE salary_audit (
    employee_id INTEGER NOT NULL REFERENCES employees (id),
    old_salary NUMERIC(10, 2) NOT NULL,
    new_salary NUMERIC(10, 2) NOT NULL
);

-- Index secondaire (chapitre « Optimisation ») : les recherches par date d'embauche peuvent l'utiliser.
CREATE INDEX idx_employees_hired_at ON employees (hired_at);
