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
            'video' => $this->fakeVideo('video.mp4', 5000),
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'bidding_active');

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
