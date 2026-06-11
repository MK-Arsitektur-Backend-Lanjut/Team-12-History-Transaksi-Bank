<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Repositories\Account\AccountRepositoryInterface;
use App\Repositories\Account\EloquentAccountRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Mock Repository untuk mensimulasikan kode SEBELUM Optimasi.
 */
class UnoptimizedAccountRepository implements AccountRepositoryInterface
{
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return Account::query()->latest()->paginate($perPage);
    }

    // Kasus 1: Membaca langsung dari Database tanpa Cache Redis
    public function findById(int $id): ?Account
    {
        return Account::query()->find($id);
    }

    public function findByAccountNumber(string $accountNumber): ?Account
    {
        return Account::query()->where('account_number', $accountNumber)->first();
    }

    public function create(array $data): Account
    {
        return Account::query()->create($data);
    }

    public function update(Account $account, array $data): Account
    {
        $account->update($data);
        return $account->refresh();
    }

    public function updateStatus(Account $account, string $status): Account
    {
        $account->status = $status;
        $account->save();
        return $account->refresh();
    }

    // Kasus 2 & 3: Memperbarui saldo tanpa Pessimistic Locking & tanpa memeriksa status aktif
    public function adjustBalanceAtomically(int $accountId, string $type, float $amount): Account
    {
        // Bypass DB::transaction() dan lockForUpdate(), serta bypass pengecekan status active
        $account = Account::find($accountId);
        $currentBalance = (float) $account->balance;

        if ($type === 'debit' && $currentBalance < $amount) {
            throw new \RuntimeException('Insufficient balance.');
        }

        $newBalance = $type === 'credit'
            ? $currentBalance + $amount
            : $currentBalance - $amount;

        $account->balance = round($newBalance, 2);
        $account->save();

        return $account;
    }

    // Simulasi pembaruan saldo berbasis instance (untuk mensimulasikan balapan di memori PHP)
    public function adjustBalanceUsingInstance(Account $account, string $type, float $amount): Account
    {
        $currentBalance = (float) $account->balance;
        $newBalance = $type === 'credit'
            ? $currentBalance + $amount
            : $currentBalance - $amount;

        $account->balance = round($newBalance, 2);
        $account->save();

        return $account;
    }
}

class BeforeOptimizationDemoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::flush();
    }

    /**
     * UJI KASUS 1: Query Caching vs Direct SQL.
     * Membuktikan bahwa kueri sebelum optimasi membebani database dengan kueri berulang,
     * sedangkan kueri sesudah optimasi diredam oleh Redis Cache.
     */
    public function test_caching_overhead_demo(): void
    {
        $account = Account::factory()->create();

        $unoptimizedRepo = new UnoptimizedAccountRepository();
        $optimizedRepo = new EloquentAccountRepository();

        // --- SEBELUM OPTIMASI (Direct DB Query) ---
        DB::flushQueryLog();
        DB::enableQueryLog();

        // Panggil 5 kali berurutan
        for ($i = 0; $i < 5; $i++) {
            $unoptimizedRepo->findById($account->id);
        }

        $queriesBefore = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Harus memicu 5 kueri database penuh ke MySQL disk
        $this->assertEquals(5, $queriesBefore);

        // --- SESUDAH OPTIMASI (Redis Cache) ---
        Cache::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();

        // Panggil 5 kali berurutan
        for ($i = 0; $i < 5; $i++) {
            $optimizedRepo->findById($account->id);
        }

        $queriesAfter = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Hanya memicu 1 kueri database (sisanya 4 dilayani oleh Redis RAM Cache)
        $this->assertEquals(1, $queriesAfter);
    }

    /**
     * UJI KASUS 2: Race Condition & Lost Update.
     * Membuktikan bahwa tanpa pessimistic locking, pembaruan saldo secara konkuren
     * akan saling menimpa (data korup), sedangkan dengan lock hal ini aman dilakukan.
     */
    public function test_concurrency_race_condition_lost_update_demo(): void
    {
        $account = Account::factory()->create(['balance' => 1000.00]);
        $unoptimizedRepo = new UnoptimizedAccountRepository();

        // Simulasi Request 1 membaca data saldo akun (saldo = 1000)
        $instance1 = $unoptimizedRepo->findById($account->id);

        // Simulasi Request 2 membaca data saldo akun yang sama secara simultan (saldo = 1000)
        // Karena tidak ada lock, Request 2 bisa membaca saldo 1000 sebelum Request 1 selesai meng-update.
        $instance2 = $unoptimizedRepo->findById($account->id);

        // Request 1 memproses penambahan kredit sebesar 500 (1000 + 500 = 1500) lalu menyimpan ke DB
        $unoptimizedRepo->adjustBalanceUsingInstance($instance1, 'credit', 500.00);

        // Request 2 memproses pengurangan debit sebesar 300 (1000 - 300 = 700) lalu menyimpan ke DB
        $unoptimizedRepo->adjustBalanceUsingInstance($instance2, 'debit', 300.00);

        // Saldo akhir di database yang benar seharusnya adalah 1200 (1000 + 500 - 300).
        // Namun, karena tidak ada locking, saldo 700 dari Request 2 menimpa saldo 1500 dari Request 1 (Lost Update!).
        $freshAccount = Account::find($account->id);
        
        $this->assertEquals(700.00, (float) $freshAccount->balance);
        $this->assertNotEquals(1200.00, (float) $freshAccount->balance);
    }

    /**
     * UJI KASUS 3: Bypass Pengecekan Status Rekening.
     * Membuktikan bahwa sebelum optimasi, rekening non-aktif tetap bisa bertransaksi.
     * Sesudah optimasi, gerbang transaksi memblokir rekening non-aktif.
     */
    public function test_inactive_account_transaction_bypass_demo(): void
    {
        // Buat rekening dengan status blocked (diblokir)
        $account = Account::factory()->create([
            'status' => 'blocked',
            'balance' => 1000.00,
        ]);

        $unoptimizedRepo = new UnoptimizedAccountRepository();
        $optimizedRepo = new EloquentAccountRepository();

        // --- SEBELUM OPTIMASI (Bypass Validasi Status) ---
        // Transaksi berjalan lancar walaupun rekening ditangguhkan (ini celah bug!)
        $unoptimizedRepo->adjustBalanceAtomically($account->id, 'debit', 200.00);
        
        $freshAccountUnopt = Account::find($account->id);
        $this->assertEquals(800.00, (float) $freshAccountUnopt->balance);

        // --- SESUDAH OPTIMASI (Terproteksi Status Gate) ---
        // Mencoba transaksi dengan repositori teroptimasi harus melemparkan ValidationException
        $this->expectException(ValidationException::class);
        $optimizedRepo->adjustBalanceAtomically($account->id, 'debit', 100.00);
    }
}
