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

## Demo Login

| Email | Password | Role |
|-------|----------|------|
| admin@fyp.com | password | Super Admin |
| user@fyp.com | password | User |

## XAMPP

Apache document root should be:
`C:\xampp\htdocs\fyp\public`

Or access via: `http://localhost/fyp/public`

Ensure `.env` has:
```env
APP_URL=http://localhost/fyp/public
```
