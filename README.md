# Budgie

Envelope budgeting (YNAB-style): give every dollar a job, spend from envelopes, roll with
the punches. See [PLAN.md](PLAN.md) for the full architecture and roadmap.

| App | Stack | Path |
|---|---|---|
| API | Laravel 13, MariaDB, Sanctum + Fortify (MFA), Socialite | [api/](api/) |
| Web | Nuxt 4 SPA, Pinia, Tailwind CSS 4 | [web/](web/) |
| Mobile | Flutter (Phase 3) | `mobile/` |

## Local development

Prerequisites: PHP 8.4 + Composer, Node 20+, Docker Desktop.

```sh
# 1. Infrastructure (MariaDB :3306, Redis :6379, Mailpit UI :8025)
docker compose up -d

# 2. API — http://localhost:8000
cd api
composer install
cp .env.example .env      # then: php artisan key:generate
php artisan migrate
php artisan serve

# 3. Web — http://localhost:3000
cd web
npm install
npm run dev
```

Register an account at http://localhost:3000/register and you're in.

## Tests

```sh
cd api && vendor/bin/pest      # API test suite (in-memory SQLite)
cd web && npm run typecheck    # Nuxt type safety
```

## Auth notes

- Web uses Sanctum **SPA cookie auth**: the Nuxt app calls `/sanctum/csrf-cookie`, then
  Fortify's JSON endpoints (`/login`, `/register`, `/two-factor-challenge`, `/logout`).
- MFA is TOTP via Fortify (`/user/two-factor-authentication`), with recovery codes.
- Google sign-on (Socialite) is stubbed by `GOOGLE_*` env vars — needs OAuth credentials
  from Google Cloud Console before it can be enabled.
- Mobile (Phase 3) will use Sanctum personal access tokens + device biometric unlock.
