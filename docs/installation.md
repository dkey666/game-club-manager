# Installation

## Requirements

- PHP 8.1 or newer
- Composer 2
- Node.js 18+ and npm
- SQLite support for PHP runtime
- SMTP credentials for email verification
- A Telegram bot token and admin IDs

## 1. Install dependencies

PHP:

```bash
composer install
```

Node:

```bash
npm install
```

## 2. Create environment configuration

Copy the template:

```bash
cp .env.example .env
```

Update at least:

- `APP_URL`
- `WEBHOOK_URL`
- `BOT_TOKEN`
- `ADMIN_IDS`
- `GROUP_ID`
- `SMTP_HOST`
- `SMTP_USERNAME`
- `SMTP_PASSWORD`

## 3. Initialize the database

```bash
npm run db:init
```

This creates `club.db` locally using the bootstrap logic from [database.js](../database.js).

## 4. Start locally

```bash
php -S localhost:8000
```

Then open:

```text
http://localhost:8000/
```

## 5. Configure Telegram webhook

After deploying to a public HTTPS URL:

```bash
php webhook_setup.php
```

Make sure `WEBHOOK_URL` points to your actual deployment, for example:

```text
https://your-domain.example/index.php?webhook=1
```

## 6. Production checklist

- enable HTTPS
- confirm `.env` and database files are not public
- verify SMTP delivery
- verify Telegram WebApp opens the correct `WEBAPP_URL`
- replace any leftover example values
- rotate any previously exposed secrets before launch
