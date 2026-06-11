import http from 'k6/http';
import { check, sleep } from 'k6';
import { BASE_URL, JSON_HEADERS, createAccount, uniqueSuffix } from '../lib/config.js';

export const options = {
  stages: [
    { duration: '30s', target: 10 },
    { duration: '30s', target: 30 },
    { duration: '30s', target: 0 },
  ],
  thresholds: {
    http_req_failed: ['rate<0.05'],
    http_req_duration: ['p(95)<2000'],
  },
};

export function setup() {
  const account = createAccount({
    customer_name: 'K6 Profile Stress Test',
    balance: 0,
  });

  return { accountId: account.id };
}

export default function (data) {
  const accountId = data.accountId;
  const suffix = uniqueSuffix();

  const getRes = http.get(`${BASE_URL}/accounts/${accountId}`);

  check(getRes, {
    'GET profile status 200': (r) => r.status === 200,
    'GET profile has customer_name': (r) => r.json('data.customer_name') !== undefined,
  });

  const patchPayload = JSON.stringify({
    customer_name: `Updated ${suffix}`,
    phone: `08${String(__VU).padStart(10, '0').slice(-10)}`,
  });

  const patchRes = http.patch(
    `${BASE_URL}/accounts/${accountId}`,
    patchPayload,
    { headers: JSON_HEADERS }
  );

  check(patchRes, {
    'PATCH profile status 200': (r) => r.status === 200,
    'PATCH profile updated name': (r) => r.json('data.customer_name') === `Updated ${suffix}`,
  });

  sleep(0.1);
}
