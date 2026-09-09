# Video Approval System Port + Mobile UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bring the already-built, already-tested Video Approval System (reward submissions + wallet + multi-vendor approval) from the disjoint `feat/reward-submissions-wallet` branch onto `ayush-feat`, and rebuild/verify every customer- and admin-facing screen for mobile responsiveness.

**Architecture:** Backend (models, migrations, services, policies, Filament resources, mail, controllers) ports with file copies, merged by hand into the 6 files that have diverged on `ayush-feat` (`User.php`, `Order.php`, `CheckoutController.php`, `ShieldSeeder.php`, `routes/web.php`, `EditOrder.php`/`OrderForm.php`). Views are copied where they already match the current design system, and explicitly audited/patched for mobile at a 375px viewport where the spec calls for it. No new database design, no new locking primitive — the existing atomic `UPDATE ... WHERE status = 'pending'` conditional update is the entire concurrency guard, already proven under test.

**Tech Stack:** Laravel 13, Filament 5 (Shield plugin for RBAC), Spatie MediaLibrary, Spatie Permission, SQLite (dev), Blade + Tailwind (burgundy/ivory/gold design tokens already defined in the app).

**Spec:** `docs/superpowers/specs/2026-09-05-video-approval-system-port-design.md` (references `docs/superpowers/specs/2026-09-02-reward-submissions-wallet-design.md` and `docs/superpowers/specs/2026-09-04-video-approval-system-design.md` for full backend behavior — those two docs are readable from the `feat/reward-submissions-wallet` worktree at `.worktrees/reward-submissions-wallet/docs/superpowers/specs/`).

## Global Constraints

- Source branch for every ported file: `feat/reward-submissions-wallet` (last commit `45a737d`), read via the existing worktree at `/Users/ayushman/Desktop/estele-jewellery/.worktrees/reward-submissions-wallet/backend`. Do not `git merge` that branch — its history is disjoint (different root commit) from `ayush-feat`.
- Single-reviewer lock stays exactly as built: any one `vendor` or `super_admin` approving/denying locks the request for everyone else. No dual-approval/two-signoff logic.
- `vendor` is an internal reviewer role only — no marketplace catalog, no vendor-specific wallet. Confirmed out of scope.
- No new Sanctum/API routes in this pass — keep locking/attribution logic in the model layer (already true) so a future mobile API can reuse it.
- Money fields: `decimal(10,2)`, formatted `₹{{ number_format((float) $x, 2) }}` everywhere, matching existing convention (see `account/index.blade.php`'s `text-price` order totals).
- Design tokens to reuse everywhere (already defined, do not invent new ones): `accent`, `accent-dark`, `heading`, `pinksoft`, `line`, `line-strong`, `price`, `salebadge`, `muted`. Text scale: `text-[11px]`/`[12px]`/`[13px]`/`[14px]`, uppercase tracked headers `text-[14px] font-medium uppercase tracking-[0.4px]`.
- Every task that touches a file with git history on `ayush-feat` must diff against the current file first (shown inline in each task below) — never blind-overwrite.
- Run `php artisan test` after every backend task; run the specific new/ported test file first, then the full suite at the end of Phase 1 to confirm no regression from the divergence.

---

## Phase 1 — Backend port

### Task 1: Migrations — reward_submissions, wallet_transactions, wallet columns

**Files:**
- Create: `backend/database/migrations/2026_09_05_100000_create_reward_submissions_table.php`
- Create: `backend/database/migrations/2026_09_05_100001_create_wallet_transactions_table.php`
- Create: `backend/database/migrations/2026_09_05_100002_add_wallet_balance_to_users_table.php`
- Create: `backend/database/migrations/2026_09_05_100003_add_wallet_amount_used_to_orders_table.php`
- Create: `backend/database/migrations/2026_09_05_100004_add_reviewed_by_to_reward_submissions_table.php`

**Interfaces:**
- Produces: tables `reward_submissions` (`id`, `user_id`, `order_id` unique, `status` default `pending`, `reward_amount` nullable decimal(10,2), `rejection_reason` nullable text, `reviewed_at` nullable timestamp, `reviewed_by` nullable FK→users nullOnDelete, timestamps) and `wallet_transactions` (`id`, `user_id`, `type`, `amount` decimal(10,2), `balance_after` decimal(10,2), `reason`, `reference_type` nullable, `reference_id` nullable, `created_at` only — no `updated_at`); `users.wallet_balance` decimal(10,2) default 0; `orders.wallet_amount_used` decimal(10,2) default 0. Every later task's models/services depend on these exact column names and types.

- [ ] **Step 1: Create the reward_submissions migration**

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

Save as `backend/database/migrations/2026_09_05_100000_create_reward_submissions_table.php`.

- [ ] **Step 2: Create the wallet_transactions migration**

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
            $table->string('reason'); // reward_approved | order_payment | order_refund | admin_credit:* | admin_debit:*
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

Save as `backend/database/migrations/2026_09_05_100001_create_wallet_transactions_table.php`.

- [ ] **Step 3: Add wallet_balance to users**

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

Save as `backend/database/migrations/2026_09_05_100002_add_wallet_balance_to_users_table.php`. (`phone` column already exists on `ayush-feat`'s `users` table — confirmed via `Schema::getColumnListing('users')`.)

- [ ] **Step 4: Add wallet_amount_used to orders**

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

Save as `backend/database/migrations/2026_09_05_100003_add_wallet_amount_used_to_orders_table.php`. (`total` column already exists on `ayush-feat`'s `orders` table — confirmed.)

- [ ] **Step 5: Add reviewed_by to reward_submissions**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reward_submissions', function (Blueprint $table) {
            $table->foreignId('reviewed_by')
                ->nullable()
                ->after('user_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reward_submissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
        });
    }
};
```

Save as `backend/database/migrations/2026_09_05_100004_add_reviewed_by_to_reward_submissions_table.php`.

- [ ] **Step 6: Run migrations and verify**

Run: `cd backend && php artisan migrate`
Expected: all 5 migrations run without error; `php artisan tinker --execute="dd(Schema::hasTable('reward_submissions'), Schema::hasTable('wallet_transactions'), Schema::hasColumn('users','wallet_balance'), Schema::hasColumn('orders','wallet_amount_used'), Schema::hasColumn('reward_submissions','reviewed_by'));"` prints `true` five times.

- [ ] **Step 7: Commit**

```bash
cd /Users/ayushman/Desktop/estele-jewellery
git add backend/database/migrations/2026_09_05_10000*.php
git commit -m "Add reward_submissions, wallet_transactions tables and wallet columns"
```

---

### Task 2: Models — RewardSubmission, WalletTransaction, User/Order relations

**Files:**
- Create: `backend/app/Models/RewardSubmission.php`
- Create: `backend/app/Models/WalletTransaction.php`
- Modify: `backend/app/Models/User.php`
- Modify: `backend/app/Models/Order.php`
- Test: `backend/tests/Feature/VerifyRewardSubmissionMigrationTest.php`
- Test: `backend/tests/Feature/VerifyRewardSubmissionModelTest.php`

**Interfaces:**
- Consumes: tables from Task 1.
- Produces: `RewardSubmission` (fillable `user_id, order_id, status, reward_amount, rejection_reason, reviewed_at, reviewed_by`; relations `user()`, `order()`, `reviewer()`; media collections `image`/`video` with `thumb` conversion). `WalletTransaction` (fillable `user_id, type, amount, balance_after, reason, reference_type, reference_id`; relation `user()`, `reference()` morphTo). `User::walletTransactions()`, `User::rewardSubmissions()`. `Order::rewardSubmission()` (hasOne). These relation names are used verbatim by every later task (controller, Filament tables, views).

- [ ] **Step 1: Write the migration-shape test**

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
        $this->assertTrue(Schema::hasColumn('reward_submissions', 'reviewed_by'));

        $this->assertTrue(Schema::hasTable('wallet_transactions'));
        $this->assertTrue(Schema::hasColumns('wallet_transactions', [
            'user_id', 'type', 'amount', 'balance_after', 'reason', 'reference_type', 'reference_id',
        ]));

        $this->assertTrue(Schema::hasColumn('users', 'wallet_balance'));
        $this->assertTrue(Schema::hasColumn('orders', 'wallet_amount_used'));
    }
}
```

Save as `backend/tests/Feature/VerifyRewardSubmissionMigrationTest.php`.

- [ ] **Step 2: Run it — should already pass (migrations ran in Task 1)**

Run: `cd backend && php artisan test --filter=VerifyRewardSubmissionMigrationTest`
Expected: PASS (this is a smoke test for Task 1, not new-code-driven — passing now confirms Task 1 landed correctly before building models on top of it).

- [ ] **Step 3: Create RewardSubmission model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class RewardSubmission extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

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
        'reviewed_by',
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

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
```

Save as `backend/app/Models/RewardSubmission.php`. Verify `original_images` disk exists: `grep -n "original_images" backend/config/filesystems.php` (used already by `Product`/`Review` models per the spec — confirm before running, don't invent a new disk).

- [ ] **Step 4: Create WalletTransaction model**

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

Save as `backend/app/Models/WalletTransaction.php`.

- [ ] **Step 5: Modify User.php — add wallet_balance to fillable/casts and two relations**

Read `backend/app/Models/User.php` first (current content has `#[Fillable(['name', 'email', 'phone', 'password'])]` and no wallet relations). Change the `#[Fillable]` attribute and add two methods and one cast entry:

```php
#[Fillable(['name', 'email', 'phone', 'password', 'wallet_balance'])]
```

Add after `addresses()`:

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

Add `'wallet_balance' => 'decimal:2',` inside the `casts()` return array, alongside the existing `email_verified_at`/`password` entries.

- [ ] **Step 6: Modify Order.php — add wallet_amount_used to fillable/casts and rewardSubmission relation**

Read `backend/app/Models/Order.php` first. Add `'wallet_amount_used',` to `$fillable` right after `'total',`. Add `'wallet_amount_used' => 'decimal:2',` to `casts()` right after `'total' => 'decimal:2',`. Add this method after `couponUsages()`:

```php
    public function rewardSubmission(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(RewardSubmission::class);
    }
```

Do not touch `ALLOWED_TRANSITIONS`, `booted()`, `restock()`, or `applyRefund()` in this task — those are handled in Task 3.

- [ ] **Step 7: Write the model test**

```php
<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\RewardSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class VerifyRewardSubmissionModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_a_submission_with_image_and_video_media(): void
    {
        // Media conversions (e.g. the "thumb" webp conversion) are queued and
        // run in a job; faking the queue keeps this test from executing that
        // job synchronously, since this environment's gd build has no webp
        // support compiled in.
        Queue::fake();

        $user = User::factory()->create();
        $order = $this->makeOrder($user, 'delivered');

        $submission = RewardSubmission::create([
            'user_id' => $user->id,
            'order_id' => $order->id,
            'status' => 'pending',
        ]);

        $submission->addMedia(UploadedFile::fake()->image('proof.png', 100, 100)->size(500))
            ->toMediaCollection('image');
        // media-library detects the mime type by sniffing the file's actual
        // bytes on disk, not the client-reported mime — so the fake upload
        // needs real MP4 content (a minimal ftyp box) rather than an empty
        // placeholder file, or it would be rejected as application/x-empty.
        $submission->addMedia(UploadedFile::fake()->createWithContent(
            'proof.mp4',
            hex2bin('0000001c6674797069736f6d0000020069736f6d69736f326d703431')
        ))->toMediaCollection('video');

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

Save as `backend/tests/Feature/VerifyRewardSubmissionModelTest.php`.

- [ ] **Step 8: Run tests**

Run: `cd backend && php artisan test --filter=VerifyRewardSubmissionModelTest`
Expected: PASS, 2/2.

- [ ] **Step 9: Commit**

```bash
cd /Users/ayushman/Desktop/estele-jewellery
git add backend/app/Models/RewardSubmission.php backend/app/Models/WalletTransaction.php backend/app/Models/User.php backend/app/Models/Order.php backend/tests/Feature/VerifyRewardSubmissionMigrationTest.php backend/tests/Feature/VerifyRewardSubmissionModelTest.php
git commit -m "Add RewardSubmission, WalletTransaction models and wallet relations"
```

---

### Task 3: WalletService + Order auto-refund hook (merged, not overwritten)

**Files:**
- Create: `backend/app/Services/WalletService.php`
- Create: `backend/app/Mail/WalletCredited.php`
- Create: `backend/resources/views/emails/wallet-credited.blade.php`
- Modify: `backend/app/Models/Order.php` (merge into existing `booted()`)
- Test: `backend/tests/Feature/VerifyWalletServiceTest.php` (port verbatim — see Step 5)

**Interfaces:**
- Consumes: `WalletTransaction`, `User::wallet_balance` (Task 2).
- Produces: `WalletService::credit(User $user, float $amount, string $reason, ?Model $reference = null): WalletTransaction` and `WalletService::debit(...)` (throws `\DomainException` on insufficient balance). Every later task (approval action, checkout, cancel/return hook, WalletManagement resource) calls these two methods exclusively — no other code may write `wallet_balance` or insert into `wallet_transactions`.

- [ ] **Step 1: Create the WalletCredited mailable**

```php
<?php

namespace App\Mail;

use App\Models\WalletTransaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class WalletCredited extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public WalletTransaction $transaction)
    {
    }

    public function build(): self
    {
        return $this
            ->subject('Wallet Updated')
            ->view('emails.wallet-credited');
    }
}
```

Save as `backend/app/Mail/WalletCredited.php`.

- [ ] **Step 2: Create the wallet-credited email view**

```blade
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Wallet Updated</title>
</head>
<body style="font-family: Helvetica, Arial, sans-serif; font-size: 14px; color: #222;">
    <p>Hi {{ $transaction->user->name }},</p>

    <p>
        @if($transaction->reason === 'reward_approved')
            Congratulations! ₹{{ number_format((float) $transaction->amount, 2) }} has been credited to your wallet as a reward.
        @elseif($transaction->reason === 'order_refund')
            ₹{{ number_format((float) $transaction->amount, 2) }} has been refunded to your wallet.
        @else
            ₹{{ number_format((float) $transaction->amount, 2) }} has been credited to your wallet.
        @endif
    </p>

    <p>Current wallet balance: <strong>₹{{ number_format((float) $transaction->balance_after, 2) }}</strong></p>

    <p>Thank you for shopping with {{ config('app.name') }}.</p>
</body>
</html>
```

Save as `backend/resources/views/emails/wallet-credited.blade.php`. Mobile check (per spec's audit): this template has no fixed-width table and no small tap targets — it's plain paragraphs, already renders fine on a phone-width email client. No changes needed; note this in the Phase 3 audit as "verified, no change".

- [ ] **Step 3: Create WalletService**

```php
<?php

namespace App\Services;

use App\Mail\WalletCredited;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

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
        $transaction = DB::transaction(function () use ($user, $amount, $reason, $reference) {
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

        Mail::to($user->email)->queue(new WalletCredited($transaction->fresh(['user'])));

        return $transaction;
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

Save as `backend/app/Services/WalletService.php`.

- [ ] **Step 4: Merge the auto-refund hook into Order::booted()**

Read `backend/app/Models/Order.php`'s current `booted()` method (it has a `static::updating(...)` guard and a `static::updated(...)` listener that queues `OrderPacked` on transition to `accepted` — do not remove or replace that listener). Add a **second** `static::updated(...)` listener right after the existing one, inside `booted()`:

```php
        // Runs post-commit (updating() cannot be used here — WalletService::credit()
        // opens and commits its OWN transaction, so crediting the wallet from a
        // pre-commit hook could leave the wallet credited even if the outer status
        // update itself later rolled back). wasChanged() (not isDirty()) is the
        // correct check here: by the time `updated` fires, dirty-tracking has
        // already been cleared, but wasChanged() still reports what the save
        // actually persisted.
        static::updated(function (Order $order) {
            if (! $order->wasChanged('status')) {
                return;
            }

            if (
                in_array($order->status, self::RESTOCKING_STATUSES, true)
                && (float) $order->wallet_amount_used > 0
                && $order->user_id
            ) {
                app(\App\Services\WalletService::class)->credit(
                    $order->user,
                    (float) $order->wallet_amount_used,
                    'order_refund',
                    $order,
                );
            }
        });
```

The result is `booted()` with three registrations total: the existing `updating` transition guard, the existing `updated` OrderPacked-mail listener, and this new `updated` wallet-refund listener. Both `updated` listeners fire independently on the same event — Laravel supports multiple listeners per event, they don't need merging into one closure.

- [ ] **Step 5: Port the WalletService test verbatim**

Copy `backend/tests/Feature/VerifyWalletServiceTest.php` from the worktree at `/Users/ayushman/Desktop/estele-jewellery/.worktrees/reward-submissions-wallet/backend/tests/Feature/VerifyWalletServiceTest.php` to the same path in the main repo, unchanged (it only exercises `WalletService::credit()`/`debit()` in isolation — no dependency on anything that diverged).

Run: `cp /Users/ayushman/Desktop/estele-jewellery/.worktrees/reward-submissions-wallet/backend/tests/Feature/VerifyWalletServiceTest.php /Users/ayushman/Desktop/estele-jewellery/backend/tests/Feature/VerifyWalletServiceTest.php`

- [ ] **Step 6: Run tests**

Run: `cd backend && php artisan test --filter=VerifyWalletServiceTest`
Expected: PASS.

Also run the full Order-related suite to confirm the merged `booted()` didn't break the existing `accepted`-transition mail hook:

Run: `cd backend && php artisan test --filter=VerifyOrderStatusPipelineTest`
Expected: PASS (pre-existing test, must remain green — this is the regression check for the merge in Step 4).

- [ ] **Step 7: Commit**

```bash
cd /Users/ayushman/Desktop/estele-jewellery
git add backend/app/Services/WalletService.php backend/app/Mail/WalletCredited.php backend/resources/views/emails/wallet-credited.blade.php backend/app/Models/Order.php backend/tests/Feature/VerifyWalletServiceTest.php
git commit -m "Add WalletService and auto-refund-on-cancel/return hook"
```

---

### Task 4: Vendor role + RewardSubmissionPolicy

**Files:**
- Modify: `backend/database/seeders/ShieldSeeder.php`
- Create: `backend/app/Policies/RewardSubmissionPolicy.php`
- Test: `backend/tests/Feature/VerifyRewardSubmissionPolicyTest.php`

**Interfaces:**
- Consumes: `RewardSubmission` model (Task 2), Spatie `Role`/Shield permission strings (`ViewAny:RewardSubmission` etc.).
- Produces: `vendor` role seeded with `ViewAny:RewardSubmission`, `View:RewardSubmission`, `Update:RewardSubmission` only (no Create/Delete). Policy class registered via Laravel's model-policy auto-discovery convention (`RewardSubmission` → `RewardSubmissionPolicy`, no explicit registration needed since both follow the standard naming). Later tasks (Filament resource, controller) rely on `$user->can('ViewAny', RewardSubmission::class)` etc. resolving through this policy.

- [ ] **Step 1: Read current ShieldSeeder.php and add RewardSubmission to the permission-generating resource list**

Read `backend/database/seeders/ShieldSeeder.php` first. Find the array of resource names used to auto-generate `ViewAny:X`/`View:X`/etc. permissions (contains entries like `'Banner', 'Category', 'Collection', ...'Review',`) and add `'RewardSubmission',` to that list.

- [ ] **Step 2: Add the vendor role block at the end of ShieldSeeder::run()**

Add this at the end of the `run()` method, after the existing role-permission blocks (e.g. after wherever `NewsletterSubscriber`'s read-only permissions are synced):

```php
        // Reviews and approves/denies customer reward-submission videos —
        // no other resource access. Update (not Create/Delete): the
        // approve/reject actions on RewardSubmissionsTable both operate via
        // an update, and the resource's own canCreate() is already false —
        // there is nothing for a vendor to create or delete here.
        $vendor = Role::firstOrCreate(['name' => 'vendor', 'guard_name' => 'web']);
        $vendor->syncPermissions([
            'ViewAny:RewardSubmission', 'View:RewardSubmission', 'Update:RewardSubmission',
        ]);
```

Confirm `Role` is already imported at the top of the file (it will be, since `super_admin`/`marketing` roles are created the same way) — if not, add `use Spatie\Permission\Models\Role;`.

- [ ] **Step 3: Create RewardSubmissionPolicy**

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\RewardSubmission;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class RewardSubmissionPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:RewardSubmission');
    }

    public function view(AuthUser $authUser, ?RewardSubmission $rewardSubmission = null): bool
    {
        return $authUser->can('View:RewardSubmission');
    }

    public function update(AuthUser $authUser, ?RewardSubmission $rewardSubmission = null): bool
    {
        return $authUser->can('Update:RewardSubmission');
    }

    public function delete(AuthUser $authUser, ?RewardSubmission $rewardSubmission = null): bool
    {
        return $authUser->can('Delete:RewardSubmission');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:RewardSubmission');
    }
}
```

Save as `backend/app/Policies/RewardSubmissionPolicy.php`. Both `delete`/`deleteAny` are required from the start (not added later) — a prior pass on the source branch discovered that Filament falls through to default-allow for any policy method that doesn't exist at all, so omitting these would let a `vendor` permanently delete an approved submission whose wallet credit was already paid out.

- [ ] **Step 4: Write the policy test**

```php
<?php

namespace Tests\Feature;

use App\Filament\Resources\RewardSubmissions\RewardSubmissionResource;
use App\Models\Order;
use App\Models\RewardSubmission;
use App\Models\User;
use Database\Seeders\ShieldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VerifyRewardSubmissionPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendor_can_view_and_update_reward_submissions(): void
    {
        $this->seed(ShieldSeeder::class);
        $vendor = User::factory()->create();
        $vendor->assignRole('vendor');

        $this->assertTrue($vendor->can('ViewAny', RewardSubmission::class));
        $this->assertTrue($vendor->can('View', RewardSubmission::class));
        $this->assertTrue($vendor->can('Update', RewardSubmission::class));
    }

    public function test_marketing_role_cannot_view_reward_submissions(): void
    {
        $this->seed(ShieldSeeder::class);
        $marketer = User::factory()->create();
        $marketer->assignRole('marketing');

        $this->assertFalse($marketer->can('ViewAny', RewardSubmission::class));
    }

    public function test_super_admin_can_view_and_update_reward_submissions(): void
    {
        $this->seed(ShieldSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        $this->assertTrue($admin->can('ViewAny', RewardSubmission::class));
        $this->assertTrue($admin->can('Update', RewardSubmission::class));
    }

    public function test_user_with_no_role_cannot_view_reward_submissions(): void
    {
        $this->seed(ShieldSeeder::class);
        $nobody = User::factory()->create();

        $this->assertFalse($nobody->can('ViewAny', RewardSubmission::class));
    }

    public function test_vendor_cannot_delete_a_reward_submission(): void
    {
        $this->seed(ShieldSeeder::class);
        $vendor = User::factory()->create();
        $vendor->assignRole('vendor');

        $customer = User::factory()->create();
        $order = Order::create([
            'user_id' => $customer->id,
            'order_number' => 'ORD-POL-'.uniqid(),
            'customer_name' => 'Policy Test',
            'customer_email' => 'policy@example.com',
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
        $submission = RewardSubmission::create(['user_id' => $customer->id, 'order_id' => $order->id, 'status' => 'pending']);

        $this->actingAs($vendor);

        $this->assertFalse($vendor->can('Delete', $submission));
        $this->assertFalse(RewardSubmissionResource::canDelete($submission));
    }

    public function test_super_admin_can_delete_a_reward_submission(): void
    {
        $this->seed(ShieldSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        $customer = User::factory()->create();
        $order = Order::create([
            'user_id' => $customer->id,
            'order_number' => 'ORD-POL-'.uniqid(),
            'customer_name' => 'Policy Test',
            'customer_email' => 'policy@example.com',
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
        $submission = RewardSubmission::create(['user_id' => $customer->id, 'order_id' => $order->id, 'status' => 'pending']);

        $this->actingAs($admin);

        $this->assertTrue($admin->can('Delete', $submission));
        $this->assertTrue(RewardSubmissionResource::canDelete($submission));
    }
}
```

Save as `backend/tests/Feature/VerifyRewardSubmissionPolicyTest.php`. Note: this test references `App\Filament\Resources\RewardSubmissions\RewardSubmissionResource`, which doesn't exist until Task 5 — this test will fail with a class-not-found error until Task 5 lands. That's expected; re-run it at the end of Task 5, not now.

- [ ] **Step 2 (revisit after Task 5): Run tests**

Run (after Task 5 is complete): `cd backend && php artisan test --filter=VerifyRewardSubmissionPolicyTest`
Expected: PASS, 6/6.

- [ ] **Step 3: Commit**

```bash
cd /Users/ayushman/Desktop/estele-jewellery
git add backend/database/seeders/ShieldSeeder.php backend/app/Policies/RewardSubmissionPolicy.php backend/tests/Feature/VerifyRewardSubmissionPolicyTest.php
git commit -m "Add vendor role and RewardSubmissionPolicy"
```

---

### Task 5: Filament RewardSubmissions resource (approve/deny, attribution, race-safety)

**Files:**
- Create: `backend/app/Filament/Resources/RewardSubmissions/RewardSubmissionResource.php`
- Create: `backend/app/Filament/Resources/RewardSubmissions/Schemas/RewardSubmissionForm.php`
- Create: `backend/app/Filament/Resources/RewardSubmissions/Tables/RewardSubmissionsTable.php`
- Create: `backend/app/Filament/Resources/RewardSubmissions/Pages/ListRewardSubmissions.php`
- Create: `backend/app/Filament/Resources/RewardSubmissions/Pages/EditRewardSubmission.php`
- Create: `backend/app/Mail/RewardSubmissionRejected.php`
- Create: `backend/resources/views/emails/reward-submission-rejected.blade.php`
- Test: `backend/tests/Feature/VerifyRewardSubmissionApprovalTest.php`

**Interfaces:**
- Consumes: `RewardSubmission`, `WalletService::credit()` (Task 3), `RewardSubmissionPolicy` (Task 4).
- Produces: `RewardSubmissionsTable::approveAction(): Action` and `::rejectAction(): Action` (both static, reused by `EditRewardSubmission`'s header actions) — Task 6 relies on these being publicly callable static methods with this exact signature. Approve/reject payloads always set `reviewed_by => auth()->id()`.

- [ ] **Step 1: Create the RewardSubmissionRejected mailable and view**

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

Save as `backend/app/Mail/RewardSubmissionRejected.php`.

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

Save as `backend/resources/views/emails/reward-submission-rejected.blade.php`. Mobile check: plain paragraphs, no fixed-width elements — verified, no change needed (same as `wallet-credited.blade.php` in Task 3).

- [ ] **Step 2: Create RewardSubmissionForm schema**

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

Save as `backend/app/Filament/Resources/RewardSubmissions/Schemas/RewardSubmissionForm.php`. (Mobile pass for this form's fields happens in Phase 3, Task 12 — Filament `Section` with `columns(2)` already collapses to 1 column under the panel's default responsive breakpoints; Task 12 verifies this at 375px rather than changing it blind here.)

- [ ] **Step 3: Create RewardSubmissionsTable with approve/reject actions**

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
use Illuminate\Support\Facades\DB;
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
                    ->searchable()
                    // Narrowest useful column on a phone-width table — the
                    // customer/status/amount columns carry the essential
                    // "what happened" story; order number is a lookup detail.
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('reward_amount')
                    ->label('Reward')
                    ->formatStateUsing(fn ($state) => filled($state) ? '₹'.number_format((float) $state, 2) : null),
                TextColumn::make('reviewer.name')
                    ->label('Reviewed By')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Submitted')
                    ->dateTime('d M Y, h:i A')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reviewed_at')
                    ->label('Reviewed')
                    ->dateTime('d M Y, h:i A')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
                DB::transaction(function () use ($data, $record) {
                    $updated = RewardSubmission::whereKey($record->id)
                        ->where('status', 'pending')
                        ->update([
                            'status' => 'approved',
                            'reward_amount' => $data['reward_amount'],
                            'reviewed_at' => now(),
                            'reviewed_by' => auth()->id(),
                        ]);

                    if ($updated === 0) {
                        // Already reviewed by a concurrent request — no-op, no error.
                        return;
                    }

                    app(WalletService::class)->credit(
                        $record->user,
                        (float) $data['reward_amount'],
                        'reward_approved',
                        $record->fresh(),
                    );
                });
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
                DB::transaction(function () use ($data, $record) {
                    $updated = RewardSubmission::whereKey($record->id)
                        ->where('status', 'pending')
                        ->update([
                            'status' => 'rejected',
                            'rejection_reason' => $data['rejection_reason'],
                            'reviewed_at' => now(),
                            'reviewed_by' => auth()->id(),
                        ]);

                    if ($updated === 0) {
                        // Already reviewed by a concurrent request — no-op, no error.
                        return;
                    }

                    Mail::to($record->user->email)->queue(new RewardSubmissionRejected($record->fresh()));
                });
            });
    }
}
```

Save as `backend/app/Filament/Resources/RewardSubmissions/Tables/RewardSubmissionsTable.php`. The `->where('status', 'pending')` conditional update is the entire concurrency guard: two simultaneous approve/reject calls both run this query, the database allows exactly one to affect a row, and the loser's `$updated === 0` branch exits with no side effect — this is what makes "two vendors approving the same video simultaneously" impossible without any additional locking primitive.

- [ ] **Step 4: Create the resource and its two pages**

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

Save as `backend/app/Filament/Resources/RewardSubmissions/RewardSubmissionResource.php`. `navigationGroup = 'Content'` matches the existing convention for customer-submitted-content-needing-moderation resources (`ReviewResource` uses the same group).

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

Save as `backend/app/Filament/Resources/RewardSubmissions/Pages/ListRewardSubmissions.php`.

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

Save as `backend/app/Filament/Resources/RewardSubmissions/Pages/EditRewardSubmission.php`.

- [ ] **Step 5: Run the policy test from Task 4 (now unblocked)**

Run: `cd backend && php artisan test --filter=VerifyRewardSubmissionPolicyTest`
Expected: PASS, 6/6.

- [ ] **Step 6: Write the approval flow test**

```php
<?php

namespace Tests\Feature;

use App\Filament\Resources\RewardSubmissions\Pages\EditRewardSubmission;
use App\Filament\Resources\RewardSubmissions\Pages\ListRewardSubmissions;
use App\Mail\RewardSubmissionRejected;
use App\Models\Order;
use App\Models\RewardSubmission;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\WalletService;
use Database\Seeders\ShieldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_approving_a_submission_records_who_reviewed_it(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $customer = User::factory()->create(['wallet_balance' => 0]);
        $order = $this->makeOrder($customer);
        $submission = RewardSubmission::create(['user_id' => $customer->id, 'order_id' => $order->id, 'status' => 'pending']);

        Livewire::test(EditRewardSubmission::class, ['record' => $submission->getKey()])
            ->callAction('approve', data: ['reward_amount' => 150]);

        $this->assertSame($admin->id, $submission->fresh()->reviewed_by);
    }

    public function test_rejecting_a_submission_records_who_reviewed_it(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $customer = User::factory()->create();
        $order = $this->makeOrder($customer);
        $submission = RewardSubmission::create(['user_id' => $customer->id, 'order_id' => $order->id, 'status' => 'pending']);

        Livewire::test(EditRewardSubmission::class, ['record' => $submission->getKey()])
            ->callAction('reject', data: ['rejection_reason' => 'Blurry video']);

        $this->assertSame($admin->id, $submission->fresh()->reviewed_by);
    }

    public function test_a_vendor_can_approve_a_submission(): void
    {
        $this->seed(ShieldSeeder::class);
        $vendor = User::factory()->create();
        $vendor->assignRole('vendor');
        $this->actingAs($vendor);

        $customer = User::factory()->create(['wallet_balance' => 0]);
        $order = $this->makeOrder($customer);
        $submission = RewardSubmission::create(['user_id' => $customer->id, 'order_id' => $order->id, 'status' => 'pending']);

        Livewire::test(EditRewardSubmission::class, ['record' => $submission->getKey()])
            ->callAction('approve', data: ['reward_amount' => 200]);

        $submission->refresh();
        $this->assertSame('approved', $submission->status);
        $this->assertSame($vendor->id, $submission->reviewed_by);
        $this->assertSame('200.00', $customer->fresh()->wallet_balance);
    }

    public function test_reviewer_name_appears_in_the_table_after_approval(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $customer = User::factory()->create(['wallet_balance' => 0, 'name' => 'Reviewer Visibility Test']);
        $order = $this->makeOrder($customer);
        $submission = RewardSubmission::create(['user_id' => $customer->id, 'order_id' => $order->id, 'status' => 'pending']);

        Livewire::test(EditRewardSubmission::class, ['record' => $submission->getKey()])
            ->callAction('approve', data: ['reward_amount' => 150]);

        Livewire::test(ListRewardSubmissions::class)
            ->assertSee($admin->name);
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

    public function test_concurrent_approve_calls_only_credit_the_wallet_once(): void
    {
        $customer = User::factory()->create(['wallet_balance' => 0]);
        $order = $this->makeOrder($customer);
        $submission = RewardSubmission::create(['user_id' => $customer->id, 'order_id' => $order->id, 'status' => 'pending']);

        $firstCopy = RewardSubmission::find($submission->getKey());
        $secondCopy = RewardSubmission::find($submission->getKey());

        $this->runApprove($firstCopy, 150);
        $this->runApprove($secondCopy, 150);

        $submission->refresh();
        $this->assertSame('approved', $submission->status);
        $this->assertSame('150.00', $submission->reward_amount);
        $this->assertSame('150.00', $customer->fresh()->wallet_balance);
        $this->assertSame(1, WalletTransaction::where('user_id', $customer->id)->count());
    }

    public function test_concurrent_reject_calls_only_queue_one_email(): void
    {
        Mail::fake();
        $customer = User::factory()->create();
        $order = $this->makeOrder($customer);
        $submission = RewardSubmission::create(['user_id' => $customer->id, 'order_id' => $order->id, 'status' => 'pending']);

        $firstCopy = RewardSubmission::find($submission->getKey());
        $secondCopy = RewardSubmission::find($submission->getKey());

        $this->runReject($firstCopy, 'Video unclear');
        $this->runReject($secondCopy, 'Different reason');

        $submission->refresh();
        $this->assertSame('rejected', $submission->status);
        $this->assertSame('Video unclear', $submission->rejection_reason);

        Mail::assertQueued(RewardSubmissionRejected::class, 1);
    }

    private function runApprove(RewardSubmission $record, float $rewardAmount): void
    {
        DB::transaction(function () use ($record, $rewardAmount) {
            $updated = RewardSubmission::whereKey($record->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'approved',
                    'reward_amount' => $rewardAmount,
                    'reviewed_at' => now(),
                ]);

            if ($updated === 0) {
                return;
            }

            app(WalletService::class)->credit(
                $record->user,
                $rewardAmount,
                'reward_approved',
                $record->fresh(),
            );
        });
    }

    private function runReject(RewardSubmission $record, string $reason): void
    {
        DB::transaction(function () use ($record, $reason) {
            $updated = RewardSubmission::whereKey($record->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'rejected',
                    'rejection_reason' => $reason,
                    'reviewed_at' => now(),
                ]);

            if ($updated === 0) {
                return;
            }

            Mail::to($record->user->email)->queue(new RewardSubmissionRejected($record->fresh()));
        });
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

Save as `backend/tests/Feature/VerifyRewardSubmissionApprovalTest.php`.

- [ ] **Step 7: Run tests**

Run: `cd backend && php artisan test --filter=VerifyRewardSubmissionApprovalTest`
Expected: PASS, 9/9.

- [ ] **Step 8: Commit**

```bash
cd /Users/ayushman/Desktop/estele-jewellery
git add backend/app/Filament/Resources/RewardSubmissions backend/app/Mail/RewardSubmissionRejected.php backend/resources/views/emails/reward-submission-rejected.blade.php backend/tests/Feature/VerifyRewardSubmissionApprovalTest.php
git commit -m "Add Filament RewardSubmission resource with approve/reject actions"
```

---

### Task 6: Customer submission controller, routes, notification-on-new-submission

**Files:**
- Create: `backend/app/Http/Controllers/RewardSubmissionController.php`
- Create: `backend/app/Mail/NewRewardSubmissionNotification.php`
- Create: `backend/resources/views/emails/new-reward-submission.blade.php`
- Modify: `backend/routes/web.php`
- Test: `backend/tests/Feature/VerifyRewardSubmissionUploadTest.php`

**Interfaces:**
- Consumes: `RewardSubmission`, `Order` (`status === 'delivered'`), `User::role(['vendor', 'super_admin'])` (Spatie), `NewRewardSubmissionNotification` mailable.
- Produces: `GET account.rewards.index`, `POST account.rewards.store` (throttled 10/60min), used by the customer dashboard view built in Task 9.

- [ ] **Step 1: Create the NewRewardSubmissionNotification mailable and view**

```php
<?php

namespace App\Mail;

use App\Models\RewardSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NewRewardSubmissionNotification extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public RewardSubmission $submission)
    {
    }

    public function build(): self
    {
        return $this
            ->subject('New reward submission awaiting review')
            ->view('emails.new-reward-submission');
    }
}
```

Save as `backend/app/Mail/NewRewardSubmissionNotification.php`.

```blade
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>New reward submission</title>
</head>
<body style="font-family: Helvetica, Arial, sans-serif; font-size: 14px; color: #222;">
    <p>A new reward submission is waiting for review.</p>

    <p>
        <strong>Customer:</strong> {{ $submission->user->name }}<br>
        <strong>Order:</strong> {{ $submission->order->order_number }}<br>
        <strong>Submitted:</strong> {{ $submission->created_at->format('d M Y, h:i A') }}
    </p>

    <p>Review it in the admin panel under Reward Submissions.</p>
</body>
</html>
```

Save as `backend/resources/views/emails/new-reward-submission.blade.php`. Mobile check: verified, no change needed (same reasoning as the other two email templates).

- [ ] **Step 2: Create RewardSubmissionController**

```php
<?php

namespace App\Http\Controllers;

use App\Mail\NewRewardSubmissionNotification;
use App\Models\Order;
use App\Models\RewardSubmission;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

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

        // The exists() check above is a fast-path UX check, not the real
        // guard: a concurrent request for the same order can pass it before
        // either insert lands. `reward_submissions.order_id` has a DB-level
        // unique constraint that is the real backstop — this catch converts
        // that race's QueryException into the same clean 422 the pre-check
        // gives, instead of an uncaught 500.
        try {
            $submission = RewardSubmission::create([
                'user_id' => $user->id,
                'order_id' => $order->id,
                'status' => 'pending',
            ]);
        } catch (QueryException $e) {
            $isUniqueViolation = str_contains($e->getMessage(), 'order_id')
                && (str_contains($e->getMessage(), 'UNIQUE constraint failed')
                    || str_contains($e->getMessage(), 'Duplicate entry')
                    || str_contains($e->getMessage(), 'unique constraint'));

            abort_if($isUniqueViolation, 422, 'A submission already exists for this order.');

            throw $e;
        }

        $submission->addMedia($request->file('image'))->toMediaCollection('image');
        $submission->addMedia($request->file('video'))->toMediaCollection('video');

        // Sent after the media/DB writes commit, same "outside the write
        // path" placement WalletService::credit() uses for its own
        // notification — a mail failure here must never roll back or block
        // the submission itself.
        $reviewers = User::role(['vendor', 'super_admin'])->whereNotNull('email')->get();
        foreach ($reviewers as $reviewer) {
            Mail::to($reviewer->email)->queue(new NewRewardSubmissionNotification($submission->fresh(['user', 'order'])));
        }

        return redirect()->route('account.rewards.index')
            ->with('success', 'Thanks! Your submission is pending review.');
    }
}
```

Save as `backend/app/Http/Controllers/RewardSubmissionController.php`.

- [ ] **Step 3: Add routes**

Read `backend/routes/web.php` and find the existing `Route::delete('/account/addresses/{address}', ...)` line inside the authenticated account route group. Add `use App\Http\Controllers\RewardSubmissionController;` to the top-of-file `use` block (alphabetically among the other controller imports), and add these two routes directly after the addresses-destroy route, inside the same auth-protected group:

```php
    Route::get('/account/rewards', [RewardSubmissionController::class, 'index'])
        ->name('account.rewards.index');

    Route::post('/account/rewards', [RewardSubmissionController::class, 'store'])
        ->name('account.rewards.store')
        ->middleware('throttle:10,60');
```

Do not port the `Route::get('/admin', [AdminController::class, 'dashboard'])` line seen in the source branch's diff — `AdminController` does not exist there either; it's dead code from an unrelated experiment and must not be copied.

- [ ] **Step 4: Write the upload/notification test**

```php
<?php

namespace Tests\Feature;

use App\Mail\NewRewardSubmissionNotification;
use App\Models\Order;
use App\Models\RewardSubmission;
use App\Models\User;
use Database\Seeders\ShieldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Server-side validation is the real security boundary here — every test in
 * this file drives the actual HTTP endpoint, not the model directly.
 */
class VerifyRewardSubmissionUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ShieldSeeder::class);
    }

    public function test_customer_can_submit_proof_for_their_own_delivered_order(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $order = $this->makeOrder($user, 'delivered');

        $this->actingAs($user)
            ->post(route('account.rewards.store'), [
                'order_id' => $order->id,
                'image' => UploadedFile::fake()->image('proof.png')->size(2000),
                'video' => $this->fakeMp4('proof.mp4'),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('reward_submissions', [
            'user_id' => $user->id,
            'order_id' => $order->id,
            'status' => 'pending',
        ]);
    }

    public function test_submitting_proof_notifies_every_vendor_and_super_admin(): void
    {
        Queue::fake();
        Mail::fake();

        $vendor = User::factory()->create();
        $vendor->assignRole('vendor');
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $marketer = User::factory()->create();
        $marketer->assignRole('marketing');

        $user = User::factory()->create();
        $order = $this->makeOrder($user, 'delivered');

        $this->actingAs($user)
            ->post(route('account.rewards.store'), [
                'order_id' => $order->id,
                'image' => UploadedFile::fake()->image('proof.png')->size(2000),
                'video' => $this->fakeMp4('proof.mp4'),
            ])
            ->assertRedirect();

        Mail::assertQueued(NewRewardSubmissionNotification::class, fn ($mail) => $mail->hasTo($vendor->email));
        Mail::assertQueued(NewRewardSubmissionNotification::class, fn ($mail) => $mail->hasTo($admin->email));
        Mail::assertQueued(NewRewardSubmissionNotification::class, 2);
    }

    public function test_image_over_3mb_is_rejected(): void
    {
        $user = User::factory()->create();
        $order = $this->makeOrder($user, 'delivered');

        $this->actingAs($user)
            ->post(route('account.rewards.store'), [
                'order_id' => $order->id,
                'image' => UploadedFile::fake()->image('proof.png')->size(3200),
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
                'image' => UploadedFile::fake()->image('proof.png')->size(2000),
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
                'image' => UploadedFile::fake()->image('proof.png')->size(2000),
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
                'image' => UploadedFile::fake()->image('proof.png')->size(2000),
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
                'image' => UploadedFile::fake()->image('proof.png')->size(2000),
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
                'image' => UploadedFile::fake()->image('proof.png')->size(2000),
                'video' => UploadedFile::fake()->create('proof.mp4', 5000, 'video/mp4'),
            ])
            ->assertStatus(422);
    }

    public function test_concurrent_duplicate_submission_race_is_rejected_with_422_not_500(): void
    {
        $user = User::factory()->create();
        $order = $this->makeOrder($user, 'delivered');

        $alreadyRaced = false;

        RewardSubmission::creating(function (RewardSubmission $submission) use ($order, &$alreadyRaced) {
            if ($alreadyRaced) {
                return;
            }
            $alreadyRaced = true;

            RewardSubmission::withoutEvents(function () use ($order) {
                RewardSubmission::create([
                    'user_id' => $order->user_id,
                    'order_id' => $order->id,
                    'status' => 'pending',
                ]);
            });
        });

        $response = $this->actingAs($user)
            ->post(route('account.rewards.store'), [
                'order_id' => $order->id,
                'image' => UploadedFile::fake()->image('proof.png')->size(2000),
                'video' => UploadedFile::fake()->create('proof.mp4', 5000, 'video/mp4'),
            ]);

        $response->assertStatus(422);
        $this->assertSame(1, RewardSubmission::where('order_id', $order->id)->count());
    }

    public function test_rewards_page_shows_wallet_balance_and_eligible_orders(): void
    {
        $user = User::factory()->create(['wallet_balance' => 250]);
        $order = $this->makeOrder($user, 'delivered');

        $response = $this->actingAs($user)->get(route('account.rewards.index'));

        $response->assertOk()
            ->assertSee('₹250.00')
            ->assertSee($order->order_number);
    }

    public function test_rewards_page_upload_form_includes_preview_elements(): void
    {
        $user = User::factory()->create();
        $order = $this->makeOrder($user, 'delivered');

        $response = $this->actingAs($user)->get(route('account.rewards.index'));

        $response->assertOk()
            ->assertSee('reward-image-preview', false)
            ->assertSee('reward-video-preview', false);
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

    private function fakeMp4(string $name, int $kilobytes = 5): UploadedFile
    {
        $ftypBox = hex2bin('0000001c6674797069736f6d0000020069736f6d69736f326d703431');
        $content = str_pad($ftypBox, $kilobytes * 1024, "\0");

        return UploadedFile::fake()->createWithContent($name, $content);
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

Save as `backend/tests/Feature/VerifyRewardSubmissionUploadTest.php`. This test will fail until Task 9 (`account/rewards.blade.php`) exists — the two `test_rewards_page_*` cases and the `assertRedirect()`/`assertOk()` calls that render the view depend on that Blade file. Run only the non-view-rendering tests now; re-run the full file after Task 9.

- [ ] **Step 5: Run the tests that don't depend on the view yet**

Run: `cd backend && php artisan test --filter=VerifyRewardSubmissionUploadTest --filter="test_image_over_3mb_is_rejected|test_video_over_10mb_is_rejected|test_non_video_mime_is_rejected_for_video_field|test_non_delivered_order_is_rejected|test_a_user_cannot_submit_for_another_users_order|test_cannot_submit_twice_for_the_same_order|test_concurrent_duplicate_submission_race_is_rejected_with_422_not_500"`
Expected: PASS (these all `assertStatus`/`assertSessionHasErrors`/`assertNotFound` without ever reaching a view render).

- [ ] **Step 6: Commit**

```bash
cd /Users/ayushman/Desktop/estele-jewellery
git add backend/app/Http/Controllers/RewardSubmissionController.php backend/app/Mail/NewRewardSubmissionNotification.php backend/resources/views/emails/new-reward-submission.blade.php backend/routes/web.php backend/tests/Feature/VerifyRewardSubmissionUploadTest.php
git commit -m "Add customer reward-submission upload endpoint with server-side validation"
```

---

### Task 7: Checkout wallet debit (merged into existing CheckoutController)

**Files:**
- Modify: `backend/app/Http/Controllers/CheckoutController.php`
- Modify: `backend/resources/views/checkout/index.blade.php`
- Modify: `backend/resources/views/orders/invoice.blade.php`
- Test: `backend/tests/Feature/VerifyCheckoutWalletUsageTest.php` (port verbatim — see Step 5)

**Interfaces:**
- Consumes: `WalletService::debit()` (Task 3), `Order::wallet_amount_used` (Task 2).
- Produces: checkout accepts an optional `wallet_amount` field; on success, `Order::wallet_amount_used` reflects what was actually debited (clamped server-side). Used by the mobile-audit pass in Task 12 (checkout wallet input is one of the audited pieces per the "vendor wallet/status" clarification in the spec — this is the *customer* wallet-at-checkout surface, already covered).

- [ ] **Step 1: Read current CheckoutController.php in full and locate the constructor + store() method**

The current file injects `ShippingManager` and `PaymentManager` via constructor. Add a third dependency:

```php
    public function __construct(
        private readonly ShippingManager $shipping,
        private readonly PaymentManager $payments,
        private readonly \App\Services\WalletService $wallet,
    ) {}
```

Add `use App\Services\WalletService;` to the top-of-file imports instead of the inline FQCN if preferred — either is fine, match whatever style the rest of the file's imports use (the file currently uses `use` imports for everything else, so prefer `use App\Services\WalletService;` at the top and just `WalletService $wallet` in the constructor).

- [ ] **Step 2: Add wallet_amount to the store() validation array**

In `store()`'s `$request->validate([...])` call, add this line among the existing rules (order doesn't matter, but keep it near `payment_method` for readability):

```php
            'wallet_amount' => ['nullable', 'numeric', 'min:0'],
```

- [ ] **Step 3: Add the wallet debit inside the DB::transaction(), after Order::create()**

Locate the `$order = Order::create([...]);` call inside the `DB::transaction(function () use (...) { ... }, 3);` closure in `store()`. Immediately after the `foreach ($items as $item) { ... }` loop and the coupon-usage block (i.e., right before `$cart->items()->delete();`), insert:

```php
                $walletAmountUsed = 0.0;
                if (auth()->check() && (float) ($validated['wallet_amount'] ?? 0) > 0) {
                    $walletAmountUsed = min(
                        (float) $validated['wallet_amount'],
                        (float) $order->total,
                        (float) auth()->user()->wallet_balance,
                    );

                    // Razorpay always charges $order->total in full — PaymentManager
                    // doesn't know about wallet_amount_used. Applying a PARTIAL wallet
                    // debit here while still routing the remainder through Razorpay
                    // would charge the customer twice for that portion. So for razorpay
                    // orders, only ever apply the wallet when it fully covers the total
                    // (order is marked paid below and Razorpay is skipped entirely).
                    // Otherwise leave the wallet untouched and let the full amount go
                    // through the gateway as normal. COD has no such risk (settled at
                    // delivery), so partial wallet use is always allowed there.
                    if ($validated['payment_method'] === 'razorpay' && $walletAmountUsed < (float) $order->total) {
                        $walletAmountUsed = 0.0;
                    }

                    if ($walletAmountUsed > 0) {
                        // WalletService::debit() throws \DomainException on insufficient
                        // balance. Should never actually happen here — $walletAmountUsed
                        // is already clamped to the live wallet_balance above — but if it
                        // somehow does, it must surface as a field error on the checkout
                        // form rather than the generic cart-index redirect the stock-check
                        // \DomainExceptions below use. Re-thrown as \RuntimeException so
                        // the outer catch can tell the two apart.
                        try {
                            $this->wallet->debit(auth()->user(), $walletAmountUsed, 'order_payment', $order);
                        } catch (\DomainException $e) {
                            throw new \RuntimeException($e->getMessage(), previous: $e);
                        }

                        $order->update([
                            'wallet_amount_used' => $walletAmountUsed,
                            'payment_status' => $walletAmountUsed >= (float) $order->total ? 'paid' : $order->payment_status,
                        ]);
                    }
                }

```

- [ ] **Step 4: Catch the \RuntimeException from Step 3 around the transaction call**

Locate the `try { $order = DB::transaction(...); } catch (\DomainException $e) { ... }` block that wraps the transaction in `store()`. Add a `\RuntimeException` catch **before** the existing `\DomainException` catch (order matters — `\RuntimeException` does not extend `\DomainException`, so either order technically works, but placing it first keeps the wallet-specific redirect visually paired with the code that throws it):

```php
        } catch (\RuntimeException $e) {
            return redirect()->route('checkout.index')->withInput()
                ->withErrors(['wallet_amount' => $e->getMessage()]);
        } catch (\DomainException $e) {
            return redirect()->route('cart.index')->with('error', $e->getMessage());
        }
```

- [ ] **Step 5: Add the wallet input to checkout/index.blade.php**

Read `backend/resources/views/checkout/index.blade.php` and find the gap between the `order_note` textarea's closing `</div>` and the `<button ... type="submit">Place Order</button>` line. Insert:

```blade
        @auth
          @if((float) auth()->user()->wallet_balance > 0)
            <div class="mb-4 rounded-lg border border-line p-4">
              <label class="mb-1.5 block text-[13px] font-medium text-heading" for="wallet_amount">
                Use wallet balance (available: ₹{{ number_format((float) auth()->user()->wallet_balance, 2) }})
              </label>
              <input class="w-full border border-line-strong bg-white px-4 py-2.5 text-[13px]" id="wallet_amount" name="wallet_amount" type="number" min="0" step="0.01" max="{{ auth()->user()->wallet_balance }}" placeholder="0.00" value="{{ old('wallet_amount') }}">
              @error('wallet_amount') <p class="mt-1 text-[12px] text-salebadge">{{ $message }}</p> @enderror
            </div>
          @endif
        @endauth

```

Guest checkout (`@auth` false) gets no wallet option at all, per spec.

- [ ] **Step 6: Add the Wallet Used line to the invoice**

Read `backend/resources/views/orders/invoice.blade.php` and find the `<tr>` for the `Shipping` row (label/value pair). Insert immediately after it, before the `refunded_amount` conditional row if one exists:

```blade
        @if($order->wallet_amount_used > 0)
            <tr>
                <td class="label">Wallet Used</td>
                <td class="text-right">&minus; Rs. {{ number_format($order->wallet_amount_used, 2) }}</td>
            </tr>
        @endif
```

- [ ] **Step 7: Update EditOrder refund action and OrderForm to account for wallet_amount_used**

Read `backend/app/Filament/Resources/Orders/Pages/EditOrder.php` and find the refund action's closure where `$maxRefundable = (float) $record->total - $alreadyRefunded;` is computed. Change it to:

```php
                    $maxRefundable = (float) $record->total - (float) $record->wallet_amount_used - $alreadyRefunded;
```

This prevents double-refunding when the auto-refund hook (Task 3) already credited the wallet portion back before an admin manually refunds the remaining gateway-paid portion.

Read `backend/app/Filament/Resources/Orders/Schemas/OrderForm.php` and find the `TextInput::make('total')->numeric()->prefix('₹')->disabled(),` line. Add immediately after it:

```php
                        TextInput::make('wallet_amount_used')
                            ->label('Wallet Used')
                            ->numeric()
                            ->prefix('₹')
                            ->disabled()
                            ->visible(fn (?Order $record) => $record && (float) $record->wallet_amount_used > 0),
```

- [ ] **Step 8: Port the checkout wallet usage test verbatim**

Copy from the worktree:

```bash
cp /Users/ayushman/Desktop/estele-jewellery/.worktrees/reward-submissions-wallet/backend/tests/Feature/VerifyCheckoutWalletUsageTest.php /Users/ayushman/Desktop/estele-jewellery/backend/tests/Feature/VerifyCheckoutWalletUsageTest.php
```

Open the copied file. `CheckoutController` is never constructed manually in tests on this codebase — `ShippingManager`/`PaymentManager` are driven through the real container with faked config/HTTP, not DI mocks (see `backend/tests/Feature/VerifyRazorpayCheckoutFlowTest.php` for the exact pattern: `config(['services.razorpay.key_id' => 'rzp_test_fake', ...])`, `Setting::updateOrCreate(['key' => 'payment_provider'], ['value' => 'razorpay'])` for the razorpay path, and `Http::fake(['*/v1/orders' => Http::response([...], 200)])` for the outbound Razorpay call — no `Http::fake` entry is needed for the shipping quote itself, since `ShippingManager::quote()` already returns a flat/free rate with no outbound HTTP call when no Shiprocket config is set). If the ported test issues a real HTTP call to Razorpair or Shiprocket that isn't faked, add the matching `Http::fake` entry using this same pattern rather than mocking the service class. Do not weaken any wallet-specific assertion to route around a construction issue — there should be none, since nothing in this test needs to bypass the controller's real dependencies.

- [ ] **Step 9: Run tests**

Run: `cd backend && php artisan test --filter=VerifyCheckoutWalletUsageTest`
Expected: PASS. If failures are due to `ShippingManager`/`PaymentManager` construction (divergence from the source branch), fix the test's setup to bind fakes/mocks for those services the same way any other existing checkout test in this codebase does — do not weaken the wallet-specific assertions to work around it.

Also re-run the full checkout-adjacent suite to catch regressions from the merge:

Run: `cd backend && php artisan test --filter=Checkout`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
cd /Users/ayushman/Desktop/estele-jewellery
git add backend/app/Http/Controllers/CheckoutController.php backend/resources/views/checkout/index.blade.php backend/resources/views/orders/invoice.blade.php backend/app/Filament/Resources/Orders/Pages/EditOrder.php backend/app/Filament/Resources/Orders/Schemas/OrderForm.php backend/tests/Feature/VerifyCheckoutWalletUsageTest.php
git commit -m "Deduct wallet balance at checkout, skip gateway when fully wallet-paid"
```

---

### Task 8: Admin Wallet Management resource

**Files:**
- Create: `backend/app/Filament/Resources/WalletManagement/WalletManagementResource.php`
- Create: `backend/app/Filament/Resources/WalletManagement/Tables/WalletManagementTable.php`
- Create: `backend/app/Filament/Resources/WalletManagement/Pages/ListWalletManagement.php`
- Create: `backend/resources/views/filament/wallet-management/history.blade.php`
- Test: `backend/tests/Feature/VerifyWalletManagementResourceTest.php` (port verbatim — see Step 4)

**Interfaces:**
- Consumes: `WalletService` (Task 3), `User::walletTransactions()` (Task 2).
- Produces: a super-admin-visible resource listing every customer with a wallet balance, with Credit/Debit/History row actions. This is not gated to `vendor` — `WalletManagementResource` has no custom `canAccess()`, so it inherits Shield's default (super_admin only, since vendor's `ShieldSeeder` permissions don't include any Wallet-related permission string).

- [ ] **Step 1: Create the resource**

```php
<?php

namespace App\Filament\Resources\WalletManagement;

use App\Filament\Resources\WalletManagement\Pages\ListWalletManagement;
use App\Filament\Resources\WalletManagement\Tables\WalletManagementTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WalletManagementResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    protected static ?string $navigationLabel = 'Wallet Management';

    protected static ?string $modelLabel = 'Customer Wallet';

    protected static ?string $pluralModelLabel = 'Customer Wallets';

    protected static string|\UnitEnum|null $navigationGroup = 'Wallet';

    public static function table(Table $table): Table
    {
        return WalletManagementTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWalletManagement::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
```

Save as `backend/app/Filament/Resources/WalletManagement/WalletManagementResource.php`.

- [ ] **Step 2: Create the table with history/credit/debit actions**

```php
<?php

namespace App\Filament\Resources\WalletManagement\Tables;

use App\Models\User;
use App\Services\WalletService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WalletManagementTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->query(fn () => User::query()->whereDoesntHave('roles'))
            ->defaultSort('wallet_balance', 'desc')
            ->searchable()
            ->columns([
                TextColumn::make('name')
                    ->label('Customer')
                    ->searchable(),
                TextColumn::make('email')
                    ->searchable(),
                TextColumn::make('wallet_balance')
                    ->label('Balance')
                    ->formatStateUsing(fn ($state) => '₹'.number_format((float) $state, 2))
                    ->sortable(),
                TextColumn::make('total_credit')
                    ->label('Total Credit')
                    ->state(fn (User $record) => $record->walletTransactions()->where('type', 'credit')->sum('amount'))
                    ->formatStateUsing(fn ($state) => '₹'.number_format((float) $state, 2)),
                TextColumn::make('total_debit')
                    ->label('Total Debit')
                    ->state(fn (User $record) => $record->walletTransactions()->where('type', 'debit')->sum('amount'))
                    ->formatStateUsing(fn ($state) => '₹'.number_format((float) $state, 2)),
            ])
            ->recordActions([
                self::historyAction(),
                self::creditAction(),
                self::debitAction(),
            ]);
    }

    public static function historyAction(): Action
    {
        return Action::make('history')
            ->label('History')
            ->icon(Heroicon::OutlinedClock)
            ->color('gray')
            ->modalHeading(fn (User $record) => "Wallet history — {$record->name}")
            ->modalContent(fn (User $record) => view('filament.wallet-management.history', [
                'transactions' => $record->walletTransactions()->latest()->limit(50)->get(),
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    public static function creditAction(): Action
    {
        return Action::make('credit')
            ->label('Credit')
            ->icon(Heroicon::OutlinedPlusCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(fn (User $record) => "Credit wallet — {$record->name}")
            ->schema([
                TextInput::make('amount')
                    ->label('Amount (₹)')
                    ->numeric()
                    ->required()
                    ->minValue(0.01),
                Textarea::make('reason')
                    ->label('Reason')
                    ->required(),
            ])
            ->action(function (array $data, User $record) {
                app(WalletService::class)->credit(
                    $record,
                    (float) $data['amount'],
                    'admin_credit: '.$data['reason'],
                );

                Notification::make()
                    ->title('Wallet credited.')
                    ->success()
                    ->send();
            });
    }

    public static function debitAction(): Action
    {
        return Action::make('debit')
            ->label('Debit')
            ->icon(Heroicon::OutlinedMinusCircle)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (User $record) => "Debit wallet — {$record->name}")
            ->schema([
                TextInput::make('amount')
                    ->label('Amount (₹)')
                    ->numeric()
                    ->required()
                    ->minValue(0.01),
                Textarea::make('reason')
                    ->label('Reason')
                    ->required(),
            ])
            ->action(function (array $data, User $record) {
                try {
                    app(WalletService::class)->debit(
                        $record,
                        (float) $data['amount'],
                        'admin_debit: '.$data['reason'],
                    );
                } catch (\DomainException $e) {
                    Notification::make()
                        ->title($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Wallet debited.')
                    ->success()
                    ->send();
            });
    }
}
```

Save as `backend/app/Filament/Resources/WalletManagement/Tables/WalletManagementTable.php`.

- [ ] **Step 3: Create the list page and history modal view**

```php
<?php

namespace App\Filament\Resources\WalletManagement\Pages;

use App\Filament\Resources\WalletManagement\WalletManagementResource;
use Filament\Resources\Pages\ListRecords;

class ListWalletManagement extends ListRecords
{
    protected static string $resource = WalletManagementResource::class;
}
```

Save as `backend/app/Filament/Resources/WalletManagement/Pages/ListWalletManagement.php`.

```blade
<div class="fi-ta-ctn overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
    @if($transactions->isEmpty())
        <p class="p-4 text-sm text-gray-500 dark:text-gray-400">No wallet activity yet.</p>
    @else
        <table class="fi-ta-table w-full text-left text-sm">
            <thead class="bg-gray-50 dark:bg-white/5">
                <tr>
                    <th class="px-4 py-2 font-medium text-gray-600 dark:text-gray-300">Date</th>
                    <th class="px-4 py-2 font-medium text-gray-600 dark:text-gray-300">Type</th>
                    <th class="px-4 py-2 font-medium text-gray-600 dark:text-gray-300">Reason</th>
                    <th class="px-4 py-2 text-right font-medium text-gray-600 dark:text-gray-300">Amount</th>
                    <th class="px-4 py-2 text-right font-medium text-gray-600 dark:text-gray-300">Balance after</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach($transactions as $transaction)
                    <tr>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $transaction->created_at->format('d M Y, h:i A') }}</td>
                        <td class="px-4 py-2">
                            <span class="{{ $transaction->type === 'credit' ? 'text-success-600' : 'text-danger-600' }}">
                                {{ ucfirst($transaction->type) }}
                            </span>
                        </td>
                        <td class="px-4 py-2">{{ str_replace('_', ' ', ucfirst($transaction->reason)) }}</td>
                        <td class="px-4 py-2 text-right whitespace-nowrap {{ $transaction->type === 'credit' ? 'text-success-600' : 'text-danger-600' }}">
                            {{ $transaction->type === 'credit' ? '+' : '-' }}₹{{ number_format((float) $transaction->amount, 2) }}
                        </td>
                        <td class="px-4 py-2 text-right whitespace-nowrap">₹{{ number_format((float) $transaction->balance_after, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
```

Save as `backend/resources/views/filament/wallet-management/history.blade.php`. This table is inside a Filament modal, which is already `overflow-x-auto`-wrapped — mobile check happens in Task 12 alongside the other admin tables.

- [ ] **Step 4: Port the WalletManagementResource test verbatim**

```bash
cp /Users/ayushman/Desktop/estele-jewellery/.worktrees/reward-submissions-wallet/backend/tests/Feature/VerifyWalletManagementResourceTest.php /Users/ayushman/Desktop/estele-jewellery/backend/tests/Feature/VerifyWalletManagementResourceTest.php
```

- [ ] **Step 5: Run tests**

Run: `cd backend && php artisan test --filter=VerifyWalletManagementResourceTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
cd /Users/ayushman/Desktop/estele-jewellery
git add backend/app/Filament/Resources/WalletManagement backend/resources/views/filament/wallet-management/history.blade.php backend/tests/Feature/VerifyWalletManagementResourceTest.php
git commit -m "Add admin Wallet Management resource and wallet-credited notifications"
```

---

### Task 9: Customer Rewards & Wallet dashboard (mobile-first from the start)

**Files:**
- Create: `backend/resources/views/account/rewards.blade.php`
- Modify: `backend/resources/views/account/index.blade.php`

**Interfaces:**
- Consumes: `RewardSubmissionController::index()`'s view data (`eligibleOrders`, `submissions`, `walletBalance`, `walletTransactions`) from Task 6.
- Produces: the page every remaining customer-facing acceptance criterion in the spec (status visibility, upload+preview, wallet balance) points at. Completes the two `test_rewards_page_*` cases left pending from Task 6.

- [ ] **Step 1: Add the nav link from account/index.blade.php**

Read `backend/resources/views/account/index.blade.php`. In the desktop `<nav aria-label="Account">` list (the `<ul class="space-y-1 text-[13px]">` block), add a new `<li>` between "Order History" and "Addresses":

```blade
          <li><a class="block rounded-md px-3 py-2.5 text-heading transition-colors hover:bg-pinksoft" href="{{ route('account.rewards.index') }}">Rewards & Wallet</a></li>
```

Also add a mobile-only shortcut link next to the existing `My Addresses` mobile link in the Order History section header (`<a class="text-[12px] font-medium text-heading underline hover:text-accent md:hidden" href="{{ route('account.addresses') }}">My Addresses</a>`) — add a second one right after it:

```blade
          <a class="text-[12px] font-medium text-heading underline hover:text-accent md:hidden" href="{{ route('account.rewards.index') }}">Rewards & Wallet</a>
```

- [ ] **Step 2: Build the rewards.blade.php page**

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

    {{-- Wallet balance stat card — full-width on mobile, so it's the first
         thing a customer sees on a phone before scrolling to submissions. --}}
    <section class="mb-8 rounded-lg border border-line p-4 sm:p-6">
      <h2 class="mb-2 text-[14px] font-medium uppercase tracking-[0.4px]">Wallet Balance</h2>
      <p class="text-[28px] font-medium text-price sm:text-[32px]">₹{{ number_format((float) $walletBalance, 2) }}</p>
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
            <img class="reward-image-preview mb-3 hidden h-32 w-32 max-w-full rounded-lg border border-line object-cover sm:h-40 sm:w-40" alt="Selected image preview">

            <label class="mb-1.5 block text-[13px] font-medium text-heading" for="video-{{ $order->id }}">Video (max 10MB)</label>
            <input class="mb-1 w-full border border-line-strong bg-white px-4 py-2.5 text-[13px]" id="video-{{ $order->id }}" name="video" type="file" accept="video/mp4,video/webm" required>
            <p class="reward-file-error mb-3 hidden text-[12px] text-salebadge" data-for="video-{{ $order->id }}"></p>
            <video class="reward-video-preview mb-3 hidden w-full max-w-full rounded-lg border border-line sm:max-w-sm" controls playsinline></video>

            <button class="inline-flex w-full items-center justify-center gap-2 border border-accent bg-accent px-6 py-2.5 text-[12px] font-medium uppercase tracking-[0.5px] text-white transition-colors hover:border-accent-dark hover:bg-accent-dark sm:w-auto" type="submit">
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
                <span @class([
                  'inline-block rounded-full px-3 py-1 text-[11px] font-medium uppercase tracking-[0.3px]',
                  'bg-pinksoft text-accent' => $submission->status === 'pending',
                  'bg-green-100 text-green-700' => $submission->status === 'approved',
                  'bg-red-100 text-salebadge' => $submission->status === 'rejected',
                ])>{{ ucfirst($submission->status) }}</span>
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
          <table class="w-full min-w-[480px] text-left text-[13px]">
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

      var imageInput = form.querySelector('input[name="image"]');
      var videoInput = form.querySelector('input[name="video"]');
      var imagePreview = form.querySelector('.reward-image-preview');
      var videoPreview = form.querySelector('.reward-video-preview');

      imageInput.addEventListener('change', function () {
        if (imageInput.files[0]) {
          imagePreview.src = URL.createObjectURL(imageInput.files[0]);
          imagePreview.classList.remove('hidden');
        } else {
          imagePreview.classList.add('hidden');
        }
      });

      videoInput.addEventListener('change', function () {
        if (videoInput.files[0]) {
          videoPreview.src = URL.createObjectURL(videoInput.files[0]);
          videoPreview.classList.remove('hidden');
        } else {
          videoPreview.classList.add('hidden');
        }
      });

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

Save as `backend/resources/views/account/rewards.blade.php`. Differences from the source branch's version (mobile-first pass, per spec): wallet balance card is larger and full-width with `sm:p-6`/`sm:text-[32px]` scaling; both preview elements gained `max-w-full` so they can never force horizontal scroll on a narrow viewport; the submit button is `w-full` on mobile and `sm:w-auto` on larger screens (matching the full-width-button convention already used in `account/index.blade.php`'s Profile/Password forms); the transaction table got `min-w-[480px]` inside its `overflow-x-auto` wrapper so it scrolls cleanly as a unit on mobile instead of squeezing columns unreadably; status badges now render success/danger colors (green/red) instead of reusing the pink "pending" badge color for every status, since the old version's `bg-pinksoft`/`text-accent` badge was applied unconditionally to every status including approved/rejected.

- [ ] **Step 3: Verify the `@class` directive's boolean-key array syntax matches this Laravel version**

Run: `cd backend && php artisan tinker --execute="echo app()->version();"` — confirm Laravel 13.x supports `@class` (it has since Laravel 9). No action needed if version checks out; this step is just a sanity check before running tests.

- [ ] **Step 4: Run the full upload test file (now that the view exists)**

Run: `cd backend && php artisan test --filter=VerifyRewardSubmissionUploadTest`
Expected: PASS, 12/12 (all tests from Task 6, including the two `test_rewards_page_*` ones that were pending).

- [ ] **Step 5: Commit**

```bash
cd /Users/ayushman/Desktop/estele-jewellery
git add backend/resources/views/account/rewards.blade.php backend/resources/views/account/index.blade.php
git commit -m "Add customer Rewards & Wallet account page, mobile-first"
```

---

### Task 10: End-to-end wallet test + full regression run

**Files:**
- Test: `backend/tests/Feature/VerifyRewardWalletEndToEndTest.php` (port verbatim — see Step 1)
- Test: `backend/tests/Feature/VerifyOrderStatusPipelineTest.php` (already exists on `ayush-feat` — regression check only, no edit expected)

**Interfaces:**
- Consumes: everything from Tasks 1–9.
- Produces: nothing new — this is the whole-feature integration proof (reward approval → wallet credit → checkout debit → cancel refund → ledger consistency in one pass) plus a full-suite regression gate before moving to Phase 2 (mobile UI audit).

- [ ] **Step 1: Port the end-to-end test verbatim**

```bash
cp /Users/ayushman/Desktop/estele-jewellery/.worktrees/reward-submissions-wallet/backend/tests/Feature/VerifyRewardWalletEndToEndTest.php /Users/ayushman/Desktop/estele-jewellery/backend/tests/Feature/VerifyRewardWalletEndToEndTest.php
```

Open the copied file and check it against the same real-container-with-faked-config pattern described in Task 7 Step 8 if it drives checkout — never weaken assertions to route around it.

- [ ] **Step 2: Run it**

Run: `cd backend && php artisan test --filter=VerifyRewardWalletEndToEndTest`
Expected: PASS.

- [ ] **Step 3: Run the full test suite**

Run: `cd backend && php artisan test`
Expected: all reward/wallet-related tests pass; any pre-existing failures unrelated to this feature (if the codebase already has some, per the pattern noted in the `05195ed` commit's "same 11 pre-existing failures as before this change") should be the *same* count as before this port — compare against a baseline run if unsure:

```bash
git stash push -u -m "pre-port-baseline-check"
php artisan test 2>&1 | tail -5
git stash pop
```

(Use the stash carefully per this session's git-worktree stash-safety rules — capture the stash entry's SHA immediately via `git stash list --format='%H %gs'` and restore with `git stash apply <sha>`, not bare `pop`, since the stash stack is shared across worktrees.)

- [ ] **Step 4: Commit**

```bash
cd /Users/ayushman/Desktop/estele-jewellery
git add backend/tests/Feature/VerifyRewardWalletEndToEndTest.php
git commit -m "Add end-to-end test: reward approval -> wallet credit -> checkout debit -> cancel refund"
```

---

## Phase 2 — Mobile-responsive audit (all listed screens)

Every task in this phase is a **verify-and-fix** pass at a 375px-wide viewport, not a rebuild — Phase 1's views were already built mobile-first where they're new (Task 9's `rewards.blade.php`, Task 7's checkout wallet input). This phase covers the screens the spec's checklist requires but Phase 1 didn't already birth mobile-first, plus a final confirmation pass on the ones that were.

### Task 11: Playwright mobile-viewport pass — customer screens

**Files:**
- No file changes expected unless a genuine overflow/clipping bug is found; if found, modify the specific view file identified.

**Interfaces:**
- Consumes: a running local server (`php artisan serve`) and the routes from Tasks 6/9.

- [ ] **Step 1: Start the local server**

Run: `cd backend && php artisan serve --port=8000 &` (background)

- [ ] **Step 2: Screenshot the rewards dashboard at 375px width**

Use Playwright (already the project's established UI-verification tool per the `05195ed` commit's stated practice) to load `http://127.0.0.1:8000/account/rewards` at a 375x812 viewport (an authenticated session with at least one eligible delivered order and one of each submission status — seed this with `php artisan tinker` or a one-off factory script if no such data exists locally), and take a full-page screenshot.

- [ ] **Step 3: Inspect the screenshot for the checklist items**

Confirm, from the screenshot: no horizontal scrollbar on the page body; the wallet balance card spans the full content width; upload form file inputs and buttons are full-width and not clipped; status badges wrap cleanly without overlapping the order number; the wallet transaction table sits inside its own horizontally-scrollable container without pushing the rest of the page wider.

- [ ] **Step 4: Test the upload preview interaction at mobile width**

Still at 375px, select a small image and a small (real ftyp-header) MP4 file into the two file inputs via Playwright's file-chooser API, and screenshot again to confirm the `<img>`/`<video>` previews render capped by `max-w-full` (no preview wider than the viewport).

- [ ] **Step 5: Fix any genuine finding**

If any of Steps 3–4 reveal actual overflow (not just a visually dense but non-overflowing layout), fix the specific Tailwind classes in `backend/resources/views/account/rewards.blade.php` and re-screenshot to confirm the fix. If nothing is found, this task's outcome is "verified, no change" — do not invent a fix for a non-problem.

- [ ] **Step 6: Commit (only if a fix was made)**

```bash
cd /Users/ayushman/Desktop/estele-jewellery
git add backend/resources/views/account/rewards.blade.php
git commit -m "Fix mobile overflow found in Rewards dashboard Playwright audit"
```

If no fix was needed, skip this step — there's nothing to commit for a verify-only pass.

---

### Task 12: Playwright mobile-viewport pass — admin/vendor screens

**Files:**
- No file changes expected unless a genuine overflow/clipping bug is found; if found, modify the specific Filament resource file identified (most likely `RewardSubmissionsTable.php` or `RewardSubmissionForm.php`).

**Interfaces:**
- Consumes: a running local server, a seeded `vendor`-role user (via `ShieldSeeder` + `assignRole('vendor')`), and at least one `pending` `RewardSubmission` row to review.

- [ ] **Step 1: Log in as a vendor at 375px width**

Via Playwright, navigate to `http://127.0.0.1:8000/admin/login` at a 375x812 viewport, log in as a seeded vendor user, and screenshot the login page itself first — confirm no horizontal scroll and the email/password inputs are full-width and tappable (this is the "Admin login" checklist item — Filament's own default responsive shell, verify-only per the spec).

- [ ] **Step 2: Screenshot the Reward Submissions list at mobile width**

Navigate to `/admin/reward-submissions`, screenshot. Confirm: the always-visible columns (customer, status, reward amount) plus the Approve/Deny/Edit row actions fit without forcing horizontal scroll on the table itself — a small amount of scroll on a genuinely wide table is acceptable per Filament's own convention (it's already wrapped `overflow-x-auto`), but check that the *primary* columns aren't among what's pushed off-screen.

- [ ] **Step 3: Open the Approve modal at mobile width and screenshot**

Click "Approve" on a pending row; screenshot the modal. Confirm the reward-amount numeric input and the submit button are full width within the modal and comfortably tappable (not a cramped inline layout).

- [ ] **Step 4: Repeat for the Deny modal**

Same as Step 3 for "Deny"/"Reject" — confirm the reason textarea and submit button.

- [ ] **Step 5: Check the image/video proof preview inside the row/edit view at mobile width**

Open the Edit page for a submission with both image and video attached; screenshot. Confirm the `SpatieMediaLibraryFileUpload` preview thumbnails don't force horizontal scroll and are legible without pinch-zoom.

- [ ] **Step 6: Fix any genuine finding**

If any table column needs to move from always-visible to `toggleable(isToggledHiddenByDefault: true)` to stop primary-column clipping, make that change in `RewardSubmissionsTable::configure()`. If the modal forms are already fine (Filament's modal is full-width responsive by default per the spec), no change is needed — record "verified, no change".

- [ ] **Step 7: Commit (only if a fix was made)**

```bash
cd /Users/ayushman/Desktop/estele-jewellery
git add backend/app/Filament/Resources/RewardSubmissions
git commit -m "Fix mobile column overflow found in RewardSubmissions table Playwright audit"
```

---

### Task 13: Playwright mobile-viewport pass — super admin dashboard, wallet management, checkout wallet

**Files:**
- No file changes expected unless a genuine overflow/clipping bug is found.

**Interfaces:**
- Consumes: a running local server, a seeded `super_admin` user, at least one customer with a non-zero wallet balance and transaction history.

- [ ] **Step 1: Screenshot the super admin's Reward Submissions list at mobile width**

Log in as `super_admin` (not vendor) at 375px width, navigate to `/admin/reward-submissions`, screenshot. Confirm the extra visibility a super admin has (same table as Task 12 — no separate restricted view exists per the spec) renders identically at mobile width.

- [ ] **Step 2: Screenshot Wallet Management list and History modal at mobile width**

Navigate to `/admin/wallet-management`, screenshot. Open the "History" action's modal for a customer with transaction history, screenshot the `history.blade.php` table inside the modal — confirm its `overflow-x-auto` wrapper contains the table without pushing the modal wider than the viewport.

- [ ] **Step 3: Screenshot the Credit/Debit action modals at mobile width**

Open both, screenshot, confirm the amount input and reason textarea are usable.

- [ ] **Step 4: Screenshot the checkout page's wallet input at mobile width**

As an authenticated customer with a positive wallet balance, navigate to `/checkout` at 375px, screenshot the wallet-amount input block added in Task 7. Confirm it doesn't overflow and the label (which includes the balance amount inline) wraps cleanly rather than getting clipped.

- [ ] **Step 5: Fix any genuine finding**

Same rule as Tasks 11–12: fix only real overflow, in the specific file identified.

- [ ] **Step 6: Commit (only if a fix was made)**

```bash
cd /Users/ayushman/Desktop/estele-jewellery
git add -A
git commit -m "Fix mobile overflow found in super admin / wallet management Playwright audit"
```

---

### Task 14: Final full-suite regression + spec checklist sign-off

**Files:**
- None (verification-only task).

**Interfaces:**
- Consumes: everything from Tasks 1–13.

- [ ] **Step 1: Run the full backend test suite one more time**

Run: `cd backend && php artisan test`
Expected: same pass/fail count as the Task 10 Step 3 baseline (no new failures introduced by the mobile-audit tasks, which shouldn't have touched any PHP test-covered logic — only Blade/Filament view code).

- [ ] **Step 2: Walk the spec's mobile-responsive audit checklist one item at a time**

From `docs/superpowers/specs/2026-09-05-video-approval-system-port-design.md`'s checklist, confirm and check off each: customer dashboard, upload form, video/image preview, approval status badges, admin login, super admin/vendor list table, approve/deny modal forms, wallet transaction history table.

- [ ] **Step 3: Confirm no stray dead code was ported**

Run: `cd backend && grep -rn "AdminController" routes/ app/ 2>/dev/null` — expect no output (confirms the dead `/admin` route from the source branch's routes/web.php diff was correctly excluded in Task 6).

- [ ] **Step 4: Final commit if the checklist walk-through required any doc update**

If any checklist item needed a note added to the spec (e.g., marking an item as verified), commit that:

```bash
cd /Users/ayushman/Desktop/estele-jewellery
git add docs/superpowers/specs/2026-09-05-video-approval-system-port-design.md
git commit -m "Mark mobile-responsive audit checklist complete"
```
