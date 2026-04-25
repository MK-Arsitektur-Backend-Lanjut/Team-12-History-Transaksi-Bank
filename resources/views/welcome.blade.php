<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction Logging API</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            margin: 0;
            padding: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .container {
            background: white;
            border-radius: 8px;
            padding: 40px;
            max-width: 800px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
        }
        h1 {
            color: #333;
            margin: 0 0 10px 0;
        }
        .subtitle {
            color: #666;
            font-size: 16px;
            margin-bottom: 30px;
        }
        .info-box {
            background: #f8f9fa;
            border-left: 4px solid #667eea;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .endpoint {
            background: #f0f4ff;
            padding: 12px 15px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            color: #667eea;
            font-weight: bold;
            margin: 10px 0;
            word-break: break-all;
        }
        .method {
            display: inline-block;
            background: #667eea;
            color: white;
            padding: 5px 10px;
            border-radius: 3px;
            font-weight: bold;
            font-size: 12px;
            margin-right: 10px;
        }
        .features {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin: 20px 0;
        }
        .feature {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            border-left: 4px solid #667eea;
        }
        .feature h3 {
            margin: 0 0 8px 0;
            color: #333;
            font-size: 14px;
        }
        .feature p {
            margin: 0;
            color: #666;
            font-size: 13px;
        }
        .button {
            display: inline-block;
            background: #667eea;
            color: white;
            padding: 12px 24px;
            border-radius: 4px;
            text-decoration: none;
            font-weight: bold;
            margin-top: 20px;
            transition: background 0.3s;
        }
        .button:hover {
            background: #764ba2;
        }
        .code-block {
            background: #2d2d2d;
            color: #f8f8f2;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            margin: 15px 0;
            line-height: 1.4;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🏦 Transaction Logging API</h1>
        <p class="subtitle">API untuk pencatatan transaksi debit/kredit dengan validasi saldo</p>

        <div class="info-box">
            <strong>✅ Server Running!</strong> Aplikasi Laravel Anda berjalan dengan baik. Gunakan API endpoint di bawah untuk mencatat transaksi.
        </div>

        <h2 style="margin-top: 30px; margin-bottom: 10px;">📌 API Endpoint</h2>
        <div class="endpoint">
            <span class="method">POST</span>
            /api/transactions
        </div>

        <h2 style="margin-top: 30px; margin-bottom: 15px;">✨ Fitur Utama</h2>
        <div class="features">
            <div class="feature">
                <h3>💰 Debit/Kredit</h3>
                <p>Catat transaksi debit dan kredit dengan mudah</p>
            </div>
            <div class="feature">
                <h3>🔐 Validasi Saldo</h3>
                <p>Sistem otomatis cek saldo sebelum transaksi</p>
            </div>
            <div class="feature">
                <h3>📝 Referensi Unik</h3>
                <p>Setiap transaksi mendapat ID unik (UUID)</p>
            </div>
            <div class="feature">
                <h3>📊 History Tracking</h3>
                <p>Semua transaksi tercatat dengan timestamp</p>
            </div>
        </div>

        <h2 style="margin-top: 30px; margin-bottom: 15px;">📨 Request Body</h2>
        <div class="code-block">
{
  "account_id": 1,
  "type": "debit",
  "amount": 100000
}
        </div>

        <h2 style="margin-top: 30px; margin-bottom: 15px;">📤 Response Success</h2>
        <div class="code-block">
{
  "success": true,
  "transaction": {
    "id": 500001,
    "account_id": 1,
    "reference_number": "550E8400-E29B-41D4-A716-446655440000",
    "type": "debit",
    "amount": 100000,
    "balance_after": 9900000,
    "created_at": "2026-04-25T10:30:45Z",
    "updated_at": "2026-04-25T10:30:45Z"
  }
}
        </div>

        <h2 style="margin-top: 30px; margin-bottom: 15px;">❌ Response Error (Saldo Tidak Cukup)</h2>
        <div class="code-block">
{
  "success": false,
  "message": "Saldo tidak cukup."
}
        </div>

        <h2 style="margin-top: 30px; margin-bottom: 15px;">🧪 Cara Test</h2>
        <p><strong>Pilih salah satu:</strong></p>
        <ol>
            <li><strong>Postman:</strong> Buka Postman, buat request POST ke endpoint di atas</li>
            <li><strong>cURL:</strong> Gunakan command cURL di terminal</li>
            <li><strong>Thunder Client:</strong> Ekstensi VS Code untuk testing API</li>
        </ol>

        <p style="margin-top: 30px; color: #666; font-size: 13px;">
            <strong>Perlu bantuan?</strong> Gunakan tools testing favorit Anda dan panggil endpoint di atas dengan method POST.
        </p>
    </div>
</body>
</html>