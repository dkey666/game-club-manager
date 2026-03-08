# Architecture

## Overview

`Dkx Game Club OS/Manager` is a monolithic web application optimized for simple hosting. The current implementation uses PHP entry points, SQLite persistence, Telegram WebApp validation, and static frontend pages.

## Main runtime pieces

- `index.php`: serves as the Telegram webhook entrypoint and the main web entry.
- `api.php`: JSON API for points, bookings, referrals, notifications, rankings, and admin actions.
- `admin.html`, `new.html`, `booking.html`, `points.html`, `profile.html`: frontend pages used by customers and administrators.
- `config.php`: reads runtime configuration from `.env` and defines shared constants.
- `database.js`: optional Node helper for bootstrapping the SQLite schema locally.

## Data flow

1. A user opens the Telegram bot or WebApp.
2. Telegram sends webhook updates to `index.php?webhook=1`.
3. The web frontend calls `api.php?action=...` for dynamic actions.
4. `api.php` initializes or updates SQLite tables on demand.
5. Admin actions send responses back to users through the Telegram Bot API.

## Storage model

The project currently uses a single SQLite database file.

Main entities:

- `users`
- `computers`
- `bookings`
- `tasks`
- `user_tasks`
- `points_transactions`
- `referrals`
- `user_notifications`
- `email_verifications`
- `daily_rewards`
- `user_ranks`
- `rate_limits`

See [database/schema.sql](../database/schema.sql) for the public schema reference.

## Deployment model

The app is designed for shared hosting or a small VPS with:

- PHP 8.1+
- SQLite support
- Apache with `.htaccess`
- outbound access to Telegram Bot API and SMTP

## Current technical debt

- business logic is split between `index.php` and `api.php`
- runtime `ALTER TABLE` calls replace explicit migrations
- `/uz` duplicates significant frontend and webhook logic
- frontend scripts are mostly inline and not bundled
- admin and user flows still assume a tightly coupled single-club deployment

## Recommended evolution

- extract bootstrap/config/database layers into dedicated files
- introduce migration scripts
- move shared frontend code into reusable assets
- add API tests around booking, points, referral, and notification flows
