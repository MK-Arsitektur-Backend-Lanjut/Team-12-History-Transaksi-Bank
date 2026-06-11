# 📖 Setup Guide - Account Management API

Panduan lengkap untuk setup dan menjalankan project Account Management API dari scratch.

## 📋 Prerequisites

Pastikan sudah install:
- **PHP 8.2+** - [Download](https://www.php.net/downloads)
- **Composer** - [Download](https://getcomposer.org/download/)
- **MySQL 8.0+** - [Download](https://dev.mysql.com/downloads/mysql/)
- **Git** - [Download](https://git-scm.com/download)
- **Docker & Docker Compose** (Optional, recommended) - [Download](https://www.docker.com/products/docker-desktop)

## 🚀 Setup Langkah-Langkah

### 1. Clone Repository

```bash
git clone https://github.com/MK-Arsitektur-Backend-Lanjut/Team-12-History-Transaksi-Bank.git
cd Team-12-History-Transaksi-Bank
git pull origin main
```

### 2. Install Dependencies

```bash
# Install PHP dependencies
composer install

# Install Node dependencies (untuk Vite/frontend)
npm install
```

### 3. Setup Environment File

```bash
# Copy environment template
cp .env.example .env
```

Edit `.env` sesuaikan database config:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=modul_account_management
DB_USERNAME=root
DB_PASSWORD=root
```

Jika menggunakan Docker, gunakan:

```env
DB_HOST=team12_db
DB_USERNAME=laravel
DB_PASSWORD=laravel
```

### 4. Generate Application Key

```bash
php artisan key:generate
```

### 5. Setup Database

#### Option A: Dengan Docker (Recommended)

```bash
# Start containers (app, web, db)
docker compose up -d

# Wait 10-15 seconds untuk DB startup

# Run migrations
docker compose exec app php artisan migrate

# (Optional) Seed database
docker compose exec app php artisan db:seed
```

#### Option B: Local MySQL

```bash
# Pastikan MySQL running
# Create database
mysql -u root -p -e "CREATE DATABASE modul_account_management;"

# Run migrations
php artisan migrate

# (Optional) Seed database
php artisan db:seed
```

### 6. Generate Swagger Documentation

```bash
php artisan l5-swagger:generate
```

### 7. Jalankan Development Server

#### Dengan Docker:

```bash
# Sudah running dari docker compose up -d
# Access: http://localhost:8000
```

#### Tanpa Docker:

```bash
# Terminal 1: Start Laravel dev server
php artisan serve

# Terminal 2: (Optional) Build frontend assets
npm run dev
```

## 📚 Akses Application

- **Main API**: `http://localhost:8000/api`
- **Swagger Documentation**: `http://localhost:8000/api/documentation`
- **Web Server** (Nginx): `http://localhost:8000`

## 🗄️ Database Struktur

### Tables:

| Table | Purpose |
|-------|---------|
| `accounts` | Penyimpanan data akun pelanggan |
| `transactions` | Log semua transaksi debit/kredit |
| `users` | User authentication (framework default) |

### Key Indexes:

- `accounts.account_number` (UNIQUE)
- `accounts.email` (UNIQUE)
- `accounts.status` (INDEX)
- `transactions.account_id` (INDEX)
- `transactions.created_at` (INDEX)

## 🔄 Workflow Development

### File Struktur:

```
app/
├── Http/Controllers/
│   ├── Api/AccountController.php
│   ├── StatementController.php
│   └── TransactionController.php
├── Models/
│   ├── Account.php
│   ├── Transaction.php
│   └── User.php
├── Repositories/
│   ├── Account/EloquentAccountRepository.php
│   └── EloquentStatementRepository.php
└── Services/Account/AccountService.php

routes/
└── api.php              # Semua API routes

database/migrations/    # Database schema

config/
└── l5-swagger.php      # Swagger configuration
```

### Development Checklist:

1. ✅ Pull latest code: `git pull origin main`
2. ✅ Install dependencies: `composer install && npm install`
3. ✅ Setup .env file
4. ✅ Run migrations: `php artisan migrate`
5. ✅ Generate Swagger: `php artisan l5-swagger:generate`
6. ✅ Start server: `docker compose up -d` atau `php artisan serve`

## 🧪 Testing

### Run Tests:

```bash
# Dengan Docker
docker compose exec app php artisan test

# Local
php artisan test
```

### Test Files:

```
tests/
├── Feature/
│   └── ExampleTest.php
└── Unit/
    └── ExampleTest.php
```

## 🛠️ Useful Commands

```bash
# Database
php artisan migrate              # Run migrations
php artisan migrate:rollback     # Rollback last migration
php artisan db:seed              # Run seeders

# Swagger/API Docs
php artisan l5-swagger:generate  # Generate documentation

# Tinker (REPL)
php artisan tinker

# Cache/Optimization
php artisan cache:clear
php artisan config:cache
php artisan route:cache

# Docker
docker compose up -d             # Start containers
docker compose down              # Stop containers
docker compose logs -f app       # View logs
docker compose exec app bash     # SSH ke container
```

## ⚠️ Common Issues & Solutions

### Issue: "SQLSTATE[HY000]: General error: 1030 Got error..."

**Solution**: MySQL belum fully started. Tunggu 15 detik setelah `docker compose up -d`.

```bash
# Check DB health
docker compose exec db mysql -u root -proot -e "SELECT 1"

# Wait dulu sebelum migrate
sleep 15
docker compose exec app php artisan migrate
```

### Issue: "Port 8000 already in use"

**Solution**:
```bash
# Find process using port 8000
netstat -ano | findstr :8000
# Kill process
taskkill /PID <PID> /F

# Or use different port
php artisan serve --port=8080
```

### Issue: "No such file or directory: .env"

**Solution**:
```bash
cp .env.example .env
php artisan key:generate
```

### Issue: Swagger documentation tidak update setelah code change

**Solution**:
```bash
php artisan l5-swagger:generate
```

## 📞 Support

Jika ada issue:

1. Check logs:
   ```bash
   tail -f storage/logs/laravel.log
   # atau
   docker compose logs -f app
   ```

2. Clear cache:
   ```bash
   php artisan cache:clear
   php artisan config:clear
   ```

3. Composer/npm issues:
   ```bash
   rm -r vendor
   composer install
   ```

## ✨ Next Steps

Setelah setup selesai:

1. **Baca API Documentation** di `http://localhost:8000/api/documentation`
2. **Eksplor Codebase**:
   - Start dari `routes/api.php` untuk understand route structure
   - Lihat `AccountController.php` untuk controller example
   - Check `AccountService.php` untuk business logic
3. **Jalankan Sample Request** via Swagger UI untuk test endpoints

---

**Last Updated**: June 3, 2026
**PHP Version**: 8.2+
**Laravel Version**: 11.x
