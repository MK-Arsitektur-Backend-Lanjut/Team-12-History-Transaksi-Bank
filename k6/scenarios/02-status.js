import http from 'k6/http';
import { check, sleep } from 'k6';
import { BASE_URL, JSON_HEADERS, createAccount } from '../lib/config.js';

const STATUS_CYCLE = ['active', 'inactive', 'active'];

export const options = {
  stages: [
    { duration: '30s', target: 10 },
    { duration: '30s', target: 20 },
    { duration: '30s', target: 0 },
  ],
  thresholds: {
    http_req_failed: ['rate<0.05'],
    http_req_duration: ['p(95)<2000'],
  },
};

export function setup() {
  const account = createAccount({
    customer_name: 'K6 Status Stress Test',
    status: 'active',
  });

  return { accountId: account.id };
}

export default function (data) {
  const accountId = data.accountId;
  const status = STATUS_CYCLE[__ITER % STATUS_CYCLE.length];

  const res = http.patch(
    `${BASE_URL}/accounts/${accountId}/status`,
    JSON.stringify({ status }),
    { headers: JSON_HEADERS }
  );

  check(res, {
    'PATCH status 200': (r) => r.status === 200,
    'PATCH status matches payload': (r) => r.json('data.status') === status,
  });

  sleep(0.1);
}
