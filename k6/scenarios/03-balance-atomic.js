import http from 'k6/http';
import { check, sleep } from 'k6';
import { BASE_URL, JSON_HEADERS, createAccount } from '../lib/config.js';

const DEBIT_AMOUNT = parseFloat(__ENV.DEBIT_AMOUNT || '10.00');
const INITIAL_BALANCE = parseFloat(__ENV.INITIAL_BALANCE || '10000000');

export const options = {
  stages: [
    { duration: '20s', target: 10 },
    { duration: '40s', target: 50 },
    { duration: '20s', target: 0 },
  ],
  thresholds: {
    http_req_failed: ['rate<0.05'],
    http_req_duration: ['p(95)<5000'],
    'checks{scenario:balance_adjust}': ['rate>0.95'],
  },
};

export function setup() {
  const account = createAccount({
    customer_name: 'K6 Atomic Balance Stress Test',
    balance: INITIAL_BALANCE,
    status: 'active',
  });

  return {
    accountId: account.id,
    initialBalance: account.balance,
    debitAmount: DEBIT_AMOUNT,
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

  sleep(0.05);
}

export function teardown(data) {
  const res = http.get(`${BASE_URL}/accounts/${data.accountId}`);
  const finalBalance = parseFloat(res.json('data.balance'));

  console.log(`Account ID: ${data.accountId}`);
  console.log(`Initial balance: ${data.initialBalance}`);
  console.log(`Final balance: ${finalBalance}`);
  console.log(
    'Verify: final balance should equal initial balance minus (successful debits x debit amount).'
  );
  console.log(`Debit amount per request: ${data.debitAmount}`);
}
