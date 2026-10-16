# Gestion des kits, étiquetage et optimisation

Application web de référentiel articles/kits, traçabilité par étiquette unique (réception, assemblage, sortie),
impression d'étiquettes Zebra/PDF et simulation des kits réalisables. Elle suit le dossier de conception v2.

## Pile

- Laravel 13 + PostgreSQL 18, interface Inertia + React, Fortify (connexion, double authentification)
- spatie/laravel-permission pour les rôles, dompdf pour les PDF
- Service Python isolé (FastAPI + OR-Tools CP-SAT) pour l'optimisation, dans `optimiseur/`

## Installation sur un poste de développement

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Dans `.env`, renseigner `DB_PASSWORD` (PostgreSQL) et `OPTIMISEUR_JETON` (une chaîne aléatoire d'au moins 32 caractères).
Créer les bases `gestion_kits` et `gestion_kits_test`, puis :

```bash
php artisan migrate --seed
python -m venv optimiseur/.venv
optimiseur/.venv/Scripts/pip install -r optimiseur/requirements.txt
npm run build
```

Données de démonstration (comptes, kits A et B, stock de l'exemple 5.3) : `php artisan db:seed --class=DemoSeeder`.
Les comptes de test sont listés en tête de `database/seeders/DemoSeeder.php`.

## Lancer l'application

Deux terminaux :

```bash
php artisan serve
php artisan optimiseur:lancer
```

Puis ouvrir http://localhost:8000. Pour un premier compte administrateur réel : `php artisan app:creer-admin`.

## Imprimantes

Déclarées dans Administration › Paramètres :

| Adresse | Effet |
| --- | --- |
| `tcp://10.0.0.50:9100` | ZPL envoyé directement à la Zebra |
| `fichier:nom` | ZPL écrit dans `storage/app/private/impressions` (test sans imprimante) |
| `navigateur` | PDF 50 × 30 mm ouvert dans le navigateur |

## Tests

```bash
php artisan test
cd optimiseur && .venv/Scripts/python -m pytest -q
```

Les deux suites tournent aussi sur GitHub Actions à chaque envoi (`.github/workflows/tests.yml`).

## Sécurité

- Double authentification obligatoire pour tout rôle qui détient une permission sensible (`MFA_OBLIGATOIRE=true`).
- Droits vérifiés côté serveur sur chaque route ; journal d'audit en ajout seul, imposé par la base.
- Le service d'optimisation n'écoute que sur 127.0.0.1 et exige le jeton partagé ; il n'a aucun accès à la base.
