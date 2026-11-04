<?php

namespace Tests\Feature;

use App\Filament\Resources\Banners\Pages\CreateBanner;
use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Models\User;
use Database\Seeders\ShieldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 5 gap closure (spec §3.1/§4.1 — "mandatory field at upload, reject
 * the upload/save if missing"). Category's image is optional, so alt text
 * is only conditionally required when an image is actually attached;
 * Banner's image is unconditionally required, so its alt text always is too.
 */
class VerifyImageAltTextEnforcementTest extends TestCase
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

    public function test_banner_requires_alt_text_when_image_present(): void
    {
        $this->actingAsSuperAdmin();

        Livewire::test(CreateBanner::class)
            ->fillForm([
                'title' => 'Test banner',
                'sort_order' => 0,
                'is_active' => true,
                'image' => [$this->fakePng('banner.png')],
                'image_alt_text' => '',
            ])
            ->call('create')
            ->assertHasFormErrors(['image_alt_text']);
    }

    public function test_category_does_not_require_alt_text_without_an_image(): void
    {
        $this->actingAsSuperAdmin();

        Livewire::test(CreateCategory::class)
            ->fillForm([
                'name' => 'No Image Category',
                'slug' => 'no-image-category',
                'sort_order' => 0,
                'image_alt_text' => '',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('categories', ['slug' => 'no-image-category']);
    }

    public function test_category_requires_alt_text_when_an_image_is_uploaded(): void
    {
        $this->actingAsSuperAdmin();

        Livewire::test(CreateCategory::class)
            ->fillForm([
                'name' => 'With Image Category',
                'slug' => 'with-image-category',
                'sort_order' => 0,
                'image' => [$this->fakePng('category.png')],
                'image_alt_text' => '',
            ])
            ->call('create')
            ->assertHasFormErrors(['image_alt_text']);
    }

    public function test_category_creates_successfully_with_image_and_alt_text(): void
    {
        $this->actingAsSuperAdmin();

        Livewire::test(CreateCategory::class)
            ->fillForm([
                'name' => 'Complete Category',
                'slug' => 'complete-category',
                'sort_order' => 0,
                'image' => [$this->fakePng('category.png')],
                'image_alt_text' => 'A necklace displayed on a marble surface',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('categories', [
            'slug' => 'complete-category',
            'image_alt_text' => 'A necklace displayed on a marble surface',
        ]);
    }
}
