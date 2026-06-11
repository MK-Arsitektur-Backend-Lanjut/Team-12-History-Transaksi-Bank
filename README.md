# 🏦 Account Management API

Sistem manajemen akun bank dengan transaction logging dan statement generation.

**Repo**: [Team-12-History-Transaksi-Bank](https://github.com/MK-Arsitektur-Backend-Lanjut/Team-12-History-Transaksi-Bank)

---

## 📋 Table of Contents

- [Features](#-features)
- [Quick Start](#-quick-start)
- [Documentation](#-documentation)
- [API Endpoints](#-api-endpoints)
- [Architecture](#-architecture)
- [Tech Stack](#-tech-stack)

---

## ✨ Features

### 🔐 Account Management
- Create & manage customer accounts
- Update customer profile (name, email, phone, address)
- Account status management (active, inactive, blocked)
- Atomic balance adjustments with row-level locking

### 💰 Transaction Logging
- Automatic transaction recording for debit/credit operations
- Unique reference number per transaction
- Balance before/after tracking
- Transaction history audit trail

### 📊 Statement Generation
- Paginated transaction history retrieval
- Date range filtering
- Summary totals (total debit, total credit)
- CSV export for record-keeping

### 🔒 Data Integrity
- Database transactions with pessimistic locking
- Sufficient balance validation
- Account status validation before operations
- Unique constraints on critical fields

---

## ⚡ Quick Start

### 🐳 Docker Way (Recommended)

```bash
git clone https://github.com/MK-Arsitektur-Backend-Lanjut/Team-12-History-Transaksi-Bank.git
cd Team-12-History-Transaksi-Bank
git pull origin main

cp .env.example .env
composer install && npm install
php artisan key:generate

docker compose up -d
sleep 15
docker compose exec app php artisan migrate
docker compose exec app php artisan l5-swagger:generate
```

**Access**:
- API: `http://localhost:8000/api`
- Swagger UI: `http://localhost:8000/api/documentation`

### 🖥️ Local MySQL Way

```bash
git clone https://github.com/MK-Arsitektur-Backend-Lanjut/Team-12-History-Transaksi-Bank.git
cd Team-12-History-Transaksi-Bank
git pull origin main

cp .env.example .env
# Edit .env - set DB credentials

composer install && npm install
php artisan key:generate
php artisan migrate
php artisan l5-swagger:generate

php artisan serve
```

**More setup details?** → See [`QUICKSTART.md`](./QUICKSTART.md) or [`SETUP_GUIDE.md`](./SETUP_GUIDE.md)

---

## 📚 Documentation

| Document | Purpose |
|----------|---------|
| **[QUICKSTART.md](./QUICKSTART.md)** | 5-minute setup guide |
| **[SETUP_GUIDE.md](./SETUP_GUIDE.md)** | Complete setup documentation with troubleshooting |
| **API Docs** | Swagger UI at `/api/documentation` |

---

## 🎯 API Endpoints

### Accounts

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/accounts` | List all accounts (paginated) |
| POST | `/api/accounts` | Create new account |
| GET | `/api/accounts/{id}` | Get account detail |
| PATCH | `/api/accounts/{id}` | Update profile |
| PATCH | `/api/accounts/{id}/status` | Update status |
| POST | `/api/accounts/{id}/balance/adjust` | Adjust balance (debit/credit) |

### Statements

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/statements` | Get statement (paginated) |
| GET | `/api/statements/export` | Export as CSV |

### Transactions

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/transactions` | Log transaction |

**Full API documentation** available at `/api/documentation` after running the server.

---

## 🏗️ Architecture

### Layered Architecture

```
Routes (api.php)
    ↓
Controllers (Http/Controllers/)
    ↓
Services (Services/)
    ↓
Repositories (Repositories/)
    ↓
Models (Models/)
    ↓
Database
```

### Key Components

- **Controllers**: Request handling & routing
- **Services**: Business logic & orchestration
- **Repositories**: Data access abstraction
- **Models**: Eloquent ORM entities
- **Migrations**: Database schema versioning

### Design Patterns

✅ Repository Pattern - Data access abstraction
✅ Service Layer - Business logic encapsulation
✅ Dependency Injection - Loose coupling
✅ Atomic Transactions - Data consistency with database locking

---

## 🛠️ Tech Stack

| Component | Technology |
|-----------|-----------|
| Framework | Laravel 11.x |
| PHP | 8.2+ |
| Database | MySQL 8.0+ |
| API Docs | L5-Swagger (OpenAPI 3.0) |
| Containerization | Docker & Docker Compose |
| Frontend Build | Vite |
| Package Manager | Composer, npm |

---

## 📁 Project Structure

```
├── app/
│   ├── Http/Controllers/        # Request handlers
│   ├── Models/                  # Eloquent models
│   ├── Repositories/            # Data access layer
│   ├── Services/                # Business logic
│   └── OpenApi/                 # Swagger schema definitions
├── routes/
│   └── api.php                  # API routes
├── database/
│   ├── migrations/              # Schema definitions
│   ├── factories/               # Model factories
│   └── seeders/                 # Database seeders
├── config/
│   ├── app.php
│   ├── database.php
│   └── l5-swagger.php           # Swagger config
├── storage/api-docs/            # Generated Swagger docs
├── docker/                       # Docker configuration
├── SETUP_GUIDE.md               # Complete setup guide
├── QUICKSTART.md                # Quick start guide
├── docker-compose.yml           # Docker compose config
└── .env.example                 # Environment template
```

---

## 🧪 Testing

```bash
# Run all tests
php artisan test

# Run with Docker
docker compose exec app php artisan test

# Run specific test
php artisan test --filter=AccountTest
```

---

## 🔄 Development Workflow

1. **Create feature branch**
   ```bash
   git checkout -b feature/your-feature-name
   ```

2. **Make changes** following project conventions

3. **Update Swagger docs** (if API changes)
   ```bash
   php artisan l5-swagger:generate
   ```

4. **Test & commit**
   ```bash
   php artisan test
   git add .
   git commit -m "feat: your feature description"
   ```

5. **Push & create PR**
   ```bash
   git push origin feature/your-feature-name
   ```

---

## 📝 Important Commands

```bash
# Database
php artisan migrate              # Run migrations
php artisan migrate:rollback     # Rollback migrations
php artisan db:seed              # Seed database

# API Documentation
php artisan l5-swagger:generate  # Generate Swagger docs

# Cache & Optimization
php artisan cache:clear
php artisan config:cache
php artisan route:cache

# Docker
docker compose up -d             # Start containers
docker compose down              # Stop containers
docker compose logs -f app       # View logs
```

---

## ⚠️ Troubleshooting

### Port 8000 already in use
```bash
php artisan serve --port=8080
```

### Database connection error
Wait 15 seconds after `docker compose up -d` for database initialization.

### Swagger docs not updating
```bash
php artisan l5-swagger:generate
```

**More troubleshooting?** → See [`SETUP_GUIDE.md`](./SETUP_GUIDE.md#-common-issues--solutions)

---

## 👥 Team

**Team 12 - Arsitektur Backend Lanjut**

---

## 📄 License

MIT License - See LICENSE file

---

## 📞 Support

- Check logs: `storage/logs/laravel.log`
- Clear cache: `php artisan cache:clear`
- Consult docs: [`SETUP_GUIDE.md`](./SETUP_GUIDE.md)


## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## Docker Setup (Laravel + Nginx + MySQL)

Project ini sudah disiapkan agar bisa berjalan di Docker sesuai ketentuan tugas.

### 1) Build dan jalankan container

```bash
docker compose up -d --build
```

### 2) Install dependency PHP di container

```bash
docker compose exec app composer install
```

### 3) Inisialisasi aplikasi

```bash
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

### 4) Akses aplikasi

- App: `http://localhost:8000`
- Swagger UI: `http://localhost:8000/api/documentation`

### 5) Perintah bantu

```bash
docker compose ps
docker compose logs -f web
docker compose logs -f app
docker compose down
```

Catatan:
- Konfigurasi environment untuk container ada di file `.env.docker`.
- Mapping port MySQL container adalah `3309:3306`.

## Seeder Beban Data Transaksi

Seeder `TransactionLoadSeeder` dibuat untuk memenuhi syarat minimal `50.000` transaksi per rekening.

Perintah menjalankan seeder (dalam Docker):

```bash
docker compose exec app php artisan migrate:fresh --seed
```

Parameter opsional (via environment container):

- `SEED_ACCOUNTS_COUNT` (default: `1`)
- `SEED_TRANSACTIONS_PER_ACCOUNT` (default: `50000`, otomatis dipaksa minimal `50000`)
