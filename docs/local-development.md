# Local Development

## Overview

This project supports two local environments:

1. **SQLite** — lightweight database file, no MySQL server required.
2. **MySQL** — local copy of the production database (`radium_desk_local`).

| Environment | Use when |
|-------------|----------|
| SQLite | Running automated tests, validating migrations on a clean schema, or working in a CI-like setup where speed matters more than real data. |
| MySQL | Building or debugging UI against realistic data, reproducing production bugs, or verifying behavior that depends on production-like volume and relationships. |

Both environments are configured via checked-in templates (`.env.sqlite` and `.env.mysql`). Your active configuration lives in `.env`, which is never committed.

---

## SQLite

**Purpose:**

- Fast testing
- Fresh migrations
- CI-like environment

**Switch:**

```bash
cp .env.sqlite .env
php artisan optimize:clear
composer dev
```

`composer dev` starts the app server, queue worker, Reverb, log tail, and Vite dev server concurrently.

---

## MySQL

**Purpose:**

- Local copy of production database
- UI development
- Bug reproduction

**Switch:**

```bash
cp .env.mysql .env
php artisan optimize:clear
composer dev
```

The MySQL template points at database `radium_desk_local` on `127.0.0.1:3306`. Ensure MySQL is running and the database exists before starting the app.

---

## Refreshing the production clone

Periodically replace the local MySQL data with a fresh dump from production so UI work and bug reproduction stay accurate.

1. **Download SQL dump** — obtain the latest production database export through your team’s usual process (e.g. managed backup, ops export, or approved tooling).

2. **Import into `radium_desk_local`** — recreate or overwrite the local database, then load the dump:

   ```bash
   mysql -u root -e "DROP DATABASE IF EXISTS radium_desk_local; CREATE DATABASE radium_desk_local;"
   mysql -u root radium_desk_local < /path/to/production-dump.sql
   ```

   Adjust username, host, and dump path to match your machine.

3. **Switch to `.env.mysql`** — if you are not already on the MySQL profile:

   ```bash
   cp .env.mysql .env
   ```

4. **Clear Laravel cache** — so config and cached routes reflect the MySQL environment:

   ```bash
   php artisan optimize:clear
   ```

   Restart `composer dev` if it is already running.

---

## Good practices

- **Never edit `.env.mysql` directly after switching.** Copy the template to `.env` and change only `.env` if you need local overrides. That keeps the templates stable for the next `cp`.
- **Never commit `.env` files.** They are listed in `.gitignore` along with `.env.sqlite`, `.env.mysql`, and backup variants.
- **Keep `.env.example` as the project template.** New settings belong in `.env.example` first; environment-specific values belong in `.env.sqlite` or `.env.mysql` as appropriate.
- **Refresh the production clone periodically.** Stale local data leads to false positives and missed edge cases.
- **Use SQLite for automated tests whenever possible.** Tests run faster and stay isolated from your MySQL clone. Reserve MySQL for manual exploration and scenarios that require production-like data.
