const { execSync } = require('child_process');

// Config
const BASE_URL = process.argv[2] || 'http://127.0.0.1:8000';
const ENDPOINT = process.argv[3] || 'index'; // 'index' or 'export'

let url = '';
if (ENDPOINT === 'export') {
    // Export endpoint is heavier because it streams database rows
    url = `${BASE_URL}/api/statements/export?account_id=1&start_date=2026-06-10&end_date=2026-06-11`;
} else {
    // Index endpoint uses Redis cache
    url = `${BASE_URL}/api/statements?account_id=1&start_date=2026-06-10&end_date=2026-06-11&per_page=3`;
}

console.log(`===================================================`);
console.log(`      AUTOMATED BREAKPOINT STRESS TEST             `);
console.log(`===================================================`);
console.log(`Target Endpoint : ${ENDPOINT.toUpperCase()}`);
console.log(`Target URL      : ${url}`);
console.log(`Ramping up concurrent users to find breaking point...\n`);

let concurrency = 50;
const step = 50;
const maxConcurrency = 5000;

while (concurrency <= maxConcurrency) {
    process.stdout.write(`Testing ${concurrency} VUs...\n`);
    try {
        const cmd = `npx autocannon -c ${concurrency} -d 10 -t 30 --json "${url}"`;
        const resultRaw = execSync(cmd, { stdio: ['pipe', 'pipe', 'ignore'] }).toString();
        
        // Find JSON block in output (in case npm warnings are printed)
        const jsonStart = resultRaw.indexOf('{');
        if (jsonStart === -1) {
            throw new Error("Invalid response from benchmarking tool.");
        }
        const jsonStr = resultRaw.substring(jsonStart);
        const result = JSON.parse(jsonStr);

        const sent = result.requests.sent || 0;
        const completed = result.requests.total || 0;
        const errors = result.errors || 0;
        const timeouts = result.timeouts || 0;
        const non2xx = result.non2xx || 0;
        
        const avgLatency = result.latency.average || 0;
        const p50Latency = result.latency.p50 || 0;
        const p97_5Latency = result.latency.p97_5 || 0;
        const maxLatency = result.latency.max || 0;
        const throughput = result.requests.average || 0;

        console.log(`  [OK] Sent: ${sent} | Completed: ${completed} | Throughput: ${throughput.toFixed(1)} req/s`);
        console.log(`       Latency  -> Avg: ${avgLatency.toFixed(1)}ms | Median: ${p50Latency.toFixed(1)}ms | p97.5: ${p97_5Latency.toFixed(1)}ms | Max: ${maxLatency.toFixed(1)}ms`);
        console.log(`       Failures -> Conn Errors: ${errors} | Timeouts: ${timeouts} | HTTP Errors: ${non2xx}\n`);

        // If the system starts failing (connection errors, timeouts, or non-2xx responses like 500, 502, 504)
        if (errors > 0 || timeouts > 0 || non2xx > 0) {
            console.log(`💥💥💥 SYSTEM BREAKING POINT DETECTED 💥💥💥`);
            console.log(`The system crashed / failed at: ${concurrency} concurrent users!`);
            console.log(`---------------------------------------------------`);
            console.log(`Breakdown:`);
            console.log(`  - Connection Errors   : ${errors}`);
            console.log(`  - Timeouts            : ${timeouts}`);
            console.log(`  - HTTP Errors (5xx)    : ${non2xx}`);
            console.log(`  - Last stable latency  : Avg ${avgLatency.toFixed(1)}ms | Median ${p50Latency.toFixed(1)}ms | p97.5 ${p97_5Latency.toFixed(1)}ms`);
            console.log(`===================================================`);
            break;
        }
    } catch (e) {
        console.log(`\n💥💥💥 SYSTEM CRASHED (CONNECTION REFUSED / TIMEOUT) 💥💥💥`);
        console.log(`The system completely crashed at: ${concurrency} concurrent users!`);
        console.log(`Error details: ${e.message}`);
        console.log(`===================================================`);
        break;
    }
    concurrency += step;
}
