import http from 'k6/http';
import { check, sleep } from 'k6';
import { BASE_URL, JSON_HEADERS, REQUEST_TIMEOUT, resolveTestAccount, CORE_BANKING_STRESS_STAGES } from '../lib/config.js';

const STATUS_CYCLE = ['active', 'inactive', 'active'];

export const options = {
  setupTimeout: '600s',
  stages: CORE_BANKING_STRESS_STAGES,
  thresholds: {
    http_req_failed: ['rate<0.15'],
    http_req_duration: ['p(95)<180000'],
    'checks{scenario:status}': ['rate>0.85'],
  },
};

export function setup() {
  const account = resolveTestAccount({
    customer_name: 'K6 Status Stress Test',
    status: 'active',
  });

  console.log(`Using account ID: ${account.id}`);

  return { accountId: account.id };
}

export default function (data) {
  const accountId = data.accountId;
  const status = STATUS_CYCLE[__ITER % STATUS_CYCLE.length];

  const res = http.patch(
    `${BASE_URL}/accounts/${accountId}/status`,
    JSON.stringify({ status }),
    { headers: JSON_HEADERS, timeout: REQUEST_TIMEOUT }
  );

  check(
    res,
    {
      'PATCH status 200': (r) => r.status === 200,
    },
    { scenario: 'status' }
  );

  sleep(0.5);
}
