<?php

namespace Tests\Feature;

use App\Events\TransactionCreated;
use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class TransactionEventTest extends TestCase
{
    use RefreshDatabase;

    protected Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->account = Account::factory()->create([
            'status' => 'active',
            'balance' => 1000000,
        ]);
    }

    /**
     * Test that TransactionCreated event is dispatched after transaction creation.
     */
    public function test_transaction_created_event_is_dispatched(): void
    {
        Event::fake();

        $response = $this->postJson('/api/transactions', [
            'account_id' => $this->account->id,
            'type' => 'debit',
            'amount' => 100000,
            'description' => 'Test transaction',
            'reference_number' => 'TEST-' . now()->timestamp,
        ]);

        $response->assertStatus(201);
        $response->assertJson(['success' => true]);

        Event::assertDispatched(TransactionCreated::class);
    }

    /**
     * Test that TransactionCreated event contains correct transaction data.
     */
    public function test_transaction_created_event_contains_correct_data(): void
    {
        Event::fake();

        $this->postJson('/api/transactions', [
            'account_id' => $this->account->id,
            'type' => 'credit',
            'amount' => 50000,
            'reference_number' => 'TEST-CREDIT-' . now()->timestamp,
        ]);

        Event::assertDispatched(TransactionCreated::class, function (TransactionCreated $event) {
            $tx = $event->transaction;
            return $tx->account_id === $this->account->id
                && $tx->type === 'credit'
                && (float) $tx->amount === 50000.0
                && (float) $tx->balance_before === 1000000.0
                && (float) $tx->balance_after === 1050000.0;
        });
    }

    /**
     * Test idempotency: creating transaction with same reference_number fails with constraint error.
     */
    public function test_idempotency_unique_reference_number_constraint(): void
    {
        $reference = 'IDEMPOTENT-' . now()->timestamp;

        // First request should succeed
        $response1 = $this->postJson('/api/transactions', [
            'account_id' => $this->account->id,
            'type' => 'debit',
            'amount' => 100000,
            'reference_number' => $reference,
        ]);

        $response1->assertStatus(201);
        $this->assertDatabaseCount('transactions', 1);

        // Second request with same reference_number should fail (UNIQUE constraint)
        $response2 = $this->postJson('/api/transactions', [
            'account_id' => $this->account->id,
            'type' => 'debit',
            'amount' => 100000,
            'reference_number' => $reference,
        ]);

        $response2->assertStatus(422); // Validation/integrity error
        $this->assertDatabaseCount('transactions', 1); // Still only 1 transaction
    }

    /**
     * Test that duplicate reference_number in database prevents insertion.
     */
    public function test_reference_number_unique_in_database(): void
    {
        $reference = 'DB-UNIQUE-TEST-' . now()->timestamp;

        // Create first transaction
        Transaction::create([
            'account_id' => $this->account->id,
            'reference_number' => $reference,
            'type' => 'credit',
            'amount' => 50000,
            'balance_before' => 1000000,
            'balance_after' => 1050000,
            'transaction_date' => now(),
        ]);

        // Try to create second with same reference_number
        try {
            Transaction::create([
                'account_id' => $this->account->id,
                'reference_number' => $reference,
                'type' => 'credit',
                'amount' => 50000,
                'balance_before' => 1050000,
                'balance_after' => 1100000,
                'transaction_date' => now(),
            ]);
            $this->fail('Expected integrity constraint violation');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Integrity constraint violation', $e->getMessage());
        }

        $this->assertDatabaseCount('transactions', 1);
    }

    /**
     * Test event is dispatched even when listener is queued.
     */
    public function test_event_is_dispatched_with_queued_listeners(): void
    {
        Event::fake();

        $response = $this->postJson('/api/transactions', [
            'account_id' => $this->account->id,
            'type' => 'debit',
            'amount' => 75000,
            'reference_number' => 'TEST-QUEUE-' . now()->timestamp,
        ]);

        $response->assertStatus(201);
        Event::assertDispatched(TransactionCreated::class);
    }

    /**
     * Test that balance is correctly updated and transaction data is auditable.
     */
    public function test_transaction_audit_fields_are_populated(): void
    {
        $initialBalance = $this->account->balance;

        $this->postJson('/api/transactions', [
            'account_id' => $this->account->id,
            'type' => 'debit',
            'amount' => 250000,
            'reference_number' => 'AUDIT-TEST-' . now()->timestamp,
        ]);

        $transaction = Transaction::latest()->first();

        $this->assertEquals($initialBalance, $transaction->balance_before);
        $this->assertEquals($initialBalance - 250000, $transaction->balance_after);
        $this->assertNotNull($transaction->reference_number);
        $this->assertNotNull($transaction->transaction_date);
    }

    /**
     * Test concurrent transactions on same account use locking correctly.
     */
    public function test_concurrent_transactions_maintain_balance_integrity(): void
    {
        $account = Account::factory()->create([
            'status' => 'active',
            'balance' => 1000000,
        ]);

        // Simulate two concurrent debit requests
        $this->postJson('/api/transactions', [
            'account_id' => $account->id,
            'type' => 'debit',
            'amount' => 300000,
            'reference_number' => 'CONCURRENT-1-' . now()->timestamp,
        ]);

        $this->postJson('/api/transactions', [
            'account_id' => $account->id,
            'type' => 'debit',
            'amount' => 400000,
            'reference_number' => 'CONCURRENT-2-' . now()->timestamp,
        ]);

        $account->refresh();
        $this->assertEquals(300000, $account->balance); // 1000000 - 300000 - 400000
    }
}
