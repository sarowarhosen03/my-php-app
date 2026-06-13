# my-php-app

A local PHP development environment powered by Docker. Includes Nginx, PHP-FPM 8.2, MySQL 8, and phpMyAdmin — all wired together and ready to run with a single command.

---

## Stack

| Service    | Image              | Description                          |
|------------|--------------------|--------------------------------------|
| PHP        | `php:8.2-fpm`      | PHP-FPM with `pdo_mysql` & `mysqli`  |
| Nginx      | `nginx:alpine`     | Web server, proxies PHP via FastCGI  |
| MySQL      | `mysql:8.0`        | Relational database                  |
| phpMyAdmin | `phpmyadmin:latest`| Web-based MySQL GUI                  |

---

## Project Structure

```
my-php-app/
├── docker-compose.yml       # Orchestrates all services
├── .docker/
│   ├── Dockerfile           # Builds the PHP image with MySQL extensions
│   ├── nginx.conf           # Nginx server block config
│   └── php.ini              # Custom PHP settings
└── src/
    └── index.php            # Your application entry point
```

---

## URLs

| Service    | URL                          |
|------------|------------------------------|
| App        | http://localhost:8080        |
| phpMyAdmin | http://localhost:8081        |

---

## Database Credentials

| Setting   | Value      |
|-----------|------------|
| Host      | `mysql` (inside Docker) / `127.0.0.1` (from host) |
| Port      | `3306` (inside Docker) / `3307` (from host)        |
| Database  | `app_db`   |
| Username  | `app_user` |
| Password  | `app_pass` |
| Root password | `root` |

> **Note:** These credentials are for local development only. Change them before deploying anywhere else.

---

## Getting Started

### Prerequisites

- [Docker](https://docs.docker.com/get-docker/)
- [Docker Compose](https://docs.docker.com/compose/install/) v2+

### Start

```bash
docker compose up -d --build
```

The `--build` flag is only needed on first run or after changing the `Dockerfile`.

### Stop

```bash
docker compose down
```

### Stop and remove volumes (full reset)

```bash
docker compose down -v
```

> This deletes the MySQL data volume. All database data will be lost.

### View logs

```bash
# All services
docker compose logs -f

# Specific service
docker compose logs -f mysql
docker compose logs -f php
docker compose logs -f nginx
```

### Rebuild a single service

```bash
docker compose up -d --build php
```

---

## Connecting to MySQL from PHP

Use these settings in your PHP code:

```php
$pdo = new PDO(
    dsn: 'mysql:host=mysql;port=3306;dbname=app_db;charset=utf8mb4',
    username: 'app_user',
    password: 'app_pass',
);
```

Or with `mysqli`:

```php
$mysqli = new mysqli('mysql', 'app_user', 'app_pass', 'app_db');
```

---

## PHP Configuration

Custom PHP settings live in `.docker/php.ini` and are mounted into the container at runtime.

| Setting                 | Value  |
|-------------------------|--------|
| `display_errors`        | On     |
| `error_reporting`       | E_ALL  |
| `memory_limit`          | 256M   |
| `upload_max_filesize`   | 32M    |
| `post_max_size`         | 32M    |
| `max_execution_time`    | 60s    |

Edit `.docker/php.ini` and restart the `php` container to apply changes:

```bash
docker compose restart php
```

---

## Troubleshooting

### Port already in use

If you see `bind: address already in use`, another process is using one of the mapped ports. Check which ports are taken:

```bash
sudo ss -tulpn | grep -E '8080|8081|3307'
```

Then either stop the conflicting process or update the host-side port in `docker-compose.yml` (e.g. change `"8080:80"` to `"8090:80"`).

### MySQL fails to start (corrupt data volume)

If MySQL logs show `Cannot create redo log files` or `data files are corrupt`, the data volume is stale. Remove it and restart:

```bash
docker compose down -v
docker compose up -d --build
```

### Permission issues on `src/`

If Nginx or PHP can't read files in `src/`, ensure the files are world-readable:

```bash
chmod -R 755 src/
```
