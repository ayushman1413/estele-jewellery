# Reward Submissions & Wallet Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a customer submit unboxing proof (1 image + 1 video) for a delivered order, have an admin approve (with a discretionary ₹ reward) or reject it in Filament, credit approved rewards to a per-user wallet, and let customers spend wallet balance at checkout — with a full transaction ledger and auto-refund on cancel/return.

**Architecture:** Two new Eloquent models (`RewardSubmission`, `WalletTransaction`) plus a `wallet_balance` column on `User` and a `wallet_amount_used` column on `Order`. A single `WalletService` is the only code path that ever changes `wallet_balance` or writes a `wallet_transactions` row — every other piece (Filament approve action, checkout, cancel hook) calls into it rather than doing balance arithmetic itself. Media (image/video) uses the existing Spatie MediaLibrary setup, same disks as `Review`. Admin moderation follows the existing `Review`/`ReviewResource` pattern exactly (Schemas/Tables/Pages, `canCreate() => false`, status-gated row actions).

**Tech Stack:** Laravel 11, Filament 5 (Schemas/Tables/Pages resource split), Spatie MediaLibrary, Laravel session auth (no API/Sanctum), Blade views, PHPUnit feature tests with `RefreshDatabase`.

**Spec:** `docs/superpowers/specs/2026-09-02-reward-submissions-wallet-design.md`

## Global Constraints

- Image upload: required, image mime, **max 3072 KB (3MB)**.
- Video upload: required, mimes `mp4`/`webm`, **max 10240 KB (10MB)**.
- Server-side validation is the actual security boundary — client-side checks are UX-only and must never be trusted alone.
- One `reward_submissions` row per `order_id` (DB-unique, backstopped by a controller pre-check).
- Only `delivered` orders are eligible for submission.
- `wallet_balance` and `wallet_transactions` rows are **only** ever written via `WalletService::credit()` / `WalletService::debit()` — no other code path touches them.
- `WalletService::debit()` must throw rather than allow a negative balance.
- All money columns: `decimal(10,2)`, cast `decimal:2` on the model (matches `Order`'s existing convention).
- Route names/controllers follow the existing `account.*` naming convention in `routes/web.php`.
- Admin resource follows the `Review`/`ReviewResource` file layout exactly: `Resource.php` + `Pages/{List,Edit}*.php` + `Schemas/*Form.php` + `Tables/*Table.php`.
- Every new migration is dated after `2026_08_26` (the latest existing migration).

---

## Task 1: Migrations — `reward_submissions`, `wallet_transactions`, `users.wallet_balance`, `orders.wallet_amount_used`

**Files:**
- Create: `backend/database/migrations/2026_09_02_100000_create_reward_submissions_table.php`
- Create: `backend/database/migrations/2026_09_02_100001_create_wallet_transactions_table.php`
- Create: `backend/database/migrations/2026_09_02_100002_add_wallet_balance_to_users_table.php`
- Create: `backend/database/migrations/2026_09_02_100003_add_wallet_amount_used_to_orders_table.php`
- Test: `backend/tests/Feature/VerifyRewardSubmissionMigrationTest.php`

**Interfaces:**
- Produces: `reward_submissions` table (`id`, `user_id`, `order_id` unique, `status` default `pending`, `reward_amount` nullable decimal(10,2), `rejection_reason` nullable text, `reviewed_at` nullable timestamp, timestamps). `wallet_transactions` table (`id`, `user_id`, `type`, `amount` decimal(10,2), `balance_after` decimal(10,2), `reason`, `reference_type` nullable, `reference_id` nullable unsignedBigInteger, `created_at` only — no `updated_at`). `users.wallet_balance` decimal(10,2) default 0. `orders.wallet_amount_used` decimal(10,2) default 0.

- [ ] **Step 1: Write the migrations**

`backend/database/migrations/2026_09_02_100000_create_reward_submissions_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->decimal('reward_amount', 10, 2)->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_submissions');
    }
};
```

`backend/database/migrations/2026_09_02_100001_create_wallet_transactions_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type'); // credit | debit
            $table->decimal('amount', 10, 2);
            $table->decimal('balance_after', 10, 2);
            $table->string('reason'); // reward_approved | order_payment | order_refund
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('user_id');
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
```

`backend/database/migrations/2026_09_02_100002_add_wallet_balance_to_users_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('wallet_balance', 10, 2)->default(0)->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('wallet_balance');
        });
    }
};
```

`backend/database/migrations/2026_09_02_100003_add_wallet_amount_used_to_orders_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('wallet_amount_used', 10, 2)->default(0)->after('total');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('wallet_amount_used');
        });
    }
};
```

- [ ] **Step 2: Write the migration-shape test**

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VerifyRewardSubmissionMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_tables_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('reward_submissions'));
        $this->assertTrue(Schema::hasColumns('reward_submissions', [
            'user_id', 'order_id', 'status', 'reward_amount', 'rejection_reason', 'reviewed_at',
        ]));

        $this->assertTrue(Schema::hasTable('wallet_transactions'));
        $this->assertTrue(Schema::hasColumns('wallet_transactions', [
            'user_id', 'type', 'amount', 'balance_after', 'reason', 'reference_type', 'reference_id',
        ]));

        $this->assertTrue(Schema::hasColumn('users', 'wallet_balance'));
        $this->assertTrue(Schema::hasColumn('orders', 'wallet_amount_used'));
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `cd backend && php artisan test --filter=VerifyRewardSubmissionMigrationTest`
Expected: FAIL — tables/columns don't exist yet.

- [ ] **Step 4: Run migrations, then run the test again**

Run: `cd backend && php artisan migrate` then `php artisan test --filter=VerifyRewardSubmissionMigrationTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
cd backend && git add database/migrations tests/Feature/VerifyRewardSubmissionMigrationTest.php
git commit -m "Add reward_submissions/wallet_transactions tables and wallet columns"
```

---

## Task 2: `WalletTransaction` model + `WalletService`

**Files:**
- Create: `backend/app/Models/WalletTransaction.php`
- Create: `backend/app/Services/WalletService.php`
- Test: `backend/tests/Feature/VerifyWalletServiceTest.php`

**Interfaces:**
- Consumes: `users.wallet_balance` column, `wallet_transactions` table (Task 1).
- Produces: `WalletService::credit(User $user, float $amount, string $reason, ?Model $reference = null): WalletTransaction`, `WalletService::debit(User $user, float $amount, string $reason, ?Model $reference = null): WalletTransaction` (throws `\DomainException` if `$amount > $user->wallet_balance`). `WalletTransaction` belongsTo `User`, cast `amount`/`balance_after` as `decimal:2`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VerifyWalletServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_credit_increases_balance_and_writes_ledger_row(): void
    {
        $user = User::factory()->create(['wallet_balance' => 100]);

        $transaction = app(WalletService::class)->credit($user, 50, 'reward_approved');

        $this->assertSame('150.00', $user->fresh()->wallet_balance);
        $this->assertInstanceOf(WalletTransaction::class, $transaction);
        $this->assertSame('credit', $transaction->type);
        $this->assertSame('50.00', $transaction->amount);
        $this->assertSame('150.00', $transaction->balance_after);
        $this->assertSame('reward_approved', $transaction->reason);
    }

    public function test_debit_decreases_balance_and_writes_ledger_row(): void
    {
        $user = User::factory()->create(['wallet_balance' => 100]);

        $transaction = app(WalletService::class)->debit($user, 40, 'order_payment');

        $this->assertSame('60.00', $user->fresh()->wallet_balance);
        $this->assertSame('debit', $transaction->type);
        $this->assertSame('40.00', $transaction->amount);
        $this->assertSame('60.00', $transaction->balance_after);
    }

    public function test_debit_more_than_balance_throws_and_changes_nothing(): void
    {
        $user = User::factory()->create(['wallet_balance' => 10]);

        $this->expectException(\DomainException::class);

        try {
            app(WalletService::class)->debit($user, 20, 'order_payment');
        } finally {
            $this->assertSame('10.00', $user->fresh()->wallet_balance);
            $this->assertSame(0, WalletTransaction::count());
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=VerifyWalletServiceTest`
Expected: FAIL with "Class WalletService not found" (or similar).

- [ ] **Step 3: Write the model**

`backend/app/Models/WalletTransaction.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'type',
        'amount',
        'balance_after',
        'reason',
        'reference_type',
        'reference_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reference(): \Illuminate\Database\Eloquent\Relations\MorphTo
    {
        return $this->morphTo();
    }
}
```

- [ ] **Step 4: Write the service**

`backend/app/Services/WalletService.php`:

```php
<?php

namespace App\Services;

use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The only code path allowed to change users.wallet_balance or write a
 * wallet_transactions row. Every caller (reward approval, checkout debit,
 * cancel/return refund) goes through here so balance math never happens
 * twice in two different places.
 */
class WalletService
{
    public function credit(User $user, float $amount, string $reason, ?Model $reference = null): WalletTransaction
    {
        return DB::transaction(function () use ($user, $amount, $reason, $reference) {
            $locked = User::whereKey($user->id)->lockForUpdate()->first();

            $newBalance = (float) $locked->wallet_balance + $amount;
            $locked->update(['wallet_balance' => $newBalance]);

            return WalletTransaction::create([
                'user_id' => $locked->id,
                'type' => 'credit',
                'amount' => $amount,
                'balance_after' => $newBalance,
                'reason' => $reason,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
            ]);
        });
    }

    /**
     * @throws \DomainException if $amount exceeds the user's current balance.
     */
    public function debit(User $user, float $amount, string $reason, ?Model $reference = null): WalletTransaction
    {
        return DB::transaction(function () use ($user, $amount, $reason, $reference) {
            $locked = User::whereKey($user->id)->lockForUpdate()->first();

            if ($amount > (float) $locked->wallet_balance) {
                throw new \DomainException('Wallet balance is insufficient for this debit.');
            }

            $newBalance = (float) $locked->wallet_balance - $amount;
            $locked->update(['wallet_balance' => $newBalance]);

            return WalletTransaction::create([
                'user_id' => $locked->id,
                'type' => 'debit',
                'amount' => $amount,
                'balance_after' => $newBalance,
                'reason' => $reason,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
            ]);
        });
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd backend && php artisan test --filter=VerifyWalletServiceTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
cd backend && git add app/Models/WalletTransaction.php app/Services/WalletService.php tests/Feature/VerifyWalletServiceTest.php
git commit -m "Add WalletTransaction model and WalletService"
```

---

## Task 3: `User` wallet relation/cast + `Order.wallet_amount_used` cast

**Files:**
- Modify: `backend/app/Models/User.php`
- Modify: `backend/app/Models/Order.php`
- Test: `backend/tests/Feature/VerifyWalletServiceTest.php` (extend)

**Interfaces:**
- Consumes: `WalletTransaction` (Task 2).
- Produces: `User::walletTransactions(): HasMany`, `User::$casts['wallet_balance'] = 'decimal:2'`, `Order::$casts['wallet_amount_used'] = 'decimal:2'`, `Order::$fillable` includes `wallet_amount_used`.

- [ ] **Step 1: Write the failing test**

Add to `backend/tests/Feature/VerifyWalletServiceTest.php`:

```php
    public function test_user_has_a_wallet_transactions_relation(): void
    {
        $user = User::factory()->create();
        app(\App\Services\WalletService::class)->credit($user, 25, 'reward_approved');

        $this->assertCount(1, $user->walletTransactions);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=VerifyWalletServiceTest`
Expected: FAIL — "Call to undefined method App\Models\User::walletTransactions()".

- [ ] **Step 3: Update `User.php`**

Read `backend/app/Models/User.php` first. Add the relation and cast:

```php
    public function walletTransactions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(WalletTransaction::class)->latest();
    }

    public function rewardSubmissions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(RewardSubmission::class)->latest();
    }
```

placed after `addresses()`. Add `'wallet_balance' => 'decimal:2',` to the `casts()` array alongside `email_verified_at`/`password`.

- [ ] **Step 4: Update `Order.php`**

Read `backend/app/Models/Order.php` first. Add `'wallet_amount_used',` to `$fillable` (after `'total',`), and `'wallet_amount_used' => 'decimal:2',` to `casts()` (alongside `'total' => 'decimal:2',`).

- [ ] **Step 5: Run test to verify it passes**

Run: `cd backend && php artisan test --filter=VerifyWalletServiceTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
cd backend && git add app/Models/User.php app/Models/Order.php tests/Feature/VerifyWalletServiceTest.php
git commit -m "Add wallet relations/casts to User and Order"
```

---

## Task 4: `RewardSubmission` model (with media collections)

**Files:**
- Create: `backend/app/Models/RewardSubmission.php`
- Create: `backend/database/factories/RewardSubmissionFactory.php`
- Test: `backend/tests/Feature/VerifyRewardSubmissionModelTest.php`

**Interfaces:**
- Consumes: `reward_submissions` table (Task 1), `User`, `Order` models.
- Produces: `RewardSubmission implements HasMedia` with media collections `image` (singleFile, image mimes) and `video` (singleFile, `video/mp4`/`video/webm`), `belongsTo(User)`, `belongsTo(Order)`, `$fillable = ['user_id','order_id','status','reward_amount','rejection_reason','reviewed_at']`, casts `reward_amount => decimal:2`, `reviewed_at => datetime`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\RewardSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class VerifyRewardSubmissionModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_a_submission_with_image_and_video_media(): void
    {
        $user = User::factory()->create();
        $order = $this->makeOrder($user, 'delivered');

        $submission = RewardSubmission::create([
            'user_id' => $user->id,
            'order_id' => $order->id,
            'status' => 'pending',
        ]);

        $submission->addMedia(UploadedFile::fake()->image('proof.jpg', 100, 100)->size(500))
            ->toMediaCollection('image');
        $submission->addMedia(UploadedFile::fake()->create('proof.mp4', 2000, 'video/mp4'))
            ->toMediaCollection('video');

        $this->assertTrue($submission->fresh()->hasMedia('image'));
        $this->assertTrue($submission->fresh()->hasMedia('video'));
        $this->assertSame('pending', $submission->status);
        $this->assertSame($user->id, $submission->user->id);
        $this->assertSame($order->id, $submission->order->id);
    }

    public function test_only_one_submission_per_order_is_allowed_at_the_database_level(): void
    {
        $user = User::factory()->create();
        $order = $this->makeOrder($user, 'delivered');
        RewardSubmission::create(['user_id' => $user->id, 'order_id' => $order->id, 'status' => 'pending']);

        $this->expectException(\Illuminate\Database\QueryException::class);

        RewardSubmission::create(['user_id' => $user->id, 'order_id' => $order->id, 'status' => 'pending']);
    }

    private function makeOrder(User $user, string $status): Order
    {
        return Order::create([
            'user_id' => $user->id,
            'order_number' => 'ORD-RS-'.uniqid(),
            'customer_name' => 'Reward Test',
            'customer_email' => 'reward@example.com',
            'customer_phone' => '9999999999',
            'shipping_address_line1' => 'Test St',
            'shipping_city' => 'Hyderabad',
            'shipping_state' => 'Telangana',
            'shipping_postal_code' => '500001',
            'shipping_country' => 'India',
            'subtotal' => 500,
            'discount_amount' => 0,
            'shipping_fee' => 0,
            'total' => 500,
            'payment_method' => 'cod',
            'payment_status' => 'pending',
            'status' => $status,
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=VerifyRewardSubmissionModelTest`
Expected: FAIL — "Class RewardSubmission not found".

- [ ] **Step 3: Write the model**

`backend/app/Models/RewardSubmission.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class RewardSubmission extends Model implements HasMedia
{
    use InteractsWithMedia;

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('image')
            ->useDisk('original_images')
            ->storeConversionsOnDisk('public')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->singleFile();

        $this->addMediaCollection('video')
            ->useDisk('original_images')
            ->acceptsMimeTypes(['video/mp4', 'video/webm'])
            ->singleFile();
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->performOnCollections('image')
            ->width(400)
            ->format('webp')
            ->quality(78);
    }

    protected $fillable = [
        'user_id',
        'order_id',
        'status',
        'reward_amount',
        'rejection_reason',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'reward_amount' => 'decimal:2',
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
```

- [ ] **Step 4: Write the factory**

`backend/database/factories/RewardSubmissionFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\RewardSubmission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RewardSubmission>
 */
class RewardSubmissionFactory extends Factory
{
    protected $model = RewardSubmission::class;

    public function definition(): array
    {
        return [
            'status' => 'pending',
        ];
    }
}
```

Add `use Illuminate\Database\Eloquent\Factories\HasFactory;` and `use HasFactory;` to `RewardSubmission` (mirrors `Review`'s pattern) — update the model file accordingly.

- [ ] **Step 5: Run test to verify it passes**

Run: `cd backend && php artisan test --filter=VerifyRewardSubmissionModelTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
cd backend && git add app/Models/RewardSubmission.php database/factories/RewardSubmissionFactory.php tests/Feature/VerifyRewardSubmissionModelTest.php
git commit -m "Add RewardSubmission model with image/video media collections"
```

---

## Task 5: Customer submission routes + `RewardSubmissionController`

**Files:**
- Create: `backend/app/Http/Controllers/RewardSubmissionController.php`
- Modify: `backend/routes/web.php`
- Test: `backend/tests/Feature/VerifyRewardSubmissionUploadTest.php`

**Interfaces:**
- Consumes: `RewardSubmission` (Task 4), `Order` (owned-order pattern from `AccountController::authorizeOwnOrder`).
- Produces: routes `account.rewards.index` (GET `/account/rewards`), `account.rewards.store` (POST `/account/rewards`), both under the existing `auth` middleware group.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\RewardSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Server-side validation is the real security boundary here (spec: "upload
 * validation implemented on both frontend and backend for security") — every
 * test in this file drives the actual HTTP endpoint, not the model directly.
 */
class VerifyRewardSubmissionUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_submit_proof_for_their_own_delivered_order(): void
    {
        $user = User::factory()->create();
        $order = $this->makeOrder($user, 'delivered');

        $this->actingAs($user)
            ->post(route('account.rewards.store'), [
                'order_id' => $order->id,
                'image' => UploadedFile::fake()->image('proof.jpg')->size(2000),
                'video' => UploadedFile::fake()->create('proof.mp4', 5000, 'video/mp4'),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('reward_submissions', [
            'user_id' => $user->id,
            'order_id' => $order->id,
            'status' => 'pending',
        ]);
    }

    public function test_image_over_3mb_is_rejected(): void
    {
        $user = User::factory()->create();
        $order = $this->makeOrder($user, 'delivered');

        $this->actingAs($user)
            ->post(route('account.rewards.store'), [
                'order_id' => $order->id,
                'image' => UploadedFile::fake()->image('proof.jpg')->size(3200),
                'video' => UploadedFile::fake()->create('proof.mp4', 5000, 'video/mp4'),
            ])
            ->assertSessionHasErrors('image');

        $this->assertDatabaseMissing('reward_submissions', ['order_id' => $order->id]);
    }

    public function test_video_over_10mb_is_rejected(): void
    {
        $user = User::factory()->create();
        $order = $this->makeOrder($user, 'delivered');

        $this->actingAs($user)
            ->post(route('account.rewards.store'), [
                'order_id' => $order->id,
                'image' => UploadedFile::fake()->image('proof.jpg')->size(2000),
                'video' => UploadedFile::fake()->create('proof.mp4', 10500, 'video/mp4'),
            ])
            ->assertSessionHasErrors('video');

        $this->assertDatabaseMissing('reward_submissions', ['order_id' => $order->id]);
    }

    public function test_non_video_mime_is_rejected_for_video_field(): void
    {
        $user = User::factory()->create();
        $order = $this->makeOrder($user, 'delivered');

        $this->actingAs($user)
            ->post(route('account.rewards.store'), [
                'order_id' => $order->id,
                'image' => UploadedFile::fake()->image('proof.jpg')->size(2000),
                'video' => UploadedFile::fake()->create('proof.txt', 100, 'text/plain'),
            ])
            ->assertSessionHasErrors('video');
    }

    public function test_non_delivered_order_is_rejected(): void
    {
        $user = User::factory()->create();
        $order = $this->makeOrder($user, 'placed');

        $this->actingAs($user)
            ->post(route('account.rewards.store'), [
                'order_id' => $order->id,
                'image' => UploadedFile::fake()->image('proof.jpg')->size(2000),
                'video' => UploadedFile::fake()->create('proof.mp4', 5000, 'video/mp4'),
            ])
            ->assertStatus(422);
    }

    public function test_a_user_cannot_submit_for_another_users_order(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $order = $this->makeOrder($owner, 'delivered');

        $this->actingAs($stranger)
            ->post(route('account.rewards.store'), [
                'order_id' => $order->id,
                'image' => UploadedFile::fake()->image('proof.jpg')->size(2000),
                'video' => UploadedFile::fake()->create('proof.mp4', 5000, 'video/mp4'),
            ])
            ->assertNotFound();
    }

    public function test_cannot_submit_twice_for_the_same_order(): void
    {
        $user = User::factory()->create();
        $order = $this->makeOrder($user, 'delivered');
        RewardSubmission::create(['user_id' => $user->id, 'order_id' => $order->id, 'status' => 'pending']);

        $this->actingAs($user)
            ->post(route('account.rewards.store'), [
                'order_id' => $order->id,
                'image' => UploadedFile::fake()->image('proof.jpg')->size(2000),
                'video' => UploadedFile::fake()->create('proof.mp4', 5000, 'video/mp4'),
            ])
            ->assertStatus(422);
    }

    private function makeOrder(User $user, string $status): Order
    {
        return Order::create([
            'user_id' => $user->id,
            'order_number' => 'ORD-RS-'.uniqid(),
            'customer_name' => 'Reward Test',
            'customer_email' => 'reward@example.com',
            'customer_phone' => '9999999999',
            'shipping_address_line1' => 'Test St',
            'shipping_city' => 'Hyderabad',
            'shipping_state' => 'Telangana',
            'shipping_postal_code' => '500001',
            'shipping_country' => 'India',
            'subtotal' => 500,
            'discount_amount' => 0,
            'shipping_fee' => 0,
            'total' => 500,
            'payment_method' => 'cod',
            'payment_status' => 'pending',
            'status' => $status,
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=VerifyRewardSubmissionUploadTest`
Expected: FAIL — route `account.rewards.store` not defined.

- [ ] **Step 3: Add routes**

Read `backend/routes/web.php` first. Add `use App\Http\Controllers\RewardSubmissionController;` to the `use` block at the top (alphabetically, after `ReviewController`). Inside the existing `Route::middleware('auth')->group(function () { ... })` block that contains the `account.*` routes (the one with `account.addresses.destroy` as its last route), add before the closing `});`:

```php
    Route::get('/account/rewards', [RewardSubmissionController::class, 'index'])
        ->name('account.rewards.index');

    Route::post('/account/rewards', [RewardSubmissionController::class, 'store'])
        ->name('account.rewards.store')
        ->middleware('throttle:10,60');
```

- [ ] **Step 4: Write the controller**

`backend/app/Http/Controllers/RewardSubmissionController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\RewardSubmission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RewardSubmissionController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        $eligibleOrders = $user->orders()
            ->where('status', 'delivered')
            ->whereDoesntHave('rewardSubmission')
            ->get();

        $submissions = $user->rewardSubmissions()->with('order')->get();

        return view('account.rewards', [
            'eligibleOrders' => $eligibleOrders,
            'submissions' => $submissions,
            'walletBalance' => $user->wallet_balance,
            'walletTransactions' => $user->walletTransactions()->paginate(20),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = Auth::user();

        $validated = $request->validate([
            'order_id' => ['required', 'integer'],
            // Server-side validation is the real security boundary — the
            // frontend's accept/size checks are UX only and are never trusted.
            'image' => ['required', 'image', 'max:3072'],
            'video' => ['required', 'mimes:mp4,webm', 'max:10240'],
        ]);

        $order = $user->orders()->find($validated['order_id']);
        abort_if(! $order, 404);
        abort_unless($order->status === 'delivered', 422, 'Only delivered orders are eligible for a reward submission.');
        abort_if(RewardSubmission::where('order_id', $order->id)->exists(), 422, 'A submission already exists for this order.');

        $submission = RewardSubmission::create([
            'user_id' => $user->id,
            'order_id' => $order->id,
            'status' => 'pending',
        ]);

        $submission->addMedia($request->file('image'))->toMediaCollection('image');
        $submission->addMedia($request->file('video'))->toMediaCollection('video');

        return redirect()->route('account.rewards.index')
            ->with('success', 'Thanks! Your submission is pending review.');
    }
}
```

- [ ] **Step 5: Add the `rewardSubmission`/`rewardSubmissions` relations to `Order`**

Read `backend/app/Models/Order.php` first (needed for `whereDoesntHave('rewardSubmission')` above). Add after `couponUsages()`:

```php
    public function rewardSubmission(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(RewardSubmission::class);
    }
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `cd backend && php artisan test --filter=VerifyRewardSubmissionUploadTest`
Expected: PASS (7 tests)

- [ ] **Step 7: Commit**

```bash
cd backend && git add app/Http/Controllers/RewardSubmissionController.php app/Models/Order.php routes/web.php tests/Feature/VerifyRewardSubmissionUploadTest.php
git commit -m "Add customer reward-submission upload endpoint with server-side validation"
```

---

## Task 6: Account "Rewards & Wallet" Blade view (frontend validation + display)

**Files:**
- Create: `backend/resources/views/account/rewards.blade.php`
- Modify: `backend/resources/views/account/index.blade.php`
- Test: `backend/tests/Feature/VerifyRewardSubmissionUploadTest.php` (extend)

**Interfaces:**
- Consumes: view data from `RewardSubmissionController::index` — `$eligibleOrders`, `$submissions`, `$walletBalance`, `$walletTransactions` (Task 5).
- Produces: rendered page at `account.rewards.index` linked from the main account nav; client-side `accept`/size checks on the upload inputs (UX-only, mirrors the constraint spec's "frontend validation ... for security" requirement, but the server check from Task 5 remains authoritative).

- [ ] **Step 1: Write the failing test**

```php
    public function test_rewards_page_shows_wallet_balance_and_eligible_orders(): void
    {
        $user = User::factory()->create(['wallet_balance' => 250]);
        $order = $this->makeOrder($user, 'delivered');

        $response = $this->actingAs($user)->get(route('account.rewards.index'));

        $response->assertOk()
            ->assertSee('₹250.00')
            ->assertSee($order->order_number);
    }

    public function test_rewards_page_shows_rejection_reason_for_rejected_submission(): void
    {
        $user = User::factory()->create();
        $order = $this->makeOrder($user, 'delivered');
        RewardSubmission::create([
            'user_id' => $user->id,
            'order_id' => $order->id,
            'status' => 'rejected',
            'rejection_reason' => 'Video was unclear',
            'reviewed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('account.rewards.index'))
            ->assertOk()
            ->assertSee('Video was unclear');
    }
```

Add both methods to `backend/tests/Feature/VerifyRewardSubmissionUploadTest.php`.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=VerifyRewardSubmissionUploadTest`
Expected: FAIL — view `account.rewards` not found.

- [ ] **Step 3: Write the view**

`backend/resources/views/account/rewards.blade.php`:

```blade
@extends('layouts.app')

@section('meta_title', 'Rewards & Wallet | '.($siteSettings['site_name'] ?? 'Estele'))
@section('meta_description', 'Submit unboxing proof for rewards and manage your wallet balance.')

@section('content')

  <nav class="mx-auto w-full max-w-wrapper px-3 md:px-4 flex flex-wrap items-center gap-1.5 py-4 text-[13px] text-muted" aria-label="Breadcrumb">
    <x-breadcrumb :items="[['label' => 'My Account', 'url' => route('account.index')], ['label' => 'Rewards & Wallet']]" />
  </nav>

  <div class="mx-auto w-full max-w-wrapper px-3 pb-10 md:px-4 md:pb-[60px]">
    <h1 class="mb-6 text-[20px] uppercase tracking-[0.5px] md:text-[26px]">Rewards & Wallet</h1>

    @if(session('success'))
      <p class="mb-6 rounded-lg border border-line bg-pinksoft px-4 py-3 text-[13px] text-heading">{{ session('success') }}</p>
    @endif

    <section class="mb-8 rounded-lg border border-line p-4">
      <h2 class="mb-2 text-[14px] font-medium uppercase tracking-[0.4px]">Wallet Balance</h2>
      <p class="text-[24px] font-medium text-price">₹{{ number_format((float) $walletBalance, 2) }}</p>
    </section>

    <section class="mb-8">
      <h2 class="mb-4 text-[14px] font-medium uppercase tracking-[0.4px]">Submit Unboxing Proof</h2>

      @if($eligibleOrders->isEmpty())
        <p class="rounded-lg border border-line bg-pinksoft px-4 py-4 text-[13px] text-heading">
          No delivered orders are currently eligible for a reward submission.
        </p>
      @else
        @foreach($eligibleOrders as $order)
          <form class="mb-4 rounded-lg border border-line p-4 reward-submission-form" action="{{ route('account.rewards.store') }}" method="post" enctype="multipart/form-data" data-max-image-bytes="3145728" data-max-video-bytes="10485760">
            @csrf
            <input type="hidden" name="order_id" value="{{ $order->id }}">
            <p class="mb-3 text-[13px] font-medium text-heading">Order #{{ $order->order_number }}</p>

            <label class="mb-1.5 block text-[13px] font-medium text-heading" for="image-{{ $order->id }}">Image (max 3MB)</label>
            <input class="mb-1 w-full border border-line-strong bg-white px-4 py-2.5 text-[13px]" id="image-{{ $order->id }}" name="image" type="file" accept="image/*" required>
            <p class="reward-file-error mb-3 hidden text-[12px] text-salebadge" data-for="image-{{ $order->id }}"></p>

            <label class="mb-1.5 block text-[13px] font-medium text-heading" for="video-{{ $order->id }}">Video (max 10MB)</label>
            <input class="mb-1 w-full border border-line-strong bg-white px-4 py-2.5 text-[13px]" id="video-{{ $order->id }}" name="video" type="file" accept="video/mp4,video/webm" required>
            <p class="reward-file-error mb-3 hidden text-[12px] text-salebadge" data-for="video-{{ $order->id }}"></p>

            <button class="inline-flex items-center justify-center gap-2 border border-accent bg-accent px-6 py-2.5 text-[12px] font-medium uppercase tracking-[0.5px] text-white transition-colors hover:border-accent-dark hover:bg-accent-dark" type="submit">
              Submit for Reward
            </button>
          </form>
        @endforeach
      @endif
    </section>

    <section class="mb-8">
      <h2 class="mb-4 text-[14px] font-medium uppercase tracking-[0.4px]">Your Submissions</h2>

      @if($submissions->isEmpty())
        <p class="text-[13px] text-muted">No submissions yet.</p>
      @else
        <div class="space-y-3">
          @foreach($submissions as $submission)
            <div class="rounded-lg border border-line p-4">
              <div class="mb-1 flex flex-wrap items-center justify-between gap-2">
                <span class="text-[13px] font-medium text-heading">Order #{{ $submission->order->order_number }}</span>
                <span class="inline-block rounded-full bg-pinksoft px-3 py-1 text-[11px] font-medium uppercase tracking-[0.3px] text-accent">{{ ucfirst($submission->status) }}</span>
              </div>
              @if($submission->status === 'approved')
                <p class="text-[13px] text-price">Reward credited: ₹{{ number_format((float) $submission->reward_amount, 2) }}</p>
              @elseif($submission->status === 'rejected')
                <p class="text-[13px] text-muted">Reason: {{ $submission->rejection_reason }}</p>
              @endif
            </div>
          @endforeach
        </div>
      @endif
    </section>

    <section>
      <h2 class="mb-4 text-[14px] font-medium uppercase tracking-[0.4px]">Wallet Transaction History</h2>

      @if($walletTransactions->isEmpty())
        <p class="text-[13px] text-muted">No wallet activity yet.</p>
      @else
        <div class="overflow-x-auto">
          <table class="w-full text-left text-[13px]">
            <thead>
              <tr class="border-b border-line text-[11px] uppercase tracking-[0.3px] text-muted">
                <th class="py-2">Date</th>
                <th class="py-2">Type</th>
                <th class="py-2">Reason</th>
                <th class="py-2 text-right">Amount</th>
                <th class="py-2 text-right">Balance</th>
              </tr>
            </thead>
            <tbody>
              @foreach($walletTransactions as $transaction)
                <tr class="border-b border-line">
                  <td class="py-2">{{ $transaction->created_at->format('d M Y') }}</td>
                  <td class="py-2">{{ ucfirst($transaction->type) }}</td>
                  <td class="py-2">{{ str_replace('_', ' ', ucfirst($transaction->reason)) }}</td>
                  <td class="py-2 text-right {{ $transaction->type === 'credit' ? 'text-price' : 'text-salebadge' }}">
                    {{ $transaction->type === 'credit' ? '+' : '-' }}₹{{ number_format((float) $transaction->amount, 2) }}
                  </td>
                  <td class="py-2 text-right">₹{{ number_format((float) $transaction->balance_after, 2) }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
        <div class="mt-4">{{ $walletTransactions->links() }}</div>
      @endif
    </section>
  </div>

  <script>
    // UX-only guard: blocks an obviously-oversized file before the network
    // round-trip. The server (RewardSubmissionController::store) re-validates
    // every upload unconditionally — this check is never the security boundary.
    document.querySelectorAll('.reward-submission-form').forEach(function (form) {
      var maxImage = parseInt(form.dataset.maxImageBytes, 10);
      var maxVideo = parseInt(form.dataset.maxVideoBytes, 10);

      form.addEventListener('submit', function (event) {
        var valid = true;
        form.querySelectorAll('.reward-file-error').forEach(function (el) { el.classList.add('hidden'); el.textContent = ''; });

        var image = form.querySelector('input[name="image"]');
        var video = form.querySelector('input[name="video"]');

        if (image.files[0] && image.files[0].size > maxImage) {
          valid = false;
          showError(image, 'Image must be 3MB or smaller.');
        }
        if (video.files[0] && video.files[0].size > maxVideo) {
          valid = false;
          showError(video, 'Video must be 10MB or smaller.');
        }

        if (!valid) {
          event.preventDefault();
        }
      });

      function showError(input, message) {
        var error = form.querySelector('.reward-file-error[data-for="' + input.id + '"]');
        if (error) {
          error.textContent = message;
          error.classList.remove('hidden');
        }
      }
    });
  </script>

@endsection
```

- [ ] **Step 4: Link the new page from the account nav**

Read `backend/resources/views/account/index.blade.php` first. In the `<nav aria-label="Account">` list, add after the "Addresses" `<li>`:

```blade
          <li><a class="block rounded-md px-3 py-2.5 text-heading transition-colors hover:bg-pinksoft" href="{{ route('account.rewards.index') }}">Rewards & Wallet</a></li>
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd backend && php artisan test --filter=VerifyRewardSubmissionUploadTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
cd backend && git add resources/views/account/rewards.blade.php resources/views/account/index.blade.php tests/Feature/VerifyRewardSubmissionUploadTest.php
git commit -m "Add customer Rewards & Wallet account page with client-side upload checks"
```

---

## Task 7: `RewardSubmissionRejected` Mailable

**Files:**
- Create: `backend/app/Mail/RewardSubmissionRejected.php`
- Create: `backend/resources/views/emails/reward-submission-rejected.blade.php`
- Test: `backend/tests/Feature/VerifyRewardSubmissionApprovalTest.php` (created in Task 8, this task's test is folded into it per the "reject" case — see Task 8 Step 1)

**Interfaces:**
- Consumes: `RewardSubmission` (Task 4).
- Produces: `App\Mail\RewardSubmissionRejected` — `Mailable implements ShouldQueue`, constructor `(public RewardSubmission $submission)`, subject references the order number, view `emails.reward-submission-rejected`.

- [ ] **Step 1: Write the Mailable**

`backend/app/Mail/RewardSubmissionRejected.php` (mirrors `backend/app/Mail/OrderPacked.php` exactly):

```php
<?php

namespace App\Mail;

use App\Models\RewardSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class RewardSubmissionRejected extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public RewardSubmission $submission)
    {
    }

    public function build(): self
    {
        return $this
            ->subject("Your reward submission for order {$this->submission->order->order_number} was not approved")
            ->view('emails.reward-submission-rejected');
    }
}
```

- [ ] **Step 2: Write the view**

`backend/resources/views/emails/reward-submission-rejected.blade.php`:

```blade
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Reward submission update</title>
</head>
<body style="font-family: Helvetica, Arial, sans-serif; font-size: 14px; color: #222;">
    <p>Hi {{ $submission->user->name }},</p>

    <p>Your reward submission for order <strong>{{ $submission->order->order_number }}</strong> was not approved.</p>

    <p><strong>Reason:</strong> {{ $submission->rejection_reason }}</p>

    <p>You're welcome to submit proof for a different eligible order at any time.</p>

    <p>Thank you for shopping with {{ config('app.name') }}.</p>
</body>
</html>
```

- [ ] **Step 3: Commit**

```bash
cd backend && git add app/Mail/RewardSubmissionRejected.php resources/views/emails/reward-submission-rejected.blade.php
git commit -m "Add RewardSubmissionRejected mailable"
```

(No standalone test run here — this Mailable is exercised end-to-end by Task 8's Filament reject-action test, matching how `OrderPacked` itself is only tested via `Order`'s status-transition test rather than in isolation.)

---

## Task 8: Filament `RewardSubmissionResource` (admin approve/reject)

**Files:**
- Create: `backend/app/Filament/Resources/RewardSubmissions/RewardSubmissionResource.php`
- Create: `backend/app/Filament/Resources/RewardSubmissions/Schemas/RewardSubmissionForm.php`
- Create: `backend/app/Filament/Resources/RewardSubmissions/Tables/RewardSubmissionsTable.php`
- Create: `backend/app/Filament/Resources/RewardSubmissions/Pages/ListRewardSubmissions.php`
- Create: `backend/app/Filament/Resources/RewardSubmissions/Pages/EditRewardSubmission.php`
- Modify: `backend/database/seeders/ShieldSeeder.php`
- Test: `backend/tests/Feature/VerifyRewardSubmissionApprovalTest.php`

**Interfaces:**
- Consumes: `RewardSubmission` (Task 4), `WalletService::credit()` (Task 2), `RewardSubmissionRejected` (Task 7).
- Produces: Filament admin pages at `admin/reward-submissions` with row/header **Approve** (prompts `reward_amount`) and **Reject** (prompts `rejection_reason`) actions, each visible only while `status === 'pending'`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature;

use App\Filament\Resources\RewardSubmissions\Pages\EditRewardSubmission;
use App\Mail\RewardSubmissionRejected;
use App\Models\Order;
use App\Models\RewardSubmission;
use App\Models\User;
use Database\Seeders\ShieldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class VerifyRewardSubmissionApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSuperAdmin(): User
    {
        $this->seed(ShieldSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $this->actingAs($admin);

        return $admin;
    }

    public function test_approving_a_submission_credits_the_customers_wallet(): void
    {
        $this->actingAsSuperAdmin();
        $customer = User::factory()->create(['wallet_balance' => 0]);
        $order = $this->makeOrder($customer);
        $submission = RewardSubmission::create(['user_id' => $customer->id, 'order_id' => $order->id, 'status' => 'pending']);

        Livewire::test(EditRewardSubmission::class, ['record' => $submission->getKey()])
            ->callAction('approve', data: ['reward_amount' => 150]);

        $submission->refresh();
        $this->assertSame('approved', $submission->status);
        $this->assertSame('150.00', $submission->reward_amount);
        $this->assertNotNull($submission->reviewed_at);
        $this->assertSame('150.00', $customer->fresh()->wallet_balance);
        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $customer->id,
            'type' => 'credit',
            'amount' => '150.00',
            'reason' => 'reward_approved',
        ]);
    }

    public function test_rejecting_a_submission_sets_reason_and_queues_email(): void
    {
        Mail::fake();
        $this->actingAsSuperAdmin();
        $customer = User::factory()->create();
        $order = $this->makeOrder($customer);
        $submission = RewardSubmission::create(['user_id' => $customer->id, 'order_id' => $order->id, 'status' => 'pending']);

        Livewire::test(EditRewardSubmission::class, ['record' => $submission->getKey()])
            ->callAction('reject', data: ['rejection_reason' => 'Video unclear']);

        $submission->refresh();
        $this->assertSame('rejected', $submission->status);
        $this->assertSame('Video unclear', $submission->rejection_reason);
        $this->assertSame('0.00', $customer->fresh()->wallet_balance);

        Mail::assertQueued(RewardSubmissionRejected::class, fn ($mail) => $mail->submission->is($submission));
    }

    public function test_approve_and_reject_actions_are_hidden_once_already_reviewed(): void
    {
        $this->actingAsSuperAdmin();
        $customer = User::factory()->create();
        $order = $this->makeOrder($customer);
        $submission = RewardSubmission::create([
            'user_id' => $customer->id,
            'order_id' => $order->id,
            'status' => 'approved',
            'reward_amount' => 100,
            'reviewed_at' => now(),
        ]);

        Livewire::test(EditRewardSubmission::class, ['record' => $submission->getKey()])
            ->assertActionHidden('approve')
            ->assertActionHidden('reject');
    }

    private function makeOrder(User $user): Order
    {
        return Order::create([
            'user_id' => $user->id,
            'order_number' => 'ORD-RS-'.uniqid(),
            'customer_name' => 'Reward Test',
            'customer_email' => 'reward@example.com',
            'customer_phone' => '9999999999',
            'shipping_address_line1' => 'Test St',
            'shipping_city' => 'Hyderabad',
            'shipping_state' => 'Telangana',
            'shipping_postal_code' => '500001',
            'shipping_country' => 'India',
            'subtotal' => 500,
            'discount_amount' => 0,
            'shipping_fee' => 0,
            'total' => 500,
            'payment_method' => 'cod',
            'payment_status' => 'pending',
            'status' => 'delivered',
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=VerifyRewardSubmissionApprovalTest`
Expected: FAIL — `EditRewardSubmission` class not found.

- [ ] **Step 3: Write the resource**

`backend/app/Filament/Resources/RewardSubmissions/RewardSubmissionResource.php`:

```php
<?php

namespace App\Filament\Resources\RewardSubmissions;

use App\Filament\Resources\RewardSubmissions\Pages\EditRewardSubmission;
use App\Filament\Resources\RewardSubmissions\Pages\ListRewardSubmissions;
use App\Filament\Resources\RewardSubmissions\Schemas\RewardSubmissionForm;
use App\Filament\Resources\RewardSubmissions\Tables\RewardSubmissionsTable;
use App\Models\RewardSubmission;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class RewardSubmissionResource extends Resource
{
    protected static ?string $model = RewardSubmission::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    public static function form(Schema $schema): Schema
    {
        return RewardSubmissionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RewardSubmissionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRewardSubmissions::route('/'),
            'edit' => EditRewardSubmission::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
```

- [ ] **Step 4: Write the form schema**

`backend/app/Filament/Resources/RewardSubmissions/Schemas/RewardSubmissionForm.php`:

```php
<?php

namespace App\Filament\Resources\RewardSubmissions\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RewardSubmissionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Submission')
                    ->columns(2)
                    ->schema([
                        Select::make('user_id')
                            ->label('Customer')
                            ->relationship('user', 'name')
                            ->disabled(),
                        Select::make('order_id')
                            ->label('Order')
                            ->relationship('order', 'order_number')
                            ->disabled(),
                        Select::make('status')
                            ->options([
                                'pending' => 'Pending',
                                'approved' => 'Approved',
                                'rejected' => 'Rejected',
                            ])
                            ->disabled(),
                        TextInput::make('reward_amount')
                            ->label('Reward amount')
                            ->numeric()
                            ->prefix('₹')
                            ->disabled(),
                        Textarea::make('rejection_reason')
                            ->disabled()
                            ->visible(fn (?string $state) => filled($state))
                            ->columnSpanFull(),
                    ]),

                Section::make('Proof')
                    ->columns(2)
                    ->schema([
                        SpatieMediaLibraryFileUpload::make('image')
                            ->collection('image')
                            ->conversion('thumb')
                            ->disabled(),
                        SpatieMediaLibraryFileUpload::make('video')
                            ->collection('video')
                            ->disabled(),
                    ]),
            ]);
    }
}
```

- [ ] **Step 5: Write the table with Approve/Reject actions**

`backend/app/Filament/Resources/RewardSubmissions/Tables/RewardSubmissionsTable.php`:

```php
<?php

namespace App\Filament\Resources\RewardSubmissions\Tables;

use App\Mail\RewardSubmissionRejected;
use App\Models\RewardSubmission;
use App\Services\WalletService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Mail;

class RewardSubmissionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                SpatieMediaLibraryImageColumn::make('image')
                    ->collection('image')
                    ->conversion('thumb')
                    ->circular(),
                TextColumn::make('user.name')
                    ->label('Customer')
                    ->searchable(),
                TextColumn::make('order.order_number')
                    ->label('Order')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('reward_amount')
                    ->label('Reward')
                    ->money('inr'),
                TextColumn::make('created_at')
                    ->label('Submitted')
                    ->dateTime('d M Y, h:i A')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                    ]),
            ])
            ->recordActions([
                self::approveAction(),
                self::rejectAction(),
                EditAction::make(),
            ]);
    }

    public static function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Approve')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (RewardSubmission $record) => $record->status === 'pending')
            ->schema([
                TextInput::make('reward_amount')
                    ->label('Reward amount (₹)')
                    ->numeric()
                    ->required()
                    ->minValue(0.01),
            ])
            ->action(function (array $data, RewardSubmission $record) {
                $record->update([
                    'status' => 'approved',
                    'reward_amount' => $data['reward_amount'],
                    'reviewed_at' => now(),
                ]);

                app(WalletService::class)->credit(
                    $record->user,
                    (float) $data['reward_amount'],
                    'reward_approved',
                    $record,
                );
            });
    }

    public static function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (RewardSubmission $record) => $record->status === 'pending')
            ->schema([
                Textarea::make('rejection_reason')
                    ->label('Reason')
                    ->required(),
            ])
            ->action(function (array $data, RewardSubmission $record) {
                $record->update([
                    'status' => 'rejected',
                    'rejection_reason' => $data['rejection_reason'],
                    'reviewed_at' => now(),
                ]);

                Mail::to($record->user->email)->queue(new RewardSubmissionRejected($record));
            });
    }
}
```

- [ ] **Step 6: Write the pages**

`backend/app/Filament/Resources/RewardSubmissions/Pages/ListRewardSubmissions.php`:

```php
<?php

namespace App\Filament\Resources\RewardSubmissions\Pages;

use App\Filament\Resources\RewardSubmissions\RewardSubmissionResource;
use Filament\Resources\Pages\ListRecords;

class ListRewardSubmissions extends ListRecords
{
    protected static string $resource = RewardSubmissionResource::class;
}
```

`backend/app/Filament/Resources/RewardSubmissions/Pages/EditRewardSubmission.php`:

```php
<?php

namespace App\Filament\Resources\RewardSubmissions\Pages;

use App\Filament\Resources\RewardSubmissions\RewardSubmissionResource;
use App\Filament\Resources\RewardSubmissions\Tables\RewardSubmissionsTable;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRewardSubmission extends EditRecord
{
    protected static string $resource = RewardSubmissionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            RewardSubmissionsTable::approveAction(),
            RewardSubmissionsTable::rejectAction(),
            DeleteAction::make(),
        ];
    }
}
```

- [ ] **Step 7: Add the resource's permissions to `ShieldSeeder`**

Read `backend/database/seeders/ShieldSeeder.php` first. Add `'RewardSubmission',` to the `RESOURCES` array (after `'Review',`).

- [ ] **Step 8: Run tests to verify they pass**

Run: `cd backend && php artisan test --filter=VerifyRewardSubmissionApprovalTest`
Expected: PASS (3 tests)

- [ ] **Step 9: Commit**

```bash
cd backend && git add app/Filament/Resources/RewardSubmissions database/seeders/ShieldSeeder.php tests/Feature/VerifyRewardSubmissionApprovalTest.php
git commit -m "Add Filament RewardSubmission resource with approve/reject actions"
```

---

## Task 9: Wallet deduction at checkout

**Files:**
- Modify: `backend/app/Http/Controllers/CheckoutController.php`
- Modify: `backend/resources/views/checkout/index.blade.php`
- Test: `backend/tests/Feature/VerifyCheckoutWalletUsageTest.php`

**Interfaces:**
- Consumes: `WalletService::debit()` (Task 2), `users.wallet_balance`, `orders.wallet_amount_used` (Task 1/3).
- Produces: `CheckoutController::store` accepts optional `wallet_amount`, clamps it server-side to `min(requested, orderTotal, user.wallet_balance)`, sets `order.wallet_amount_used`, debits the wallet, and (when the wallet fully covers the total) marks the order paid without going through cod/razorpay.

- [ ] **Step 1: Write the failing tests**

This test mirrors the exact cart/session-seeding pattern used by the existing
`backend/tests/Feature/VerifyRazorpayCheckoutFlowTest.php` (`Cart` is keyed
purely by `session_id` — it has no `user_id` column — so the cart is seeded
by capturing the real session cookie from a `GET` request, then replaying it
on the `POST`):

```php
<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VerifyCheckoutWalletUsageTest extends TestCase
{
    use RefreshDatabase;

    private function checkoutPayload(): array
    {
        return [
            'customer_first_name' => 'Jane',
            'customer_last_name' => 'Doe',
            'customer_email' => 'jane@example.com',
            'customer_phone' => '9999999999',
            'shipping_address_line1' => '1 Main St',
            'shipping_city' => 'Hyderabad',
            'shipping_state' => 'Telangana',
            'shipping_postal_code' => '500001',
            'payment_method' => 'cod',
        ];
    }

    public function test_partial_wallet_use_reduces_debit_and_keeps_order_pending_payment(): void
    {
        $user = User::factory()->create(['wallet_balance' => 100]);
        [$sessionCookieName, $sessionCookieValue] = $this->seedCart($user, $this->makeProduct(500));

        $response = $this->withUnencryptedCookie($sessionCookieName, $sessionCookieValue)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/checkout', $this->checkoutPayload() + ['wallet_amount' => 100]);

        $response->assertRedirect();
        $order = Order::where('customer_email', 'jane@example.com')->first();
        $this->assertNotNull($order);
        $this->assertSame('100.00', $order->wallet_amount_used);
        $this->assertSame('0.00', $user->fresh()->wallet_balance);
        $this->assertSame('pending', $order->payment_status);

        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $user->id,
            'type' => 'debit',
            'amount' => '100.00',
            'reason' => 'order_payment',
        ]);
    }

    public function test_wallet_amount_is_clamped_to_available_balance(): void
    {
        $user = User::factory()->create(['wallet_balance' => 30]);
        [$sessionCookieName, $sessionCookieValue] = $this->seedCart($user, $this->makeProduct(500));

        $this->withUnencryptedCookie($sessionCookieName, $sessionCookieValue)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/checkout', $this->checkoutPayload() + ['customer_email' => 'clamp@example.com', 'wallet_amount' => 999])
            ->assertRedirect();

        $order = Order::where('customer_email', 'clamp@example.com')->first();
        $this->assertSame('30.00', $order->wallet_amount_used);
        $this->assertSame('0.00', $user->fresh()->wallet_balance);
    }

    public function test_wallet_fully_covering_total_marks_order_paid_immediately(): void
    {
        $user = User::factory()->create(['wallet_balance' => 1000]);
        [$sessionCookieName, $sessionCookieValue] = $this->seedCart($user, $this->makeProduct(200));

        $this->withUnencryptedCookie($sessionCookieName, $sessionCookieValue)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/checkout', $this->checkoutPayload() + ['customer_email' => 'fullcover@example.com', 'wallet_amount' => 1000])
            ->assertRedirect();

        $order = Order::where('customer_email', 'fullcover@example.com')->first();
        $this->assertSame((float) $order->total, (float) $order->wallet_amount_used);
        $this->assertSame('paid', $order->payment_status);
    }

    private function makeProduct(float $price): Product
    {
        return Product::create([
            'title' => 'Wallet Test Product',
            'slug' => 'wallet-test-product-'.uniqid(),
            'sku' => 'SKU-WALLET-'.uniqid(),
            'price' => $price,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);
    }

    /**
     * @return array{0: string, 1: string} [sessionCookieName, sessionCookieValue]
     */
    private function seedCart(User $user, Product $product): array
    {
        $this->actingAs($user);
        config(['session.driver' => 'database']);

        $firstResponse = $this->get('/cart');
        $sessionCookieName = config('session.cookie');
        $sessionCookieValue = collect($firstResponse->headers->getCookies())
            ->first(fn ($c) => $c->getName() === $sessionCookieName)
            ->getValue();
        $sessionId = DB::table('sessions')->orderByDesc('last_activity')->value('id');

        $cart = Cart::firstOrCreate(['session_id' => $sessionId]);
        $cart->items()->create(['product_id' => $product->id, 'quantity' => 1]);

        return [$sessionCookieName, $sessionCookieValue];
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=VerifyCheckoutWalletUsageTest`
Expected: FAIL — `wallet_amount_used` stays `0.00` regardless of `wallet_amount` posted (feature not wired yet).

- [ ] **Step 3: Update `CheckoutController::store`**

Read `backend/app/Http/Controllers/CheckoutController.php` first (needed since the transaction closure and post-transaction payment branch both change). Add `use App\Services\WalletService;` to the imports, and inject it via the constructor:

```php
    public function __construct(
        private readonly ShippingManager $shipping,
        private readonly PaymentManager $payments,
        private readonly WalletService $wallet,
    ) {}
```

Add `'wallet_amount' => ['nullable', 'numeric', 'min:0'],` to the `$request->validate([...])` array in `store()`.

Inside the `DB::transaction()` closure, after `$order = Order::create([...]);` and before the `foreach ($items as $item)` loop that creates order items, insert:

```php
                $walletAmountUsed = 0.0;
                if (auth()->check() && (float) ($validated['wallet_amount'] ?? 0) > 0) {
                    $walletAmountUsed = min(
                        (float) $validated['wallet_amount'],
                        (float) $order->total,
                        (float) auth()->user()->wallet_balance,
                    );

                    if ($walletAmountUsed > 0) {
                        $this->wallet->debit(auth()->user(), $walletAmountUsed, 'order_payment', $order);
                        $order->update([
                            'wallet_amount_used' => $walletAmountUsed,
                            'payment_status' => $walletAmountUsed >= (float) $order->total ? 'paid' : $order->payment_status,
                        ]);
                    }
                }
```

Change the closure's `use` clause from `use ($validated, $cart, $items, $shippingFee)` to include nothing new (all needed variables — `$validated`, `auth()`— are already in scope; `auth()` is a global helper, not a captured variable, so no `use` clause change is actually required — verify this when editing and leave the `use` clause as-is unless the linter flags it).

After the transaction, change:

```php
        if ($order->payment_method !== 'razorpay') {
            return redirect()->route('checkout.confirmation', $order)->with('success', 'Order placed successfully.');
        }
```

to:

```php
        if ($order->payment_status === 'paid' || $order->payment_method !== 'razorpay') {
            return redirect()->route('checkout.confirmation', $order)->with('success', 'Order placed successfully.');
        }
```

(A fully wallet-paid order skips Razorpay even if `razorpay` was the selected method, since nothing remains payable.)

- [ ] **Step 4: Add the wallet input to the checkout view**

Read `backend/resources/views/checkout/index.blade.php` first. Near the order summary/totals section, add (guarded to authenticated users with a positive balance):

```blade
@auth
  @if((float) auth()->user()->wallet_balance > 0)
    <div class="mb-4 rounded-lg border border-line p-4">
      <label class="mb-1.5 block text-[13px] font-medium text-heading" for="wallet_amount">
        Use wallet balance (available: ₹{{ number_format((float) auth()->user()->wallet_balance, 2) }})
      </label>
      <input class="w-full border border-line-strong bg-white px-4 py-2.5 text-[13px]" id="wallet_amount" name="wallet_amount" type="number" min="0" step="0.01" max="{{ auth()->user()->wallet_balance }}" placeholder="0.00">
    </div>
  @endif
@endauth
```

(Exact placement inside the existing form depends on the current template structure — insert it inside the same `<form>` that posts to `checkout.store`, anywhere before the submit button, so it's included in the POST body.)

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd backend && php artisan test --filter=VerifyCheckoutWalletUsageTest`
Expected: PASS (3 tests)

- [ ] **Step 6: Run the full checkout test suite to check for regressions**

Run: `cd backend && php artisan test --filter=Checkout`
Expected: PASS — existing checkout tests (coupon, stock lock, razorpay) still pass unchanged since wallet logic is additive and defaults to `wallet_amount = 0`.

- [ ] **Step 7: Commit**

```bash
cd backend && git add app/Http/Controllers/CheckoutController.php resources/views/checkout/index.blade.php tests/Feature/VerifyCheckoutWalletUsageTest.php
git commit -m "Deduct wallet balance at checkout, skip gateway when fully wallet-paid"
```

---

## Task 10: Wallet auto-refund on order cancel/return

**Files:**
- Modify: `backend/app/Models/Order.php`
- Test: `backend/tests/Feature/VerifyOrderStatusPipelineTest.php` (extend)

**Interfaces:**
- Consumes: `WalletService::credit()` (Task 2), `Order::RESTOCKING_STATUSES`, `Order.wallet_amount_used` (Task 3).
- Produces: cancelling/returning an order with `wallet_amount_used > 0` and a non-null `user_id` credits that amount back to the customer's wallet exactly once, with reason `order_refund`.

- [ ] **Step 1: Write the failing test**

Add to `backend/tests/Feature/VerifyOrderStatusPipelineTest.php` (read the file first — it already has a `makeOrder()` helper and `Mail::fake()` patterns to follow):

```php
    public function test_cancelling_an_order_refunds_the_wallet_amount_used(): void
    {
        $user = \App\Models\User::factory()->create(['wallet_balance' => 0]);
        $order = $this->makeOrder(['status' => 'placed', 'user_id' => $user->id, 'wallet_amount_used' => 50]);

        $order->update(['status' => 'cancelled']);

        $this->assertSame('50.00', $user->fresh()->wallet_balance);
        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $user->id,
            'type' => 'credit',
            'amount' => '50.00',
            'reason' => 'order_refund',
        ]);
    }

    public function test_cancelling_a_guest_order_with_wallet_amount_used_does_not_error(): void
    {
        // Defensive case only — a guest order (no user_id) can never actually
        // have wallet_amount_used > 0 in practice (checkout requires auth to
        // apply wallet), but the refund hook must not blow up if it happens.
        $order = $this->makeOrder(['status' => 'placed', 'user_id' => null, 'wallet_amount_used' => 50]);

        $order->update(['status' => 'cancelled']);

        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_cancelling_an_order_with_no_wallet_amount_used_does_not_touch_wallet(): void
    {
        $user = \App\Models\User::factory()->create(['wallet_balance' => 20]);
        $order = $this->makeOrder(['status' => 'placed', 'user_id' => $user->id]);

        $order->update(['status' => 'cancelled']);

        $this->assertSame('20.00', $user->fresh()->wallet_balance);
        $this->assertSame(0, \App\Models\WalletTransaction::count());
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=VerifyOrderStatusPipelineTest`
Expected: FAIL — wallet balance stays `0.00`, no `wallet_transactions` row.

- [ ] **Step 3: Update `Order::restock()` call site / add the refund hook**

Read `backend/app/Models/Order.php` first. In the `static::updating` closure, immediately after the existing:

```php
            if (in_array($to, self::RESTOCKING_STATUSES, true)) {
                $order->restock();
            }
```

add:

```php
            if (in_array($to, self::RESTOCKING_STATUSES, true) && (float) $order->wallet_amount_used > 0 && $order->user_id) {
                app(\App\Services\WalletService::class)->credit(
                    $order->user()->first(),
                    (float) $order->wallet_amount_used,
                    'order_refund',
                    $order,
                );
            }
```

(Placed inside `updating`, same as `restock()`, so it fires exactly once per order for the same reason the existing restock comment documents: cancelled/returned are terminal states, so no repeat transition — and thus no repeat refund — is possible afterward.)

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test --filter=VerifyOrderStatusPipelineTest`
Expected: PASS (all tests in this file, including the 3 new ones)

- [ ] **Step 5: Commit**

```bash
cd backend && git add app/Models/Order.php tests/Feature/VerifyOrderStatusPipelineTest.php
git commit -m "Auto-refund wallet_amount_used to customer wallet on cancel/return"
```

---

## Task 11: Full regression pass

**Files:**
- None (verification only).

**Interfaces:**
- Consumes: everything from Tasks 1–10.
- Produces: confidence that nothing existing broke.

- [ ] **Step 1: Run the entire backend test suite**

Run: `cd backend && php artisan test`
Expected: PASS — every existing test (Order pipeline, checkout, reviews, media limits, Filament resources) plus every new test added in Tasks 1–10.

- [ ] **Step 2: If anything fails, fix forward**

Read the failing test's file and the code it exercises before changing either. Do not weaken an assertion to make it pass — if a new feature broke an old invariant (e.g., an existing checkout test that didn't anticipate the new `wallet_amount_used` column appearing in an `assertDatabaseHas` array), fix the test's expectations to include the new column's default value, not the underlying behavior.

- [ ] **Step 3: Final commit if fixes were needed**

```bash
cd backend && git add -A
git commit -m "Fix regressions from reward submissions & wallet feature"
```
