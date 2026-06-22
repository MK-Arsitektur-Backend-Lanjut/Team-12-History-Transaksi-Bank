import http from 'k6/http';
import { check, sleep } from 'k6';
import { BASE_URL, JSON_HEADERS, createAccount } from '../lib/config.js';

const DEBIT_AMOUNT = parseFloat(__ENV.DEBIT_AMOUNT || '10.00');
const INITIAL_BALANCE = parseFloat(__ENV.INITIAL_BALANCE || '10000000');

export const options = {
  stages: [
    { duration: '15s', target: 10 },
    { duration: '30s', target: 50 },
    { duration: '15s', target: 0 },
  ],
  thresholds: {
    http_req_failed: ['rate<0.05'],
    http_req_duration: ['p(95)<3000'],
    'checks{scenario:transaction_logging}': ['rate>0.95'],
  },
};

export function setup() {
  const account = createAccount({
    customer_name: 'K6 Transaction Logging Stress Test',
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
  const uniqueRef = `TXN-${Date.now()}-${__VU}-${__ITER}-${Math.floor(Math.random() * 100000)}`;
  
  const res = http.post(
    `${BASE_URL}/transactions`,
    JSON.stringify({
      account_id: data.accountId,
      type: 'debit',
      amount: data.debitAmount,
      description: 'K6 Transaction Logging Test',
      reference_number: uniqueRef,
    }),
    {
      headers: JSON_HEADERS,
      tags: { scenario: 'transaction_logging' },
    }
  );

  check(
    res,
    {
      'transaction status 201': (r) => r.status === 201,
      'transaction returns success': (r) => r.json('success') === true,
      'transaction returns body': (r) => r.json('transaction.id') !== undefined,
    },
    { scenario: 'transaction_logging' }
  );

  sleep(0.05);
}

export function teardown(data) {
  const res = http.get(`${BASE_URL}/accounts/${data.accountId}`);
  const finalBalance = parseFloat(res.json('data.balance'));

  console.log(`--- Skenario 04: Transaction Logging Teardown ---`);
  console.log(`Account ID: ${data.accountId}`);
  console.log(`Initial balance: ${data.initialBalance}`);
  console.log(`Final balance: ${finalBalance}`);
  console.log(`Debit amount per request: ${data.debitAmount}`);
  console.log(`Verify: final balance should equal initial balance minus (successful debits x debit amount).`);
  console.log(`------------------------------------------------`);
}
