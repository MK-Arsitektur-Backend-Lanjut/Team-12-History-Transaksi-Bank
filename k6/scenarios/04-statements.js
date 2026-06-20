import http from 'k6/http';
import { check, sleep } from 'k6';
import {
  BASE_URL,
  JSON_HEADERS,
  REQUEST_TIMEOUT,
  statementDateRange,
  resolveStatementAccount,
} from '../lib/config.js';

const MIN_TRANSACTIONS = parseInt(__ENV.STATEMENT_MIN_TOTAL || '50000', 10);
const DAYS_BACK = parseInt(__ENV.STATEMENT_DAYS_BACK || '2', 10);

export const options = {
  setupTimeout: '300s',
  stages: [
    { duration: '1m', target: 3 },
    { duration: '1m', target: 5 },
    { duration: '3m', target: 8 },
    { duration: '1m', target: 0 },
  ],
  thresholds: {
    http_req_failed: ['rate<0.05'],
    http_req_duration: ['p(95)<30000'],
    'checks{scenario:statement_list}': ['rate>0.95'],
  },
};

export function setup() {
  const dates = statementDateRange(DAYS_BACK);
  const accountId = resolveStatementAccount(dates, MIN_TRANSACTIONS);

  const probeUrl =
    `${BASE_URL}/statements?account_id=${accountId}` +
    `&start_date=${dates.start_date}&end_date=${dates.end_date}&per_page=1`;

  const probe = http.get(probeUrl, {
    headers: JSON_HEADERS,
    timeout: REQUEST_TIMEOUT,
  });

  if (probe.status !== 200) {
    throw new Error(
      `Statement probe failed for account ${accountId} (${probe.status}).\n` +
        '  docker compose exec app php artisan db:seed --class=TransactionLoadSeeder\n' +
        `  Or: k6 run -e STATEMENT_ACCOUNT_ID=2 k6/scenarios/04-statements.js`
    );
  }

  let total;
  try {
    total = probe.json('meta.total');
  } catch {
    throw new Error(`Invalid statement response: ${probe.body}`);
  }

  if (total < MIN_TRANSACTIONS) {
    throw new Error(
      `Account ${accountId} has ${total} transactions in ` +
        `${dates.start_date}..${dates.end_date}, need >= ${MIN_TRANSACTIONS}.\n` +
        '  docker compose exec app php artisan db:seed --class=TransactionLoadSeeder'
    );
  }

  console.log(`Using account ID: ${accountId}`);
  console.log(`Date range: ${dates.start_date} to ${dates.end_date}`);
  console.log(`Transactions in range: ${total}`);

  return {
    accountId,
    startDate: dates.start_date,
    endDate: dates.end_date,
    totalTransactions: total,
  };
}

export default function (data) {
  const mode = __ITER % 3;
  let page = 1;
  let perPage = 15;

  if (mode === 1) {
    page = 1 + (__ITER % 100);
    perPage = 15;
  } else if (mode === 2) {
    perPage = 100;
  }

  const url =
    `${BASE_URL}/statements?account_id=${data.accountId}` +
    `&start_date=${data.startDate}&end_date=${data.endDate}` +
    `&per_page=${perPage}&page=${page}`;

  const res = http.get(url, {
    headers: JSON_HEADERS,
    timeout: REQUEST_TIMEOUT,
    tags: { scenario: 'statement_list' },
  });

  check(
    res,
    {
      'GET statements status 200': (r) => r.status === 200,
      'GET statements has summary': (r) => {
        try {
          const summary = r.json('summary');
          return summary.total_credit !== undefined && summary.total_debit !== undefined;
        } catch {
          return false;
        }
      },
      'GET statements has meta.total': (r) => {
        try {
          return r.json('meta.total') >= 1;
        } catch {
          return false;
        }
      },
      'GET statements returns transactions array': (r) => {
        try {
          return Array.isArray(r.json('transactions'));
        } catch {
          return false;
        }
      },
    },
    { scenario: 'statement_list' }
  );

  sleep(0.5);
}

export function teardown(data) {
  const exportUrl =
    `${BASE_URL}/statements/export?account_id=${data.accountId}` +
    `&start_date=${data.startDate}&end_date=${data.endDate}`;

  const exportRes = http.get(exportUrl, {
    headers: JSON_HEADERS,
    timeout: REQUEST_TIMEOUT,
  });

  console.log(`Seeded transactions in range: ${data.totalTransactions}`);
  console.log(`Export CSV status: ${exportRes.status}`);
  console.log(`Export body size: ${exportRes.body ? exportRes.body.length : 0} bytes`);
  console.log(
    'Verify row count: docker compose exec db mysql -u laravel -plaravel modul_account_management ' +
      `-e "SELECT COUNT(*) FROM transactions WHERE account_id=${data.accountId};"`
  );
}
