import http from 'k6/http';
import { check, sleep } from 'k6';
import { BASE_URL, JSON_HEADERS, REQUEST_TIMEOUT, resolveTestAccount, CORE_BANKING_STRESS_STAGES } from '../lib/config.js';

const DEBIT_AMOUNT = parseFloat(__ENV.DEBIT_AMOUNT || '10.00');
const INITIAL_BALANCE = parseFloat(__ENV.INITIAL_BALANCE || '10000000');

export const options = {
  setupTimeout: '600s',
  stages: CORE_BANKING_STRESS_STAGES,
  thresholds: {
    http_req_failed: ['rate<0.15'],
    http_req_duration: ['p(95)<180000'],
    'checks{scenario:balance_adjust}': ['rate>0.85'],
  },
};

export function setup() {
  const account = resolveTestAccount({
    customer_name: 'K6 Atomic Balance Stress Test',
    balance: INITIAL_BALANCE,
    status: 'active',
  });

  return {
    accountId: account.id,
    initialBalance: account.balance,
    debitAmount: DEBIT_AMOUNT,
    fromEnv: account.fromEnv,
  };
}

export default function (data) {
  const res = http.post(
    `${BASE_URL}/accounts/${data.accountId}/balance/adjust`,
    JSON.stringify({
      type: 'debit',
      amount: data.debitAmount,
    }),
    {
      headers: JSON_HEADERS,
      timeout: REQUEST_TIMEOUT,
      tags: { scenario: 'balance_adjust' },
    }
  );

  check(
    res,
    {
      'debit status 200': (r) => r.status === 200,
      'debit returns balance': (r) => r.json('data.balance') !== undefined,
    },
    { scenario: 'balance_adjust' }
  );

  sleep(0.3);
}

export function teardown(data) {
  const res = http.get(`${BASE_URL}/accounts/${data.accountId}`, {
    headers: JSON_HEADERS,
    timeout: REQUEST_TIMEOUT,
  });
  const finalBalance = parseFloat(res.json('data.balance'));

  console.log(`Account ID: ${data.accountId}`);
  console.log(`Initial balance: ${data.initialBalance}`);
  console.log(`Final balance: ${finalBalance}`);
  console.log(
    'Verify: final balance should equal initial balance minus (successful debits x debit amount).'
  );
  console.log(`Debit amount per request: ${data.debitAmount}`);
}
