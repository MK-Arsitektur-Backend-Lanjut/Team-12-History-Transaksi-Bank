<?php

namespace Tests\Feature;

use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountManagementSmokeTest extends TestCase
{
    use RefreshDatabase;

    private function makeAccountPayload(array $overrides = []): array
    {
        $suffix = Str::lower(Str::random(8));

        return array_merge([
            'account_number' => 'ACC' . Str::upper(Str::random(16)),
            'customer_name' => 'Smoke Test ' . $suffix,
            'email' => $suffix . '@example.com',
            'phone' => '08123456789',
            'address' => 'Bandung',
            'status' => 'active',
            'balance' => 0,
        ], $overrides);
    }

    public function test_account_endpoints_smoke_flow(): void
    {
        $createResponse = $this->postJson('/api/accounts', $this->makeAccountPayload());

        $createResponse->assertCreated()
            ->assertJsonPath('message', 'Account created successfully.');

        $accountId = $createResponse->json('data.id');

        $this->assertDatabaseHas('accounts', [
            'id' => $accountId,
            'customer_name' => $createResponse->json('data.customer_name'),
        ]);

        $listResponse = $this->getJson('/api/accounts');
        $listResponse->assertOk()
            ->assertJsonPath('data.0.id', $accountId);

        $showResponse = $this->getJson('/api/accounts/' . $accountId);
        $showResponse->assertOk()
            ->assertJsonPath('data.id', $accountId)
            ->assertJsonPath('data.email', $createResponse->json('data.email'));

        $updateResponse = $this->patchJson('/api/accounts/' . $accountId, [
            'customer_name' => 'Updated Smoke Name',
            'phone' => '0899999999',
        ]);

        $updateResponse->assertOk()
            ->assertJsonPath('message', 'Account updated successfully.')
            ->assertJsonPath('data.customer_name', 'Updated Smoke Name');

        $statusResponse = $this->patchJson('/api/accounts/' . $accountId . '/status', [
            'status' => 'blocked',
        ]);

        $statusResponse->assertOk()
            ->assertJsonPath('message', 'Account status updated successfully.')
            ->assertJsonPath('data.status', 'blocked');

        $this->assertDatabaseHas('accounts', [
            'id' => $accountId,
            'status' => 'blocked',
        ]);
    }

    public function test_balance_adjust_endpoint_smoke_flow(): void
    {
        $account = Account::create($this->makeAccountPayload([
            'customer_name' => 'Balance Smoke',
            'email' => 'balance-' . Str::lower(Str::random(8)) . '@example.com',
            'balance' => 0,
            'status' => 'active',
        ]));

        $creditResponse = $this->postJson('/api/accounts/' . $account->id . '/balance/adjust', [
            'type' => 'credit',
            'amount' => 250,
        ]);

        $creditResponse->assertOk()
            ->assertJsonPath('message', 'Balance adjusted successfully.');

        $debitResponse = $this->postJson('/api/accounts/' . $account->id . '/balance/adjust', [
            'type' => 'debit',
            'amount' => 100,
        ]);

        $debitResponse->assertOk();

        $account->refresh();
        $this->assertSame(150.0, (float) $account->balance);
    }

    public function test_transaction_and_statement_endpoints_smoke_flow(): void
    {
        $account = Account::create($this->makeAccountPayload([
            'customer_name' => 'Statement Smoke',
            'email' => 'statement-' . Str::lower(Str::random(8)) . '@example.com',
            'balance' => 0,
            'status' => 'active',
        ]));

        $creditResponse = $this->postJson('/api/transactions', [
            'account_id' => $account->id,
            'type' => 'credit',
            'amount' => 1000,
            'description' => 'Initial credit',
        ]);

        $creditResponse->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('transaction.account_id', $account->id)
            ->assertJsonPath('transaction.type', 'credit');

        $debitResponse = $this->postJson('/api/transactions', [
            'account_id' => $account->id,
            'type' => 'debit',
            'amount' => 250,
            'description' => 'Withdrawal',
        ]);

        $debitResponse->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('transaction.type', 'debit');

        $account->refresh();
        $this->assertSame(750.0, (float) $account->balance);
        $this->assertDatabaseCount('transactions', 2);

        $startDate = Carbon::now()->startOfDay()->toDateString();
        $endDate = Carbon::now()->endOfDay()->toDateString();

        $statementResponse = $this->getJson('/api/statements?account_id=' . $account->id . '&start_date=' . $startDate . '&end_date=' . $endDate . '&per_page=15');

        $statementResponse->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);

        $this->assertSame(1000.0, (float) $statementResponse->json('summary.total_credit'));
        $this->assertSame(250.0, (float) $statementResponse->json('summary.total_debit'));

        $exportResponse = $this->get('/api/statements/export?account_id=' . $account->id . '&start_date=' . $startDate . '&end_date=' . $endDate);

        $exportResponse->assertOk();
        $this->assertSame('text/csv; charset=UTF-8', $exportResponse->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="statement_' . $account->id . '_', $exportResponse->headers->get('Content-Disposition'));
    }
}
