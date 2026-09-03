<?php

namespace App\Filament\Widgets;

use App\Models\Cart;
use App\Models\Order;
use App\Models\Review;
use App\Models\User;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

class StoreStatsWidget extends StatsOverviewWidget
{
    /**
     * Thin public wrapper around the protected getStats() so feature tests
     * can assert the computed values directly (Filament widgets aren't
     * otherwise easy to render headlessly in a test).
     *
     * @return Stat[]
     */
    public function getStatsForTest(): array
    {
        return $this->getStats();
    }

    protected function getStats(): array
    {
        $periodStart = now()->subDays(30);
        $previousStart = now()->subDays(60);
        $previousEnd = $periodStart;

        return [
            $this->makeStat(
                label: 'Total Orders',
                icon: Heroicon::OutlinedClipboardDocumentList,
                color: 'primary',
                current: Order::whereBetween('created_at', [$periodStart, now()])->count(),
                previous: Order::whereBetween('created_at', [$previousStart, $previousEnd])->count(),
            ),

            $this->makeStat(
                label: 'Total Revenue',
                icon: Heroicon::OutlinedBanknotes,
                color: 'success',
                current: (float) Order::whereBetween('created_at', [$periodStart, now()])->where('status', '!=', 'cancelled')->sum('total'),
                previous: (float) Order::whereBetween('created_at', [$previousStart, $previousEnd])->where('status', '!=', 'cancelled')->sum('total'),
                formatter: fn (float $value) => '₹' . number_format($value, 2),
            ),

            $this->makeStat(
                label: 'Pending Orders',
                icon: Heroicon::OutlinedClock,
                color: 'warning',
                current: Order::whereBetween('created_at', [$periodStart, now()])->whereIn('status', ['placed', 'accepted', 'packed'])->count(),
                previous: Order::whereBetween('created_at', [$previousStart, $previousEnd])->whereIn('status', ['placed', 'accepted', 'packed'])->count(),
            ),

            $this->makeStat(
                label: 'Delivered Orders',
                icon: Heroicon::OutlinedCheckCircle,
                color: 'info',
                current: Order::whereBetween('created_at', [$periodStart, now()])->where('status', 'delivered')->count(),
                previous: Order::whereBetween('created_at', [$previousStart, $previousEnd])->where('status', 'delivered')->count(),
            ),

            $this->makeStat(
                label: 'Cart Abandoned',
                icon: Heroicon::OutlinedShoppingCart,
                color: 'danger',
                current: $this->abandonedCartsCount($periodStart, now()),
                previous: $this->abandonedCartsCount($previousStart, $previousEnd),
            ),

            $this->makeStat(
                label: 'Total Reviews',
                icon: Heroicon::OutlinedStar,
                color: 'warning',
                current: Review::whereBetween('created_at', [$periodStart, now()])->count(),
                previous: Review::whereBetween('created_at', [$previousStart, $previousEnd])->count(),
            ),

            $this->makeStat(
                label: 'Total Customers',
                icon: Heroicon::OutlinedUsers,
                color: 'info',
                // "Customer" = a user with no admin-panel role (see User::canAccessPanel()).
                current: User::doesntHave('roles')->whereBetween('created_at', [$periodStart, now()])->count(),
                previous: User::doesntHave('roles')->whereBetween('created_at', [$previousStart, $previousEnd])->count(),
            ),
        ];
    }

    private function abandonedCartsCount(Carbon $from, Carbon $to): int
    {
        return Cart::whereBetween('created_at', [$from, $to])
            ->whereHas('items')
            ->whereDoesntHave('items', fn ($query) => $query->where('updated_at', '>=', now()->subHours(24)))
            ->count();
    }

    private function makeStat(
        string $label,
        Heroicon $icon,
        string $color,
        int|float $current,
        int|float $previous,
        ?\Closure $formatter = null,
    ): Stat {
        $percentChange = $previous > 0
            ? (($current - $previous) / $previous) * 100
            : ($current > 0 ? 100.0 : 0.0);

        $isPositive = $percentChange >= 0;
        $formattedValue = $formatter ? $formatter($current) : number_format($current);

        return Stat::make($label, $formattedValue)
            ->icon($icon)
            ->color($color)
            ->description(sprintf('%s%.1f%% vs previous 30 days', $isPositive ? '+' : '', $percentChange))
            ->descriptionIcon($isPositive ? Heroicon::OutlinedArrowTrendingUp : Heroicon::OutlinedArrowTrendingDown)
            ->descriptionColor($isPositive ? 'success' : 'danger');
    }
}
