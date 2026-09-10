<?php

namespace App\Filament\Widgets;

use App\Models\OldJewelleryRequest;
use App\Models\OldJewelleryVendorInvitation;
use App\Models\OldJewelleryWalletCredit;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OldJewelleryStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected ?string $heading = 'Old Jewellery';

    protected ?string $description = 'Sell-your-old-jewellery requests, vendor bidding and wallet credits (all time).';

    /**
     * @return Stat[]
     */
    public function getStatsForTest(): array
    {
        return $this->getStats();
    }

    public static function canView(): bool
    {
        return (bool) auth()->user()?->can('ViewAny:OldJewelleryRequest');
    }

    protected function getStats(): array
    {
        $totalRequests = OldJewelleryRequest::count();
        $pending = OldJewelleryRequest::whereIn('status', ['pending', 'submitted', 'vendors_notified'])->count();
        $activeBidding = OldJewelleryRequest::where('status', 'bidding_active')->count();
        $completed = OldJewelleryRequest::whereIn('status', ['bid_selected', 'wallet_pending', 'wallet_credited', 'wallet_expired', 'completed'])->count();
        $cancelled = OldJewelleryRequest::where('status', 'cancelled')->count();

        $vendorResponses = OldJewelleryVendorInvitation::where('response_status', '!=', 'pending')->count();
        $vendorAccepted = OldJewelleryVendorInvitation::where('response_status', 'accepted')->count();

        $highestBid = (float) OldJewelleryRequest::max('final_amount');
        $averageValuation = (float) OldJewelleryRequest::whereNotNull('final_amount')->avg('final_amount');

        $totalCredits = OldJewelleryWalletCredit::count();
        $totalCredited = (float) OldJewelleryWalletCredit::sum('credited_amount');
        $expiredCredits = (float) OldJewelleryWalletCredit::where('status', 'expired')->sum('remaining_amount');
        $usedCredits = OldJewelleryWalletCredit::whereIn('status', ['used', 'partially_used'])->count();
        $conversion = $totalCredits > 0 ? ($usedCredits / $totalCredits) * 100 : 0.0;

        return [
            Stat::make('Total Requests', number_format($totalRequests))
                ->icon(Heroicon::OutlinedSparkles)
                ->color('primary')
                ->description("{$cancelled} cancelled"),
            Stat::make('Pending Requests', number_format($pending))
                ->icon(Heroicon::OutlinedClock)
                ->color('gray')
                ->description('Awaiting vendor invitations'),
            Stat::make('Active Bidding', number_format($activeBidding))
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('warning')
                ->description('Inside the 3-hour window'),
            Stat::make('Completed Requests', number_format($completed))
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->description('Bid selected or wallet credited'),
            Stat::make('Vendor Responses', number_format($vendorResponses))
                ->icon(Heroicon::OutlinedUserGroup)
                ->color('info')
                ->description("{$vendorAccepted} accepted"),
            Stat::make('Highest Bid', '₹'.number_format($highestBid, 2))
                ->icon(Heroicon::OutlinedTrophy)
                ->color('success')
                ->description('Average valuation ₹'.number_format($averageValuation, 2)),
            Stat::make('Wallet Credited', '₹'.number_format($totalCredited, 2))
                ->icon(Heroicon::OutlinedWallet)
                ->color('primary')
                ->description(number_format($totalCredits).' credits issued'),
            Stat::make('Expired Wallet Credits', '₹'.number_format($expiredCredits, 2))
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->description('Unused amount lost to expiry'),
            Stat::make('Conversion to Purchase', number_format($conversion, 1).'%')
                ->icon(Heroicon::OutlinedShoppingBag)
                ->color('success')
                ->description("{$usedCredits} of {$totalCredits} credits used on orders"),
        ];
    }
}
