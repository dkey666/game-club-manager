# Contributing

Thanks for contributing to `Dkx Game Club OS/Manager`.

## Before you start

- use a separate branch for each change
- do not commit `.env`, databases, logs, or local debug files
- prefer small, reviewable pull requests
- document any behavior changes in `README.md`, `docs/`, or `CHANGELOG.md`

## Local setup

1. Install PHP dependencies with `composer install`.
2. Install Node dependencies with `npm install`.
3. Copy `.env.example` to `.env`.
4. Initialize the local SQLite database with `npm run db:init`.
5. Start the local server with `php -S localhost:8000`.

## Coding expectations

- preserve the existing product behavior unless the change is intentional
- avoid hardcoding production secrets, domains, or admin identifiers
- keep new configuration in `.env.example`
- update documentation when adding setup or deployment requirements

## Pull requests

Please include:

- a short summary of what changed
- any setup or migration steps
- screenshots for UI changes when relevant
- notes about risks, edge cases, or testing gaps
