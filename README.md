# Token Tracker Backend API

Complete Laravel backend for Token Tracker application.

## Setup Instructions

### 1. Install Dependencies

Once SSL certificate issue is resolved, run:

```bash
cd backend
composer install
```

### 2. Environment Configuration

```bash
cp .env.example .env
php artisan key:generate
```

### 3. Database Configuration

Update `.env` with your MySQL credentials:

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=token_tracker
DB_USERNAME=root
DB_PASSWORD=your_password
```

### 4. Create Database

```sql
CREATE DATABASE token_tracker;
```

### 5. Run Migrations

```bash
php artisan migrate
```

This will create:

- `cache` table
- `cache_locks` table
- `sessions` table
- `jobs` table
- `job_batches` table
- `failed_jobs` table
- `token_data` table (your custom table)

### 6. Start Server

```bash
php artisan serve
```

The API will be available at: `http://localhost:8000`

## API Endpoints

### POST `/api/v1/token-data`

Save token data to database.

**Request:**

```json
{
  "timeSlotId": "2025-03-12_09:15",
  "date": "2025-03-12",
  "timeSlot": "09:15",
  "entries": [...],
  "counts": {...},
  "timestamp": "2025-03-12T09:15:00.000Z"
}
```

### GET `/api/v1/token-data/date/{date}`

Get token data for a specific date.

### GET `/api/v1/token-data/range?start_date=2025-03-12&end_date=2025-03-15`

Get token data for a date range.

## Next Steps

1. Fix SSL certificate issue for Composer
2. Run `composer install` to install all Laravel dependencies
3. Configure database in `.env`
4. Run `php artisan migrate` to create all tables
5. Start the server with `php artisan serve`
