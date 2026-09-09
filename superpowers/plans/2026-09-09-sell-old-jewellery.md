# Sell Your Old Jewellery — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the complete backend for the "Sell Your Old Jewellery" feature: customer submission → vendor invitations → 3-hour bidding → highest-bid selection → 10% deduction / 90% wallet credit → 10-day wallet expiry → checkout usage, plus Filament admin, REST API, notifications, scheduler, and tests.

**Architecture:** New domain (`vendors`, `old_jewellery_*` tables) bolted onto the existing Laravel 13 app at `backend/`. Reuses `WalletService` (extended, not duplicated), the `Order`-style string-status + `ALLOWED_TRANSITIONS` const pattern, the `RewardSubmission` Spatie-media pattern, and Filament Shield permissions. Adds first-ever `routes/api.php`, first `app/Jobs/`, first scheduler registration, first `app/Notifications/`, and Sanctum for customer API auth.

**Tech Stack:** Laravel 13, PHP 8.3+, Filament 5, MySQL (sqlite in tests), Spatie MediaLibrary, Spatie Permission + Filament Shield, Laravel Sanctum (new), PHPUnit (not Pest — matches existing `phpunit.xml`).

**Spec:** `docs/superpowers/specs/2026-09-09-sell-old-jewellery-design.md`

## Global Constraints

- All paths are relative to `backend/` (the Laravel app root), e.g. `app/Models/Vendor.php` means `backend/app/Models/Vendor.php`.
- PHP 8.3+, Laravel 13 idioms (`protected function casts(): array`, not `protected $casts`).
- No Pest — PHPUnit, matching `tests/TestCase.php` and `phpunit.xml` (sqlite `:memory:`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`).
- Money: `decimal(12,2)` columns, `decimal:2` casts, never raw floats persisted. Deduction/credit computed as `deduction = round(amount * 0.10, 2)`, `credited = amount - deduction` (subtraction, not a second multiply, so the two always sum exactly to `amount`).
- All wallet balance mutations go through `App\Services\WalletService::credit()`/`debit()` — never write `users.wallet_balance` or `wallet_transactions` directly anywhere else.
- Status columns are plain `string`, guarded by an `ALLOWED_TRANSITIONS` const + `static::updating()` hook, exactly like `App\Models\Order`.
- No public frontend files of any kind. Filament resources are backend admin and are in scope.
- No destructive migrations against existing tables (`users`, `orders`, `wallet_transactions`, etc.) — only additive columns/tables.
- Every bid-mutating action independently re-checks `now() < bidding_end_at` server-side, never trusting scheduler timing alone.
- Vendor tokens: cryptographically random, stored only as a SHA-256 hash (`hash('sha256', $plaintext)`), plaintext never persisted or logged.
- One bid per vendor per request (DB-unique-enforced), no bid revision.
- Tie-break on equal highest bids: earliest `submitted_at` wins.
- Wallet checkout spend order: expiring (`old_jewellery_wallet_credits`) rows first, soonest-`expires_at` first, before falling through to non-expiring balance.
- Commit after every task (see step lists) with a `feat:`/`test:`/`fix:` prefix; no `Co-Authored-By` trailer.

---

## Task 1: Migrations — `vendors`, `old_jewellery_requests`, invitations, bids, activity logs, wallet credits

**Files:**
- Create: `database/migrations/2026_09_09_100000_create_vendors_table.php`
- Create: `database/migrations/2026_09_09_100001_create_old_jewellery_requests_table.php`
- Create: `database/migrations/2026_09_09_100002_create_old_jewellery_vendor_invitations_table.php`
- Create: `database/migrations/2026_09_09_100003_create_old_jewellery_bids_table.php`
- Create: `database/migrations/2026_09_09_100004_create_old_jewellery_activity_logs_table.php`
- Create: `database/migrations/2026_09_09_100005_create_old_jewellery_wallet_credits_table.php`
- Test: `tests/Feature/OldJewellery/MigrationsRunTest.php`

**Interfaces:**
- Produces: tables `vendors`, `old_jewellery_requests`, `old_jewellery_vendor_invitations`, `old_jewellery_bids`, `old_jewellery_activity_logs`, `old_jewellery_wallet_credits` with columns exactly as listed below — later tasks' models/fillable arrays depend on these exact column names.

- [ ] **Step 1: Write the migrations**

`database/migrations/2026_09_09_100000_create_vendors_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('company_name')->nullable();
            $table->string('mobile')->unique();
            $table->timestamp('mobile_verified_at')->nullable();
            $table->string('email')->nullable();
            $table->string('whatsapp_number')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
```

`database/migrations/2026_09_09_100001_create_old_jewellery_requests_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('old_jewellery_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_number')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('description')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('bidding_start_at')->nullable();
            $table->timestamp('bidding_end_at')->nullable();
            $table->foreignId('winning_bid_id')->nullable();
            $table->decimal('final_amount', 12, 2)->nullable();
            $table->decimal('deduction_amount', 12, 2)->nullable();
            $table->decimal('credited_amount', 12, 2)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('status');
            $table->index('bidding_start_at');
            $table->index('bidding_end_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('old_jewellery_requests');
    }
};
```

`database/migrations/2026_09_09_100002_create_old_jewellery_vendor_invitations_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('old_jewellery_vendor_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('old_jewellery_request_id')->constrained('old_jewellery_requests')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->string('token_hash')->unique();
            $table->timestamp('expires_at');
            $table->string('response_status')->default('pending');
            $table->text('decline_reason')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['old_jewellery_request_id', 'vendor_id'], 'oj_invitations_request_vendor_unique');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('old_jewellery_vendor_invitations');
    }
};
```

`database/migrations/2026_09_09_100003_create_old_jewellery_bids_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('old_jewellery_bids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('old_jewellery_request_id')->constrained('old_jewellery_requests')->cascadeOnDelete();
            $table->string('bidder_type'); // vendor | admin
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->foreignId('admin_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('invitation_id')->nullable()->constrained('old_jewellery_vendor_invitations')->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->boolean('is_valid')->default(true);
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->index('old_jewellery_request_id');
            $table->index('submitted_at');
            $table->unique(['old_jewellery_request_id', 'vendor_id'], 'oj_bids_request_vendor_unique');
        });

        // Add the winning_bid_id FK now that old_jewellery_bids exists (chicken-and-egg
        // with old_jewellery_requests, created first above).
        Schema::table('old_jewellery_requests', function (Blueprint $table) {
            $table->foreign('winning_bid_id')->references('id')->on('old_jewellery_bids')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('old_jewellery_requests', function (Blueprint $table) {
            $table->dropForeign(['winning_bid_id']);
        });

        Schema::dropIfExists('old_jewellery_bids');
    }
};
```

`database/migrations/2026_09_09_100004_create_old_jewellery_activity_logs_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('old_jewellery_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('old_jewellery_request_id')->constrained('old_jewellery_requests')->cascadeOnDelete();
            $table->string('actor_type'); // customer | vendor | admin | system
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action');
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('old_jewellery_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('old_jewellery_activity_logs');
    }
};
```

`database/migrations/2026_09_09_100005_create_old_jewellery_wallet_credits_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('old_jewellery_wallet_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('old_jewellery_request_id')->unique()->constrained('old_jewellery_requests')->cascadeOnDelete();
            $table->foreignId('wallet_transaction_id')->constrained('wallet_transactions')->cascadeOnDelete();
            $table->decimal('gross_amount', 12, 2);
            $table->decimal('deduction_amount', 12, 2);
            $table->decimal('credited_amount', 12, 2);
            $table->decimal('remaining_amount', 12, 2);
            $table->timestamp('credited_at');
            $table->timestamp('expires_at');
            $table->string('status')->default('active'); // active | partially_used | used | expired
            $table->timestamp('reminder_3d_sent_at')->nullable();
            $table->timestamp('reminder_1d_sent_at')->nullable();
            $table->timestamp('reminder_0d_sent_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('expires_at');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('old_jewellery_wallet_credits');
    }
};
```

- [ ] **Step 2: Write a test that the migrations run and produce the expected tables/columns**

`tests/Feature/OldJewellery/MigrationsRunTest.php`:

```php
<?php

namespace Tests\Feature\OldJewellery;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationsRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_old_jewellery_tables_exist_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('vendors'));
        $this->assertTrue(Schema::hasColumns('vendors', [
            'name', 'company_name', 'mobile', 'mobile_verified_at', 'email', 'whatsapp_number', 'is_active',
        ]));

        $this->assertTrue(Schema::hasTable('old_jewellery_requests'));
        $this->assertTrue(Schema::hasColumns('old_jewellery_requests', [
            'request_number', 'user_id', 'status', 'bidding_start_at', 'bidding_end_at',
            'winning_bid_id', 'final_amount', 'deduction_amount', 'credited_amount', 'closed_at',
        ]));

        $this->assertTrue(Schema::hasTable('old_jewellery_vendor_invitations'));
        $this->assertTrue(Schema::hasColumns('old_jewellery_vendor_invitations', [
            'old_jewellery_request_id', 'vendor_id', 'token_hash', 'expires_at',
            'response_status', 'decline_reason', 'responded_at', 'notified_at',
        ]));

        $this->assertTrue(Schema::hasTable('old_jewellery_bids'));
        $this->assertTrue(Schema::hasColumns('old_jewellery_bids', [
            'old_jewellery_request_id', 'bidder_type', 'vendor_id', 'admin_user_id',
            'invitation_id', 'amount', 'is_valid', 'submitted_at',
        ]));

        $this->assertTrue(Schema::hasTable('old_jewellery_activity_logs'));
        $this->assertTrue(Schema::hasColumns('old_jewellery_activity_logs', [
            'old_jewellery_request_id', 'actor_type', 'actor_id', 'action', 'from_status', 'to_status', 'metadata',
        ]));

        $this->assertTrue(Schema::hasTable('old_jewellery_wallet_credits'));
        $this->assertTrue(Schema::hasColumns('old_jewellery_wallet_credits', [
            'user_id', 'old_jewellery_request_id', 'wallet_transaction_id', 'gross_amount',
            'deduction_amount', 'credited_amount', 'remaining_amount', 'credited_at', 'expires_at', 'status',
        ]));
    }
}
```

- [ ] **Step 3: Run the test**

Run: `cd backend && php artisan test tests/Feature/OldJewellery/MigrationsRunTest.php`
Expected: PASS (all `assertTrue` succeed).

- [ ] **Step 4: Commit**

```bash
cd backend
git add database/migrations/2026_09_09_1000*.php tests/Feature/OldJewellery/MigrationsRunTest.php
git commit -m "feat: add old-jewellery and vendor migrations"
```

---

## Task 2: Models — `Vendor`, `OldJewelleryRequest`, `OldJewelleryVendorInvitation`, `OldJewelleryBid`, `OldJewelleryActivityLog`, `OldJewelleryWalletCredit`

**Files:**
- Create: `app/Models/Vendor.php`
- Create: `app/Models/OldJewelleryRequest.php`
- Create: `app/Models/OldJewelleryVendorInvitation.php`
- Create: `app/Models/OldJewelleryBid.php`
- Create: `app/Models/OldJewelleryActivityLog.php`
- Create: `app/Models/OldJewelleryWalletCredit.php`
- Test: `tests/Feature/OldJewellery/ModelRelationsTest.php`

**Interfaces:**
- Consumes: tables from Task 1.
- Produces: `Vendor::invitations()`, `Vendor::bids()`; `OldJewelleryRequest::user()`, `->invitations()`, `->bids()`, `->activityLogs()`, `->winningBid()`, `->walletCredit()`, plus `OldJewelleryRequest::ALLOWED_TRANSITIONS` const and media collections `image`/`video`; `OldJewelleryVendorInvitation::request()`, `->vendor()`, `->bid()`; `OldJewelleryBid::request()`, `->vendor()`, `->adminUser()`, `->invitation()`; `OldJewelleryWalletCredit::user()`, `->request()`, `->walletTransaction()`. These exact method names are relied on by the Services in Task 3+.

- [ ] **Step 1: Write `app/Models/Vendor.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A marketplace vendor who bids on old-jewellery requests. Vendors never log
 * in — they act only through a per-invitation signed token
 * (OldJewelleryVendorInvitation). Distinct from the "vendor" Spatie role
 * (reward-submission reviewers), which is an unrelated concept.
 */
class Vendor extends Model
{
    protected $fillable = [
        'name',
        'company_name',
        'mobile',
        'mobile_verified_at',
        'email',
        'whatsapp_number',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'mobile_verified_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(OldJewelleryVendorInvitation::class);
    }

    public function bids(): HasMany
    {
        return $this->hasMany(OldJewelleryBid::class);
    }
}
```

- [ ] **Step 2: Write `app/Models/OldJewelleryRequest.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class OldJewelleryRequest extends Model implements HasMedia
{
    use InteractsWithMedia;

    /**
     * Mirrors Order::ALLOWED_TRANSITIONS — enforced in updating() below so no
     * direct API/tinker/bulk update can skip or reverse the pipeline.
     */
    public const ALLOWED_TRANSITIONS = [
        'pending' => ['submitted', 'cancelled'],
        'submitted' => ['vendors_notified', 'cancelled'],
        'vendors_notified' => ['bidding_active', 'cancelled'],
        'bidding_active' => ['bidding_closed', 'cancelled'],
        'bidding_closed' => ['bid_selected', 'cancelled'],
        'bid_selected' => ['wallet_pending', 'cancelled'],
        'wallet_pending' => ['wallet_credited', 'cancelled'],
        'wallet_credited' => ['wallet_expired', 'completed'],
        'wallet_expired' => ['completed'],
        'completed' => [],
        'cancelled' => [],
    ];

    protected $fillable = [
        'user_id',
        'request_number',
        'description',
        'status',
        'bidding_start_at',
        'bidding_end_at',
        'winning_bid_id',
        'final_amount',
        'deduction_amount',
        'credited_amount',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'bidding_start_at' => 'datetime',
            'bidding_end_at' => 'datetime',
            'final_amount' => 'decimal:2',
            'deduction_amount' => 'decimal:2',
            'credited_amount' => 'decimal:2',
            'closed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'request_number';
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('image')
            ->useDisk('original_images')
            ->storeConversionsOnDisk('public')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->singleFile();

        $this->addMediaCollection('video')
            ->useDisk('original_images')
            ->acceptsMimeTypes(['video/mp4', 'video/quicktime'])
            ->singleFile();
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->performOnCollections('image')
            ->width(400)
            ->format('png')
            ->quality(78);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(OldJewelleryVendorInvitation::class);
    }

    public function bids(): HasMany
    {
        return $this->hasMany(OldJewelleryBid::class);
    }

    public function winningBid(): BelongsTo
    {
        return $this->belongsTo(OldJewelleryBid::class, 'winning_bid_id');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(OldJewelleryActivityLog::class);
    }

    public function walletCredit(): HasOne
    {
        return $this->hasOne(OldJewelleryWalletCredit::class);
    }

    protected static function booted(): void
    {
        static::updating(function (OldJewelleryRequest $request) {
            if (! $request->isDirty('status')) {
                return;
            }

            $from = $request->getOriginal('status');
            $to = $request->status;

            if (! array_key_exists($from, self::ALLOWED_TRANSITIONS)) {
                return;
            }

            if (! in_array($to, self::ALLOWED_TRANSITIONS[$from], true)) {
                throw ValidationException::withMessages([
                    'status' => "Old jewellery request cannot move from \"{$from}\" to \"{$to}\".",
                ]);
            }
        });
    }
}
```

- [ ] **Step 3: Write `app/Models/OldJewelleryVendorInvitation.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OldJewelleryVendorInvitation extends Model
{
    protected $fillable = [
        'old_jewellery_request_id',
        'vendor_id',
        'token_hash',
        'expires_at',
        'response_status',
        'decline_reason',
        'responded_at',
        'notified_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'responded_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(OldJewelleryRequest::class, 'old_jewellery_request_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function bid(): HasOne
    {
        return $this->hasOne(OldJewelleryBid::class, 'invitation_id');
    }
}
```

- [ ] **Step 4: Write `app/Models/OldJewelleryBid.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OldJewelleryBid extends Model
{
    protected $fillable = [
        'old_jewellery_request_id',
        'bidder_type',
        'vendor_id',
        'admin_user_id',
        'invitation_id',
        'amount',
        'is_valid',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'is_valid' => 'boolean',
            'submitted_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(OldJewelleryRequest::class, 'old_jewellery_request_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(OldJewelleryVendorInvitation::class, 'invitation_id');
    }
}
```

- [ ] **Step 5: Write `app/Models/OldJewelleryActivityLog.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OldJewelleryActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'old_jewellery_request_id',
        'actor_type',
        'actor_id',
        'action',
        'from_status',
        'to_status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(OldJewelleryRequest::class, 'old_jewellery_request_id');
    }
}
```

- [ ] **Step 6: Write `app/Models/OldJewelleryWalletCredit.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OldJewelleryWalletCredit extends Model
{
    protected $fillable = [
        'user_id',
        'old_jewellery_request_id',
        'wallet_transaction_id',
        'gross_amount',
        'deduction_amount',
        'credited_amount',
        'remaining_amount',
        'credited_at',
        'expires_at',
        'status',
        'reminder_3d_sent_at',
        'reminder_1d_sent_at',
        'reminder_0d_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'gross_amount' => 'decimal:2',
            'deduction_amount' => 'decimal:2',
            'credited_amount' => 'decimal:2',
            'remaining_amount' => 'decimal:2',
            'credited_at' => 'datetime',
            'expires_at' => 'datetime',
            'reminder_3d_sent_at' => 'datetime',
            'reminder_1d_sent_at' => 'datetime',
            'reminder_0d_sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(OldJewelleryRequest::class, 'old_jewellery_request_id');
    }

    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }
}
```

- [ ] **Step 7: Write the relations test**

`tests/Feature/OldJewellery/ModelRelationsTest.php`:

```php
<?php

namespace Tests\Feature\OldJewellery;

use App\Models\OldJewelleryActivityLog;
use App\Models\OldJewelleryBid;
use App\Models\OldJewelleryRequest;
use App\Models\OldJewelleryVendorInvitation;
use App\Models\OldJewelleryWalletCredit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelRelationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_relations_resolve_end_to_end(): void
    {
        $user = User::factory()->create();

        $request = OldJewelleryRequest::create([
            'user_id' => $user->id,
            'request_number' => 'OJ-2026-000001',
            'status' => 'pending',
            'description' => 'A necklace',
        ]);

        $vendor = Vendor::create([
            'name' => 'Test Vendor',
            'mobile' => '9000000001',
            'is_active' => true,
        ]);

        $invitation = OldJewelleryVendorInvitation::create([
            'old_jewellery_request_id' => $request->id,
            'vendor_id' => $vendor->id,
            'token_hash' => hash('sha256', 'plaintext-token'),
            'expires_at' => now()->addHours(3),
        ]);

        $bid = OldJewelleryBid::create([
            'old_jewellery_request_id' => $request->id,
            'bidder_type' => 'vendor',
            'vendor_id' => $vendor->id,
            'invitation_id' => $invitation->id,
            'amount' => 1100,
            'submitted_at' => now(),
        ]);

        OldJewelleryActivityLog::create([
            'old_jewellery_request_id' => $request->id,
            'actor_type' => 'system',
            'action' => 'request_submitted',
        ]);

        $walletTransaction = WalletTransaction::create([
            'user_id' => $user->id,
            'type' => 'credit',
            'amount' => 990,
            'balance_after' => 990,
            'reason' => 'old_jewellery_sale',
        ]);

        $credit = OldJewelleryWalletCredit::create([
            'user_id' => $user->id,
            'old_jewellery_request_id' => $request->id,
            'wallet_transaction_id' => $walletTransaction->id,
            'gross_amount' => 1100,
            'deduction_amount' => 110,
            'credited_amount' => 990,
            'remaining_amount' => 990,
            'credited_at' => now(),
            'expires_at' => now()->addDays(10),
        ]);

        $this->assertTrue($request->invitations->contains($invitation));
        $this->assertTrue($request->bids->contains($bid));
        $this->assertTrue($request->activityLogs->first()->action === 'request_submitted');
        $this->assertTrue($request->walletCredit->is($credit));
        $this->assertTrue($vendor->invitations->contains($invitation));
        $this->assertTrue($vendor->bids->contains($bid));
        $this->assertTrue($invitation->vendor->is($vendor));
        $this->assertTrue($invitation->bid->is($bid));
        $this->assertTrue($bid->vendor->is($vendor));
        $this->assertTrue($credit->walletTransaction->is($walletTransaction));
    }

    public function test_status_transition_guard_blocks_illegal_moves(): void
    {
        $user = User::factory()->create();

        $request = OldJewelleryRequest::create([
            'user_id' => $user->id,
            'request_number' => 'OJ-2026-000002',
            'status' => 'pending',
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $request->update(['status' => 'wallet_credited']);
    }

    public function test_status_transition_guard_allows_legal_moves(): void
    {
        $user = User::factory()->create();

        $request = OldJewelleryRequest::create([
            'user_id' => $user->id,
            'request_number' => 'OJ-2026-000003',
            'status' => 'pending',
        ]);

        $request->update(['status' => 'submitted']);

        $this->assertSame('submitted', $request->fresh()->status);
    }
}
```

- [ ] **Step 8: Run tests**

Run: `cd backend && php artisan test tests/Feature/OldJewellery/ModelRelationsTest.php`
Expected: PASS (3 tests).

- [ ] **Step 9: Commit**

```bash
cd backend
git add app/Models/Vendor.php app/Models/OldJewelleryRequest.php app/Models/OldJewelleryVendorInvitation.php app/Models/OldJewelleryBid.php app/Models/OldJewelleryActivityLog.php app/Models/OldJewelleryWalletCredit.php tests/Feature/OldJewellery/ModelRelationsTest.php
git commit -m "feat: add old-jewellery and vendor models with relations"
```

---

*(Continued in subsequent tasks: request-number generator + OldJewelleryRequestService, VendorInvitationService, OldJewelleryBiddingService, OldJewelleryClosingService, OldJewelleryWalletService, Sanctum setup, API layer, Filament resources, Jobs/scheduler, Notifications, checkout wallet-spend-order integration, and end-to-end tests — each as its own task below.)*

---

## Task 3: `OldJewelleryRequestService` — request creation, request-number generation, media validation

**Files:**
- Create: `app/Services/OldJewellery/OldJewelleryRequestService.php`
- Create: `database/migrations/2026_09_09_100006_create_old_jewellery_number_sequences_table.php`
- Test: `tests/Feature/OldJewellery/OldJewelleryRequestServiceTest.php`

**Interfaces:**
- Consumes: `OldJewelleryRequest` (Task 2), `OldJewelleryActivityLog` (Task 2).
- Produces: `OldJewelleryRequestService::create(User $user, array $data, ?UploadedFile $image, UploadedFile $video): OldJewelleryRequest` where `$data = ['description' => ?string]`. Throws `\Illuminate\Validation\ValidationException` if `$video` is missing (defense in depth — the Form Request in Task 6 is the primary guard, this is the service-layer backstop per spec "do not trust client-side validation" and "must not be created without video"). Also produces `OldJewelleryRequestService::generateRequestNumber(): string` (format `OJ-{Y}-{6-digit sequence}`, collision-free via a locked counter row).

- [ ] **Step 1: Write the sequence-counter migration**

A tiny dedicated counter table avoids scanning/locking the (potentially large) `old_jewellery_requests` table just to find "the next number" — same reasoning a ticket/invoice-number generator commonly uses.

`database/migrations/2026_09_09_100006_create_old_jewellery_number_sequences_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('old_jewellery_number_sequences', function (Blueprint $table) {
            $table->string('year', 4)->primary();
            $table->unsignedInteger('last_value')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('old_jewellery_number_sequences');
    }
};
```

- [ ] **Step 2: Write the failing test**

`tests/Feature/OldJewellery/OldJewelleryRequestServiceTest.php`:

```php
<?php

namespace Tests\Feature\OldJewellery;

use App\Models\OldJewelleryRequest;
use App\Models\User;
use App\Services\OldJewellery\OldJewelleryRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OldJewelleryRequestServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_request_number_is_unique_and_formatted(): void
    {
        $service = app(OldJewelleryRequestService::class);

        $first = $service->generateRequestNumber();
        $second = $service->generateRequestNumber();

        $this->assertMatchesRegularExpression('/^OJ-\d{4}-\d{6}$/', $first);
        $this->assertNotSame($first, $second);
    }

    public function test_create_requires_a_video(): void
    {
        Storage::fake('original_images');
        Storage::fake('public');

        $user = User::factory()->create();
        $service = app(OldJewelleryRequestService::class);

        $this->expectException(ValidationException::class);

        $service->create($user, ['description' => 'A ring'], null, null);
    }

    public function test_create_persists_request_with_video_and_optional_image(): void
    {
        Storage::fake('original_images');
        Storage::fake('public');

        $user = User::factory()->create();
        $service = app(OldJewelleryRequestService::class);

        $video = UploadedFile::fake()->create('jewellery.mp4', 5000, 'video/mp4');
        $image = UploadedFile::fake()->image('jewellery.jpg');

        $request = $service->create($user, ['description' => 'A ring'], $image, $video);

        $this->assertInstanceOf(OldJewelleryRequest::class, $request);
        $this->assertSame('submitted', $request->status);
        $this->assertNotNull($request->bidding_start_at);
        $this->assertNotNull($request->bidding_end_at);
        $this->assertEqualsWithDelta(
            $request->bidding_start_at->addHours(3)->timestamp,
            $request->bidding_end_at->timestamp,
            1,
        );
        $this->assertTrue($request->hasMedia('video'));
        $this->assertTrue($request->hasMedia('image'));
        $this->assertDatabaseHas('old_jewellery_activity_logs', [
            'old_jewellery_request_id' => $request->id,
            'action' => 'request_submitted',
        ]);
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `cd backend && php artisan test tests/Feature/OldJewellery/OldJewelleryRequestServiceTest.php`
Expected: FAIL — `Class "App\Services\OldJewellery\OldJewelleryRequestService" not found`.

- [ ] **Step 4: Write the service**

`app/Services/OldJewellery/OldJewelleryRequestService.php`:

```php
<?php

namespace App\Services\OldJewellery;

use App\Models\OldJewelleryActivityLog;
use App\Models\OldJewelleryRequest;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates old-jewellery requests. Video is compulsory by business rule —
 * validated here again even though the Form Request (Task 6) already
 * enforces it, since this service is also the target of direct calls
 * (tests, Filament, future internal tooling) that could otherwise bypass
 * HTTP-layer validation entirely.
 */
class OldJewelleryRequestService
{
    private const BIDDING_WINDOW_HOURS = 3;

    public function create(User $user, array $data, ?UploadedFile $image, ?UploadedFile $video): OldJewelleryRequest
    {
        if (! $video) {
            throw ValidationException::withMessages([
                'video' => ['A video is required.'],
            ]);
        }

        return DB::transaction(function () use ($user, $data, $image, $video) {
            $now = now();

            $request = OldJewelleryRequest::create([
                'user_id' => $user->id,
                'request_number' => $this->generateRequestNumber(),
                'description' => $data['description'] ?? null,
                'status' => 'pending',
                'bidding_start_at' => $now,
                'bidding_end_at' => $now->copy()->addHours(self::BIDDING_WINDOW_HOURS),
            ]);

            $request->addMedia($video)->toMediaCollection('video');

            if ($image) {
                $request->addMedia($image)->toMediaCollection('image');
            }

            $request->update(['status' => 'submitted']);

            OldJewelleryActivityLog::create([
                'old_jewellery_request_id' => $request->id,
                'actor_type' => 'customer',
                'actor_id' => $user->id,
                'action' => 'request_submitted',
                'from_status' => 'pending',
                'to_status' => 'submitted',
            ]);

            return $request->fresh();
        });
    }

    /**
     * Format: OJ-{year}-{6-digit sequence}, e.g. OJ-2026-000123. Uses a
     * dedicated counter row (locked for update inside this transaction) so
     * two concurrent requests in the same second can never collide — the
     * counter increment and the read happen atomically under the row lock.
     */
    public function generateRequestNumber(): string
    {
        return DB::transaction(function () {
            $year = now()->format('Y');

            $sequence = DB::table('old_jewellery_number_sequences')
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if (! $sequence) {
                DB::table('old_jewellery_number_sequences')->insert([
                    'year' => $year,
                    'last_value' => 0,
                ]);
                $nextValue = 1;
            } else {
                $nextValue = $sequence->last_value + 1;
            }

            DB::table('old_jewellery_number_sequences')
                ->where('year', $year)
                ->update(['last_value' => $nextValue]);

            return sprintf('OJ-%s-%06d', $year, $nextValue);
        });
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd backend && php artisan test tests/Feature/OldJewellery/OldJewelleryRequestServiceTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
cd backend
git add database/migrations/2026_09_09_100006_create_old_jewellery_number_sequences_table.php app/Services/OldJewellery/OldJewelleryRequestService.php tests/Feature/OldJewellery/OldJewelleryRequestServiceTest.php
git commit -m "feat: add OldJewelleryRequestService with request-number generation"
```

---

## Task 4: `VendorInvitationService` — invite all active vendors, secure tokens

**Files:**
- Create: `app/Services/OldJewellery/VendorInvitationService.php`
- Test: `tests/Feature/OldJewellery/VendorInvitationServiceTest.php`

**Interfaces:**
- Consumes: `OldJewelleryRequest` (Task 2), `Vendor` (Task 2), `OldJewelleryVendorInvitation` (Task 2).
- Produces: `VendorInvitationService::inviteAll(OldJewelleryRequest $request): \Illuminate\Support\Collection` returning a collection of `['invitation' => OldJewelleryVendorInvitation, 'plaintext_token' => string]`. Also produces `VendorInvitationService::hashToken(string $plaintext): string`. Idempotent: calling `inviteAll()` twice for the same request does not create duplicate invitations (checked via existing-invitation count before inserting). Moves request status `submitted → vendors_notified` (and if any invitations were created, later `vendors_notified → bidding_active` is left to the caller/Task 5, since "active" bidding starts once notifications are queued, which Task 9's notification dispatch confirms — this task only creates invitation rows and returns tokens, notification sending itself is Task 9).

- [ ] **Step 1: Write the failing test**

`tests/Feature/OldJewellery/VendorInvitationServiceTest.php`:

```php
<?php

namespace Tests\Feature\OldJewellery;

use App\Models\OldJewelleryRequest;
use App\Models\User;
use App\Models\Vendor;
use App\Services\OldJewellery\VendorInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorInvitationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeRequest(): OldJewelleryRequest
    {
        return OldJewelleryRequest::create([
            'user_id' => User::factory()->create()->id,
            'request_number' => 'OJ-2026-000010',
            'status' => 'submitted',
            'bidding_start_at' => now(),
            'bidding_end_at' => now()->addHours(3),
        ]);
    }

    public function test_invites_only_active_vendors(): void
    {
        $request = $this->makeRequest();

        $active = Vendor::create(['name' => 'Active Co', 'mobile' => '9000000010', 'is_active' => true]);
        Vendor::create(['name' => 'Inactive Co', 'mobile' => '9000000011', 'is_active' => false]);

        $results = app(VendorInvitationService::class)->inviteAll($request);

        $this->assertCount(1, $results);
        $this->assertSame($active->id, $results->first()['invitation']->vendor_id);
        $this->assertSame('vendors_notified', $request->fresh()->status);
    }

    public function test_token_is_stored_only_as_a_hash(): void
    {
        $request = $this->makeRequest();
        Vendor::create(['name' => 'Active Co', 'mobile' => '9000000012', 'is_active' => true]);

        $results = app(VendorInvitationService::class)->inviteAll($request);
        $result = $results->first();

        $this->assertNotEmpty($result['plaintext_token']);
        $this->assertSame(
            hash('sha256', $result['plaintext_token']),
            $result['invitation']->token_hash,
        );
        $this->assertDatabaseMissing('old_jewellery_vendor_invitations', [
            'token_hash' => $result['plaintext_token'],
        ]);
    }

    public function test_inviting_twice_does_not_duplicate_invitations(): void
    {
        $request = $this->makeRequest();
        Vendor::create(['name' => 'Active Co', 'mobile' => '9000000013', 'is_active' => true]);

        $service = app(VendorInvitationService::class);
        $service->inviteAll($request);
        $service->inviteAll($request->fresh());

        $this->assertSame(1, $request->fresh()->invitations()->count());
    }

    public function test_invitation_expires_at_matches_bidding_end_at(): void
    {
        $request = $this->makeRequest();
        Vendor::create(['name' => 'Active Co', 'mobile' => '9000000014', 'is_active' => true]);

        $results = app(VendorInvitationService::class)->inviteAll($request);

        $this->assertSame(
            $request->bidding_end_at->timestamp,
            $results->first()['invitation']->expires_at->timestamp,
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test tests/Feature/OldJewellery/VendorInvitationServiceTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the service**

`app/Services/OldJewellery/VendorInvitationService.php`:

```php
<?php

namespace App\Services\OldJewellery;

use App\Models\OldJewelleryActivityLog;
use App\Models\OldJewelleryRequest;
use App\Models\OldJewelleryVendorInvitation;
use App\Models\Vendor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VendorInvitationService
{
    /**
     * Creates one invitation per active vendor and returns the plaintext
     * tokens for the caller to hand to the notification dispatcher — the
     * plaintext is never persisted, so this is the only place it ever exists
     * outside of the notification payload itself.
     *
     * Idempotent: if this request already has invitations, returns them
     * without creating duplicates (and without re-exposing plaintext
     * tokens for already-created rows, since those are unrecoverable by
     * design — a second call after a partial failure should not attempt to
     * re-notify vendors that already have a row).
     *
     * @return Collection<int, array{invitation: OldJewelleryVendorInvitation, plaintext_token: string}>
     */
    public function inviteAll(OldJewelleryRequest $request): Collection
    {
        return DB::transaction(function () use ($request) {
            $locked = OldJewelleryRequest::whereKey($request->id)->lockForUpdate()->first();

            if ($locked->invitations()->exists()) {
                return collect();
            }

            $vendors = Vendor::where('is_active', true)->get();

            $results = $vendors->map(function (Vendor $vendor) use ($locked) {
                $plaintext = Str::random(64);

                $invitation = OldJewelleryVendorInvitation::create([
                    'old_jewellery_request_id' => $locked->id,
                    'vendor_id' => $vendor->id,
                    'token_hash' => $this->hashToken($plaintext),
                    'expires_at' => $locked->bidding_end_at,
                    'response_status' => 'pending',
                ]);

                return ['invitation' => $invitation, 'plaintext_token' => $plaintext];
            });

            if ($results->isNotEmpty()) {
                $locked->update(['status' => 'vendors_notified']);

                OldJewelleryActivityLog::create([
                    'old_jewellery_request_id' => $locked->id,
                    'actor_type' => 'system',
                    'action' => 'vendors_notified',
                    'from_status' => 'submitted',
                    'to_status' => 'vendors_notified',
                    'metadata' => ['vendor_count' => $results->count()],
                ]);
            }

            return $results;
        });
    }

    public function hashToken(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd backend && php artisan test tests/Feature/OldJewellery/VendorInvitationServiceTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
cd backend
git add app/Services/OldJewellery/VendorInvitationService.php tests/Feature/OldJewellery/VendorInvitationServiceTest.php
git commit -m "feat: add VendorInvitationService with secure per-vendor tokens"
```

---

## Task 5: `OldJewelleryBiddingService` — vendor accept/decline, admin bid, deadline enforcement

**Files:**
- Create: `app/Services/OldJewellery/OldJewelleryBiddingService.php`
- Test: `tests/Feature/OldJewellery/OldJewelleryBiddingServiceTest.php`

**Interfaces:**
- Consumes: `OldJewelleryVendorInvitation`, `OldJewelleryBid`, `OldJewelleryRequest`, `OldJewelleryActivityLog`, `VendorInvitationService::hashToken()` (Task 4).
- Produces:
  - `OldJewelleryBiddingService::findInvitationByToken(string $plaintextToken): ?OldJewelleryVendorInvitation`
  - `OldJewelleryBiddingService::accept(OldJewelleryVendorInvitation $invitation, float $amount): OldJewelleryBid` — throws `\DomainException` if expired, already responded, or amount ≤ 0.
  - `OldJewelleryBiddingService::decline(OldJewelleryVendorInvitation $invitation, ?string $reason): OldJewelleryVendorInvitation` — throws `\DomainException` if expired or already responded.
  - `OldJewelleryBiddingService::submitAdminBid(OldJewelleryRequest $request, User $admin, float $amount): OldJewelleryBid` — throws `\DomainException` if `now() >= bidding_end_at` or amount ≤ 0. Enforces one admin bid per request (updates existing row if present — admin isn't token-limited to one-shot like vendors, since spec doesn't require it, but there's still exactly one `OldJewelleryBid` row per `(request, admin_user_id)` pair by convention: this method upserts).

- [ ] **Step 1: Write the failing test**

`tests/Feature/OldJewellery/OldJewelleryBiddingServiceTest.php`:

```php
<?php

namespace Tests\Feature\OldJewellery;

use App\Models\OldJewelleryRequest;
use App\Models\OldJewelleryVendorInvitation;
use App\Models\User;
use App\Models\Vendor;
use App\Services\OldJewellery\OldJewelleryBiddingService;
use App\Services\OldJewellery\VendorInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OldJewelleryBiddingServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeRequestWithInvitation(bool $expired = false): array
    {
        $request = OldJewelleryRequest::create([
            'user_id' => User::factory()->create()->id,
            'request_number' => 'OJ-2026-000020',
            'status' => 'vendors_notified',
            'bidding_start_at' => $expired ? now()->subHours(4) : now(),
            'bidding_end_at' => $expired ? now()->subHours(1) : now()->addHours(3),
        ]);

        $vendor = Vendor::create(['name' => 'V', 'mobile' => '9000000020', 'is_active' => true]);

        $invitation = OldJewelleryVendorInvitation::create([
            'old_jewellery_request_id' => $request->id,
            'vendor_id' => $vendor->id,
            'token_hash' => app(VendorInvitationService::class)->hashToken('plain-token'),
            'expires_at' => $request->bidding_end_at,
            'response_status' => 'pending',
        ]);

        return [$request, $vendor, $invitation];
    }

    public function test_find_invitation_by_token_matches_hash(): void
    {
        [, , $invitation] = $this->makeRequestWithInvitation();

        $found = app(OldJewelleryBiddingService::class)->findInvitationByToken('plain-token');

        $this->assertTrue($found->is($invitation));
    }

    public function test_find_invitation_by_wrong_token_returns_null(): void
    {
        $this->makeRequestWithInvitation();

        $found = app(OldJewelleryBiddingService::class)->findInvitationByToken('wrong-token');

        $this->assertNull($found);
    }

    public function test_accept_creates_a_bid_and_marks_invitation_accepted(): void
    {
        [, , $invitation] = $this->makeRequestWithInvitation();

        $bid = app(OldJewelleryBiddingService::class)->accept($invitation, 800);

        $this->assertSame('800.00', $bid->amount);
        $this->assertSame('vendor', $bid->bidder_type);
        $this->assertSame('accepted', $invitation->fresh()->response_status);
    }

    public function test_accept_rejects_non_positive_amount(): void
    {
        [, , $invitation] = $this->makeRequestWithInvitation();

        $this->expectException(\DomainException::class);

        app(OldJewelleryBiddingService::class)->accept($invitation, 0);
    }

    public function test_accept_rejects_after_deadline_even_if_scheduler_has_not_run(): void
    {
        [, , $invitation] = $this->makeRequestWithInvitation(expired: true);

        $this->expectException(\DomainException::class);

        app(OldJewelleryBiddingService::class)->accept($invitation, 500);
    }

    public function test_accept_rejects_a_second_response_from_same_invitation(): void
    {
        [, , $invitation] = $this->makeRequestWithInvitation();

        app(OldJewelleryBiddingService::class)->accept($invitation, 500);

        $this->expectException(\DomainException::class);

        app(OldJewelleryBiddingService::class)->accept($invitation->fresh(), 600);
    }

    public function test_decline_marks_invitation_declined_with_reason(): void
    {
        [, , $invitation] = $this->makeRequestWithInvitation();

        app(OldJewelleryBiddingService::class)->decline($invitation, 'Too damaged');

        $invitation->refresh();
        $this->assertSame('declined', $invitation->response_status);
        $this->assertSame('Too damaged', $invitation->decline_reason);
        $this->assertDatabaseMissing('old_jewellery_bids', ['invitation_id' => $invitation->id]);
    }

    public function test_admin_bid_creates_a_bid_row(): void
    {
        [$request] = $this->makeRequestWithInvitation();
        $admin = User::factory()->create();

        $bid = app(OldJewelleryBiddingService::class)->submitAdminBid($request, $admin, 950);

        $this->assertSame('admin', $bid->bidder_type);
        $this->assertSame('950.00', $bid->amount);
        $this->assertSame($admin->id, $bid->admin_user_id);
    }

    public function test_admin_bid_rejected_after_deadline(): void
    {
        [$request] = $this->makeRequestWithInvitation(expired: true);
        $admin = User::factory()->create();

        $this->expectException(\DomainException::class);

        app(OldJewelleryBiddingService::class)->submitAdminBid($request, $admin, 950);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test tests/Feature/OldJewellery/OldJewelleryBiddingServiceTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the service**

`app/Services/OldJewellery/OldJewelleryBiddingService.php`:

```php
<?php

namespace App\Services\OldJewellery;

use App\Models\OldJewelleryActivityLog;
use App\Models\OldJewelleryBid;
use App\Models\OldJewelleryRequest;
use App\Models\OldJewelleryVendorInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Every mutating method here independently re-checks now() < bidding_end_at
 * — the scheduler-driven close job (Task 7) is a convenience, not the
 * source of truth for whether bidding is still open.
 */
class OldJewelleryBiddingService
{
    public function findInvitationByToken(string $plaintextToken): ?OldJewelleryVendorInvitation
    {
        $hash = app(VendorInvitationService::class)->hashToken($plaintextToken);

        return OldJewelleryVendorInvitation::where('token_hash', $hash)->first();
    }

    public function accept(OldJewelleryVendorInvitation $invitation, float $amount): OldJewelleryBid
    {
        if ($amount <= 0) {
            throw new \DomainException('Bid amount must be positive.');
        }

        return DB::transaction(function () use ($invitation, $amount) {
            $locked = OldJewelleryVendorInvitation::whereKey($invitation->id)->lockForUpdate()->first();

            if ($locked->response_status !== 'pending') {
                throw new \DomainException('This invitation has already been responded to.');
            }

            if (now()->greaterThanOrEqualTo($locked->expires_at)) {
                throw new \DomainException('The bidding window for this request has closed.');
            }

            $bid = OldJewelleryBid::create([
                'old_jewellery_request_id' => $locked->old_jewellery_request_id,
                'bidder_type' => 'vendor',
                'vendor_id' => $locked->vendor_id,
                'invitation_id' => $locked->id,
                'amount' => $amount,
                'submitted_at' => now(),
            ]);

            $locked->update([
                'response_status' => 'accepted',
                'responded_at' => now(),
            ]);

            OldJewelleryActivityLog::create([
                'old_jewellery_request_id' => $locked->old_jewellery_request_id,
                'actor_type' => 'vendor',
                'actor_id' => $locked->vendor_id,
                'action' => 'vendor_bid_submitted',
                'metadata' => ['amount' => $amount],
            ]);

            return $bid;
        });
    }

    public function decline(OldJewelleryVendorInvitation $invitation, ?string $reason): OldJewelleryVendorInvitation
    {
        return DB::transaction(function () use ($invitation, $reason) {
            $locked = OldJewelleryVendorInvitation::whereKey($invitation->id)->lockForUpdate()->first();

            if ($locked->response_status !== 'pending') {
                throw new \DomainException('This invitation has already been responded to.');
            }

            if (now()->greaterThanOrEqualTo($locked->expires_at)) {
                throw new \DomainException('The bidding window for this request has closed.');
            }

            $locked->update([
                'response_status' => 'declined',
                'decline_reason' => $reason,
                'responded_at' => now(),
            ]);

            OldJewelleryActivityLog::create([
                'old_jewellery_request_id' => $locked->old_jewellery_request_id,
                'actor_type' => 'vendor',
                'actor_id' => $locked->vendor_id,
                'action' => 'vendor_declined',
                'metadata' => ['reason' => $reason],
            ]);

            return $locked;
        });
    }

    public function submitAdminBid(OldJewelleryRequest $request, User $admin, float $amount): OldJewelleryBid
    {
        if ($amount <= 0) {
            throw new \DomainException('Bid amount must be positive.');
        }

        return DB::transaction(function () use ($request, $admin, $amount) {
            $locked = OldJewelleryRequest::whereKey($request->id)->lockForUpdate()->first();

            if (now()->greaterThanOrEqualTo($locked->bidding_end_at)) {
                throw new \DomainException('The bidding window for this request has closed.');
            }

            $bid = OldJewelleryBid::updateOrCreate(
                [
                    'old_jewellery_request_id' => $locked->id,
                    'bidder_type' => 'admin',
                    'admin_user_id' => $admin->id,
                ],
                [
                    'amount' => $amount,
                    'submitted_at' => now(),
                ],
            );

            OldJewelleryActivityLog::create([
                'old_jewellery_request_id' => $locked->id,
                'actor_type' => 'admin',
                'actor_id' => $admin->id,
                'action' => 'admin_bid_submitted',
                'metadata' => ['amount' => $amount],
            ]);

            return $bid;
        });
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd backend && php artisan test tests/Feature/OldJewellery/OldJewelleryBiddingServiceTest.php`
Expected: PASS (9 tests).

- [ ] **Step 5: Commit**

```bash
cd backend
git add app/Services/OldJewellery/OldJewelleryBiddingService.php tests/Feature/OldJewellery/OldJewelleryBiddingServiceTest.php
git commit -m "feat: add OldJewelleryBiddingService with deadline-safe vendor/admin bids"
```

---

## Task 6: `OldJewelleryWalletService` — 10%/90% split, idempotent wallet credit

**Files:**
- Create: `app/Services/OldJewellery/OldJewelleryWalletService.php`
- Test: `tests/Feature/OldJewellery/OldJewelleryWalletServiceTest.php`

**Interfaces:**
- Consumes: `App\Services\WalletService::credit()` (existing), `OldJewelleryWalletCredit` (Task 2), `OldJewelleryRequest` (Task 2).
- Produces: `OldJewelleryWalletService::creditForRequest(OldJewelleryRequest $request): ?OldJewelleryWalletCredit`. Precondition: `$request->status` must be `bid_selected` and `$request->final_amount` must be set — otherwise returns `null` without side effects (used by the closing flow, Task 7, right after bid selection). Idempotent: if an `OldJewelleryWalletCredit` row already exists for the request, returns that existing row and does nothing else (no second `WalletService::credit()` call, no second activity log entry for the crediting step).

- [ ] **Step 1: Write the failing test**

`tests/Feature/OldJewellery/OldJewelleryWalletServiceTest.php`:

```php
<?php

namespace Tests\Feature\OldJewellery;

use App\Models\OldJewelleryBid;
use App\Models\OldJewelleryRequest;
use App\Models\User;
use App\Services\OldJewellery\OldJewelleryWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OldJewelleryWalletServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeSelectedRequest(float $finalAmount): OldJewelleryRequest
    {
        $user = User::factory()->create(['wallet_balance' => 0]);

        $request = OldJewelleryRequest::create([
            'user_id' => $user->id,
            'request_number' => 'OJ-2026-000030',
            'status' => 'bidding_closed',
            'bidding_start_at' => now()->subHours(3),
            'bidding_end_at' => now(),
        ]);

        $bid = OldJewelleryBid::create([
            'old_jewellery_request_id' => $request->id,
            'bidder_type' => 'vendor',
            'amount' => $finalAmount,
            'submitted_at' => now(),
        ]);

        $request->update([
            'winning_bid_id' => $bid->id,
            'final_amount' => $finalAmount,
            'status' => 'bid_selected',
        ]);

        return $request->fresh();
    }

    public function test_credits_ninety_percent_and_deducts_ten_percent(): void
    {
        $request = $this->makeSelectedRequest(1100.00);

        $credit = app(OldJewelleryWalletService::class)->creditForRequest($request);

        $this->assertSame('110.00', $credit->deduction_amount);
        $this->assertSame('990.00', $credit->credited_amount);
        $this->assertSame('1100.00', $credit->gross_amount);
        $this->assertSame('990.00', $request->user->fresh()->wallet_balance);
        $this->assertSame('wallet_credited', $request->fresh()->status);
    }

    public function test_deduction_and_credit_always_sum_to_gross(): void
    {
        // 33.33 is a classic rounding trap for a 10%/90% split.
        $request = $this->makeSelectedRequest(33.33);

        $credit = app(OldJewelleryWalletService::class)->creditForRequest($request);

        $this->assertEqualsWithDelta(
            (float) $credit->gross_amount,
            (float) $credit->deduction_amount + (float) $credit->credited_amount,
            0.0001,
        );
    }

    public function test_expiry_is_exactly_ten_days_from_credit(): void
    {
        $request = $this->makeSelectedRequest(1000);

        $credit = app(OldJewelleryWalletService::class)->creditForRequest($request);

        $this->assertSame(
            $credit->credited_at->copy()->addDays(10)->toDateString(),
            $credit->expires_at->toDateString(),
        );
    }

    public function test_calling_twice_does_not_double_credit(): void
    {
        $request = $this->makeSelectedRequest(1000);
        $service = app(OldJewelleryWalletService::class);

        $service->creditForRequest($request);
        $service->creditForRequest($request->fresh());

        $this->assertSame('900.00', $request->user->fresh()->wallet_balance);
        $this->assertSame(1, \App\Models\OldJewelleryWalletCredit::where('old_jewellery_request_id', $request->id)->count());
    }

    public function test_returns_null_when_no_final_amount_set(): void
    {
        $user = User::factory()->create();
        $request = OldJewelleryRequest::create([
            'user_id' => $user->id,
            'request_number' => 'OJ-2026-000031',
            'status' => 'bidding_closed',
        ]);

        $result = app(OldJewelleryWalletService::class)->creditForRequest($request);

        $this->assertNull($result);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test tests/Feature/OldJewellery/OldJewelleryWalletServiceTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the service**

`app/Services/OldJewellery/OldJewelleryWalletService.php`:

```php
<?php

namespace App\Services\OldJewellery;

use App\Models\OldJewelleryActivityLog;
use App\Models\OldJewelleryRequest;
use App\Models\OldJewelleryWalletCredit;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;

class OldJewelleryWalletService
{
    private const DEDUCTION_RATE = 0.10;

    private const WALLET_VALIDITY_DAYS = 10;

    public function __construct(private readonly WalletService $walletService) {}

    /**
     * Idempotency: an existing OldJewelleryWalletCredit row for this request
     * means crediting already happened — return it as-is rather than
     * crediting a second time. This makes the whole operation safe to call
     * twice (e.g. an overlapping/delayed scheduler run) by construction,
     * not by a secondary "already processed" flag alone.
     */
    public function creditForRequest(OldJewelleryRequest $request): ?OldJewelleryWalletCredit
    {
        $existing = OldJewelleryWalletCredit::where('old_jewellery_request_id', $request->id)->first();
        if ($existing) {
            return $existing;
        }

        if ($request->status !== 'bid_selected' || $request->final_amount === null) {
            return null;
        }

        return DB::transaction(function () use ($request) {
            $locked = OldJewelleryRequest::whereKey($request->id)->lockForUpdate()->first();

            // Re-check inside the lock: another process may have credited
            // (or moved the status) between the check above and this point.
            $existing = OldJewelleryWalletCredit::where('old_jewellery_request_id', $locked->id)->first();
            if ($existing) {
                return $existing;
            }

            if ($locked->status !== 'bid_selected' || $locked->final_amount === null) {
                return null;
            }

            $gross = (float) $locked->final_amount;
            $deduction = round($gross * self::DEDUCTION_RATE, 2);
            $credited = round($gross - $deduction, 2);

            $locked->update(['status' => 'wallet_pending']);

            $walletTransaction = $this->walletService->credit(
                $locked->user,
                $credited,
                'old_jewellery_sale',
                $locked,
            );

            $now = now();

            $credit = OldJewelleryWalletCredit::create([
                'user_id' => $locked->user_id,
                'old_jewellery_request_id' => $locked->id,
                'wallet_transaction_id' => $walletTransaction->id,
                'gross_amount' => $gross,
                'deduction_amount' => $deduction,
                'credited_amount' => $credited,
                'remaining_amount' => $credited,
                'credited_at' => $now,
                'expires_at' => $now->copy()->addDays(self::WALLET_VALIDITY_DAYS),
                'status' => 'active',
            ]);

            $locked->update([
                'status' => 'wallet_credited',
                'deduction_amount' => $deduction,
                'credited_amount' => $credited,
            ]);

            OldJewelleryActivityLog::create([
                'old_jewellery_request_id' => $locked->id,
                'actor_type' => 'system',
                'action' => 'wallet_credited',
                'from_status' => 'wallet_pending',
                'to_status' => 'wallet_credited',
                'metadata' => [
                    'gross_amount' => $gross,
                    'deduction_amount' => $deduction,
                    'credited_amount' => $credited,
                    'expires_at' => $credit->expires_at->toIso8601String(),
                ],
            ]);

            return $credit;
        });
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd backend && php artisan test tests/Feature/OldJewellery/OldJewelleryWalletServiceTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
cd backend
git add app/Services/OldJewellery/OldJewelleryWalletService.php tests/Feature/OldJewellery/OldJewelleryWalletServiceTest.php
git commit -m "feat: add OldJewelleryWalletService with idempotent 10%/90% split"
```

---

## Task 7: `OldJewelleryClosingService` — highest-bid selection, tie-break, idempotent close

**Files:**
- Create: `app/Services/OldJewellery/OldJewelleryClosingService.php`
- Test: `tests/Feature/OldJewellery/OldJewelleryClosingServiceTest.php`

**Interfaces:**
- Consumes: `OldJewelleryBid`, `OldJewelleryRequest`, `OldJewelleryActivityLog`, `OldJewelleryWalletService::creditForRequest()` (Task 6).
- Produces: `OldJewelleryClosingService::close(OldJewelleryRequest $request): OldJewelleryRequest`. Behavior:
  - No-op (returns `$request` unchanged) if `status !== 'bidding_active'` or `now() < bidding_end_at` — this is what makes a duplicate/overlapping scheduler run safe: the second call finds the row no longer matching and does nothing.
  - Selects all `is_valid = true` bids for the request (vendor-accepted + admin), picks max `amount`; ties broken by earliest `submitted_at`.
  - No valid bids → status `bidding_active → bidding_closed → cancelled`, logs `no_valid_bids`, no wallet action.
  - Valid bid found → sets `winning_bid_id`/`final_amount`, moves `bidding_active → bidding_closed → bid_selected`, logs `bidding_closed` and `bid_selected`, then calls `OldJewelleryWalletService::creditForRequest()`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/OldJewellery/OldJewelleryClosingServiceTest.php`:

```php
<?php

namespace Tests\Feature\OldJewellery;

use App\Models\OldJewelleryBid;
use App\Models\OldJewelleryRequest;
use App\Models\User;
use App\Services\OldJewellery\OldJewelleryClosingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OldJewelleryClosingServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeActiveRequest(): OldJewelleryRequest
    {
        return OldJewelleryRequest::create([
            'user_id' => User::factory()->create(['wallet_balance' => 0])->id,
            'request_number' => 'OJ-2026-000040',
            'status' => 'bidding_active',
            'bidding_start_at' => now()->subHours(3),
            'bidding_end_at' => now()->subMinute(),
        ]);
    }

    public function test_selects_highest_bid_and_credits_wallet(): void
    {
        $request = $this->makeActiveRequest();

        OldJewelleryBid::create([
            'old_jewellery_request_id' => $request->id,
            'bidder_type' => 'vendor',
            'amount' => 800,
            'submitted_at' => now()->subMinutes(30),
        ]);
        $winning = OldJewelleryBid::create([
            'old_jewellery_request_id' => $request->id,
            'bidder_type' => 'admin',
            'amount' => 950,
            'submitted_at' => now()->subMinutes(20),
        ]);

        $result = app(OldJewelleryClosingService::class)->close($request);

        $this->assertSame('wallet_credited', $result->status);
        $this->assertSame($winning->id, $result->winning_bid_id);
        $this->assertSame('950.00', $result->final_amount);
        $this->assertSame('855.00', $request->user->fresh()->wallet_balance);
    }

    public function test_ties_broken_by_earliest_submission(): void
    {
        $request = $this->makeActiveRequest();

        $earlier = OldJewelleryBid::create([
            'old_jewellery_request_id' => $request->id,
            'bidder_type' => 'vendor',
            'amount' => 900,
            'submitted_at' => now()->subMinutes(30),
        ]);
        OldJewelleryBid::create([
            'old_jewellery_request_id' => $request->id,
            'bidder_type' => 'admin',
            'amount' => 900,
            'submitted_at' => now()->subMinutes(10),
        ]);

        $result = app(OldJewelleryClosingService::class)->close($request);

        $this->assertSame($earlier->id, $result->winning_bid_id);
    }

    public function test_no_valid_bids_cancels_without_wallet_action(): void
    {
        $request = $this->makeActiveRequest();

        $result = app(OldJewelleryClosingService::class)->close($request);

        $this->assertSame('cancelled', $result->status);
        $this->assertNull($result->winning_bid_id);
        $this->assertSame('0.00', $request->user->fresh()->wallet_balance);
        $this->assertDatabaseHas('old_jewellery_activity_logs', [
            'old_jewellery_request_id' => $request->id,
            'action' => 'no_valid_bids',
        ]);
    }

    public function test_ignores_invalid_bids(): void
    {
        $request = $this->makeActiveRequest();

        OldJewelleryBid::create([
            'old_jewellery_request_id' => $request->id,
            'bidder_type' => 'vendor',
            'amount' => 5000,
            'is_valid' => false,
            'submitted_at' => now()->subMinutes(30),
        ]);
        $valid = OldJewelleryBid::create([
            'old_jewellery_request_id' => $request->id,
            'bidder_type' => 'vendor',
            'amount' => 500,
            'submitted_at' => now()->subMinutes(20),
        ]);

        $result = app(OldJewelleryClosingService::class)->close($request);

        $this->assertSame($valid->id, $result->winning_bid_id);
    }

    public function test_closing_twice_does_not_credit_wallet_twice(): void
    {
        $request = $this->makeActiveRequest();

        OldJewelleryBid::create([
            'old_jewellery_request_id' => $request->id,
            'bidder_type' => 'vendor',
            'amount' => 1000,
            'submitted_at' => now()->subMinutes(20),
        ]);

        $service = app(OldJewelleryClosingService::class);
        $service->close($request);
        $service->close($request->fresh());

        $this->assertSame('900.00', $request->user->fresh()->wallet_balance);
    }

    public function test_does_nothing_if_bidding_window_still_open(): void
    {
        $request = OldJewelleryRequest::create([
            'user_id' => User::factory()->create()->id,
            'request_number' => 'OJ-2026-000041',
            'status' => 'bidding_active',
            'bidding_start_at' => now(),
            'bidding_end_at' => now()->addHours(3),
        ]);

        $result = app(OldJewelleryClosingService::class)->close($request);

        $this->assertSame('bidding_active', $result->status);
    }

    public function test_does_nothing_if_status_is_not_bidding_active(): void
    {
        $request = OldJewelleryRequest::create([
            'user_id' => User::factory()->create()->id,
            'request_number' => 'OJ-2026-000042',
            'status' => 'submitted',
            'bidding_start_at' => now()->subHours(3),
            'bidding_end_at' => now()->subMinute(),
        ]);

        $result = app(OldJewelleryClosingService::class)->close($request);

        $this->assertSame('submitted', $result->status);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test tests/Feature/OldJewellery/OldJewelleryClosingServiceTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the service**

`app/Services/OldJewellery/OldJewelleryClosingService.php`:

```php
<?php

namespace App\Services\OldJewellery;

use App\Models\OldJewelleryActivityLog;
use App\Models\OldJewelleryBid;
use App\Models\OldJewelleryRequest;
use Illuminate\Support\Facades\DB;

class OldJewelleryClosingService
{
    public function __construct(private readonly OldJewelleryWalletService $walletService) {}

    /**
     * Idempotent by construction: the lockForUpdate + status/deadline
     * re-check inside the transaction means a second concurrent or delayed
     * call always finds the row already moved past 'bidding_active' and
     * returns without any further effect — no separate "already closed"
     * flag is needed.
     */
    public function close(OldJewelleryRequest $request): OldJewelleryRequest
    {
        return DB::transaction(function () use ($request) {
            $locked = OldJewelleryRequest::whereKey($request->id)->lockForUpdate()->first();

            if ($locked->status !== 'bidding_active' || now()->lessThan($locked->bidding_end_at)) {
                return $locked;
            }

            $locked->update(['status' => 'bidding_closed']);

            OldJewelleryActivityLog::create([
                'old_jewellery_request_id' => $locked->id,
                'actor_type' => 'system',
                'action' => 'bidding_closed',
                'from_status' => 'bidding_active',
                'to_status' => 'bidding_closed',
            ]);

            $winner = OldJewelleryBid::where('old_jewellery_request_id', $locked->id)
                ->where('is_valid', true)
                ->orderByDesc('amount')
                ->orderBy('submitted_at')
                ->first();

            if (! $winner) {
                $locked->update(['status' => 'cancelled']);

                OldJewelleryActivityLog::create([
                    'old_jewellery_request_id' => $locked->id,
                    'actor_type' => 'system',
                    'action' => 'no_valid_bids',
                    'from_status' => 'bidding_closed',
                    'to_status' => 'cancelled',
                ]);

                return $locked->fresh();
            }

            $locked->update([
                'winning_bid_id' => $winner->id,
                'final_amount' => $winner->amount,
                'closed_at' => now(),
                'status' => 'bid_selected',
            ]);

            OldJewelleryActivityLog::create([
                'old_jewellery_request_id' => $locked->id,
                'actor_type' => 'system',
                'action' => 'bid_selected',
                'from_status' => 'bidding_closed',
                'to_status' => 'bid_selected',
                'metadata' => [
                    'winning_bid_id' => $winner->id,
                    'amount' => (string) $winner->amount,
                    'bidder_type' => $winner->bidder_type,
                ],
            ]);

            $this->walletService->creditForRequest($locked->fresh());

            return $locked->fresh();
        });
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd backend && php artisan test tests/Feature/OldJewellery/OldJewelleryClosingServiceTest.php`
Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
cd backend
git add app/Services/OldJewellery/OldJewelleryClosingService.php tests/Feature/OldJewellery/OldJewelleryClosingServiceTest.php
git commit -m "feat: add OldJewelleryClosingService with idempotent highest-bid selection"
```

---

## Task 8: Install Sanctum, add `api` guard, register `routes/api.php`, add `VendorTokenAuth` middleware

**Files:**
- Modify: `composer.json` (add `laravel/sanctum`)
- Create: `config/sanctum.php` (published by the Sanctum installer)
- Modify: `config/auth.php` (add `api` guard)
- Modify: `app/Models/User.php` (add `HasApiTokens` trait)
- Create: `database/migrations/2026_09_09_100007_create_personal_access_tokens_table.php` (published by Sanctum installer, or hand-written if the installer isn't runnable in this environment — write it by hand below to keep the task self-contained)
- Create: `routes/api.php`
- Modify: `bootstrap/app.php` (register `api:` routing key, add `api` middleware group with `EnsureFrontendRequestsAreStateful` is NOT needed here since this is token-only, not SPA-cookie auth — keep it plain `auth:sanctum`)
- Create: `app/Http/Middleware/VendorTokenAuth.php`
- Test: `tests/Feature/OldJewellery/VendorTokenAuthMiddlewareTest.php`

**Interfaces:**
- Produces: `auth:sanctum` guard usable on any route; `vendor.token` middleware alias resolving `{token}` route param into an `OldJewelleryVendorInvitation` bound to `$request->attributes->get('old_jewellery_invitation')`, returning a `403`/`404` JSON envelope (matching Task 9's response format) when the token is invalid/expired — later API controller tasks (Task 10) depend on this attribute name.

- [ ] **Step 1: Require Sanctum in composer.json**

Add to the `require` block of `composer.json` (alphabetically, matching existing `sort-packages: true`):

```json
"laravel/sanctum": "^4.0",
```

Run: `cd backend && composer require laravel/sanctum`
Expected: package installs, `config/sanctum.php` and a `create_personal_access_tokens_table` migration are published automatically by Sanctum's service provider discovery (Laravel auto-publishes on first `php artisan vendor:publish --tag=sanctum-config --tag=sanctum-migrations` — run that explicitly if the migration/config files are not present after `composer require`):

```bash
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
```

- [ ] **Step 2: Add the `api` guard to `config/auth.php`**

Modify the `guards` array in `config/auth.php` (currently only `web` per the audit) to add:

```php
'api' => [
    'driver' => 'sanctum',
    'provider' => 'users',
],
```

- [ ] **Step 3: Add `HasApiTokens` to `User`**

In `app/Models/User.php`, add the import `use Laravel\Sanctum\HasApiTokens;` and add `HasApiTokens` to the existing `use HasFactory, Notifiable, HasRoles;` trait list, making it `use HasApiTokens, HasFactory, Notifiable, HasRoles;`.

- [ ] **Step 4: Register `routes/api.php` in `bootstrap/app.php`**

Modify the `withRouting()` call in `bootstrap/app.php` to add an `api:` key:

```php
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
```

- [ ] **Step 5: Create a minimal `routes/api.php`**

```php
<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Old-jewellery and wallet routes are added in Task 10.
});
```

- [ ] **Step 6: Write the failing middleware test**

`tests/Feature/OldJewellery/VendorTokenAuthMiddlewareTest.php`:

```php
<?php

namespace Tests\Feature\OldJewellery;

use App\Models\OldJewelleryRequest;
use App\Models\OldJewelleryVendorInvitation;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class VendorTokenAuthMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router)
    {
        Route::middleware('vendor.token')->get('/__test/vendor-token/{token}', function () {
            $invitation = request()->attributes->get('old_jewellery_invitation');

            return response()->json(['invitation_id' => $invitation->id]);
        });
    }

    private function makeInvitation(bool $expired = false): array
    {
        $request = OldJewelleryRequest::create([
            'user_id' => User::factory()->create()->id,
            'request_number' => 'OJ-2026-000050',
            'status' => 'vendors_notified',
            'bidding_start_at' => now(),
            'bidding_end_at' => $expired ? now()->subMinute() : now()->addHours(3),
        ]);

        $vendor = Vendor::create(['name' => 'V', 'mobile' => '9000000050', 'is_active' => true]);

        $invitation = OldJewelleryVendorInvitation::create([
            'old_jewellery_request_id' => $request->id,
            'vendor_id' => $vendor->id,
            'token_hash' => hash('sha256', 'good-token'),
            'expires_at' => $request->bidding_end_at,
            'response_status' => 'pending',
        ]);

        return [$request, $vendor, $invitation];
    }

    public function test_valid_token_resolves_invitation(): void
    {
        [, , $invitation] = $this->makeInvitation();

        $response = $this->getJson('/__test/vendor-token/good-token');

        $response->assertOk()->assertJson(['invitation_id' => $invitation->id]);
    }

    public function test_invalid_token_is_rejected(): void
    {
        $this->makeInvitation();

        $response = $this->getJson('/__test/vendor-token/wrong-token');

        $response->assertStatus(404);
        $response->assertJson(['success' => false]);
    }

    public function test_expired_token_is_rejected(): void
    {
        $this->makeInvitation(expired: true);

        $response = $this->getJson('/__test/vendor-token/good-token');

        $response->assertStatus(403);
        $response->assertJson(['success' => false]);
    }
}
```

- [ ] **Step 7: Run test to verify it fails**

Run: `cd backend && php artisan test tests/Feature/OldJewellery/VendorTokenAuthMiddlewareTest.php`
Expected: FAIL — `vendor.token` middleware alias not defined.

- [ ] **Step 8: Write the middleware**

`app/Http/Middleware/VendorTokenAuth.php`:

```php
<?php

namespace App\Http\Middleware;

use App\Services\OldJewellery\OldJewelleryBiddingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the {token} route parameter into an OldJewelleryVendorInvitation
 * and rejects the request before it ever reaches a controller if the token
 * doesn't match any invitation or its bidding window has expired. Vendors
 * have no account/session — this middleware is their entire authorization
 * boundary, so it never relies on the URL alone: the token is checked
 * against a stored hash, not compared as a literal path segment.
 */
class VendorTokenAuth
{
    public function __construct(private readonly OldJewelleryBiddingService $biddingService) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->route('token');

        $invitation = $token ? $this->biddingService->findInvitationByToken($token) : null;

        if (! $invitation) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or unrecognized vendor link.',
            ], 404);
        }

        if (now()->greaterThanOrEqualTo($invitation->expires_at)) {
            return response()->json([
                'success' => false,
                'message' => 'This vendor link has expired.',
            ], 403);
        }

        $request->attributes->set('old_jewellery_invitation', $invitation);

        return $next($request);
    }
}
```

- [ ] **Step 9: Register the middleware alias**

Modify `bootstrap/app.php`'s `withMiddleware()` closure to add:

```php
$middleware->alias([
    'vendor.token' => \App\Http\Middleware\VendorTokenAuth::class,
]);
```

(Add this line inside the existing `function (Middleware $middleware): void { ... }` closure, alongside the existing `trustProxies`/`validateCsrfTokens`/`append`/`redirectGuestsTo` calls.)

- [ ] **Step 10: Run tests to verify they pass**

Run: `cd backend && php artisan test tests/Feature/OldJewellery/VendorTokenAuthMiddlewareTest.php`
Expected: PASS (3 tests).

- [ ] **Step 11: Run the full suite once to confirm Sanctum didn't break existing auth**

Run: `cd backend && php artisan test`
Expected: PASS — all pre-existing tests plus the new ones.

- [ ] **Step 12: Commit**

```bash
cd backend
git add composer.json composer.lock config/sanctum.php config/auth.php app/Models/User.php routes/api.php bootstrap/app.php app/Http/Middleware/VendorTokenAuth.php tests/Feature/OldJewellery/VendorTokenAuthMiddlewareTest.php database/migrations/*personal_access_tokens*.php
git commit -m "feat: install Sanctum, add api guard, vendor token auth middleware"
```

---

## Task 9: `ApiController` base, API Resources, Form Requests

**Files:**
- Create: `app/Http/Controllers/Api/ApiController.php`
- Create: `app/Http/Resources/OldJewelleryRequestResource.php`
- Create: `app/Http/Resources/OldJewelleryVendorViewResource.php` (vendor-facing — strips everything a vendor must not see)
- Create: `app/Http/Resources/OldJewelleryAdminResource.php` (admin-facing — includes all bids/vendor identities/admin bid)
- Create: `app/Http/Resources/WalletSummaryResource.php`
- Create: `app/Http/Resources/WalletTransactionResource.php`
- Create: `app/Http/Resources/OldJewelleryWalletCreditResource.php`
- Create: `app/Http/Requests/Api/StoreOldJewelleryRequestRequest.php`
- Create: `app/Http/Requests/Api/VendorAcceptBidRequest.php`
- Create: `app/Http/Requests/Api/VendorDeclineRequest.php`
- Create: `app/Http/Requests/Api/AdminSubmitBidRequest.php`
- Test: `tests/Feature/OldJewellery/ApiResourceVendorIsolationTest.php`

**Interfaces:**
- Produces: `ApiController::success(mixed $data, string $message = '', int $status = 200): JsonResponse` and `ApiController::error(string $message, array $errors = [], int $status = 422): JsonResponse` — the envelope every Task 10 controller returns through. `OldJewelleryVendorViewResource` never serializes `token_hash`, other vendors' bids, other vendor identities, or the admin bid — Task 10's vendor endpoints depend on this guarantee.

- [ ] **Step 1: Write the failing resource-isolation test first**

`tests/Feature/OldJewellery/ApiResourceVendorIsolationTest.php`:

```php
<?php

namespace Tests\Feature\OldJewellery;

use App\Http\Resources\OldJewelleryVendorViewResource;
use App\Models\OldJewelleryBid;
use App\Models\OldJewelleryRequest;
use App\Models\OldJewelleryVendorInvitation;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiResourceVendorIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendor_resource_never_exposes_other_bids_admin_bid_or_token_hash(): void
    {
        $request = OldJewelleryRequest::create([
            'user_id' => User::factory()->create()->id,
            'request_number' => 'OJ-2026-000060',
            'status' => 'vendors_notified',
            'bidding_start_at' => now(),
            'bidding_end_at' => now()->addHours(3),
        ]);

        $vendorA = Vendor::create(['name' => 'Vendor A', 'mobile' => '9000000060', 'is_active' => true]);
        $vendorB = Vendor::create(['name' => 'Vendor B', 'mobile' => '9000000061', 'is_active' => true]);

        $invitationA = OldJewelleryVendorInvitation::create([
            'old_jewellery_request_id' => $request->id,
            'vendor_id' => $vendorA->id,
            'token_hash' => hash('sha256', 'token-a'),
            'expires_at' => $request->bidding_end_at,
        ]);

        OldJewelleryBid::create([
            'old_jewellery_request_id' => $request->id,
            'bidder_type' => 'vendor',
            'vendor_id' => $vendorB->id,
            'amount' => 5000,
            'submitted_at' => now(),
        ]);
        OldJewelleryBid::create([
            'old_jewellery_request_id' => $request->id,
            'bidder_type' => 'admin',
            'admin_user_id' => User::factory()->create()->id,
            'amount' => 9999,
            'submitted_at' => now(),
        ]);

        $payload = (new OldJewelleryVendorViewResource($invitationA->load('request')))
            ->response()
            ->getData(true);

        $json = json_encode($payload);

        $this->assertStringNotContainsString('token_hash', $json);
        $this->assertStringNotContainsString('Vendor B', $json);
        $this->assertStringNotContainsString('5000', $json);
        $this->assertStringNotContainsString('9999', $json);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test tests/Feature/OldJewellery/ApiResourceVendorIsolationTest.php`
Expected: FAIL — class `OldJewelleryVendorViewResource` not found.

- [ ] **Step 3: Write `app/Http/Controllers/Api/ApiController.php`**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Base for every API controller — enforces the single response envelope
 * shape used across the whole API ({success, message, data} /
 * {success, message, errors}) so no endpoint drifts from it ad hoc.
 */
abstract class ApiController extends Controller
{
    protected function success(mixed $data = null, string $message = '', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    protected function error(string $message, array $errors = [], int $status = 422): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }
}
```

- [ ] **Step 4: Write `app/Http/Resources/OldJewelleryRequestResource.php` (customer-facing)**

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer-facing view of their own request. Never includes vendor
 * identities, individual bid amounts, or the admin bid — only the
 * customer's own request/status/final outcome.
 */
class OldJewelleryRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'request_number' => $this->request_number,
            'description' => $this->description,
            'status' => $this->status,
            'bidding_start_at' => $this->bidding_start_at?->toIso8601String(),
            'bidding_end_at' => $this->bidding_end_at?->toIso8601String(),
            'final_amount' => $this->final_amount,
            'deduction_amount' => $this->deduction_amount,
            'credited_amount' => $this->credited_amount,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'image_url' => $this->getFirstMediaUrl('image', 'thumb') ?: null,
            'has_video' => $this->hasMedia('video'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 5: Write `app/Http/Resources/OldJewelleryVendorViewResource.php`**

Wraps an `OldJewelleryVendorInvitation` (not the request directly), exposing only what that one vendor is allowed to see about the request it was invited to.

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Vendor-facing view, scoped to a single invitation. Deliberately excludes:
 * other vendors' names/bids, the admin bid, the customer's identity, and
 * the token hash. This is the only shape a vendor endpoint (Task 10) is
 * allowed to return.
 */
class OldJewelleryVendorViewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $ojRequest = $this->request;

        return [
            'request_number' => $ojRequest->request_number,
            'description' => $ojRequest->description,
            'bidding_end_at' => $ojRequest->bidding_end_at?->toIso8601String(),
            'response_status' => $this->response_status,
            'image_url' => $ojRequest->getFirstMediaUrl('image', 'thumb') ?: null,
            'video_url' => $ojRequest->hasMedia('video')
                ? URL::temporarySignedRoute(
                    'old-jewellery.vendor.video',
                    now()->addMinutes(30),
                    ['invitation' => $this->id],
                )
                : null,
        ];
    }
}
```

Add the missing `use Illuminate\Support\Facades\URL;` import to the top of the file (alongside the existing `use` statements).

- [ ] **Step 6: Write `app/Http/Resources/OldJewelleryAdminResource.php`**

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin-facing view — the only resource allowed to show all vendor
 * responses, all bid amounts, and the admin's own bid together in one
 * payload.
 */
class OldJewelleryAdminResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'request_number' => $this->request_number,
            'customer' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'phone' => $this->user->phone,
            ],
            'description' => $this->description,
            'status' => $this->status,
            'bidding_start_at' => $this->bidding_start_at?->toIso8601String(),
            'bidding_end_at' => $this->bidding_end_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'final_amount' => $this->final_amount,
            'deduction_amount' => $this->deduction_amount,
            'credited_amount' => $this->credited_amount,
            'winning_bid_id' => $this->winning_bid_id,
            'image_url' => $this->getFirstMediaUrl('image', 'thumb') ?: null,
            'video_url' => $this->hasMedia('video') ? $this->getFirstMediaUrl('video') : null,
            'invitations' => $this->whenLoaded('invitations', fn () => $this->invitations->map(fn ($invitation) => [
                'vendor_id' => $invitation->vendor_id,
                'vendor_name' => $invitation->vendor->name,
                'response_status' => $invitation->response_status,
                'decline_reason' => $invitation->decline_reason,
                'responded_at' => $invitation->responded_at?->toIso8601String(),
            ])),
            'bids' => $this->whenLoaded('bids', fn () => $this->bids->map(fn ($bid) => [
                'id' => $bid->id,
                'bidder_type' => $bid->bidder_type,
                'vendor_name' => $bid->vendor?->name,
                'admin_name' => $bid->adminUser?->name,
                'amount' => $bid->amount,
                'is_valid' => $bid->is_valid,
                'submitted_at' => $bid->submitted_at?->toIso8601String(),
            ])),
        ];
    }
}
```

- [ ] **Step 7: Write the wallet resources**

`app/Http/Resources/WalletSummaryResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WalletSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'balance' => $this->wallet_balance,
        ];
    }
}
```

`app/Http/Resources/WalletTransactionResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WalletTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->type,
            'amount' => $this->amount,
            'balance_after' => $this->balance_after,
            'reason' => $this->reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
```

`app/Http/Resources/OldJewelleryWalletCreditResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OldJewelleryWalletCreditResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'request_number' => $this->request->request_number,
            'gross_amount' => $this->gross_amount,
            'deduction_amount' => $this->deduction_amount,
            'credited_amount' => $this->credited_amount,
            'remaining_amount' => $this->remaining_amount,
            'credited_at' => $this->credited_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'status' => $this->status,
        ];
    }
}
```

- [ ] **Step 8: Write the Form Requests**

`app/Http/Requests/Api/StoreOldJewelleryRequestRequest.php`:

```php
<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreOldJewelleryRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'description' => ['nullable', 'string', 'max:2000'],
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
            'video' => ['required', 'file', 'mimes:mp4,mov,quicktime', 'max:20480'],
        ];
    }

    public function messages(): array
    {
        return [
            'video.required' => 'A video is required.',
        ];
    }
}
```

`app/Http/Requests/Api/VendorAcceptBidRequest.php`:

```php
<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class VendorAcceptBidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
```

`app/Http/Requests/Api/VendorDeclineRequest.php`:

```php
<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class VendorDeclineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
```

`app/Http/Requests/Api/AdminSubmitBidRequest.php`:

```php
<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class AdminSubmitBidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route-level permission middleware (Task 10) is the real gate
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
```

- [ ] **Step 9: Run tests to verify they pass**

Run: `cd backend && php artisan test tests/Feature/OldJewellery/ApiResourceVendorIsolationTest.php`
Expected: PASS (1 test).

- [ ] **Step 10: Commit**

```bash
cd backend
git add app/Http/Controllers/Api/ApiController.php app/Http/Resources/*.php app/Http/Requests/Api/*.php tests/Feature/OldJewellery/ApiResourceVendorIsolationTest.php
git commit -m "feat: add API response envelope, resources, and form requests"
```

---

## Task 10: `OldJewelleryRequestPolicy`, API controllers, and full `routes/api.php`

**Files:**
- Create: `app/Policies/OldJewelleryRequestPolicy.php`
- Modify: `app/Providers/AppServiceProvider.php` (register the policy — `Gate::policy(OldJewelleryRequest::class, OldJewelleryRequestPolicy::class)`, mirroring the existing `Gate::policy(User::class, CustomerPolicy::class)` line)
- Create: `app/Http/Controllers/Api/OldJewelleryRequestController.php`
- Create: `app/Http/Controllers/Api/WalletController.php`
- Create: `app/Http/Controllers/Api/VendorOldJewelleryController.php`
- Create: `app/Http/Controllers/Api/AdminOldJewelleryController.php`
- Create: `app/Http/Controllers/VendorMediaController.php` (serves the signed video URL from Task 9's resource)
- Modify: `routes/api.php`
- Test: `tests/Feature/OldJewellery/CustomerApiTest.php`
- Test: `tests/Feature/OldJewellery/VendorApiTest.php`
- Test: `tests/Feature/OldJewellery/AdminApiTest.php`
- Test: `tests/Feature/OldJewellery/WalletApiTest.php`

**Interfaces:**
- Consumes: `OldJewelleryRequestService::create()` (Task 3), `VendorInvitationService::inviteAll()` (Task 4), `OldJewelleryBiddingService` (Task 5), `OldJewelleryClosingService` (Task 7), `ApiController::success()/error()` (Task 9), all Resources (Task 9), `VendorTokenAuth` middleware (Task 8).
- Produces: the full route list from the spec, all responding with the `{success, message, data}`/`{success, message, errors}` envelope.

- [ ] **Step 1: Write `app/Policies/OldJewelleryRequestPolicy.php`**

```php
<?php

namespace App\Policies;

use App\Models\OldJewelleryRequest;
use App\Models\User;

class OldJewelleryRequestPolicy
{
    public function view(User $user, OldJewelleryRequest $oldJewelleryRequest): bool
    {
        return $user->id === $oldJewelleryRequest->user_id;
    }

    public function viewAny(User $user): bool
    {
        return true; // scoped to the user's own requests in the controller query
    }

    public function manageAsAdmin(User $user): bool
    {
        return $user->can('ViewAny:OldJewelleryRequest');
    }

    public function bidAsAdmin(User $user): bool
    {
        return $user->can('Update:OldJewelleryRequest');
    }
}
```

- [ ] **Step 2: Register the policy in `AppServiceProvider::boot()`**

Add the import `use App\Models\OldJewelleryRequest;` and `use App\Policies\OldJewelleryRequestPolicy;`, then add this line next to the existing `Gate::policy(User::class, CustomerPolicy::class);`:

```php
Gate::policy(OldJewelleryRequest::class, OldJewelleryRequestPolicy::class);
```

- [ ] **Step 3: Write `app/Http/Controllers/Api/OldJewelleryRequestController.php`**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\StoreOldJewelleryRequestRequest;
use App\Http\Resources\OldJewelleryRequestResource;
use App\Models\OldJewelleryRequest;
use App\Services\OldJewellery\OldJewelleryRequestService;
use App\Services\OldJewellery\VendorInvitationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class OldJewelleryRequestController extends ApiController
{
    public function __construct(
        private readonly OldJewelleryRequestService $requestService,
        private readonly VendorInvitationService $invitationService,
    ) {}

    public function store(StoreOldJewelleryRequestRequest $request)
    {
        try {
            $oldJewelleryRequest = $this->requestService->create(
                $request->user(),
                ['description' => $request->validated('description')],
                $request->file('image'),
                $request->file('video'),
            );
        } catch (ValidationException $e) {
            return $this->error('Validation failed.', $e->errors());
        }

        // Vendor invitations are created synchronously right after submission
        // (spec: "request created -> vendors notified" happens as part of the
        // same submission flow, not a separate manual admin step). Actual
        // notification SENDING is queued (Task 13) — invitation-row creation
        // itself stays synchronous and fast (no external I/O).
        $this->invitationService->inviteAll($oldJewelleryRequest->fresh());

        return $this->success(
            new OldJewelleryRequestResource($oldJewelleryRequest->fresh()),
            'Old jewellery request created successfully.',
            201,
        );
    }

    public function index(Request $request)
    {
        $requests = $request->user()
            ->oldJewelleryRequests()
            ->latest()
            ->paginate(15);

        return $this->success(OldJewelleryRequestResource::collection($requests));
    }

    public function show(Request $request, OldJewelleryRequest $oldJewelleryRequest)
    {
        Gate::authorize('view', $oldJewelleryRequest);

        return $this->success(new OldJewelleryRequestResource($oldJewelleryRequest));
    }

    public function status(Request $request, OldJewelleryRequest $oldJewelleryRequest)
    {
        Gate::authorize('view', $oldJewelleryRequest);

        return $this->success([
            'request_number' => $oldJewelleryRequest->request_number,
            'status' => $oldJewelleryRequest->status,
            'bidding_end_at' => $oldJewelleryRequest->bidding_end_at?->toIso8601String(),
        ]);
    }
}
```

- [ ] **Step 4: Add `oldJewelleryRequests()` relation to `User`**

Modify `app/Models/User.php`: add a `oldJewelleryRequests(): HasMany` relation method next to the existing `orders()`/`addresses()`/`walletTransactions()`/`rewardSubmissions()` relations:

```php
public function oldJewelleryRequests(): HasMany
{
    return $this->hasMany(OldJewelleryRequest::class);
}
```

Add `use App\Models\OldJewelleryRequest;` is not needed (same namespace), but ensure `HasMany` is already imported (it is, per the audited file).

- [ ] **Step 5: Write `app/Http/Controllers/Api/WalletController.php`**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\OldJewelleryWalletCreditResource;
use App\Http\Resources\WalletSummaryResource;
use App\Http\Resources\WalletTransactionResource;
use Illuminate\Http\Request;

class WalletController extends ApiController
{
    public function show(Request $request)
    {
        return $this->success(new WalletSummaryResource($request->user()));
    }

    public function transactions(Request $request)
    {
        $transactions = $request->user()->walletTransactions()->latest()->paginate(20);

        return $this->success(WalletTransactionResource::collection($transactions));
    }

    public function oldJewelleryCredits(Request $request)
    {
        $credits = $request->user()
            ->oldJewelleryWalletCredits()
            ->with('request')
            ->latest()
            ->paginate(20);

        return $this->success(OldJewelleryWalletCreditResource::collection($credits));
    }
}
```

Add the matching relation to `app/Models/User.php`:

```php
public function oldJewelleryWalletCredits(): HasMany
{
    return $this->hasMany(OldJewelleryWalletCredit::class);
}
```

- [ ] **Step 6: Write `app/Http/Controllers/Api/VendorOldJewelleryController.php`**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\VendorAcceptBidRequest;
use App\Http\Requests\Api\VendorDeclineRequest;
use App\Http\Resources\OldJewelleryVendorViewResource;
use App\Models\OldJewelleryVendorInvitation;
use App\Services\OldJewellery\OldJewelleryBiddingService;
use Illuminate\Http\Request;

class VendorOldJewelleryController extends ApiController
{
    public function __construct(private readonly OldJewelleryBiddingService $biddingService) {}

    public function show(Request $request)
    {
        $invitation = $this->invitation($request);

        return $this->success(new OldJewelleryVendorViewResource($invitation->load('request')));
    }

    public function accept(VendorAcceptBidRequest $request)
    {
        $invitation = $this->invitation($request);

        try {
            $this->biddingService->accept($invitation, (float) $request->validated('amount'));
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), [], 409);
        }

        return $this->success(
            new OldJewelleryVendorViewResource($invitation->fresh()->load('request')),
            'Bid submitted successfully.',
        );
    }

    public function decline(VendorDeclineRequest $request)
    {
        $invitation = $this->invitation($request);

        try {
            $this->biddingService->decline($invitation, $request->validated('reason'));
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), [], 409);
        }

        return $this->success(
            new OldJewelleryVendorViewResource($invitation->fresh()->load('request')),
            'Response recorded.',
        );
    }

    /**
     * VendorTokenAuth (Task 8) has already validated the token and stashed
     * the resolved invitation on the request — every action here trusts
     * only that attribute, never the raw {token} path segment.
     */
    private function invitation(Request $request): OldJewelleryVendorInvitation
    {
        return $request->attributes->get('old_jewellery_invitation');
    }
}
```

- [ ] **Step 7: Write `app/Http/Controllers/Api/AdminOldJewelleryController.php`**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\AdminSubmitBidRequest;
use App\Http\Resources\OldJewelleryAdminResource;
use App\Models\OldJewelleryRequest;
use App\Services\OldJewellery\OldJewelleryBiddingService;
use App\Services\OldJewellery\OldJewelleryClosingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AdminOldJewelleryController extends ApiController
{
    public function __construct(
        private readonly OldJewelleryBiddingService $biddingService,
        private readonly OldJewelleryClosingService $closingService,
    ) {}

    public function index(Request $request)
    {
        Gate::authorize('manageAsAdmin', OldJewelleryRequest::class);

        $requests = OldJewelleryRequest::with(['user'])->latest()->paginate(15);

        return $this->success(OldJewelleryAdminResource::collection($requests));
    }

    public function show(Request $request, OldJewelleryRequest $oldJewelleryRequest)
    {
        Gate::authorize('manageAsAdmin', OldJewelleryRequest::class);

        return $this->success(new OldJewelleryAdminResource(
            $oldJewelleryRequest->load(['user', 'invitations.vendor', 'bids.vendor', 'bids.adminUser']),
        ));
    }

    public function bids(Request $request, OldJewelleryRequest $oldJewelleryRequest)
    {
        Gate::authorize('manageAsAdmin', OldJewelleryRequest::class);

        return $this->success(new OldJewelleryAdminResource(
            $oldJewelleryRequest->load(['bids.vendor', 'bids.adminUser']),
        ));
    }

    public function bid(AdminSubmitBidRequest $request, OldJewelleryRequest $oldJewelleryRequest)
    {
        Gate::authorize('bidAsAdmin', OldJewelleryRequest::class);

        try {
            $this->biddingService->submitAdminBid(
                $oldJewelleryRequest,
                $request->user(),
                (float) $request->validated('amount'),
            );
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), [], 409);
        }

        return $this->success(
            new OldJewelleryAdminResource($oldJewelleryRequest->fresh()->load(['bids.vendor', 'bids.adminUser'])),
            'Bid submitted.',
        );
    }

    public function close(Request $request, OldJewelleryRequest $oldJewelleryRequest)
    {
        Gate::authorize('manageAsAdmin', OldJewelleryRequest::class);

        $result = $this->closingService->close($oldJewelleryRequest);

        return $this->success(new OldJewelleryAdminResource($result), 'Closing processed.');
    }
}
```

- [ ] **Step 8: Write `app/Http/Controllers/VendorMediaController.php` (signed-URL video streaming)**

This is a plain web controller (not under `Api/`) since it's reached via a signed URL, not Sanctum/token-header auth — the signature itself is the authorization.

```php
<?php

namespace App\Http\Controllers;

use App\Models\OldJewelleryVendorInvitation;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VendorMediaController extends Controller
{
    public function video(Request $request, OldJewelleryVendorInvitation $invitation): StreamedResponse
    {
        abort_unless($request->hasValidSignature(), 403);

        $media = $invitation->request->getFirstMedia('video');
        abort_unless($media, 404);

        return response()->streamDownload(function () use ($media) {
            echo $media->stream();
        }, $media->file_name, ['Content-Type' => $media->mime_type]);
    }
}
```

- [ ] **Step 9: Add the signed route for vendor video streaming to `routes/web.php`**

Add near the bottom of `routes/web.php` (outside any `auth` group — the signature is the auth):

```php
Route::get('/old-jewellery/vendor-video/{invitation}', [\App\Http\Controllers\VendorMediaController::class, 'video'])
    ->name('old-jewellery.vendor.video')
    ->middleware('signed');
```

- [ ] **Step 10: Write the complete `routes/api.php`**

Replace the placeholder from Task 8 with:

```php
<?php

use App\Http\Controllers\Api\AdminOldJewelleryController;
use App\Http\Controllers\Api\OldJewelleryRequestController;
use App\Http\Controllers\Api\VendorOldJewelleryController;
use App\Http\Controllers\Api\WalletController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/old-jewellery/requests', [OldJewelleryRequestController::class, 'store'])
            ->middleware('throttle:10,60');
        Route::get('/old-jewellery/requests', [OldJewelleryRequestController::class, 'index']);
        Route::get('/old-jewellery/requests/{oldJewelleryRequest:request_number}', [OldJewelleryRequestController::class, 'show']);
        Route::get('/old-jewellery/requests/{oldJewelleryRequest:request_number}/status', [OldJewelleryRequestController::class, 'status']);

        Route::get('/wallet', [WalletController::class, 'show']);
        Route::get('/wallet/transactions', [WalletController::class, 'transactions']);
        Route::get('/wallet/old-jewellery-credits', [WalletController::class, 'oldJewelleryCredits']);

        Route::prefix('admin/old-jewellery')->group(function () {
            Route::get('/', [AdminOldJewelleryController::class, 'index']);
            Route::get('/{oldJewelleryRequest:request_number}', [AdminOldJewelleryController::class, 'show']);
            Route::get('/{oldJewelleryRequest:request_number}/bids', [AdminOldJewelleryController::class, 'bids']);
            Route::post('/{oldJewelleryRequest:request_number}/bid', [AdminOldJewelleryController::class, 'bid']);
            Route::post('/{oldJewelleryRequest:request_number}/close', [AdminOldJewelleryController::class, 'close']);
        });
    });

    Route::prefix('vendor/old-jewellery/{token}')
        ->middleware(['vendor.token', 'throttle:30,1'])
        ->group(function () {
            Route::get('/', [VendorOldJewelleryController::class, 'show']);
            Route::post('/accept', [VendorOldJewelleryController::class, 'accept']);
            Route::post('/decline', [VendorOldJewelleryController::class, 'decline']);
        });
});
```

- [ ] **Step 11: Write `tests/Feature/OldJewellery/CustomerApiTest.php`**

```php
<?php

namespace Tests\Feature\OldJewellery;

use App\Models\OldJewelleryRequest;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CustomerApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_create_a_request_with_video_only(): void
    {
        Storage::fake('original_images');
        Storage::fake('public');

        $user = User::factory()->create();
        Vendor::create(['name' => 'V', 'mobile' => '9000000070', 'is_active' => true]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/old-jewellery/requests', [
            'description' => 'A bangle',
            'video' => UploadedFile::fake()->create('video.mp4', 5000, 'video/mp4'),
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'vendors_notified');

        $this->assertDatabaseCount('old_jewellery_vendor_invitations', 1);
    }

    public function test_video_is_required(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/old-jewellery/requests', [
            'description' => 'A bangle',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors('video');
    }

    public function test_image_over_3mb_is_rejected(): void
    {
        Storage::fake('original_images');

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/old-jewellery/requests', [
            'video' => UploadedFile::fake()->create('video.mp4', 5000, 'video/mp4'),
            'image' => UploadedFile::fake()->create('big.jpg', 4000, 'image/jpeg'),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('image');
    }

    public function test_video_over_20mb_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/old-jewellery/requests', [
            'video' => UploadedFile::fake()->create('video.mp4', 25000, 'video/mp4'),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('video');
    }

    public function test_customer_cannot_view_another_customers_request(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $request = OldJewelleryRequest::create([
            'user_id' => $owner->id,
            'request_number' => 'OJ-2026-000080',
            'status' => 'submitted',
        ]);

        $response = $this->actingAs($intruder, 'sanctum')
            ->getJson("/api/v1/old-jewellery/requests/{$request->request_number}");

        $response->assertStatus(403);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/old-jewellery/requests', []);

        $response->assertStatus(401);
    }
}
```

- [ ] **Step 12: Write `tests/Feature/OldJewellery/VendorApiTest.php`**

```php
<?php

namespace Tests\Feature\OldJewellery;

use App\Models\OldJewelleryRequest;
use App\Models\OldJewelleryVendorInvitation;
use App\Models\User;
use App\Models\Vendor;
use App\Services\OldJewellery\VendorInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeInvitation(bool $expired = false): array
    {
        $request = OldJewelleryRequest::create([
            'user_id' => User::factory()->create()->id,
            'request_number' => 'OJ-2026-000090',
            'status' => 'vendors_notified',
            'bidding_start_at' => now(),
            'bidding_end_at' => $expired ? now()->subMinute() : now()->addHours(3),
        ]);

        $vendor = Vendor::create(['name' => 'V', 'mobile' => '9000000090', 'is_active' => true]);

        $invitation = OldJewelleryVendorInvitation::create([
            'old_jewellery_request_id' => $request->id,
            'vendor_id' => $vendor->id,
            'token_hash' => app(VendorInvitationService::class)->hashToken('vendor-token'),
            'expires_at' => $request->bidding_end_at,
        ]);

        return [$request, $vendor, $invitation];
    }

    public function test_vendor_can_view_via_valid_token(): void
    {
        $this->makeInvitation();

        $response = $this->getJson('/api/v1/vendor/old-jewellery/vendor-token');

        $response->assertOk()->assertJsonPath('success', true);
    }

    public function test_vendor_can_accept_with_amount(): void
    {
        $this->makeInvitation();

        $response = $this->postJson('/api/v1/vendor/old-jewellery/vendor-token/accept', ['amount' => 800]);

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseHas('old_jewellery_bids', ['amount' => '800.00']);
    }

    public function test_vendor_cannot_accept_with_zero_amount(): void
    {
        $this->makeInvitation();

        $response = $this->postJson('/api/v1/vendor/old-jewellery/vendor-token/accept', ['amount' => 0]);

        $response->assertStatus(422);
    }

    public function test_vendor_can_decline(): void
    {
        $this->makeInvitation();

        $response = $this->postJson('/api/v1/vendor/old-jewellery/vendor-token/decline', ['reason' => 'Too worn']);

        $response->assertOk();
        $this->assertDatabaseHas('old_jewellery_vendor_invitations', ['response_status' => 'declined']);
    }

    public function test_expired_token_returns_403_on_accept(): void
    {
        $this->makeInvitation(expired: true);

        $response = $this->postJson('/api/v1/vendor/old-jewellery/vendor-token/accept', ['amount' => 800]);

        $response->assertStatus(403)->assertJsonPath('success', false);
    }

    public function test_invalid_token_returns_404(): void
    {
        $this->makeInvitation();

        $response = $this->getJson('/api/v1/vendor/old-jewellery/wrong-token');

        $response->assertStatus(404);
    }

    public function test_reused_token_after_accept_is_rejected(): void
    {
        $this->makeInvitation();

        $this->postJson('/api/v1/vendor/old-jewellery/vendor-token/accept', ['amount' => 800]);
        $response = $this->postJson('/api/v1/vendor/old-jewellery/vendor-token/accept', ['amount' => 900]);

        $response->assertStatus(409);
    }
}
```

- [ ] **Step 13: Write `tests/Feature/OldJewellery/AdminApiTest.php`**

```php
<?php

namespace Tests\Feature\OldJewellery;

use App\Models\OldJewelleryBid;
use App\Models\OldJewelleryRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $admin = User::factory()->create();
        Permission::firstOrCreate(['name' => 'ViewAny:OldJewelleryRequest', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'Update:OldJewelleryRequest', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $role->givePermissionTo(['ViewAny:OldJewelleryRequest', 'Update:OldJewelleryRequest']);
        $admin->assignRole($role);

        return $admin;
    }

    public function test_admin_can_submit_a_bid(): void
    {
        $admin = $this->makeAdmin();

        $request = OldJewelleryRequest::create([
            'user_id' => User::factory()->create()->id,
            'request_number' => 'OJ-2026-000100',
            'status' => 'vendors_notified',
            'bidding_start_at' => now(),
            'bidding_end_at' => now()->addHours(3),
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/old-jewellery/{$request->request_number}/bid", ['amount' => 950]);

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseHas('old_jewellery_bids', ['bidder_type' => 'admin', 'amount' => '950.00']);
    }

    public function test_non_admin_is_forbidden(): void
    {
        $user = User::factory()->create();

        $request = OldJewelleryRequest::create([
            'user_id' => User::factory()->create()->id,
            'request_number' => 'OJ-2026-000101',
            'status' => 'vendors_notified',
            'bidding_start_at' => now(),
            'bidding_end_at' => now()->addHours(3),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/admin/old-jewellery/{$request->request_number}/bid", ['amount' => 950]);

        $response->assertStatus(403);
    }

    public function test_admin_close_selects_highest_bid(): void
    {
        $admin = $this->makeAdmin();

        $request = OldJewelleryRequest::create([
            'user_id' => User::factory()->create(['wallet_balance' => 0])->id,
            'request_number' => 'OJ-2026-000102',
            'status' => 'bidding_active',
            'bidding_start_at' => now()->subHours(3),
            'bidding_end_at' => now()->subMinute(),
        ]);

        OldJewelleryBid::create([
            'old_jewellery_request_id' => $request->id,
            'bidder_type' => 'vendor',
            'amount' => 700,
            'submitted_at' => now()->subMinutes(10),
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/old-jewellery/{$request->request_number}/close");

        $response->assertOk()->assertJsonPath('data.status', 'wallet_credited');
    }

    public function test_admin_bid_after_deadline_returns_409(): void
    {
        $admin = $this->makeAdmin();

        $request = OldJewelleryRequest::create([
            'user_id' => User::factory()->create()->id,
            'request_number' => 'OJ-2026-000103',
            'status' => 'bidding_active',
            'bidding_start_at' => now()->subHours(4),
            'bidding_end_at' => now()->subHour(),
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/old-jewellery/{$request->request_number}/bid", ['amount' => 950]);

        $response->assertStatus(409);
    }
}
```

- [ ] **Step 14: Write `tests/Feature/OldJewellery/WalletApiTest.php`**

```php
<?php

namespace Tests\Feature\OldJewellery;

use App\Models\OldJewelleryBid;
use App\Models\OldJewelleryRequest;
use App\Models\User;
use App\Services\OldJewellery\OldJewelleryWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_wallet_summary_returns_balance(): void
    {
        $user = User::factory()->create(['wallet_balance' => 250.50]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/wallet');

        $response->assertOk()->assertJsonPath('data.balance', '250.50');
    }

    public function test_old_jewellery_credits_endpoint_lists_credits(): void
    {
        $user = User::factory()->create(['wallet_balance' => 0]);

        $request = OldJewelleryRequest::create([
            'user_id' => $user->id,
            'request_number' => 'OJ-2026-000110',
            'status' => 'bidding_closed',
            'bidding_start_at' => now()->subHours(3),
            'bidding_end_at' => now(),
        ]);
        $bid = OldJewelleryBid::create([
            'old_jewellery_request_id' => $request->id,
            'bidder_type' => 'vendor',
            'amount' => 1000,
            'submitted_at' => now(),
        ]);
        $request->update(['winning_bid_id' => $bid->id, 'final_amount' => 1000, 'status' => 'bid_selected']);
        app(OldJewelleryWalletService::class)->creditForRequest($request->fresh());

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/wallet/old-jewellery-credits');

        $response->assertOk()->assertJsonPath('data.0.credited_amount', '900.00');
    }
}
```

- [ ] **Step 15: Run the full new test suite**

Run: `cd backend && php artisan test tests/Feature/OldJewellery`
Expected: PASS — all tests across all `OldJewellery` feature tests so far.

- [ ] **Step 16: Run the entire project test suite to confirm no regression**

Run: `cd backend && php artisan test`
Expected: PASS — every pre-existing test plus all new ones.

- [ ] **Step 17: Commit**

```bash
cd backend
git add app/Policies/OldJewelleryRequestPolicy.php app/Providers/AppServiceProvider.php app/Http/Controllers/Api/*.php app/Http/Controllers/VendorMediaController.php app/Models/User.php routes/api.php routes/web.php tests/Feature/OldJewellery/CustomerApiTest.php tests/Feature/OldJewellery/VendorApiTest.php tests/Feature/OldJewellery/AdminApiTest.php tests/Feature/OldJewellery/WalletApiTest.php
git commit -m "feat: add old-jewellery/vendor/admin/wallet REST API and policy"
```

---

## Task 11: WhatsApp stub + Notifications (vendor invite, admin alert, customer finalized)

**Files:**
- Create: `app/Services/WhatsApp/WhatsAppGateway.php` (interface)
- Create: `app/Services/WhatsApp/LogWhatsAppGateway.php` (stub, mirrors `LogOtpGateway`)
- Create: `app/Notifications/Channels/WhatsAppChannel.php`
- Create: `app/Notifications/VendorInvitedToBid.php`
- Create: `app/Notifications/AdminNewOldJewelleryRequest.php`
- Create: `app/Notifications/CustomerOldJewelleryFinalized.php`
- Modify: `config/services.php` (add `whatsapp` block, mirroring `vas_sms`)
- Modify: `.env.example` (add `WHATSAPP_*` vars)
- Modify: `app/Services/OldJewellery/VendorInvitationService.php` (dispatch `VendorInvitedToBid` per vendor + `AdminNewOldJewelleryRequest` once)
- Modify: `app/Services/OldJewellery/OldJewelleryWalletService.php` (dispatch `CustomerOldJewelleryFinalized` after crediting)
- Test: `tests/Feature/OldJewellery/NotificationDispatchTest.php`

**Interfaces:**
- Consumes: `OldJewelleryVendorInvitation`, `OldJewelleryRequest`, `OldJewelleryWalletCredit` (Task 2), `Vendor` (Task 2).
- Produces: `WhatsAppGateway::send(string $to, string $message): void` interface; `LogWhatsAppGateway` binds to it in `AppServiceProvider` when no `WHATSAPP_PROVIDER` env var is set (always, for now — no real provider exists yet per spec, this is the permanent-until-credentials-exist state). All three Notification classes implement `ShouldQueue` and declare `via()` returning `['mail', WhatsAppChannel::class]` only when a recipient has a phone/WhatsApp number, `['mail']` otherwise.

- [ ] **Step 1: Write the failing test**

`tests/Feature/OldJewellery/NotificationDispatchTest.php`:

```php
<?php

namespace Tests\Feature\OldJewellery;

use App\Models\OldJewelleryRequest;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\CustomerOldJewelleryFinalized;
use App\Notifications\VendorInvitedToBid;
use App\Services\OldJewellery\OldJewelleryWalletService;
use App\Services\OldJewellery\VendorInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_inviting_vendors_notifies_each_one(): void
    {
        Notification::fake();

        $request = OldJewelleryRequest::create([
            'user_id' => User::factory()->create()->id,
            'request_number' => 'OJ-2026-000120',
            'status' => 'submitted',
            'bidding_start_at' => now(),
            'bidding_end_at' => now()->addHours(3),
        ]);
        $vendor = Vendor::create(['name' => 'V', 'mobile' => '9000000120', 'email' => 'v@example.com', 'is_active' => true]);

        app(VendorInvitationService::class)->inviteAll($request);

        Notification::assertSentOnDemand(VendorInvitedToBid::class);
    }

    public function test_wallet_credit_notifies_customer(): void
    {
        Notification::fake();

        $user = User::factory()->create(['wallet_balance' => 0]);
        $request = OldJewelleryRequest::create([
            'user_id' => $user->id,
            'request_number' => 'OJ-2026-000121',
            'status' => 'bidding_closed',
            'bidding_start_at' => now()->subHours(3),
            'bidding_end_at' => now(),
        ]);
        $bid = \App\Models\OldJewelleryBid::create([
            'old_jewellery_request_id' => $request->id,
            'bidder_type' => 'vendor',
            'amount' => 1000,
            'submitted_at' => now(),
        ]);
        $request->update(['winning_bid_id' => $bid->id, 'final_amount' => 1000, 'status' => 'bid_selected']);

        app(OldJewelleryWalletService::class)->creditForRequest($request->fresh());

        Notification::assertSentTo($user, CustomerOldJewelleryFinalized::class);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test tests/Feature/OldJewellery/NotificationDispatchTest.php`
Expected: FAIL — notification classes not found.

- [ ] **Step 3: Write the WhatsApp gateway interface + stub**

`app/Services/WhatsApp/WhatsAppGateway.php`:

```php
<?php

namespace App\Services\WhatsApp;

interface WhatsAppGateway
{
    /**
     * Send a WhatsApp message to a phone number. Implementations decide the
     * transport (WhatsApp Business API provider, log, etc.) — callers never
     * see it.
     */
    public function send(string $to, string $message): void;
}
```

`app/Services/WhatsApp/LogWhatsAppGateway.php`:

```php
<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Log;

/**
 * Default WhatsApp gateway: no WhatsApp Business API provider is configured
 * yet (WHATSAPP_PROVIDER is unset), so messages are logged instead of sent —
 * same "ready but inactive until configured" posture as LogOtpGateway. Swap
 * in a real provider later by adding one class implementing WhatsAppGateway
 * and rebinding it in AppServiceProvider — no caller changes needed.
 */
class LogWhatsAppGateway implements WhatsAppGateway
{
    public function send(string $to, string $message): void
    {
        Log::info('WhatsApp message requested — no provider configured, logging instead of sending', [
            'to' => $to,
            'message' => $message,
        ]);
    }
}
```

- [ ] **Step 4: Bind the gateway in `AppServiceProvider::register()`**

Modify `app/Providers/AppServiceProvider.php`'s `register()` method (currently empty `//`):

```php
public function register(): void
{
    $this->app->bind(
        \App\Services\WhatsApp\WhatsAppGateway::class,
        \App\Services\WhatsApp\LogWhatsAppGateway::class,
    );
}
```

(A real provider is wired in later by changing only this binding — see spec §3/§11 and `.env.example` documentation in Step 9 below.)

- [ ] **Step 5: Write `app/Notifications/Channels/WhatsAppChannel.php`**

```php
<?php

namespace App\Notifications\Channels;

use App\Services\WhatsApp\WhatsAppGateway;
use Illuminate\Notifications\Notification;

class WhatsAppChannel
{
    public function __construct(private readonly WhatsAppGateway $gateway) {}

    public function send(mixed $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toWhatsApp')) {
            return;
        }

        $to = $notifiable->routeNotificationFor('whatsapp', $notification)
            ?? $notifiable->whatsapp_number
            ?? $notifiable->phone
            ?? null;

        if (! $to) {
            return;
        }

        $message = $notification->toWhatsApp($notifiable);

        $this->gateway->send($to, $message);
    }
}
```

- [ ] **Step 6: Write the three Notification classes**

`app/Notifications/VendorInvitedToBid.php`:

```php
<?php

namespace App\Notifications;

use App\Models\OldJewelleryVendorInvitation;
use App\Notifications\Channels\WhatsAppChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VendorInvitedToBid extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly OldJewelleryVendorInvitation $invitation,
        public readonly string $plaintextToken,
    ) {}

    public function via(object $notifiable): array
    {
        $channels = ['mail'];

        if (filled($notifiable->whatsapp_number)) {
            $channels[] = WhatsAppChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->invitation->request;

        return (new MailMessage)
            ->subject("New old jewellery request {$request->request_number} — respond within 3 hours")
            ->greeting("Hello {$notifiable->name},")
            ->line("A customer has submitted an old jewellery item for valuation (request {$request->request_number}).")
            ->line("You have until {$request->bidding_end_at->format('d M Y, h:i A')} to respond.")
            ->action('View request and respond', $this->responseUrl())
            ->line('If you do not respond before the deadline, this request will be closed to you.');
    }

    public function toWhatsApp(object $notifiable): string
    {
        $request = $this->invitation->request;

        return "New old jewellery request {$request->request_number}. Respond by {$request->bidding_end_at->format('d M Y, h:i A')}: {$this->responseUrl()}";
    }

    private function responseUrl(): string
    {
        return rtrim(config('app.url'), '/')."/api/v1/vendor/old-jewellery/{$this->plaintextToken}";
    }
}
```

`app/Notifications/AdminNewOldJewelleryRequest.php`:

```php
<?php

namespace App\Notifications;

use App\Models\OldJewelleryRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AdminNewOldJewelleryRequest extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly OldJewelleryRequest $oldJewelleryRequest) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("New old jewellery request {$this->oldJewelleryRequest->request_number}")
            ->line("A customer submitted a new old jewellery request: {$this->oldJewelleryRequest->request_number}.")
            ->line("Bidding closes at {$this->oldJewelleryRequest->bidding_end_at->format('d M Y, h:i A')}.")
            ->action('Review in admin', url("/admin/old-jewellery-requests/{$this->oldJewelleryRequest->id}"));
    }
}
```

`app/Notifications/CustomerOldJewelleryFinalized.php`:

```php
<?php

namespace App\Notifications;

use App\Models\OldJewelleryWalletCredit;
use App\Notifications\Channels\WhatsAppChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomerOldJewelleryFinalized extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly OldJewelleryWalletCredit $credit) {}

    public function via(object $notifiable): array
    {
        $channels = ['mail'];

        if (filled($notifiable->phone)) {
            $channels[] = WhatsAppChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->credit->request;

        return (new MailMessage)
            ->subject("Your old jewellery valuation is complete — {$request->request_number}")
            ->greeting("Hello {$notifiable->name},")
            ->line("Your old jewellery request {$request->request_number} has been valued at ₹{$this->credit->gross_amount}.")
            ->line("A 10% platform fee of ₹{$this->credit->deduction_amount} was deducted.")
            ->line("₹{$this->credit->credited_amount} has been credited to your wallet.")
            ->line("This credit expires on {$this->credit->expires_at->format('d M Y')}.")
            ->action('Shop now', url('/'));
    }

    public function toWhatsApp(object $notifiable): string
    {
        $request = $this->credit->request;

        return "Your old jewellery request {$request->request_number} was valued at ₹{$this->credit->gross_amount}. ₹{$this->credit->credited_amount} credited to your wallet (expires {$this->credit->expires_at->format('d M Y')}).";
    }
}
```

- [ ] **Step 7: Dispatch notifications from the services**

Modify `app/Services/OldJewellery/VendorInvitationService.php` — add imports `use App\Notifications\AdminNewOldJewelleryRequest;`, `use App\Notifications\VendorInvitedToBid;`, `use App\Models\User;`, `use Illuminate\Support\Facades\Notification;`, then add this right after the `if ($results->isNotEmpty()) { ... }` block's activity-log call, still inside the transaction closure (queued notifications are safe to dispatch pre-commit since they only enqueue a job row, matching the existing `Mail::to(...)->queue()` placement convention used elsewhere — but to be strictly consistent with "notification delivery must be separated from the business transaction," dispatch AFTER the transaction returns instead):

Change the method to capture results and notify after the `DB::transaction()` call returns:

```php
public function inviteAll(OldJewelleryRequest $request): Collection
{
    $results = DB::transaction(function () use ($request) {
        $locked = OldJewelleryRequest::whereKey($request->id)->lockForUpdate()->first();

        if ($locked->invitations()->exists()) {
            return collect();
        }

        $vendors = Vendor::where('is_active', true)->get();

        $created = $vendors->map(function (Vendor $vendor) use ($locked) {
            $plaintext = Str::random(64);

            $invitation = OldJewelleryVendorInvitation::create([
                'old_jewellery_request_id' => $locked->id,
                'vendor_id' => $vendor->id,
                'token_hash' => $this->hashToken($plaintext),
                'expires_at' => $locked->bidding_end_at,
                'response_status' => 'pending',
            ]);

            return ['invitation' => $invitation, 'plaintext_token' => $plaintext];
        });

        if ($created->isNotEmpty()) {
            $locked->update(['status' => 'vendors_notified']);

            OldJewelleryActivityLog::create([
                'old_jewellery_request_id' => $locked->id,
                'actor_type' => 'system',
                'action' => 'vendors_notified',
                'from_status' => 'submitted',
                'to_status' => 'vendors_notified',
                'metadata' => ['vendor_count' => $created->count()],
            ]);
        }

        return $created;
    });

    // Notification dispatch happens outside the DB transaction: a mail/queue
    // failure here must never roll back the invitation rows that were just
    // committed (same "separate business transaction from notification
    // delivery" reasoning as WalletService::credit()'s own placement).
    foreach ($results as $result) {
        Notification::route('mail', $result['invitation']->vendor->email)
            ->notify(new VendorInvitedToBid($result['invitation'], $result['plaintext_token']));
    }

    if ($results->isNotEmpty()) {
        $admins = User::role('super_admin')->whereNotNull('email')->get();
        Notification::send($admins, new AdminNewOldJewelleryRequest($request->fresh()));
    }

    return $results;
}
```

(This replaces the method body written in Task 4 — the class-level imports and `hashToken()` method are unchanged.)

- [ ] **Step 8: Dispatch the customer notification from `OldJewelleryWalletService`**

Modify `app/Services/OldJewellery/OldJewelleryWalletService.php` — add `use App\Notifications\CustomerOldJewelleryFinalized;` and `use Illuminate\Support\Facades\Notification;`, then after the `return $credit;` line's containing transaction closure returns (i.e., dispatch outside `DB::transaction`, same reasoning as Step 7), change `creditForRequest()`'s tail to:

```php
    public function creditForRequest(OldJewelleryRequest $request): ?OldJewelleryWalletCredit
    {
        $existing = OldJewelleryWalletCredit::where('old_jewellery_request_id', $request->id)->first();
        if ($existing) {
            return $existing;
        }

        if ($request->status !== 'bid_selected' || $request->final_amount === null) {
            return null;
        }

        $credit = DB::transaction(function () use ($request) {
            // ... unchanged body from Task 6, ending with `return $credit;` ...
        });

        if ($credit) {
            Notification::send($credit->user, new CustomerOldJewelleryFinalized($credit->load('request')));
        }

        return $credit;
    }
```

(Full method: keep the Task 6 transaction body exactly as written, just wrap it in `$credit = DB::transaction(...)` instead of `return DB::transaction(...)`, and add the notification dispatch + `return $credit;` after it. The early-return `null`/existing-row paths above the transaction are unchanged and correctly skip notification — an idempotent second call must not re-notify either.)

- [ ] **Step 9: Add `whatsapp` block to `config/services.php` and `.env.example`**

Add to `config/services.php` (mirroring the existing `vas_sms` block):

```php
// WhatsApp Business API provider for vendor/customer old-jewellery
// notifications. Leave WHATSAPP_PROVIDER unset to stay on LogWhatsAppGateway
// (messages logged, not sent) — see App\Services\WhatsApp\LogWhatsAppGateway.
'whatsapp' => [
    'provider' => env('WHATSAPP_PROVIDER'),
    'api_key' => env('WHATSAPP_API_KEY'),
    'api_url' => env('WHATSAPP_API_URL'),
    'from_number' => env('WHATSAPP_FROM_NUMBER'),
],
```

Add to `.env.example` (near the `VAS_SMS_*` block):

```
# Leave blank to stay on the default LogWhatsAppGateway (vendor/admin/customer
# old-jewellery notifications stay fully functional, messages just get logged
# instead of sent over WhatsApp) — set these to wire up a real WhatsApp
# Business API provider.
WHATSAPP_PROVIDER=
WHATSAPP_API_KEY=
WHATSAPP_API_URL=
WHATSAPP_FROM_NUMBER=
```

- [ ] **Step 10: Run tests to verify they pass**

Run: `cd backend && php artisan test tests/Feature/OldJewellery/NotificationDispatchTest.php`
Expected: PASS (2 tests).

- [ ] **Step 11: Re-run the earlier Task 4 and Task 6 tests to confirm the refactor didn't break them**

Run: `cd backend && php artisan test tests/Feature/OldJewellery/VendorInvitationServiceTest.php tests/Feature/OldJewellery/OldJewelleryWalletServiceTest.php tests/Feature/OldJewellery`
Expected: PASS — all still pass after the notification-dispatch refactor.

- [ ] **Step 12: Commit**

```bash
cd backend
git add app/Services/WhatsApp/*.php app/Notifications/*.php app/Notifications/Channels/*.php config/services.php .env.example app/Services/OldJewellery/VendorInvitationService.php app/Services/OldJewellery/OldJewelleryWalletService.php app/Providers/AppServiceProvider.php tests/Feature/OldJewellery/NotificationDispatchTest.php
git commit -m "feat: add WhatsApp stub and vendor/admin/customer notifications"
```

---

## Task 12: Filament resources — `Vendors`, `OldJewelleryRequests`; `ShieldSeeder` update

**Files:**
- Create: `app/Filament/Resources/Vendors/VendorResource.php`
- Create: `app/Filament/Resources/Vendors/Schemas/VendorForm.php`
- Create: `app/Filament/Resources/Vendors/Tables/VendorsTable.php`
- Create: `app/Filament/Resources/Vendors/Pages/ListVendors.php`
- Create: `app/Filament/Resources/Vendors/Pages/CreateVendor.php`
- Create: `app/Filament/Resources/Vendors/Pages/EditVendor.php`
- Create: `app/Policies/VendorPolicy.php`
- Create: `app/Filament/Resources/OldJewelleryRequests/OldJewelleryRequestResource.php`
- Create: `app/Filament/Resources/OldJewelleryRequests/Tables/OldJewelleryRequestsTable.php`
- Create: `app/Filament/Resources/OldJewelleryRequests/Pages/ListOldJewelleryRequests.php`
- Create: `app/Filament/Resources/OldJewelleryRequests/Pages/ViewOldJewelleryRequest.php`
- Create: `app/Filament/Resources/OldJewelleryRequests/Schemas/OldJewelleryRequestInfolist.php`
- Modify: `database/seeders/ShieldSeeder.php` (add `Vendor`, `OldJewelleryRequest` to `RESOURCES`)
- Modify: `app/Providers/AppServiceProvider.php` (register `VendorPolicy`, though Filament Shield auto-discovers `VendorPolicy` <-> `Vendor` by name convention — the explicit `Gate::policy()` call is only needed for `OldJewelleryRequestPolicy`, already added in Task 10, since `OldJewelleryRequest`/`OldJewelleryRequestPolicy` DO follow the naming convention Laravel auto-discovers... confirm no explicit registration is actually needed for either; Filament Shield's `enforcePolicies()` relies on Laravel's own policy auto-discovery by class-name match, and both `Vendor`/`VendorPolicy` and `OldJewelleryRequest`/`OldJewelleryRequestPolicy` match that convention — so no `Gate::policy()` call for either is strictly required; Task 10's explicit registration is defensive/redundant, kept as belt-and-suspenders since the Api controllers call `Gate::authorize()` directly rather than relying purely on Filament's discovery path)
- Test: `tests/Feature/OldJewellery/FilamentVendorResourceTest.php`
- Test: `tests/Feature/OldJewellery/FilamentOldJewelleryResourceTest.php`

**Interfaces:**
- Consumes: `Vendor`, `OldJewelleryRequest` (Task 2), `OldJewelleryBiddingService::submitAdminBid()` (Task 5), `OldJewelleryClosingService::close()` (Task 7).
- Produces: `/admin/vendors` and `/admin/old-jewellery-requests` Filament routes, gated by Shield permissions `{Action}:Vendor` / `{Action}:OldJewelleryRequest`.

- [ ] **Step 1: Write `app/Policies/VendorPolicy.php`** (same shape as the audited `RewardSubmissionPolicy`)

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Vendor;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class VendorPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Vendor');
    }

    public function view(AuthUser $authUser, ?Vendor $vendor = null): bool
    {
        return $authUser->can('View:Vendor');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Vendor');
    }

    public function update(AuthUser $authUser, ?Vendor $vendor = null): bool
    {
        return $authUser->can('Update:Vendor');
    }

    public function delete(AuthUser $authUser, ?Vendor $vendor = null): bool
    {
        return $authUser->can('Delete:Vendor');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Vendor');
    }

    public function restore(AuthUser $authUser, ?Vendor $vendor = null): bool
    {
        return $authUser->can('Restore:Vendor');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Vendor');
    }

    public function forceDelete(AuthUser $authUser, ?Vendor $vendor = null): bool
    {
        return $authUser->can('ForceDelete:Vendor');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Vendor');
    }

    public function replicate(AuthUser $authUser, ?Vendor $vendor = null): bool
    {
        return $authUser->can('Replicate:Vendor');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Vendor');
    }
}
```

- [ ] **Step 2: Write `app/Filament/Resources/Vendors/Schemas/VendorForm.php`**

```php
<?php

namespace App\Filament\Resources\Vendors\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class VendorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required(),
            TextInput::make('company_name'),
            TextInput::make('mobile')->required()->unique(ignoreRecord: true)->tel(),
            TextInput::make('email')->email(),
            TextInput::make('whatsapp_number')->tel(),
            Toggle::make('is_active')->default(true),
        ]);
    }
}
```

- [ ] **Step 3: Write `app/Filament/Resources/Vendors/Tables/VendorsTable.php`**

```php
<?php

namespace App\Filament\Resources\Vendors\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class VendorsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('company_name')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('mobile')->searchable(),
                TextColumn::make('email')->searchable()->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')->boolean(),
                TextColumn::make('created_at')->dateTime('d M Y, h:i A')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
```

- [ ] **Step 4: Write `app/Filament/Resources/Vendors/VendorResource.php`, its Pages, and register the policy**

```php
<?php

namespace App\Filament\Resources\Vendors;

use App\Filament\Resources\Vendors\Pages\CreateVendor;
use App\Filament\Resources\Vendors\Pages\EditVendor;
use App\Filament\Resources\Vendors\Pages\ListVendors;
use App\Filament\Resources\Vendors\Schemas\VendorForm;
use App\Filament\Resources\Vendors\Tables\VendorsTable;
use App\Models\Vendor;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class VendorResource extends Resource
{
    protected static ?string $model = Vendor::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|\UnitEnum|null $navigationGroup = 'Old Jewellery';

    public static function form(Schema $schema): Schema
    {
        return VendorForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VendorsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVendors::route('/'),
            'create' => CreateVendor::route('/create'),
            'edit' => EditVendor::route('/{record}/edit'),
        ];
    }
}
```

`app/Filament/Resources/Vendors/Pages/ListVendors.php`:

```php
<?php

namespace App\Filament\Resources\Vendors\Pages;

use App\Filament\Resources\Vendors\VendorResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVendors extends ListRecords
{
    protected static string $resource = VendorResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
```

`app/Filament/Resources/Vendors/Pages/CreateVendor.php`:

```php
<?php

namespace App\Filament\Resources\Vendors\Pages;

use App\Filament\Resources\Vendors\VendorResource;
use Filament\Resources\Pages\CreateRecord;

class CreateVendor extends CreateRecord
{
    protected static string $resource = VendorResource::class;
}
```

`app/Filament/Resources/Vendors/Pages/EditVendor.php`:

```php
<?php

namespace App\Filament\Resources\Vendors\Pages;

use App\Filament\Resources\Vendors\VendorResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditVendor extends EditRecord
{
    protected static string $resource = VendorResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
```

- [ ] **Step 5: Write `app/Filament/Resources/OldJewelleryRequests/Tables/OldJewelleryRequestsTable.php`**

```php
<?php

namespace App\Filament\Resources\OldJewelleryRequests\Tables;

use App\Models\OldJewelleryRequest;
use App\Services\OldJewellery\OldJewelleryBiddingService;
use App\Services\OldJewellery\OldJewelleryClosingService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OldJewelleryRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->paginationMode(PaginationMode::Simple)
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('request_number')->searchable(),
                TextColumn::make('user.name')->label('Customer')->searchable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('bidding_start_at')->dateTime('d M Y, h:i A')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('bidding_end_at')->dateTime('d M Y, h:i A'),
                TextColumn::make('invitations_count')->counts('invitations')->label('Vendors Invited'),
                TextColumn::make('final_amount')->formatStateUsing(fn ($state) => filled($state) ? '₹'.number_format((float) $state, 2) : '—'),
                TextColumn::make('credited_amount')->label('Wallet Credited')->formatStateUsing(fn ($state) => filled($state) ? '₹'.number_format((float) $state, 2) : '—'),
                TextColumn::make('created_at')->label('Submitted')->dateTime('d M Y, h:i A')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'pending' => 'Pending',
                    'submitted' => 'Submitted',
                    'vendors_notified' => 'Vendors Notified',
                    'bidding_active' => 'Bidding Active',
                    'bidding_closed' => 'Bidding Closed',
                    'bid_selected' => 'Bid Selected',
                    'wallet_pending' => 'Wallet Pending',
                    'wallet_credited' => 'Wallet Credited',
                    'wallet_expired' => 'Wallet Expired',
                    'completed' => 'Completed',
                    'cancelled' => 'Cancelled',
                ]),
            ])
            ->recordActions([
                self::adminBidAction(),
                self::closeAction(),
            ]);
    }

    public static function adminBidAction(): Action
    {
        return Action::make('admin_bid')
            ->label('Submit Valuation')
            ->icon(Heroicon::OutlinedCurrencyRupee)
            ->color('warning')
            ->visible(fn (OldJewelleryRequest $record) => in_array($record->status, ['vendors_notified', 'bidding_active'], true) && now()->lessThan($record->bidding_end_at))
            ->schema([
                TextInput::make('amount')->label('Valuation amount (₹)')->numeric()->required()->minValue(0.01),
            ])
            ->action(function (array $data, OldJewelleryRequest $record) {
                try {
                    app(OldJewelleryBiddingService::class)->submitAdminBid($record, auth()->user(), (float) $data['amount']);
                } catch (\DomainException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Valuation submitted.')->success()->send();
            });
    }

    public static function closeAction(): Action
    {
        return Action::make('close_bidding')
            ->label('Close Bidding Now')
            ->icon(Heroicon::OutlinedLockClosed)
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (OldJewelleryRequest $record) => $record->status === 'bidding_active')
            ->action(function (OldJewelleryRequest $record) {
                $result = app(OldJewelleryClosingService::class)->close($record);

                Notification::make()->title("Request is now: {$result->status}")->success()->send();
            });
    }
}
```

- [ ] **Step 6: Write `app/Filament/Resources/OldJewelleryRequests/Schemas/OldJewelleryRequestInfolist.php`**

```php
<?php

namespace App\Filament\Resources\OldJewelleryRequests\Schemas;

use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OldJewelleryRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Request')
                ->schema([
                    TextEntry::make('request_number'),
                    TextEntry::make('user.name')->label('Customer'),
                    TextEntry::make('user.email')->label('Customer email'),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('description'),
                    ImageEntry::make('image')->state(fn ($record) => $record->getFirstMediaUrl('image', 'thumb') ?: null),
                ]),
            Section::make('Bidding')
                ->schema([
                    TextEntry::make('bidding_start_at')->dateTime('d M Y, h:i A'),
                    TextEntry::make('bidding_end_at')->dateTime('d M Y, h:i A'),
                    TextEntry::make('final_amount')->formatStateUsing(fn ($state) => filled($state) ? '₹'.number_format((float) $state, 2) : '—'),
                    TextEntry::make('deduction_amount')->formatStateUsing(fn ($state) => filled($state) ? '₹'.number_format((float) $state, 2) : '—'),
                    TextEntry::make('credited_amount')->label('Wallet Credited')->formatStateUsing(fn ($state) => filled($state) ? '₹'.number_format((float) $state, 2) : '—'),
                ]),
            Section::make('Vendor Responses')
                ->schema([
                    RepeatableEntry::make('invitations')
                        ->schema([
                            TextEntry::make('vendor.name'),
                            TextEntry::make('response_status')->badge(),
                            TextEntry::make('responded_at')->dateTime('d M Y, h:i A'),
                        ]),
                ]),
            Section::make('Bids')
                ->schema([
                    RepeatableEntry::make('bids')
                        ->schema([
                            TextEntry::make('bidder_type')->badge(),
                            TextEntry::make('vendor.name')->label('Vendor')->placeholder('—'),
                            TextEntry::make('adminUser.name')->label('Admin')->placeholder('—'),
                            TextEntry::make('amount')->formatStateUsing(fn ($state) => '₹'.number_format((float) $state, 2)),
                            TextEntry::make('submitted_at')->dateTime('d M Y, h:i A'),
                        ]),
                ]),
            Section::make('Activity Log')
                ->schema([
                    RepeatableEntry::make('activityLogs')
                        ->schema([
                            TextEntry::make('action'),
                            TextEntry::make('actor_type'),
                            TextEntry::make('created_at')->dateTime('d M Y, h:i A'),
                        ]),
                ]),
        ]);
    }
}
```

- [ ] **Step 7: Write `app/Filament/Resources/OldJewelleryRequests/OldJewelleryRequestResource.php` and its Pages**

```php
<?php

namespace App\Filament\Resources\OldJewelleryRequests;

use App\Filament\Resources\OldJewelleryRequests\Pages\ListOldJewelleryRequests;
use App\Filament\Resources\OldJewelleryRequests\Pages\ViewOldJewelleryRequest;
use App\Filament\Resources\OldJewelleryRequests\Schemas\OldJewelleryRequestInfolist;
use App\Filament\Resources\OldJewelleryRequests\Tables\OldJewelleryRequestsTable;
use App\Models\OldJewelleryRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class OldJewelleryRequestResource extends Resource
{
    protected static ?string $model = OldJewelleryRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|\UnitEnum|null $navigationGroup = 'Old Jewellery';

    protected static ?string $recordTitleAttribute = 'request_number';

    public static function table(Table $table): Table
    {
        return OldJewelleryRequestsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return OldJewelleryRequestInfolist::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOldJewelleryRequests::route('/'),
            'view' => ViewOldJewelleryRequest::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
```

`app/Filament/Resources/OldJewelleryRequests/Pages/ListOldJewelleryRequests.php`:

```php
<?php

namespace App\Filament\Resources\OldJewelleryRequests\Pages;

use App\Filament\Resources\OldJewelleryRequests\OldJewelleryRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListOldJewelleryRequests extends ListRecords
{
    protected static string $resource = OldJewelleryRequestResource::class;
}
```

`app/Filament/Resources/OldJewelleryRequests/Pages/ViewOldJewelleryRequest.php`:

```php
<?php

namespace App\Filament\Resources\OldJewelleryRequests\Pages;

use App\Filament\Resources\OldJewelleryRequests\OldJewelleryRequestResource;
use Filament\Resources\Pages\ViewRecord;

class ViewOldJewelleryRequest extends ViewRecord
{
    protected static string $resource = OldJewelleryRequestResource::class;
}
```

- [ ] **Step 8: Update `database/seeders/ShieldSeeder.php`**

Add `'Vendor', 'OldJewelleryRequest',` to the `RESOURCES` const array (after `'RewardSubmission',`), and add both to `super_admin`'s permission set (already automatic, since `super_admin` gets `syncPermissions($permissionNames)` — the full generated list — no further change needed there beyond the `RESOURCES` addition itself).

- [ ] **Step 9: Write the Filament tests**

`tests/Feature/OldJewellery/FilamentVendorResourceTest.php`:

```php
<?php

namespace Tests\Feature\OldJewellery;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FilamentVendorResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_access_vendors_index(): void
    {
        $admin = User::factory()->create();
        Permission::firstOrCreate(['name' => 'ViewAny:Vendor', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $role->givePermissionTo('ViewAny:Vendor');
        $admin->assignRole($role);

        $response = $this->actingAs($admin)->get('/admin/vendors');

        $response->assertOk();
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        $user = User::factory()->create();
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $user->assignRole('super_admin'); // has the panel-access role but zero permissions synced

        $response = $this->actingAs($user)->get('/admin/vendors');

        $response->assertForbidden();
    }
}
```

`tests/Feature/OldJewellery/FilamentOldJewelleryResourceTest.php`:

```php
<?php

namespace Tests\Feature\OldJewellery;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FilamentOldJewelleryResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_access_old_jewellery_requests_index(): void
    {
        $admin = User::factory()->create();
        Permission::firstOrCreate(['name' => 'ViewAny:OldJewelleryRequest', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $role->givePermissionTo('ViewAny:OldJewelleryRequest');
        $admin->assignRole($role);

        $response = $this->actingAs($admin)->get('/admin/old-jewellery-requests');

        $response->assertOk();
    }
}
```

- [ ] **Step 10: Run tests**

Run: `cd backend && php artisan test tests/Feature/OldJewellery/FilamentVendorResourceTest.php tests/Feature/OldJewellery/FilamentOldJewelleryResourceTest.php`
Expected: PASS (3 tests). If Filament's resource URL slug differs from the guessed `/admin/vendors` / `/admin/old-jewellery-requests` (Filament derives it from the model name), adjust the test URLs to match the actual generated slugs (`php artisan route:list --name=filament` shows them) rather than changing the resources.

- [ ] **Step 11: Commit**

```bash
cd backend
git add app/Filament/Resources/Vendors app/Filament/Resources/OldJewelleryRequests app/Policies/VendorPolicy.php database/seeders/ShieldSeeder.php tests/Feature/OldJewellery/FilamentVendorResourceTest.php tests/Feature/OldJewellery/FilamentOldJewelleryResourceTest.php
git commit -m "feat: add Vendor and OldJewelleryRequest Filament admin resources"
```

---

## Task 13: Jobs + Scheduler — close bidding, expire wallet credits, send reminders

**Files:**
- Create: `app/Jobs/CloseExpiredOldJewelleryBiddingJob.php`
- Create: `app/Jobs/ExpireOldJewelleryWalletCreditsJob.php`
- Create: `app/Jobs/SendOldJewelleryWalletReminderJob.php`
- Create: `app/Notifications/OldJewelleryWalletExpiryReminder.php`
- Modify: `bootstrap/app.php` (add `->withSchedule()`)
- Modify: `app/Services/OldJewellery/OldJewelleryWalletService.php` (no change needed — expiry uses `WalletService::debit()` directly from the job, see below)
- Test: `tests/Feature/OldJewellery/CloseExpiredBiddingJobTest.php`
- Test: `tests/Feature/OldJewellery/ExpireWalletCreditsJobTest.php`
- Test: `tests/Feature/OldJewellery/WalletReminderJobTest.php`

**Interfaces:**
- Consumes: `OldJewelleryClosingService::close()` (Task 7), `WalletService::debit()` (existing), `OldJewelleryWalletCredit` (Task 2).
- Produces: three `ShouldQueue` Job classes, each idempotent via query preconditions (not just `ShouldBeUnique`), registered on the scheduler at `CloseExpiredOldJewelleryBiddingJob` every 5 minutes, `ExpireOldJewelleryWalletCreditsJob` and `SendOldJewelleryWalletReminderJob` daily.

- [ ] **Step 1: Write the failing test for the closing job**

`tests/Feature/OldJewellery/CloseExpiredBiddingJobTest.php`:

```php
<?php

namespace Tests\Feature\OldJewellery;

use App\Jobs\CloseExpiredOldJewelleryBiddingJob;
use App\Models\OldJewelleryBid;
use App\Models\OldJewelleryRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CloseExpiredBiddingJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_closes_only_expired_bidding_active_requests(): void
    {
        $expired = OldJewelleryRequest::create([
            'user_id' => User::factory()->create(['wallet_balance' => 0])->id,
            'request_number' => 'OJ-2026-000200',
            'status' => 'bidding_active',
            'bidding_start_at' => now()->subHours(3),
            'bidding_end_at' => now()->subMinute(),
        ]);
        OldJewelleryBid::create([
            'old_jewellery_request_id' => $expired->id,
            'bidder_type' => 'vendor',
            'amount' => 500,
            'submitted_at' => now()->subMinutes(30),
        ]);

        $stillOpen = OldJewelleryRequest::create([
            'user_id' => User::factory()->create()->id,
            'request_number' => 'OJ-2026-000201',
            'status' => 'bidding_active',
            'bidding_start_at' => now(),
            'bidding_end_at' => now()->addHours(3),
        ]);

        (new CloseExpiredOldJewelleryBiddingJob())->handle();

        $this->assertSame('wallet_credited', $expired->fresh()->status);
        $this->assertSame('bidding_active', $stillOpen->fresh()->status);
    }

    public function test_running_twice_does_not_double_credit(): void
    {
        $user = User::factory()->create(['wallet_balance' => 0]);
        $request = OldJewelleryRequest::create([
            'user_id' => $user->id,
            'request_number' => 'OJ-2026-000202',
            'status' => 'bidding_active',
            'bidding_start_at' => now()->subHours(3),
            'bidding_end_at' => now()->subMinute(),
        ]);
        OldJewelleryBid::create([
            'old_jewellery_request_id' => $request->id,
            'bidder_type' => 'vendor',
            'amount' => 1000,
            'submitted_at' => now()->subMinutes(30),
        ]);

        $job = new CloseExpiredOldJewelleryBiddingJob();
        $job->handle();
        $job->handle();

        $this->assertSame('900.00', $user->fresh()->wallet_balance);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test tests/Feature/OldJewellery/CloseExpiredBiddingJobTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write `app/Jobs/CloseExpiredOldJewelleryBiddingJob.php`**

```php
<?php

namespace App\Jobs;

use App\Models\OldJewelleryRequest;
use App\Services\OldJewellery\OldJewelleryClosingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Idempotency comes from the query below (only rows still 'bidding_active'
 * past their deadline are ever touched) plus OldJewelleryClosingService's
 * own row-lock re-check — not from ShouldBeUnique alone, since an
 * overlapping/delayed cron run is an explicit risk this must tolerate.
 */
class CloseExpiredOldJewelleryBiddingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(OldJewelleryClosingService $closingService): void
    {
        OldJewelleryRequest::where('status', 'bidding_active')
            ->where('bidding_end_at', '<=', now())
            ->cursor()
            ->each(fn (OldJewelleryRequest $request) => $closingService->close($request));
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php artisan test tests/Feature/OldJewellery/CloseExpiredBiddingJobTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Write the failing test for the wallet-expiry job**

`tests/Feature/OldJewellery/ExpireWalletCreditsJobTest.php`:

```php
<?php

namespace Tests\Feature\OldJewellery;

use App\Jobs\ExpireOldJewelleryWalletCreditsJob;
use App\Models\OldJewelleryRequest;
use App\Models\OldJewelleryWalletCredit;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpireWalletCreditsJobTest extends TestCase
{
    use RefreshDatabase;

    private function makeCredit(User $user, float $remaining, string $status, \Carbon\Carbon $expiresAt): OldJewelleryWalletCredit
    {
        $request = OldJewelleryRequest::create([
            'user_id' => $user->id,
            'request_number' => 'OJ-2026-'.random_int(300000, 399999),
            'status' => 'wallet_credited',
        ]);
        $transaction = WalletTransaction::create([
            'user_id' => $user->id,
            'type' => 'credit',
            'amount' => 900,
            'balance_after' => $user->wallet_balance,
            'reason' => 'old_jewellery_sale',
        ]);

        return OldJewelleryWalletCredit::create([
            'user_id' => $user->id,
            'old_jewellery_request_id' => $request->id,
            'wallet_transaction_id' => $transaction->id,
            'gross_amount' => 1000,
            'deduction_amount' => 100,
            'credited_amount' => 900,
            'remaining_amount' => $remaining,
            'credited_at' => now()->subDays(10),
            'expires_at' => $expiresAt,
            'status' => $status,
        ]);
    }

    public function test_expires_unused_credit_past_expiry_and_debits_remainder(): void
    {
        $user = User::factory()->create(['wallet_balance' => 900]);
        $credit = $this->makeCredit($user, 900, 'active', now()->subDay());

        (new ExpireOldJewelleryWalletCreditsJob())->handle();

        $this->assertSame('expired', $credit->fresh()->status);
        $this->assertSame('0.00', $user->fresh()->wallet_balance);
    }

    public function test_partially_used_credit_only_debits_remaining_amount(): void
    {
        $user = User::factory()->create(['wallet_balance' => 300]);
        $credit = $this->makeCredit($user, 300, 'partially_used', now()->subDay());

        (new ExpireOldJewelleryWalletCreditsJob())->handle();

        $this->assertSame('expired', $credit->fresh()->status);
        $this->assertSame('0.00', $user->fresh()->wallet_balance);
    }

    public function test_does_not_touch_credits_not_yet_expired(): void
    {
        $user = User::factory()->create(['wallet_balance' => 900]);
        $credit = $this->makeCredit($user, 900, 'active', now()->addDay());

        (new ExpireOldJewelleryWalletCreditsJob())->handle();

        $this->assertSame('active', $credit->fresh()->status);
        $this->assertSame('900.00', $user->fresh()->wallet_balance);
    }

    public function test_fully_used_credit_is_marked_expired_without_debiting(): void
    {
        $user = User::factory()->create(['wallet_balance' => 0]);
        $credit = $this->makeCredit($user, 0, 'used', now()->subDay());

        (new ExpireOldJewelleryWalletCreditsJob())->handle();

        $this->assertSame('expired', $credit->fresh()->status);
        $this->assertSame('0.00', $user->fresh()->wallet_balance);
    }

    public function test_running_twice_does_not_double_debit(): void
    {
        $user = User::factory()->create(['wallet_balance' => 900]);
        $this->makeCredit($user, 900, 'active', now()->subDay());

        $job = new ExpireOldJewelleryWalletCreditsJob();
        $job->handle();
        $job->handle();

        $this->assertSame('0.00', $user->fresh()->wallet_balance);
    }
}
```

- [ ] **Step 6: Run test to verify it fails**

Run: `cd backend && php artisan test tests/Feature/OldJewellery/ExpireWalletCreditsJobTest.php`
Expected: FAIL — class not found.

- [ ] **Step 7: Write `app/Jobs/ExpireOldJewelleryWalletCreditsJob.php`**

```php
<?php

namespace App\Jobs;

use App\Models\OldJewelleryWalletCredit;
use App\Services\WalletService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Idempotent via the query below: only rows still active/partially_used
 * past expires_at are touched, and each is flipped to 'expired' in the same
 * pass that debits it — a second run finds zero matching rows. Only the
 * *unused remainder* is debited (already-spent portion, tracked separately
 * on the row by checkout's spend-order logic, is left alone).
 */
class ExpireOldJewelleryWalletCreditsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(WalletService $walletService): void
    {
        OldJewelleryWalletCredit::whereIn('status', ['active', 'partially_used'])
            ->where('expires_at', '<=', now())
            ->cursor()
            ->each(function (OldJewelleryWalletCredit $credit) use ($walletService) {
                $remaining = (float) $credit->remaining_amount;

                if ($remaining > 0) {
                    $walletService->debit($credit->user, $remaining, 'old_jewellery_wallet_expired', $credit->request);
                }

                $credit->update(['status' => 'expired', 'remaining_amount' => 0]);
            });
    }
}
```

- [ ] **Step 8: Run test to verify it passes**

Run: `cd backend && php artisan test tests/Feature/OldJewellery/ExpireWalletCreditsJobTest.php`
Expected: PASS (5 tests).

- [ ] **Step 9: Write the failing test for the reminder job**

`tests/Feature/OldJewellery/WalletReminderJobTest.php`:

```php
<?php

namespace Tests\Feature\OldJewellery;

use App\Jobs\SendOldJewelleryWalletReminderJob;
use App\Models\OldJewelleryRequest;
use App\Models\OldJewelleryWalletCredit;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Notifications\OldJewelleryWalletExpiryReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WalletReminderJobTest extends TestCase
{
    use RefreshDatabase;

    private function makeCredit(User $user, \Carbon\Carbon $expiresAt): OldJewelleryWalletCredit
    {
        $request = OldJewelleryRequest::create([
            'user_id' => $user->id,
            'request_number' => 'OJ-2026-'.random_int(400000, 499999),
            'status' => 'wallet_credited',
        ]);
        $transaction = WalletTransaction::create([
            'user_id' => $user->id,
            'type' => 'credit',
            'amount' => 900,
            'balance_after' => 900,
            'reason' => 'old_jewellery_sale',
        ]);

        return OldJewelleryWalletCredit::create([
            'user_id' => $user->id,
            'old_jewellery_request_id' => $request->id,
            'wallet_transaction_id' => $transaction->id,
            'gross_amount' => 1000,
            'deduction_amount' => 100,
            'credited_amount' => 900,
            'remaining_amount' => 900,
            'credited_at' => now(),
            'expires_at' => $expiresAt,
            'status' => 'active',
        ]);
    }

    public function test_sends_three_day_reminder_and_stamps_it(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $credit = $this->makeCredit($user, now()->addDays(3));

        (new SendOldJewelleryWalletReminderJob())->handle();

        Notification::assertSentTo($user, OldJewelleryWalletExpiryReminder::class);
        $this->assertNotNull($credit->fresh()->reminder_3d_sent_at);
    }

    public function test_does_not_send_the_same_tier_twice(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $credit = $this->makeCredit($user, now()->addDays(3));
        $credit->update(['reminder_3d_sent_at' => now()]);

        (new SendOldJewelleryWalletReminderJob())->handle();

        Notification::assertNotSentTo($user, OldJewelleryWalletExpiryReminder::class);
    }

    public function test_sends_one_day_and_expiry_day_reminders(): void
    {
        Notification::fake();

        $userOneDay = User::factory()->create();
        $this->makeCredit($userOneDay, now()->addDay());

        $userToday = User::factory()->create();
        $this->makeCredit($userToday, now());

        (new SendOldJewelleryWalletReminderJob())->handle();

        Notification::assertSentTo($userOneDay, OldJewelleryWalletExpiryReminder::class);
        Notification::assertSentTo($userToday, OldJewelleryWalletExpiryReminder::class);
    }

    public function test_ignores_already_expired_credits(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $credit = $this->makeCredit($user, now()->subDay());
        $credit->update(['status' => 'expired']);

        (new SendOldJewelleryWalletReminderJob())->handle();

        Notification::assertNotSentTo($user, OldJewelleryWalletExpiryReminder::class);
    }
}
```

- [ ] **Step 10: Run test to verify it fails**

Run: `cd backend && php artisan test tests/Feature/OldJewellery/WalletReminderJobTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 11: Write `app/Notifications/OldJewelleryWalletExpiryReminder.php`**

```php
<?php

namespace App\Notifications;

use App\Models\OldJewelleryWalletCredit;
use App\Notifications\Channels\WhatsAppChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OldJewelleryWalletExpiryReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly OldJewelleryWalletCredit $credit) {}

    public function via(object $notifiable): array
    {
        $channels = ['mail'];

        if (filled($notifiable->phone)) {
            $channels[] = WhatsAppChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your old jewellery wallet credit is expiring soon')
            ->greeting("Hello {$notifiable->name},")
            ->line("₹{$this->credit->remaining_amount} from request {$this->credit->request->request_number} expires on {$this->credit->expires_at->format('d M Y')}.")
            ->action('Use it now', url('/'));
    }

    public function toWhatsApp(object $notifiable): string
    {
        return "Reminder: ₹{$this->credit->remaining_amount} wallet credit from request {$this->credit->request->request_number} expires on {$this->credit->expires_at->format('d M Y')}. Use it before it expires!";
    }
}
```

- [ ] **Step 12: Write `app/Jobs/SendOldJewelleryWalletReminderJob.php`**

```php
<?php

namespace App\Jobs;

use App\Models\OldJewelleryWalletCredit;
use App\Notifications\OldJewelleryWalletExpiryReminder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

/**
 * Three reminder tiers (3 days before, 1 day before, on the expiry date
 * itself), each gated by its own *_sent_at column so a daily run — even if
 * delayed and catching up on more than one day at once — never re-sends a
 * tier already stamped. Tiers are date-based (not "exactly N*24h before"),
 * so a delayed cron that runs once for two calendar days still only sends
 * each tier once.
 */
class SendOldJewelleryWalletReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $this->sendTier('reminder_3d_sent_at', now()->addDays(3)->toDateString());
        $this->sendTier('reminder_1d_sent_at', now()->addDay()->toDateString());
        $this->sendTier('reminder_0d_sent_at', now()->toDateString());
    }

    private function sendTier(string $column, string $targetDate): void
    {
        OldJewelleryWalletCredit::whereIn('status', ['active', 'partially_used'])
            ->whereNull($column)
            ->whereDate('expires_at', $targetDate)
            ->with('user', 'request')
            ->cursor()
            ->each(function (OldJewelleryWalletCredit $credit) use ($column) {
                Notification::send($credit->user, new OldJewelleryWalletExpiryReminder($credit));
                $credit->update([$column => now()]);
            });
    }
}
```

- [ ] **Step 13: Run tests to verify they pass**

Run: `cd backend && php artisan test tests/Feature/OldJewellery/WalletReminderJobTest.php`
Expected: PASS (4 tests).

- [ ] **Step 14: Register the scheduler in `bootstrap/app.php`**

Add a `->withSchedule()` call to the `Application::configure(...)` chain (this is the first scheduler registration in the project — add it after `->withRouting()`):

```php
    ->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule): void {
        $schedule->job(new \App\Jobs\CloseExpiredOldJewelleryBiddingJob())
            ->everyFiveMinutes()
            ->name('old-jewellery:close-expired-bidding')
            ->withoutOverlapping();

        $schedule->job(new \App\Jobs\ExpireOldJewelleryWalletCreditsJob())
            ->daily()
            ->name('old-jewellery:expire-wallet-credits')
            ->withoutOverlapping();

        $schedule->job(new \App\Jobs\SendOldJewelleryWalletReminderJob())
            ->daily()
            ->name('old-jewellery:wallet-expiry-reminders')
            ->withoutOverlapping();
    })
```

- [ ] **Step 15: Verify the schedule registers correctly**

Run: `cd backend && php artisan schedule:list`
Expected: output lists all three jobs with their frequencies (`*/5 * * * *`, `0 0 * * *` x2).

- [ ] **Step 16: Run the full new-tests suite plus the whole project suite**

Run: `cd backend && php artisan test`
Expected: PASS — every test in the project, including all `OldJewellery` tests added across every task so far.

- [ ] **Step 17: Commit**

```bash
cd backend
git add app/Jobs/*.php app/Notifications/OldJewelleryWalletExpiryReminder.php bootstrap/app.php tests/Feature/OldJewellery/CloseExpiredBiddingJobTest.php tests/Feature/OldJewellery/ExpireWalletCreditsJobTest.php tests/Feature/OldJewellery/WalletReminderJobTest.php
git commit -m "feat: add closing/expiry/reminder jobs and register scheduler"
```

---

## Task 14: Checkout integration — spend expiring old-jewellery credits before non-expiring balance

**Files:**
- Create: `app/Services/OldJewellery/OldJewelleryWalletSpendService.php`
- Modify: `app/Http/Controllers/CheckoutController.php` (lines ~252-289, the wallet-debit section)
- Test: `tests/Feature/OldJewellery/CheckoutWalletSpendOrderTest.php`

**Interfaces:**
- Consumes: `OldJewelleryWalletCredit` (Task 2), `WalletService::debit()` (existing, unchanged — still the sole balance/ledger mutator).
- Produces: `OldJewelleryWalletSpendService::applySpend(User $user, float $amount, \Illuminate\Database\Eloquent\Model $reference): WalletTransaction`. This is a **wrapper** around `WalletService::debit()` — it does not duplicate the balance/ledger write, it only decides which `old_jewellery_wallet_credits` rows to decrement (soonest-`expires_at` first) *before* delegating the actual debit to `WalletService::debit()`. `CheckoutController` calls this instead of `WalletService::debit()` directly for the old-jewellery-aware path.

- [ ] **Step 1: Write the failing test**

`tests/Feature/OldJewellery/CheckoutWalletSpendOrderTest.php`:

```php
<?php

namespace Tests\Feature\OldJewellery;

use App\Models\OldJewelleryRequest;
use App\Models\OldJewelleryWalletCredit;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\OldJewellery\OldJewelleryWalletSpendService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutWalletSpendOrderTest extends TestCase
{
    use RefreshDatabase;

    private function makeCredit(User $user, float $remaining, \Carbon\Carbon $expiresAt): OldJewelleryWalletCredit
    {
        $request = OldJewelleryRequest::create([
            'user_id' => $user->id,
            'request_number' => 'OJ-2026-'.random_int(500000, 599999),
            'status' => 'wallet_credited',
        ]);
        $transaction = WalletTransaction::create([
            'user_id' => $user->id,
            'type' => 'credit',
            'amount' => $remaining,
            'balance_after' => $user->wallet_balance,
            'reason' => 'old_jewellery_sale',
        ]);

        return OldJewelleryWalletCredit::create([
            'user_id' => $user->id,
            'old_jewellery_request_id' => $request->id,
            'wallet_transaction_id' => $transaction->id,
            'gross_amount' => $remaining,
            'deduction_amount' => 0,
            'credited_amount' => $remaining,
            'remaining_amount' => $remaining,
            'credited_at' => now(),
            'expires_at' => $expiresAt,
            'status' => 'active',
        ]);
    }

    public function test_spends_soonest_expiring_credit_first(): void
    {
        $user = User::factory()->create(['wallet_balance' => 700]);
        $soon = $this->makeCredit($user, 200, now()->addDays(2));
        $later = $this->makeCredit($user, 500, now()->addDays(9));

        $order = Order::create([
            'order_number' => 'ORD-TEST-SPEND-1',
            'customer_name' => 'T', 'customer_email' => 't@example.com', 'customer_phone' => '9999999999',
            'shipping_address_line1' => 'x', 'shipping_city' => 'x', 'shipping_state' => 'x', 'shipping_postal_code' => '500001', 'shipping_country' => 'India',
            'subtotal' => 300, 'discount_amount' => 0, 'shipping_fee' => 0, 'total' => 300,
            'payment_method' => 'cod', 'payment_status' => 'pending', 'status' => 'placed',
        ]);

        app(OldJewelleryWalletSpendService::class)->applySpend($user, 300, $order);

        $this->assertSame('0.00', $soon->fresh()->remaining_amount);
        $this->assertSame('used', $soon->fresh()->status);
        $this->assertSame('400.00', $later->fresh()->remaining_amount);
        $this->assertSame('partially_used', $later->fresh()->status);
        $this->assertSame('400.00', $user->fresh()->wallet_balance);
    }

    public function test_falls_through_to_non_expiring_balance_after_expiring_credits_exhausted(): void
    {
        $user = User::factory()->create(['wallet_balance' => 500]);
        // Only 200 of expiring credit exists; the other 300 in wallet_balance
        // is non-expiring (e.g. reward-submission credit).
        $this->makeCredit($user, 200, now()->addDays(2));

        $order = Order::create([
            'order_number' => 'ORD-TEST-SPEND-2',
            'customer_name' => 'T', 'customer_email' => 't@example.com', 'customer_phone' => '9999999999',
            'shipping_address_line1' => 'x', 'shipping_city' => 'x', 'shipping_state' => 'x', 'shipping_postal_code' => '500001', 'shipping_country' => 'India',
            'subtotal' => 400, 'discount_amount' => 0, 'shipping_fee' => 0, 'total' => 400,
            'payment_method' => 'cod', 'payment_status' => 'pending', 'status' => 'placed',
        ]);

        app(OldJewelleryWalletSpendService::class)->applySpend($user, 400, $order);

        $this->assertSame('100.00', $user->fresh()->wallet_balance);
        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $user->id, 'type' => 'debit', 'amount' => '400.00',
        ]);
    }

    public function test_expired_credits_are_never_spent(): void
    {
        $user = User::factory()->create(['wallet_balance' => 200]);
        $expired = $this->makeCredit($user, 200, now()->subDay());
        $expired->update(['status' => 'expired']);

        $order = Order::create([
            'order_number' => 'ORD-TEST-SPEND-3',
            'customer_name' => 'T', 'customer_email' => 't@example.com', 'customer_phone' => '9999999999',
            'shipping_address_line1' => 'x', 'shipping_city' => 'x', 'shipping_state' => 'x', 'shipping_postal_code' => '500001', 'shipping_country' => 'India',
            'subtotal' => 100, 'discount_amount' => 0, 'shipping_fee' => 0, 'total' => 100,
            'payment_method' => 'cod', 'payment_status' => 'pending', 'status' => 'placed',
        ]);

        // wallet_balance itself must have already excluded the expired
        // portion (Task 13's expiry job debits it) — this test only asserts
        // the spend service doesn't touch the expired credit row itself.
        app(OldJewelleryWalletSpendService::class)->applySpend($user, 100, $order);

        $this->assertSame('200.00', $expired->fresh()->remaining_amount);
        $this->assertSame('expired', $expired->fresh()->status);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test tests/Feature/OldJewellery/CheckoutWalletSpendOrderTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write `app/Services/OldJewellery/OldJewelleryWalletSpendService.php`**

```php
<?php

namespace App\Services\OldJewellery;

use App\Models\OldJewelleryWalletCredit;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\WalletService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Wraps WalletService::debit() with old-jewellery-credit bookkeeping.
 * WalletService remains the only writer of users.wallet_balance and
 * wallet_transactions — this class only decides which
 * old_jewellery_wallet_credits rows get decremented (soonest-expires_at
 * first) to reflect that spend, entirely within the same DB transaction
 * as the debit so the two can never drift apart.
 */
class OldJewelleryWalletSpendService
{
    public function __construct(private readonly WalletService $walletService) {}

    public function applySpend(User $user, float $amount, Model $reference): WalletTransaction
    {
        return DB::transaction(function () use ($user, $amount, $reference) {
            $transaction = $this->walletService->debit($user, $amount, 'order_payment', $reference);

            $remainingToConsume = $amount;

            $credits = OldJewelleryWalletCredit::where('user_id', $user->id)
                ->whereIn('status', ['active', 'partially_used'])
                ->where('remaining_amount', '>', 0)
                ->orderBy('expires_at')
                ->lockForUpdate()
                ->get();

            foreach ($credits as $credit) {
                if ($remainingToConsume <= 0) {
                    break;
                }

                $consume = min($remainingToConsume, (float) $credit->remaining_amount);
                $newRemaining = round((float) $credit->remaining_amount - $consume, 2);

                $credit->update([
                    'remaining_amount' => $newRemaining,
                    'status' => $newRemaining <= 0 ? 'used' : 'partially_used',
                ]);

                $remainingToConsume = round($remainingToConsume - $consume, 2);
            }

            return $transaction;
        });
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd backend && php artisan test tests/Feature/OldJewellery/CheckoutWalletSpendOrderTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Wire `OldJewelleryWalletSpendService` into `CheckoutController`**

In `app/Http/Controllers/CheckoutController.php`, the audited wallet-debit block currently reads:

```php
                    if ($walletAmountUsed > 0) {
                        try {
                            $this->wallet->debit(auth()->user(), $walletAmountUsed, 'order_payment', $order);
                        } catch (\DomainException $e) {
                            throw new \RuntimeException($e->getMessage(), previous: $e);
                        }
```

Change the single call `$this->wallet->debit(auth()->user(), $walletAmountUsed, 'order_payment', $order);` to:

```php
                        try {
                            app(\App\Services\OldJewellery\OldJewelleryWalletSpendService::class)
                                ->applySpend(auth()->user(), $walletAmountUsed, $order);
                        } catch (\DomainException $e) {
                            throw new \RuntimeException($e->getMessage(), previous: $e);
                        }
```

Everything else in that block (the `try`/`catch` wrapping, the clamping logic above it, the `$order->update([...])` after it) is unchanged — this swaps only which service performs the debit, keeping the exact same exception-to-checkout-error mapping.

- [ ] **Step 6: Run the existing checkout wallet test to confirm no regression**

Run: `cd backend && php artisan test --filter=VerifyCheckoutWalletUsageTest`
Expected: PASS — the pre-existing checkout-wallet test still passes unchanged (it does not create any `old_jewellery_wallet_credits` rows, so `OldJewelleryWalletSpendService::applySpend()` behaves identically to a plain `WalletService::debit()` call for that test's scenario — the credits query simply returns an empty collection).

- [ ] **Step 7: Run the full project test suite**

Run: `cd backend && php artisan test`
Expected: PASS — entire suite, old and new.

- [ ] **Step 8: Commit**

```bash
cd backend
git add app/Services/OldJewellery/OldJewelleryWalletSpendService.php app/Http/Controllers/CheckoutController.php tests/Feature/OldJewellery/CheckoutWalletSpendOrderTest.php
git commit -m "feat: spend expiring old-jewellery wallet credits before general balance at checkout"
```

---

## Task 15: API documentation, final full-suite run, README/env cross-check

**Files:**
- Create: `docs/API_DOCUMENTATION.md` (repo root, not `backend/` — matches where `docs/superpowers/` already lives; adjust to `backend/docs/API_DOCUMENTATION.md` only if the team's convention for consumer-facing docs differs — check for an existing `docs/` or `API_DOCUMENTATION.md` file first with `find . -iname "API_DOCUMENTATION*"` before creating a new location)
- No other files modified in this task — it is documentation + verification only.

**Interfaces:**
- None (terminal task; documents the API surface built in Tasks 8-10).

- [ ] **Step 1: Check for an existing docs location**

Run: `find /Users/ayushman/Desktop/estele-jewellery -iname "API_DOCUMENTATION*" -o -iname "openapi*.yaml" -o -iname "openapi*.json" 2>/dev/null`
Expected: no matches (confirms this is a new file, not an update) — if a match is found, update that file's location/format instead of creating a new one.

- [ ] **Step 2: Write `docs/API_DOCUMENTATION.md`**

```markdown
# Old Jewellery & Wallet API

Base URL: `{APP_URL}/api/v1`

All responses use this envelope:

Success:
```json
{ "success": true, "message": "...", "data": { ... } }
```

Validation error (422):
```json
{ "success": false, "message": "Validation failed.", "errors": { "video": ["A video is required."] } }
```

Domain error (409, e.g. bidding closed / already responded):
```json
{ "success": false, "message": "The bidding window for this request has closed." }
```

Authorization error (403):
```json
{ "success": false, "message": "This action is unauthorized." }
```

Unauthenticated (401):
```json
{ "success": false, "message": "Unauthenticated." }
```

## Authentication

Customer and admin routes require a Sanctum bearer token:

```
Authorization: Bearer {token}
```

Vendor routes require no token — the `{token}` path segment IS the credential (a
long random string emailed/WhatsApp'd to the vendor). It expires when the
request's 3-hour bidding window closes and is single-use for accept/decline.

## Customer Endpoints

### Create an old jewellery request

`POST /api/v1/old-jewellery/requests` — `auth:sanctum`, `multipart/form-data`

| Field | Type | Required | Notes |
|---|---|---|---|
| description | string | no | max 2000 chars |
| image | file | no | jpg/jpeg/png/webp, max 3MB |
| video | file | **yes** | mp4/mov, max 20MB |

curl:
```bash
curl -X POST "{APP_URL}/api/v1/old-jewellery/requests" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json" \
  -F "description=Gold-plated necklace" \
  -F "video=@/path/to/video.mp4" \
  -F "image=@/path/to/image.jpg"
```

Success (201):
```json
{
  "success": true,
  "message": "Old jewellery request created successfully.",
  "data": {
    "request_number": "OJ-2026-000123",
    "status": "vendors_notified",
    "bidding_start_at": "2026-09-09T10:00:00+00:00",
    "bidding_end_at": "2026-09-09T13:00:00+00:00",
    "final_amount": null,
    "credited_amount": null
  }
}
```

Validation error (422) — missing video:
```json
{ "success": false, "message": "Validation failed.", "errors": { "video": ["A video is required."] } }
```

### List my requests

`GET /api/v1/old-jewellery/requests` — `auth:sanctum` — paginated, 15/page.

### View one request

`GET /api/v1/old-jewellery/requests/{request_number}` — `auth:sanctum`

Returns 403 if the request belongs to another customer.

### Check status only

`GET /api/v1/old-jewellery/requests/{request_number}/status` — `auth:sanctum`

```json
{ "success": true, "data": { "request_number": "OJ-2026-000123", "status": "bidding_active", "bidding_end_at": "2026-09-09T13:00:00+00:00" } }
```

## Wallet Endpoints

### Wallet balance

`GET /api/v1/wallet` — `auth:sanctum`

```json
{ "success": true, "data": { "balance": "990.00" } }
```

### Transaction history

`GET /api/v1/wallet/transactions` — `auth:sanctum` — paginated, 20/page.

### Old-jewellery-specific credits (with expiry)

`GET /api/v1/wallet/old-jewellery-credits` — `auth:sanctum` — paginated, 20/page.

```json
{
  "success": true,
  "data": [
    {
      "request_number": "OJ-2026-000123",
      "gross_amount": "1100.00",
      "deduction_amount": "110.00",
      "credited_amount": "990.00",
      "remaining_amount": "990.00",
      "credited_at": "2026-09-08T13:00:00+00:00",
      "expires_at": "2026-09-18T13:00:00+00:00",
      "status": "active"
    }
  ]
}
```

## Vendor Endpoints (token-authenticated, no bearer token)

### View the request

`GET /api/v1/vendor/old-jewellery/{token}`

```json
{
  "success": true,
  "data": {
    "request_number": "OJ-2026-000123",
    "description": "Gold-plated necklace",
    "bidding_end_at": "2026-09-09T13:00:00+00:00",
    "response_status": "pending",
    "image_url": "https://.../thumb.png",
    "video_url": "https://.../old-jewellery/vendor-video/42?signature=..."
  }
}
```

Expired token (403):
```json
{ "success": false, "message": "This vendor link has expired." }
```

Invalid token (404):
```json
{ "success": false, "message": "Invalid or unrecognized vendor link." }
```

### Accept with a bid

`POST /api/v1/vendor/old-jewellery/{token}/accept`

| Field | Type | Required |
|---|---|---|
| amount | numeric > 0 | yes |

curl:
```bash
curl -X POST "{APP_URL}/api/v1/vendor/old-jewellery/{token}/accept" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"amount": 950}'
```

Already responded / expired (409/403):
```json
{ "success": false, "message": "This invitation has already been responded to." }
```

### Decline

`POST /api/v1/vendor/old-jewellery/{token}/decline`

| Field | Type | Required |
|---|---|---|
| reason | string | no, max 1000 chars |

## Admin Endpoints

All require `auth:sanctum` + the corresponding Shield permission
(`ViewAny:OldJewelleryRequest` / `Update:OldJewelleryRequest`).

### List all requests

`GET /api/v1/admin/old-jewellery`

### View one request (full detail: all bids, all vendor responses, admin bid)

`GET /api/v1/admin/old-jewellery/{request_number}`

### List bids for a request

`GET /api/v1/admin/old-jewellery/{request_number}/bids`

### Submit an admin valuation

`POST /api/v1/admin/old-jewellery/{request_number}/bid`

| Field | Type | Required |
|---|---|---|
| amount | numeric > 0 | yes |

Rejected after deadline (409):
```json
{ "success": false, "message": "The bidding window for this request has closed." }
```

### Force-close bidding now

`POST /api/v1/admin/old-jewellery/{request_number}/close`

Idempotent — calling this after the request is already closed returns the
request unchanged rather than erroring.

## Business rules reference

- Bidding window: exactly 3 hours from request creation.
- One bid per vendor per request — no revisions once submitted.
- Highest valid bid wins; ties broken by earliest submission time.
- 10% platform fee deducted; 90% credited to wallet.
- Wallet credit expires exactly 10 days after crediting.
- At checkout, expiring old-jewellery wallet credits are spent before any
  non-expiring wallet balance, soonest-expiry first.

## Environment variables

| Variable | Purpose | Default behavior when blank |
|---|---|---|
| `WHATSAPP_PROVIDER` | WhatsApp Business API provider name | Falls back to logging messages instead of sending |
| `WHATSAPP_API_KEY` | Provider API key | — |
| `WHATSAPP_API_URL` | Provider API base URL | — |
| `WHATSAPP_FROM_NUMBER` | Sending number | — |
```

- [ ] **Step 3: Run the entire test suite one final time**

Run: `cd backend && php artisan test`
Expected: PASS — every test in the project (pre-existing + all `OldJewellery` tests from Tasks 1-14).

- [ ] **Step 4: Verify migrations run cleanly from scratch**

Run: `cd backend && php artisan migrate:fresh --seed --env=testing 2>&1 | tail -50` (or against a disposable local DB — never against a real/shared database)
Expected: all migrations apply without error, `ShieldSeeder` runs and creates the `Vendor`/`OldJewelleryRequest` permissions.

- [ ] **Step 5: Commit**

```bash
cd backend
git add docs/API_DOCUMENTATION.md
git commit -m "docs: add old-jewellery and wallet API documentation"
```

---

## Post-implementation notes (not tasks — flag to the user, do not act on autonomously)

- The unmerged worktree at `.worktrees/reward-submissions-wallet` (branch `feat/reward-submissions-wallet`) independently re-implements the reward-submission/wallet feature and diverges from `main`. This plan does not touch, merge, or delete it — reconciling that branch is a separate decision for the user.
- No real WhatsApp provider is wired up (per user decision) — `LogWhatsAppGateway` logs messages instead of sending them until `WHATSAPP_PROVIDER`+credentials are supplied and a real gateway class is added.
- Hostinger deployment requires: the scheduler's single cron entry (`* * * * * php artisan schedule:run`) already assumed by any existing Laravel deployment on this host — confirm it's configured, since this plan is the first feature in the codebase to depend on the scheduler actually running.
