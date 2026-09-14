<?php

namespace Tests\Feature;

use App\Filament\Resources\Banners\Pages\CreateBanner;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Models\User;
use Database\Seeders\ShieldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Product image/video and Banner image upload size caps: 500KB per product
 * image, 2MB per product video, 1MB per banner image.
 */
class VerifyMediaUploadLimitsTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSuperAdmin(): User
    {
        $this->seed(ShieldSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('super_admin');
        $this->actingAs($user);

        return $user;
    }

    public function test_product_image_over_500kb_is_rejected(): void
    {
        $this->actingAsSuperAdmin();

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'title' => 'Oversized Image Product',
                'slug' => 'oversized-image-product',
                'sku' => 'SKU-OVERSIZED-IMG',
                'price' => 999,
                'stock_quantity' => 5,
                'is_active' => true,
                'gallery' => [$this->fakePng('big.png', 600)],
            ])
            ->call('create')
            ->assertHasFormErrors(['gallery']);
    }

    public function test_product_image_within_500kb_is_accepted(): void
    {
        $this->actingAsSuperAdmin();

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'title' => 'Fitting Image Product',
                'slug' => 'fitting-image-product',
                'sku' => 'SKU-FITTING-IMG',
                'price' => 999,
                'stock_quantity' => 5,
                'is_active' => true,
                'gallery' => [$this->fakePng('ok.png', 400)],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('products', ['slug' => 'fitting-image-product']);
    }

    public function test_product_video_over_2mb_is_rejected(): void
    {
        $this->actingAsSuperAdmin();

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'title' => 'Oversized Video Product',
                'slug' => 'oversized-video-product',
                'sku' => 'SKU-OVERSIZED-VID',
                'price' => 999,
                'stock_quantity' => 5,
                'is_active' => true,
                'video' => [$this->fakeVideo('clip.mp4', 3000)],
            ])
            ->call('create')
            ->assertHasFormErrors(['video']);
    }

    public function test_product_video_within_2mb_is_accepted(): void
    {
        $this->actingAsSuperAdmin();

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'title' => 'Fitting Video Product',
                'slug' => 'fitting-video-product',
                'sku' => 'SKU-FITTING-VID',
                'price' => 999,
                'stock_quantity' => 5,
                'is_active' => true,
                'video' => [$this->fakeVideo('clip.mp4', 1500)],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('products', ['slug' => 'fitting-video-product']);
    }

    public function test_banner_image_over_1mb_is_rejected(): void
    {
        $this->actingAsSuperAdmin();

        Livewire::test(CreateBanner::class)
            ->fillForm([
                'title' => 'Oversized banner',
                'sort_order' => 0,
                'is_active' => true,
                'image' => [$this->fakePng('big-banner.png', 1200)],
                'image_alt_text' => 'Oversized banner',
            ])
            ->call('create')
            ->assertHasFormErrors(['image']);
    }

    public function test_banner_image_within_1mb_is_accepted(): void
    {
        $this->actingAsSuperAdmin();

        Livewire::test(CreateBanner::class)
            ->fillForm([
                'title' => 'Fitting banner',
                'sort_order' => 0,
                'is_active' => true,
                'image' => [$this->fakePng('ok-banner.png', 800)],
                'image_alt_text' => 'Fitting banner',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('banners', ['title' => 'Fitting banner']);
    }
}
