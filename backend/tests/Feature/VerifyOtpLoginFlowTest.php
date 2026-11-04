<?php

namespace Tests\Feature;

use App\Models\OtpCode;
use App\Models\User;
use App\Services\Otp\LogOtpGateway;
use App\Services\Otp\OtpManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Self-built phone + OTP login (no third-party gateway — see LogOtpGateway).
 * Covers the full send/verify loop, the registered-vs-not-registered branch,
 * and the security properties (hashed storage, single-use, expiry).
 *
 * Swaps in a recording gateway (below) so tests can read the plaintext code
 * the same way a real SMS gateway would receive it — OtpManager only ever
 * stores/checks the hash (see code_hash), it never exposes the plaintext
 * back out, so there's no legitimate way to read it except at send() time.
 */
class VerifyOtpLoginFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RecordingOtpGatewayForTests::$lastCode = null;
        RecordingOtpGatewayForTests::$deliver = true;
        $this->app->bind(LogOtpGateway::class, RecordingOtpGatewayForTests::class);
    }

    public function test_sending_a_code_stores_it_hashed_not_plaintext(): void
    {
        app(OtpManager::class)->issue('9876543210');

        $otp = OtpCode::where('phone', '9876543210')->first();

        $this->assertNotNull($otp);
        $this->assertNotSame(RecordingOtpGatewayForTests::$lastCode, $otp->code_hash);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check(RecordingOtpGatewayForTests::$lastCode, $otp->code_hash));
    }

    public function test_verify_succeeds_with_the_correct_code(): void
    {
        $manager = app(OtpManager::class);
        $manager->issue('9876543210');

        $this->assertTrue($manager->verify('9876543210', RecordingOtpGatewayForTests::$lastCode));
    }

    public function test_verify_fails_with_the_wrong_code(): void
    {
        $manager = app(OtpManager::class);
        $manager->issue('9876543210');

        $this->assertFalse($manager->verify('9876543210', '000000'));
    }

    public function test_a_code_cannot_be_reused_after_a_successful_verify(): void
    {
        $manager = app(OtpManager::class);
        $manager->issue('9876543210');
        $code = RecordingOtpGatewayForTests::$lastCode;

        $this->assertTrue($manager->verify('9876543210', $code));
        $this->assertFalse($manager->verify('9876543210', $code));
    }

    public function test_an_expired_code_does_not_verify(): void
    {
        $manager = app(OtpManager::class);
        $manager->issue('9876543210');
        $code = RecordingOtpGatewayForTests::$lastCode;
        OtpCode::where('phone', '9876543210')->update(['expires_at' => now()->subMinute()]);

        $this->assertFalse($manager->verify('9876543210', $code));
    }

    public function test_issuing_a_new_code_invalidates_the_previous_outstanding_one(): void
    {
        $manager = app(OtpManager::class);
        $manager->issue('9876543210');
        $firstCode = RecordingOtpGatewayForTests::$lastCode;

        $manager->issue('9876543210');

        $this->assertFalse($manager->verify('9876543210', $firstCode));
    }

    public function test_full_http_flow_logs_in_an_existing_user_by_phone(): void
    {
        $user = User::factory()->create(['phone' => '9876543210']);

        $this->post(route('login.send'), ['phone' => '9876543210'])
            ->assertRedirect(route('login.verify'));

        $this->post(route('login.verify.attempt'), ['code' => RecordingOtpGatewayForTests::$lastCode])
            ->assertRedirect(route('account.index'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_country_code_prefix_still_matches_the_registered_ten_digit_number(): void
    {
        $user = User::factory()->create(['phone' => '9876543210']);

        $this->post(route('login.send'), ['phone' => '919876543210'])
            ->assertRedirect(route('login.verify'));

        $this->post(route('login.verify.attempt'), ['code' => RecordingOtpGatewayForTests::$lastCode]);

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_returns_the_guest_to_the_page_that_required_it(): void
    {
        User::factory()->create(['phone' => '9876543210']);

        $this->get(route('checkout.index'))->assertRedirect(route('login'));

        $this->post(route('login.send'), ['phone' => '9876543210']);
        $this->post(route('login.verify.attempt'), ['code' => RecordingOtpGatewayForTests::$lastCode])
            ->assertRedirect(route('checkout.index'));
    }

    public function test_full_http_flow_redirects_an_unregistered_phone_to_registration_with_prefill(): void
    {
        $this->post(route('login.send'), ['phone' => '9998887770']);

        $response = $this->post(route('login.verify.attempt'), ['code' => RecordingOtpGatewayForTests::$lastCode]);

        $response->assertRedirect(route('register'));
        $this->assertGuest();

        $this->get(route('register'))->assertSee('9998887770', false);
    }

    public function test_registration_creates_a_mobile_only_account_and_logs_in(): void
    {
        $this->post(route('login.send'), ['phone' => '9990001111']);
        $this->post(route('login.verify.attempt'), ['code' => RecordingOtpGatewayForTests::$lastCode]);

        $this->post(route('register.attempt'), ['name' => 'Reg User', 'phone' => '9990001111'])
            ->assertRedirect(route('account.index'));

        $this->assertAuthenticated();
        $user = User::where('phone', '9990001111')->firstOrFail();
        $this->assertNull($user->password);
        $this->assertNull($user->email);
    }

    public function test_registration_is_refused_without_a_verified_phone_in_the_session(): void
    {
        $this->post(route('register.attempt'), ['name' => 'Reg User', 'phone' => '9990002222'])
            ->assertSessionHasErrors('phone');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['phone' => '9990002222']);
    }

    public function test_registration_rejects_a_phone_that_differs_from_the_verified_one(): void
    {
        $this->post(route('login.send'), ['phone' => '9990003333']);
        $this->post(route('login.verify.attempt'), ['code' => RecordingOtpGatewayForTests::$lastCode]);

        $this->post(route('register.attempt'), ['name' => 'Reg User', 'phone' => '9990004444'])
            ->assertSessionHasErrors('phone');

        $this->assertGuest();
    }

    public function test_a_wrong_code_shows_a_generic_error_and_keeps_the_user_logged_out(): void
    {
        User::factory()->create(['phone' => '9876543210']);
        $this->post(route('login.send'), ['phone' => '9876543210']);

        $this->post(route('login.verify.attempt'), ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_the_correct_code_stops_working_after_five_failed_attempts(): void
    {
        User::factory()->create(['phone' => '9876543210']);
        $this->post(route('login.send'), ['phone' => '9876543210']);
        $code = RecordingOtpGatewayForTests::$lastCode;

        foreach (range(1, 5) as $ignored) {
            $this->post(route('login.verify.attempt'), ['code' => '000000']);
        }

        $this->post(route('login.verify.attempt'), ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_resend_issues_a_new_code_and_retires_the_old_one(): void
    {
        User::factory()->create(['phone' => '9876543210']);
        $this->post(route('login.send'), ['phone' => '9876543210']);
        $first = RecordingOtpGatewayForTests::$lastCode;

        $this->post(route('login.resend'))->assertRedirect();
        $second = RecordingOtpGatewayForTests::$lastCode;

        $this->assertNotSame($first, $second);
        $this->post(route('login.verify.attempt'), ['code' => $first])->assertSessionHasErrors('code');
        $this->post(route('login.verify.attempt'), ['code' => $second])->assertRedirect(route('account.index'));
    }

    public function test_verify_pages_without_a_pending_number_send_the_user_back_to_login(): void
    {
        $this->get(route('login.verify'))->assertRedirect(route('login'));
        $this->post(route('login.verify.attempt'), ['code' => '123456'])->assertRedirect(route('login'));
        $this->post(route('login.resend'))->assertRedirect(route('login'));
    }

    public function test_a_failed_sms_delivery_is_reported_instead_of_pretending_a_code_was_sent(): void
    {
        RecordingOtpGatewayForTests::$deliver = false;

        $this->from(route('login'))
            ->post(route('login.send'), ['phone' => '9876543210'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('phone')
            ->assertSessionMissing('otp_phone');

        $this->assertSame(0, OtpCode::where('phone', '9876543210')->whereNull('consumed_at')->count());
    }

    public function test_repeated_code_requests_for_one_number_are_throttled(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->post(route('login.send'), ['phone' => '9876543210'])->assertRedirect(route('login.verify'));
        }

        $this->from(route('login'))
            ->post(route('login.send'), ['phone' => '9876543210'])
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');

        $this->assertSame(3, OtpCode::where('phone', '9876543210')->count());
    }
}

/**
 * Test double: same shape as LogOtpGateway but captures the plaintext code
 * in a static instead of writing it to the log.
 */
class RecordingOtpGatewayForTests extends LogOtpGateway
{
    public static ?string $lastCode = null;

    public static bool $deliver = true;

    public function send(string $phone, string $code): bool
    {
        self::$lastCode = $code;

        return self::$deliver;
    }
}
