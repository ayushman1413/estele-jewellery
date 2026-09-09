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
