import http from 'k6/http';
import { check, sleep } from 'k6';
import { BASE_URL, JSON_HEADERS, createAccount } from '../lib/config.js';

export const options = {
  stages: [
    { duration: '10s', target: 5 },
    { duration: '20s', target: 20 },
    { duration: '10s', target: 0 },
  ],
  thresholds: {
    http_req_failed: ['rate<0.05'],
    http_req_duration: ['p(95)<3000'],
    'checks{scenario:statement_export}': ['rate>0.95'],
  },
};

export function setup() {
  // 1. Cari akun aktif yang sudah ada (misal dari seeder)
  const res = http.get(`${BASE_URL}/accounts`);
  let accountId = null;

  if (res.status === 200) {
    const accounts = res.json('data');
    if (accounts && accounts.length > 0) {
      const activeAccount = accounts.find((a) => a.status === 'active');
      if (activeAccount) {
        accountId = activeAccount.id;
      }
    }
  }

  // 2. Jika tidak ada akun aktif, buat baru dan populate transaksi
  if (!accountId) {
    const account = createAccount({
      customer_name: 'K6 Statement Export Test',
      balance: 1000000,
      status: 'active',
    });
    accountId = account.id;

    for (let i = 0; i < 50; i++) {
      http.post(
        `${BASE_URL}/transactions`,
        JSON.stringify({
          account_id: accountId,
          type: i % 2 === 0 ? 'credit' : 'debit',
          amount: 100.00,
          description: `Setup transaction ${i}`,
        }),
        { headers: JSON_HEADERS }
      );
    }
  }

  return { accountId };
}

export default function (data) {
  // Format range tanggal 3 tahun terakhir secara dinamis
  const today = new Date();
  const endDate = today.toISOString().split('T')[0];
  
  const threeYearsAgo = new Date();
  threeYearsAgo.setFullYear(today.getFullYear() - 3);
  const startDate = threeYearsAgo.toISOString().split('T')[0];

  const url = `${BASE_URL}/statements/export?account_id=${data.accountId}&start_date=${startDate}&end_date=${endDate}`;

  const res = http.get(url, {
    tags: { scenario: 'statement_export' },
  });

  check(
    res,
    {
      'export status 200': (r) => r.status === 200,
      'export returns CSV': (r) => r.headers['Content-Type'] && r.headers['Content-Type'].includes('text/csv'),
      'export has data': (r) => r.body.length > 0,
    },
    { scenario: 'statement_export' }
  );

  sleep(0.5); // Memberikan jeda lebih lama karena download CSV cukup berat
}
