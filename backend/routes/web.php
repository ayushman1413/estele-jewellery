<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AdminOldJewelleryMediaController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\CmsPageController;
use App\Http\Controllers\CollectionController;
use App\Http\Controllers\FaqController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\NewsletterController;
use App\Http\Controllers\OldJewellerySellController;
use App\Http\Controllers\OtpAuthController;
use App\Http\Controllers\PanelPasswordSetupController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\RewardSubmissionController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\VendorBidController;
use App\Http\Controllers\VendorMediaController;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

Route::get('/sitemap.xml', [SitemapController::class, 'index'])
    ->name('sitemap');

Route::get('/robots.txt', function () {
    $settings = Cache::remember(
        'site.settings',
        3600,
        fn () => Setting::pluck('value', 'key')->toArray()
    );

    $content = $settings['robots_txt'] ?? null;

    if (! $content) {
        $content = "User-agent: *\nAllow: /\nDisallow: /cart\nDisallow: /checkout\nDisallow: /account\nDisallow: /login\nDisallow: /register\nDisallow: /forgot-password\nDisallow: /reset-password\nDisallow: /payment\nDisallow: /search\n\nSitemap: ".url('/sitemap.xml');
    }

    return response($content, 200)
        ->header('Content-Type', 'text/plain');
})->name('robots');

Route::get('/', [HomeController::class, 'index'])
    ->name('home');

Route::get('/categories', [CategoryController::class, 'index'])
    ->name('categories.index');

Route::get('/categories/{category:slug}', [CategoryController::class, 'show'])
    ->name('categories.show');

Route::get('/collections', [CollectionController::class, 'index'])
    ->name('collections.index');

Route::get('/collections/{collection:slug}', [CollectionController::class, 'show'])
    ->name('collections.show');

Route::get('/products/{product:slug}', [ProductController::class, 'show'])
    ->name('products.show');

Route::post('/products/{product:slug}/reviews', [ReviewController::class, 'store'])
    ->name('products.reviews.store')
    ->middleware('throttle:5,60');

// The wishlist itself lives in the visitor's own browser (localStorage, see
// the WISHLIST blocks in app.js), so this route just renders every active
// product and the client hides the ones that were never saved — no account
// needed to keep a wishlist, same as before the Blade port.
Route::get('/wishlist', [ProductController::class, 'wishlist'])
    ->name('wishlist');

Route::get('/search', [SearchController::class, 'index'])
    ->name('search')
    ->middleware('throttle:60,1');

Route::get('/search/suggest', [SearchController::class, 'suggest'])
    ->name('search.suggest')
    ->middleware('throttle:60,1');

/*
|--------------------------------------------------------------------------
| Guest Routes
|--------------------------------------------------------------------------
*/

Route::middleware('guest')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Mobile OTP Login (only login method — no email/password)
    |--------------------------------------------------------------------------
    */

    Route::get('/login', [OtpAuthController::class, 'showPhone'])
        ->name('login');

    Route::post('/login', [OtpAuthController::class, 'sendCode'])
        ->name('login.send')
        ->middleware('throttle:5,1');

    Route::get('/login/verify', [OtpAuthController::class, 'showVerify'])
        ->name('login.verify');

    Route::post('/login/verify', [OtpAuthController::class, 'verifyCode'])
        ->name('login.verify.attempt')
        ->middleware('throttle:10,1');

    Route::post('/login/resend', [OtpAuthController::class, 'resend'])
        ->name('login.resend')
        ->middleware('throttle:3,1');

    /*
    |--------------------------------------------------------------------------
    | Registration (only reached after OTP verification finds no account)
    |--------------------------------------------------------------------------
    */

    Route::get('/register', [AuthController::class, 'showRegister'])
        ->name('register');

    Route::post('/register', [AuthController::class, 'register'])
        ->name('register.attempt')
        ->middleware('throttle:10,1');
});

/*
|--------------------------------------------------------------------------
| Logout
|--------------------------------------------------------------------------
*/

Route::post('/logout', [AuthController::class, 'logout'])
    ->name('logout')
    ->middleware('auth');

/*
|--------------------------------------------------------------------------
| Authenticated Account Routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {

    Route::get('/account', [AccountController::class, 'index'])
        ->name('account.index');

    Route::patch('/account/profile', [AccountController::class, 'updateProfile'])
        ->name('account.profile');

    Route::get('/account/orders/{order:order_number}', [AccountController::class, 'orderShow'])
        ->name('account.orders.show');

    Route::get('/account/orders/{order:order_number}/invoice', [AccountController::class, 'orderInvoice'])
        ->name('account.orders.invoice');

    Route::post('/account/orders/{order:order_number}/cancellation-request', [AccountController::class, 'requestCancellation'])
        ->name('account.orders.cancellation-request')
        ->middleware('throttle:10,1');

    Route::get('/account/addresses', [AccountController::class, 'addresses'])
        ->name('account.addresses');

    Route::post('/account/addresses', [AccountController::class, 'addressStore'])
        ->name('account.addresses.store');

    Route::patch('/account/addresses/{address}', [AccountController::class, 'addressUpdate'])
        ->name('account.addresses.update');

    Route::delete('/account/addresses/{address}', [AccountController::class, 'addressDestroy'])
        ->name('account.addresses.destroy');

    Route::get('/account/rewards', [RewardSubmissionController::class, 'index'])
        ->name('account.rewards.index');

    Route::post('/account/rewards', [RewardSubmissionController::class, 'store'])
        ->name('account.rewards.store')
        ->middleware('throttle:10,60');

    Route::get('/account/sell-jewellery', [OldJewellerySellController::class, 'landing'])
        ->name('account.sell-jewellery.landing');

    Route::get('/account/sell-jewellery/new', [OldJewellerySellController::class, 'create'])
        ->name('account.sell-jewellery.create');

    Route::post('/account/sell-jewellery', [OldJewellerySellController::class, 'store'])
        ->name('account.sell-jewellery.store')
        ->middleware('throttle:10,60');

    Route::get('/account/sell-jewellery/requests', [OldJewellerySellController::class, 'index'])
        ->name('account.sell-jewellery.index');

    Route::get('/account/sell-jewellery/requests/{oldJewelleryRequest:request_number}', [OldJewellerySellController::class, 'show'])
        ->name('account.sell-jewellery.show');

    Route::get('/account/sell-jewellery/requests/{oldJewelleryRequest:request_number}/status', [OldJewellerySellController::class, 'status'])
        ->name('account.sell-jewellery.status');

    Route::get('/account/sell-jewellery/wallet', [OldJewellerySellController::class, 'wallet'])
        ->name('account.sell-jewellery.wallet');
});

/*
|--------------------------------------------------------------------------
| Newsletter
|--------------------------------------------------------------------------
*/

Route::post('/newsletter/subscribe', [NewsletterController::class, 'store'])
    ->name('newsletter.subscribe')
    ->middleware('throttle:10,60');

/*
|--------------------------------------------------------------------------
| Content
|--------------------------------------------------------------------------
*/

Route::get('/blogs', [BlogController::class, 'index'])
    ->name('blogs.index');

Route::get('/blogs/{blog:slug}', [BlogController::class, 'show'])
    ->name('blogs.show');

Route::get('/faq', [FaqController::class, 'index'])
    ->name('faq.index');

Route::get('/pages/{cmsPage:slug}', [CmsPageController::class, 'show'])
    ->name('pages.show');

/*
|--------------------------------------------------------------------------
| Panel password setup (vendor / admin invites)
|--------------------------------------------------------------------------
| Reached only from an emailed one-time token. Throttled because the token is
| the only thing standing between a guessed link and a set password.
*/

Route::get('/panel/set-password/{token}', [PanelPasswordSetupController::class, 'show'])
    ->middleware('throttle:10,1')
    ->name('panel.password.setup');

Route::post('/panel/set-password', [PanelPasswordSetupController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('panel.password.store');

/*
|--------------------------------------------------------------------------
| Cart
|--------------------------------------------------------------------------
*/

Route::get('/cart', [CartController::class, 'index'])
    ->name('cart.index');

Route::post('/cart/coupon', [CartController::class, 'applyCoupon'])
    ->name('cart.coupon.apply')
    ->middleware('throttle:20,1');

Route::delete('/cart/coupon', [CartController::class, 'removeCoupon'])
    ->name('cart.coupon.remove')
    ->middleware('throttle:20,1');

Route::post('/cart/{product:slug}', [CartController::class, 'store'])
    ->name('cart.store');

Route::patch('/cart/items/{cartItem}', [CartController::class, 'update'])
    ->name('cart.update');

Route::delete('/cart/items/{cartItem}', [CartController::class, 'destroy'])
    ->name('cart.destroy');

/*
|--------------------------------------------------------------------------
| Checkout
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {

    Route::get('/checkout', [CheckoutController::class, 'index'])
        ->name('checkout.index');

    Route::post('/checkout', [CheckoutController::class, 'store'])
        ->name('checkout.store')
        ->middleware('throttle:10,1');
});

Route::get('/checkout/pincode/{postalCode}', [CheckoutController::class, 'pincodeLookup'])
    ->name('checkout.pincode-lookup')
    ->middleware('throttle:30,1')
    ->where('postalCode', '[0-9]{6}');

Route::get('/checkout/confirmation/{order:order_number}', [CheckoutController::class, 'confirmation'])
    ->name('checkout.confirmation')
    ->middleware('throttle:20,1');

/*
|--------------------------------------------------------------------------
| Payment
|--------------------------------------------------------------------------
*/

Route::get('/payment/{order:order_number}', [PaymentController::class, 'show'])
    ->name('payment.show')
    ->middleware('throttle:20,1');

Route::post('/payment/{order:order_number}/callback', [PaymentController::class, 'callback'])
    ->name('payment.callback')
    ->middleware('throttle:20,1');

Route::post('/webhooks/razorpay', [PaymentController::class, 'webhook'])
    ->name('webhooks.razorpay');

/*
|--------------------------------------------------------------------------
| Old Jewellery — Vendor Media (signed URL only, no session auth)
|--------------------------------------------------------------------------
*/

Route::get('/old-jewellery/vendor-video/{invitation}', [VendorMediaController::class, 'video'])
    ->name('old-jewellery.vendor.video')
    ->middleware('signed');

Route::get('/old-jewellery/vendor/{token}', [VendorBidController::class, 'show'])
    ->name('old-jewellery.vendor.show')
    ->middleware('throttle:30,1');

Route::post('/old-jewellery/vendor/{token}/accept', [VendorBidController::class, 'accept'])
    ->name('old-jewellery.vendor.accept')
    ->middleware('throttle:30,1');

Route::post('/old-jewellery/vendor/{token}/decline', [VendorBidController::class, 'decline'])
    ->name('old-jewellery.vendor.decline')
    ->middleware('throttle:30,1');
Route::get('/admin/old-jewellery/{oldJewelleryRequest}/video', [AdminOldJewelleryMediaController::class, 'video'])
    ->middleware('auth')
    ->name('admin.old-jewellery.video');

// The image original sits on a private disk with no public URL, so the
// admin lightbox streams it through the controller the same way the video is.
Route::get('/admin/old-jewellery/{oldJewelleryRequest}/image', [AdminOldJewelleryMediaController::class, 'image'])
    ->middleware('auth')
    ->name('admin.old-jewellery.image');
