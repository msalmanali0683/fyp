# FYP Admin — Laravel + Vue (Single Project)

Unified Laravel backend and Vue 3 frontend at `C:\xampp\htdocs\fyp`.

## Project Structure

```
fyp/
├── app/                    # Laravel API
├── routes/
│   ├── api.php             # REST API routes
│   └── web.php             # SPA catch-all → Vue app
├── resources/
│   ├── js/                 # Vue 3 SPA
│   ├── sass/               # Vuexy-style theme
│   └── views/app.blade.php
├── public/                 # Web root (Apache points here)
└── database/
```

## Setup

```powershell
cd C:\xampp\htdocs\fyp
composer install
npm install
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
npm run build
```

## Run (Development)

**One command:**
```powershell
cd C:\xampp\htdocs\fyp
composer run dev
```

**Or manually:**
```powershell
php artisan serve
npm run dev
```

- Artisan serve: **http://127.0.0.1:8000**
- XAMPP: **http://localhost/fyp/public**

## Demo Login (local development only)

These accounts come from `php artisan migrate --seed` and are **not** created in production.

| Email | Password | Role |
|-------|----------|------|
| admin@fyp.com | password | Super Admin |
| user@fyp.com | password | User |

## Deployment

Production runs at https://fyp.amsal.online (Hostinger) and updates automatically:

1. Push to `main`. GitHub Actions (`.github/workflows/deploy.yml`) runs the test suite.
2. If the tests pass, `deploy/publish.sh` builds the production tree (source + `vendor/` +
   `public/build/`) and pushes it to the `deploy` branch. `.env` is never part of it.
3. Hostinger's Git auto-deploy mirrors the `deploy` branch into the site folder.
4. A server cron job runs `deploy/post-deploy.sh`, which applies migrations and refreshes the
   Laravel caches once per new revision.

Things to know:

- Uploads (`storage/app/public`) and logs (`storage/logs`) are symlinks into a `persist/` folder
  outside the web root, because each Git deploy deletes everything in the site folder that is not
  in the branch. The server `.env` is the one file Hostinger leaves alone.
- A failing test blocks the deploy; the site keeps running the previous version.
- Run `DEPLOY_REMOTE_URL=... bash deploy/publish.sh` from a clean `composer install --no-dev` +
  `npm run build` checkout to publish by hand.

## XAMPP

Apache document root should be:
`C:\xampp\htdocs\fyp\public`

Or access via: `http://localhost/fyp/public`

Ensure `.env` has:
```env
APP_URL=http://localhost/fyp/public
```
