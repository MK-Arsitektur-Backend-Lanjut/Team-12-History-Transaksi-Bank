<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\DailyBalancesSummary;
use App\Repositories\Account\AccountRepositoryInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DatabaseOptimizationExtensionTest extends TestCase
{
    use RefreshDatabase;

    private AccountRepositoryInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->app->make(AccountRepositoryInterface::class);
        Cache::flush();
    }

    /**
     * Test that account lookups populate the cache, and updates invalidate it.
     */
    public function test_account_profile_caching_and_invalidation(): void
    {
        $account = Account::factory()->create([
            'balance' => 5000.00,
        ]);

        $idKey = "account:id:{$account->id}";
        $numberKey = "account:number:{$account->account_number}";

        // Assert cache is empty initially
        $this->assertFalse(Cache::has($idKey));
        $this->assertFalse(Cache::has($numberKey));

        // Retrieve through repository (should write to cache)
        $lookupById = $this->repository->findById($account->id);
        $this->assertNotNull($lookupById);
        $this->assertTrue(Cache::has($idKey));

        $lookupByNum = $this->repository->findByAccountNumber($account->account_number);
        $this->assertNotNull($lookupByNum);
        $this->assertTrue(Cache::has($numberKey));

        // Test that saved event observer clears cache on update
        $account->update(['customer_name' => 'John Doe Updated']);
        $this->assertFalse(Cache::has($idKey));
        $this->assertFalse(Cache::has($numberKey));

        // Re-warm cache
        $this->repository->findById($account->id);
        $this->repository->findByAccountNumber($account->account_number);
        $this->assertTrue(Cache::has($idKey));
        $this->assertTrue(Cache::has($numberKey));

        // Test that deleted event observer clears cache on delete
        $account->delete();
        $this->assertFalse(Cache::has($idKey));
        $this->assertFalse(Cache::has($numberKey));
    }

    /**
     * Test that daily summary generation correctly aggregates credit/debit and closing balances.
     */
    public function test_daily_summary_aggregation_command(): void
    {
        $account = Account::factory()->create([
            'balance' => 0,
        ]);

        $date = '2026-06-03';
        $start = Carbon::parse($date)->startOfDay();

        // Create transaction history for the target date
        // 1. Credit 1500
        Transaction::create([
            'account_id' => $account->id,
            'reference_number' => 'REF-C-101',
            'type' => 'credit',
            'amount' => 1500.00,
            'balance_before' => 0.00,
            'balance_after' => 1500.00,
            'transaction_date' => $start->copy()->addHours(2),
        ]);

        // 2. Debit 500
        Transaction::create([
            'account_id' => $account->id,
            'reference_number' => 'REF-D-102',
            'type' => 'debit',
            'amount' => 500.00,
            'balance_before' => 1500.00,
            'balance_after' => 1000.00,
            'transaction_date' => $start->copy()->addHours(4),
        ]);

        // 3. Credit 350
        Transaction::create([
            'account_id' => $account->id,
            'reference_number' => 'REF-C-103',
            'type' => 'credit',
            'amount' => 350.00,
            'balance_before' => 1000.00,
            'balance_after' => 1350.00,
            'transaction_date' => $start->copy()->addHours(6),
        ]);

        // Run the artisan summary command
        $exitCode = Artisan::call('app:generate-daily-summary', [
            '--date' => $date,
        ]);

        $this->assertEquals(0, $exitCode);

        // Verify the summary is created via model retrieval
        $summary = DailyBalancesSummary::where('account_id', $account->id)->first();
        $this->assertNotNull($summary);
        $this->assertEquals($date, $summary->summary_date->toDateString());
        $this->assertEquals(1850.00, (float) $summary->total_credit);
        $this->assertEquals(500.00, (float) $summary->total_debit);
        $this->assertEquals(1350.00, (float) $summary->closing_balance);
    }
}
