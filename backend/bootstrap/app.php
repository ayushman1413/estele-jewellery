<?php

use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\VendorTokenAuth;
use App\Http\Middleware\VerifyFastrrRequest;
use App\Jobs\CloseExpiredOldJewelleryBiddingJob;
use App\Jobs\ExpireOldJewelleryWalletCreditsJob;
use App\Jobs\SendOldJewelleryWalletReminderJob;
use App\Models\Redirect;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Sentry\Laravel\Integration;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->job(new CloseExpiredOldJewelleryBiddingJob)
            ->everyFiveMinutes()
            ->name('old-jewellery:close-expired-bidding')
            ->withoutOverlapping();

        $schedule->job(new ExpireOldJewelleryWalletCreditsJob)
            ->daily()
            ->name('old-jewellery:expire-wallet-credits')
            ->withoutOverlapping();

        $schedule->job(new SendOldJewelleryWalletReminderJob)
            ->daily()
            ->name('old-jewellery:wallet-expiry-reminders')
            ->withoutOverlapping();

        // Safety net: any media row whose model is gone (and its files) is
        // removed, so nothing deleted keeps occupying disk.
        $schedule->command('media-library:clean --delete-orphaned')
            ->daily()
            ->withoutOverlapping();

        $schedule->command('model:prune')
            ->daily()
            ->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        // Railway (and similar PaaS hosts) terminate TLS at their edge proxy and
        // forward plain HTTP internally with X-Forwarded-Proto: https — without
        // trusting that header, Laravel generates http:// URLs on an https:// site,
        // which browsers block as mixed content for fetch()/XHR calls.
        $middleware->trustProxies(
            at: '*',
            headers: SymfonyRequest::HEADER_X_FORWARDED_FOR
                | SymfonyRequest::HEADER_X_FORWARDED_HOST
                | SymfonyRequest::HEADER_X_FORWARDED_PORT
                | SymfonyRequest::HEADER_X_FORWARDED_PROTO,
        );

        // Razorpay posts this server-to-server with no CSRF token — the
        // webhook signature check (PaymentController::webhook) is what
        // authenticates the request instead.
        $middleware->validateCsrfTokens(except: [
            'webhooks/razorpay',
        ]);

        // Phase 6 security audit — standard response headers Laravel doesn't
        // set by default. Covers the storefront; the admin panel gets the
        // same middleware separately in AdminPanelProvider since Filament
        // defines its own middleware stack, not this one.
        $middleware->append(SecurityHeaders::class);

        // Mobile OTP is the only login method — any 'auth'-gated route hit
        // while logged out (checkout, /account) sends the guest to /login to
        // ask for their mobile number. Generic wording (not "finish your
        // order") since this same redirect also fires for a bare /account
        // visit, not just checkout.
        $middleware->redirectGuestsTo(function () {
            session()->flash('error', 'Please log in to continue.');

            return route('login');
        });

        // Vendors have no login/session — this alias is their entire
        // authorization boundary for token-link API access (see
        // App\Http\Middleware\VendorTokenAuth).
        $middleware->alias([
            'vendor.token' => VendorTokenAuth::class,
            'fastrr' => VerifyFastrrRequest::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Every api/* FormRequest's automatic validation failure otherwise
        // renders Laravel's default {message, errors} shape, which breaks
        // the {success, message, errors} envelope every other api/* error
        // path (ApiController::error(), VendorTokenAuth, ...) uses.
        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], $e->status);
        });

        // Redirect Manager (spec §5/§6 — "old URLs never 404 when slug
        // changes"). Registered for both exception types because a route
        // with a bound wildcard (e.g. /products/{product:slug}) matches the
        // URI shape fine and throws ModelNotFoundException on a stale slug
        // — a plain Route::fallback() never sees that, only a genuinely
        // unmatched path (NotFoundHttpException). Returning null falls
        // through to Laravel's normal 404 rendering (the branded 404 view).
        $redirectCheck = function (Throwable $e, Request $request) {
            if ($request->method() !== 'GET' || $request->is('api/*')) {
                return null;
            }

            $redirect = Redirect::where('old_path', Redirect::normalizePath($request->path()))
                ->where('is_active', true)
                ->first();

            return $redirect ? redirect($redirect->new_path, $redirect->status_code) : null;
        };

        $exceptions->render(fn (NotFoundHttpException $e, Request $request) => $redirectCheck($e, $request));
        $exceptions->render(fn (ModelNotFoundException $e, Request $request) => $redirectCheck($e, $request));

        // Phase 7 monitoring (spec §10 "error tracking from day one") — reports
        // uncaught exceptions to Sentry. Genuinely no-op with no config change:
        // SENTRY_LARAVEL_DSN is unset by default, config/sentry.php resolves
        // 'dsn' => null in that case, and the SDK treats a null DSN as fully
        // disabled (never dials out). Becomes live the moment a real DSN from
        // a Sentry account is added to .env — same "ready but inactive until a
        // real value is provided" pattern as tracking_head_scripts.
        Integration::handles($exceptions);
    })->create();
