import http from 'k6/http';

export const BASE_URL = __ENV.BASE_URL || 'http://localhost:8000/api';
export const REQUEST_TIMEOUT = __ENV.REQUEST_TIMEOUT || '120s';

export const JSON_HEADERS = {
  'Content-Type': 'application/json',
  Accept: 'application/json',
};

/**
 * Core Banking stress profile — peak 1000 VU.
 * Ramp: 0→250→500→750→1000, hold 5m, ramp-down 750→500→250→0.
 * Total active profile ~21 minutes (+ graceful stop).
 */
export const CORE_BANKING_STRESS_STAGES = [
  { duration: '2m', target: 250 },
  { duration: '2m', target: 500 },
  { duration: '2m', target: 750 },
  { duration: '2m', target: 1000 },
  { duration: '5m', target: 1000 },
  { duration: '2m', target: 750 },
  { duration: '2m', target: 500 },
  { duration: '2m', target: 250 },
  { duration: '2m', target: 0 },
];

export function uniqueSuffix() {
  return `${Date.now()}-${__VU}-${Math.floor(Math.random() * 100000)}`;
}

export function createAccount(overrides = {}) {
  const suffix = uniqueSuffix();
  const payload = JSON.stringify({
    customer_name: `K6 Test ${suffix}`,
    email: `k6-${suffix}@stress-test.local`,
    phone: '08123456789',
    address: 'Bandung',
    status: 'active',
    balance: 0,
    ...overrides,
  });

  const res = http.post(`${BASE_URL}/accounts`, payload, {
    headers: JSON_HEADERS,
    timeout: REQUEST_TIMEOUT,
  });

  if (res.status !== 201) {
    throw new Error(`Failed to create account: ${res.status} ${res.body}`);
  }

  return {
    id: res.json('data.id'),
    balance: parseFloat(res.json('data.balance')),
    response: res,
  };
}

/**
 * Use existing account via ACCOUNT_ID env, or create a new one in setup().
 */
export function resolveTestAccount(createOverrides = {}) {
  const accountId = __ENV.ACCOUNT_ID ? parseInt(__ENV.ACCOUNT_ID, 10) : null;

  if (accountId) {
    const res = http.get(`${BASE_URL}/accounts/${accountId}`, {
      headers: JSON_HEADERS,
      timeout: REQUEST_TIMEOUT,
    });

    if (res.status !== 200) {
      throw new Error(`Account ${accountId} not found: ${res.status} ${res.body}`);
    }

    return {
      id: accountId,
      balance: parseFloat(res.json('data.balance')),
      fromEnv: true,
    };
  }

  const account = createAccount(createOverrides);
  return { ...account, fromEnv: false };
}

function formatLocalDate(date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

/**
 * Date range for GET /api/statements (YYYY-MM-DD).
 * TransactionLoadSeeder spreads rows over ~14 hours; a 2-day window is safe.
 */
export function statementDateRange(daysBack = 2) {
  const end = new Date();
  const start = new Date();
  start.setDate(start.getDate() - daysBack);

  return {
    start_date: formatLocalDate(start),
    end_date: formatLocalDate(end),
  };
}

/**
 * Account for statement stress test.
 * Uses STATEMENT_ACCOUNT_ID if set; otherwise auto-discovers an account
 * with enough transactions in the given date range.
 */
export function resolveStatementAccount(dates, minTransactions = 50000) {
  if (__ENV.STATEMENT_ACCOUNT_ID) {
    return parseInt(__ENV.STATEMENT_ACCOUNT_ID, 10);
  }

  if (__ENV.ACCOUNT_ID) {
    return parseInt(__ENV.ACCOUNT_ID, 10);
  }

  const maxProbe = parseInt(__ENV.STATEMENT_PROBE_MAX_ID || '50', 10);

  for (let accountId = 1; accountId <= maxProbe; accountId++) {
    const probeUrl =
      `${BASE_URL}/statements?account_id=${accountId}` +
      `&start_date=${dates.start_date}&end_date=${dates.end_date}&per_page=1`;

    const probe = http.get(probeUrl, {
      headers: JSON_HEADERS,
      timeout: REQUEST_TIMEOUT,
    });

    if (probe.status !== 200) {
      continue;
    }

    try {
      const total = probe.json('meta.total');
      if (total >= minTransactions) {
        console.log(`Auto-discovered account ID ${accountId} (${total} transactions)`);
        return accountId;
      }
    } catch {
      continue;
    }
  }

  throw new Error(
    `No account with >= ${minTransactions} transactions found (probed ids 1..${maxProbe}).\n` +
      '  docker compose exec app php artisan db:seed --class=TransactionLoadSeeder\n' +
      '  Or set explicitly: k6 run -e STATEMENT_ACCOUNT_ID=2 k6/scenarios/04-statements.js'
  );
}
