# Installation

This project runs with Docker Compose and requires no host PHP, Composer, MySQL, or Redis
installation.

## Prerequisites

- Docker Engine
- Docker Compose v2
- Git

## Installation

From the repository root:

```bash
chmod +x bin/install
./bin/install
```

The installer:

1. Creates `.env.local` from `.env.example` if it does not exist.
2. Builds and starts PHP/Symfony, MySQL, Redis, and the scheduler.
3. Installs PHP dependencies inside the app container.
4. Applies Doctrine migrations.
5. Loads the demonstration data.

Open the application at <http://localhost:8000>.

The demo credentials are:

| Role | Email | Password |
|---|---|---|
| Client | `alice@client.ma` | `Client123!` |
| Cashier | `guichet@theatre.ma` | `Guichet123!` |
| Admin | `admin@theatre.ma` | `Admin123!` |

## Google Sheets configuration

Google Sheets is optional for running the application. To enable it:

1. Enable Google Sheets API and Google Drive API in Google Cloud.
2. Create a service account and download its JSON key.
3. Save the key as `var/secrets/google-service-account.json`.
4. Share the target spreadsheet with the service-account email as **Editor**.
5. Add these values to `.env.local`:

```dotenv
GOOGLE_SERVICE_ACCOUNT_JSON=/app/var/secrets/google-service-account.json
GOOGLE_SHEETS_ID=your_spreadsheet_id
GOOGLE_SHEETS_TITLE="Théâtre Royal — Ventes"
GOOGLE_SHEETS_DIRECTOR_EMAIL=direction@example.com
```

Restart the stack after changing `.env.local`:

```bash
docker compose up -d
```

The `scheduler` container checks for pending sales every minute and synchronizes them to the
`Ventes` tab. Without Google configuration, it remains idle and the rest of the application still
works.

## Useful commands

```bash
docker compose ps
docker compose logs -f app
docker compose logs -f scheduler
docker compose down
docker compose exec -T app php bin/phpunit
```

To reload the demonstration database explicitly:

```bash
docker compose exec -T app php bin/console doctrine:fixtures:load --no-interaction
```

This resets the database to the fixture state.

## Troubleshooting

If containers do not start, check:

```bash
docker compose logs
docker compose ps
```

If port `8000` is already in use, stop the process using it or change the host port in
`docker-compose.yml`.

If Google synchronization reports that an API is disabled, enable both APIs in the same Google
Cloud project that owns the service-account JSON, wait a few minutes, and restart the stack.

Never commit `.env.local` or the service-account JSON file.
