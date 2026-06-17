import http from 'k6/http';
import { check, sleep } from 'k6';
import { BASE_URL, JSON_HEADERS, createAccount } from '../lib/config.js';

export const options = {
  stages: [
    { duration: '15s', target: 10 },
    { duration: '30s', target: 30 },
    { duration: '15s', target: 0 },
  ],
  thresholds: {
    http_req_failed: ['rate<0.05'],
    http_req_duration: ['p(95)<2000'],
    'checks{scenario:statement_query}': ['rate>0.95'],
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
      customer_name: 'K6 Statement Query Test',
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

  // Acak halaman (1 s.d. 3) untuk menguji offset query
  const page = Math.floor(Math.random() * 3) + 1;

  const url = `${BASE_URL}/statements?account_id=${data.accountId}&start_date=${startDate}&end_date=${endDate}&per_page=15&page=${page}`;

  const res = http.get(url, {
    tags: { scenario: 'statement_query' },
  });

  check(
    res,
    {
      'query status 200': (r) => r.status === 200,
      'query returns account info': (r) => r.json('account.id') !== undefined,
      'query returns transactions list': (r) => Array.isArray(r.json('transactions')),
      'query returns summary': (r) => r.json('summary.total_credit') !== undefined,
    },
    { scenario: 'statement_query' }
  );

  sleep(0.1);
}
