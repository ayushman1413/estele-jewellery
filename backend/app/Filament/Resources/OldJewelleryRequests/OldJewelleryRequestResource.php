<?php

namespace App\Filament\Resources\OldJewelleryRequests;

use App\Filament\Resources\OldJewelleryRequests\Pages\ListOldJewelleryRequests;
use App\Filament\Resources\OldJewelleryRequests\Pages\ViewOldJewelleryRequest;
use App\Filament\Resources\OldJewelleryRequests\Schemas\OldJewelleryRequestInfolist;
use App\Filament\Resources\OldJewelleryRequests\Tables\OldJewelleryRequestsTable;
use App\Filament\Support\NavigationSeen;
use App\Models\OldJewelleryRequest;
use App\Models\Vendor;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OldJewelleryRequestResource extends Resource
{
    protected static ?string $model = OldJewelleryRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $navigationLabel = 'Old Jewellery';

    protected static string|\UnitEnum|null $navigationGroup = 'Sell Jewellery';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'request_number';

    public static function getNavigationBadge(): ?string
    {
        return NavigationSeen::badge('old-jewellery-requests', OldJewelleryRequest::query()->whereIn('status', ['pending', 'submitted']));
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'New requests waiting for approval';
    }

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

    /**
     * The shared App\Policies\OldJewelleryRequestPolicy::viewAny() hardcodes
     * true — correct for the customer API (Task 10), where the controller
     * query itself scopes results to the authenticated user's own requests,
     * but wrong here: it would let any authenticated panel user bypass the
     * ViewAny:OldJewelleryRequest Shield permission. Override explicitly
     * rather than touching the shared policy (that would break the API).
     */
    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('ViewAny:OldJewelleryRequest');
    }

    /**
     * Same reasoning as canViewAny(): the shared policy's view() checks
     * request ownership (customer use case), which would deny every admin
     * since admins don't "own" any request. Gate on the Shield permission
     * instead.
     */
    public static function canView(Model $record): bool
    {
        return (bool) auth()->user()?->can('View:OldJewelleryRequest')
            && self::isVisibleToCurrentVendor($record);
    }

    /**
     * A vendor contact only ever sees requests it was actually invited to bid
     * on. The Shield permission decides *whether* they reach this resource;
     * this decides *which rows* — without it, granting ViewAny would expose
     * every customer's name, address and photos to an outside buyer.
     *
     * Staff without a linked vendor record (admins) are unaffected.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if ($vendorId = self::currentVendorId()) {
            $query->whereHas('invitations', fn (Builder $q) => $q->where('vendor_id', $vendorId));
        }

        return $query;
    }

    private static function isVisibleToCurrentVendor(Model $record): bool
    {
        $vendorId = self::currentVendorId();

        return $vendorId === null
            || $record->invitations()->where('vendor_id', $vendorId)->exists();
    }

    /**
     * The bidding vendor tied to the signed-in user, or null when the user is
     * ordinary staff. Only active 'vendor' contacts are scoped — an 'admin'
     * contact is a normal panel login that happens to live in the same table.
     *
     * Public so the infolist/table can hide customer PII, other vendors'
     * bids, and the admin bid from a vendor contact's own view of a request
     * it was invited to — row-scoping alone only decides *which* requests a
     * vendor login can open, not what fields render once it does.
     */
    public static function currentVendorId(): ?int
    {
        $userId = auth()->id();

        if (! $userId) {
            return null;
        }

        return Vendor::query()
            ->where('user_id', $userId)
            ->where('access_role', Vendor::ACCESS_ROLE_VENDOR)
            ->value('id');
    }
}
