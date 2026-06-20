import http from 'k6/http';
import { check, sleep } from 'k6';
import {
  BASE_URL,
  JSON_HEADERS,
  REQUEST_TIMEOUT,
  resolveTestAccount,
  uniqueSuffix,
  CORE_BANKING_STRESS_STAGES,
} from '../lib/config.js';

export const options = {
  setupTimeout: '600s',
  stages: CORE_BANKING_STRESS_STAGES,
  thresholds: {
    http_req_failed: ['rate<0.15'],
    http_req_duration: ['p(95)<180000'],
    'checks{scenario:profile}': ['rate>0.85'],
  },
};

export function setup() {
  const account = resolveTestAccount({
    customer_name: 'K6 Profile Stress Test',
    balance: 0,
  });

  console.log(`Using account ID: ${account.id}`);

  return { accountId: account.id };
}

export default function (data) {
  const accountId = data.accountId;
  const suffix = uniqueSuffix();

  // GET PROFILE
  const getRes = http.get(`${BASE_URL}/accounts/${accountId}`, {
    headers: JSON_HEADERS,
    timeout: REQUEST_TIMEOUT,
  });

  if (getRes.status !== 200) {
    console.log(`GET FAILED`);
    console.log(`Status: ${getRes.status}`);
    console.log(`Body: ${getRes.body}`);
  }

  check(
    getRes,
    {
      'GET profile status 200': (r) => r.status === 200,
      'GET profile has customer_name': (r) => {
        try {
          return r.json('data.customer_name') !== undefined;
        } catch {
          return false;
        }
      },
    },
    { scenario: 'profile' }
  );

  const patchPayload = JSON.stringify({
    customer_name: `Updated ${suffix}`,
    phone: `08${String(__VU).padStart(10, '0').slice(-10)}`,
  });

  // Coba PATCH dulu
  const patchRes = http.patch(
    `${BASE_URL}/accounts/${accountId}`,
    patchPayload,
    {
      headers: JSON_HEADERS,
      timeout: REQUEST_TIMEOUT,
    }
  );

  if (patchRes.status !== 200) {
    console.log(`PATCH FAILED`);
    console.log(`Status: ${patchRes.status}`);
    console.log(`Body: ${patchRes.body}`);
  }

  check(
    patchRes,
    {
      'PATCH profile status 200': (r) => r.status === 200,
    },
    { scenario: 'profile' }
  );

  sleep(0.5);
}