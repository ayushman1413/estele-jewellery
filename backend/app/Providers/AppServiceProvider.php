<?php

namespace App\Providers;

use App\Models\Banner;
use App\Models\Blog;
use App\Models\BlogCategory;
use App\Models\Category;
use App\Models\CmsPage;
use App\Models\Collection;
use App\Models\Coupon;
use App\Models\Faq;
use App\Models\FaqCategory;
use App\Models\HomepageBlock;
use App\Models\HomepageBlockItem;
use App\Models\Offer;
use App\Models\OldJewelleryRequest;
use App\Models\Popup;
use App\Models\Product;
use App\Models\Review;
use App\Models\Setting;
use App\Models\User;
use App\Observers\BannerObserver;
use App\Observers\BlogCategoryObserver;
use App\Observers\BlogObserver;
use App\Observers\CategoryObserver;
use App\Observers\CmsPageObserver;
use App\Observers\CollectionObserver;
use App\Observers\CouponObserver;
use App\Observers\FaqCategoryObserver;
use App\Observers\FaqObserver;
use App\Observers\HomepageBlockItemObserver;
use App\Observers\HomepageBlockObserver;
use App\Observers\OfferObserver;
use App\Observers\PopupObserver;
use App\Observers\ProductObserver;
use App\Observers\ReviewObserver;
use App\Observers\SettingObserver;
use App\Policies\CustomerPolicy;
use App\Policies\OldJewelleryRequestPolicy;
use App\Services\WhatsApp\CloudApiWhatsAppGateway;
use App\Services\WhatsApp\LogWhatsAppGateway;
use App\Services\Otp\OtpManager;
use App\Services\WhatsApp\WhatsAppGateway;
use App\View\Composers\SiteDataComposer;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            WhatsAppGateway::class,
            fn () => config('services.whatsapp.provider') === 'cloud_api'
                ? new CloudApiWhatsAppGateway(
                    apiUrl: (string) config('services.whatsapp.api_url'),
                    apiKey: (string) config('services.whatsapp.api_key'),
                    fromNumber: (string) config('services.whatsapp.from_number'),
                )
                : new LogWhatsAppGateway,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        FilamentShield::enforcePolicies();

        // super_admin sees everything regardless of which permission rows the
        // database happens to hold — a resource added after the last
        // ShieldSeeder run (Customers was one) must not vanish from the panel.
        Gate::before(fn ($user) => $user->hasRole('super_admin') ? true : null);

        // Logins stay alive for five years unless the user signs out.
        Auth::guard('web')->setRememberDuration((int) config('session.lifetime'));

        // The per-IP throttles on the OTP routes cannot stop SMS pumping from
        // rotating addresses, so codes are also capped per phone number.
        RateLimiter::for('otp-phone', function (Request $request) {
            $raw = $request->input('phone') ?: $request->session()->get('otp_phone');
            $phone = $raw ? OtpManager::normalisePhone((string) $raw) : null;

            if (! $phone) {
                return Limit::perMinute(5)->by('otp-ip:'.$request->ip());
            }

            $response = fn () => back()->with('error', 'Too many codes were requested for this number. Please wait a few minutes and try again.');

            return [
                Limit::perMinute(3)->by('otp-phone:'.$phone)->response($response),
                Limit::perHour(10)->by('otp-phone-hour:'.$phone)->response($response),
            ];
        });

        // Laravel's policy auto-discovery matches on class name
        // ("FooPolicy" <-> "Foo"), which can't work for User: the Customers
        // admin resource needs its own permission namespace (Customer, not
        // User) so it never gets tangled up with panel-staff auth or any
        // other resource that might key off App\Models\User later.
        Gate::policy(User::class, CustomerPolicy::class);
        Gate::policy(OldJewelleryRequest::class, OldJewelleryRequestPolicy::class);

        View::composer('layouts.app', SiteDataComposer::class);

        // Keep SiteDataComposer's 1-hour nav/settings cache from ever serving stale
        // content after an admin save — see the "cache invalidation" Definition of Done gap.
        Category::observe(CategoryObserver::class);
        Collection::observe(CollectionObserver::class);
        Setting::observe(SettingObserver::class);
        Product::observe(ProductObserver::class);
        Banner::observe(BannerObserver::class);
        HomepageBlock::observe(HomepageBlockObserver::class);
        HomepageBlockItem::observe(HomepageBlockItemObserver::class);
        Offer::observe(OfferObserver::class);
        Coupon::observe(CouponObserver::class);
        BlogCategory::observe(BlogCategoryObserver::class);
        Blog::observe(BlogObserver::class);
        CmsPage::observe(CmsPageObserver::class);
        FaqCategory::observe(FaqCategoryObserver::class);
        Faq::observe(FaqObserver::class);
        Review::observe(ReviewObserver::class);
        Popup::observe(PopupObserver::class);
    }
}
