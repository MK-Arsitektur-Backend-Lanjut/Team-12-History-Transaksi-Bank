import http from 'k6/http';

export const BASE_URL = __ENV.BASE_URL || 'http://localhost:8000/api';

export const JSON_HEADERS = { 'Content-Type': 'application/json' };

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

  const res = http.post(`${BASE_URL}/accounts`, payload, { headers: JSON_HEADERS });

  if (res.status !== 201) {
    throw new Error(`Failed to create account: ${res.status} ${res.body}`);
  }

  return {
    id: res.json('data.id'),
    balance: parseFloat(res.json('data.balance')),
    response: res,
  };
}
