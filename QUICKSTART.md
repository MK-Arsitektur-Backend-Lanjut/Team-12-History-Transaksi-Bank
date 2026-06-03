# ⚡ Quick Start - 5 Menit Setup

Untuk yang mau langsung jalan tanpa baca dokumentasi panjang.

## 🏃 Super Quick (Docker Way - Recommended)

```bash
# 1. Clone & setup
git clone https://github.com/MK-Arsitektur-Backend-Lanjut/Team-12-History-Transaksi-Bank.git
cd Team-12-History-Transaksi-Bank
git pull origin main

# 2. Copy env
cp .env.example .env

# 3. Install
composer install
npm install

# 4. Generate key
php artisan key:generate

# 5. Start Docker (This does everything!)
docker compose up -d

# 6. Wait & migrate (tunggu 15 detik DB startup)
sleep 15
docker compose exec app php artisan migrate

# 7. Generate Swagger
docker compose exec app php artisan l5-swagger:generate

# DONE! 🎉
```

**Access**: 
- API: `http://localhost:8000/api`
- Docs: `http://localhost:8000/api/documentation`

---

## 🖥️ Local Way (Tanpa Docker)

```bash
# 1. Setup
git clone https://github.com/MK-Arsitektur-Backend-Lanjut/Team-12-History-Transaksi-Bank.git
cd Team-12-History-Transaksi-Bank
cp .env.example .env

# 2. Edit .env - ganti DB config ke local MySQL
# DB_HOST=127.0.0.1
# DB_USERNAME=root
# DB_PASSWORD=your_password

# 3. Install & setup
composer install
npm install
php artisan key:generate

# 4. Create database
mysql -u root -p -e "CREATE DATABASE modul_account_management;"

# 5. Migrate
php artisan migrate

# 6. Generate Swagger
php artisan l5-swagger:generate

# 7. Run server
php artisan serve
```

**Access**: `http://localhost:8000`

---

## 🔧 Troubleshooting

| Problem | Solution |
|---------|----------|
| "Port 8000 already in use" | `php artisan serve --port=8080` |
| "SQLSTATE error" | Wait 15s after `docker compose up -d` |
| "Key not set" | `php artisan key:generate` |
| ".env not found" | `cp .env.example .env` |
| "Swagger blank" | `php artisan l5-swagger:generate` |

---

## 📖 Butuh Guide Lengkap?

Lihat: **`SETUP_GUIDE.md`** untuk dokumentasi complete dengan all options & troubleshooting detail.

