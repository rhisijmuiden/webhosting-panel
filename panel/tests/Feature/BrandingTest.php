<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_product_is_called_dekwerk(): void
    {
        $this->assertSame('Dekwerk', Brand::name());
        $this->assertSame('Dekwerk', config('app.name'));
        $this->get('/login')->assertOk()
            ->assertSee('<title>', false)->assertSee('Dekwerk')
            ->assertSee(asset('brand/logo-wit.svg'))
            ->assertDontSee('Webhosting Panel')
            ->assertDontSee('rhis-logo');
        foreach (['logo', 'logoDark', 'icon', 'favicon'] as $file) {
            $this->assertFileExists(public_path(Brand::$file()), $file);
        }
    }

    public function test_the_hosting_company_defaults_to_the_config(): void
    {
        $this->assertSame(config('panel.brand.provider'), Brand::provider());
    }

    public function test_admin_changes_the_hosting_company_name_customers_see(): void
    {
        $this->actingAsAdmin();
        $this->get('/admin/branding')->assertOk()->assertSee('Company name')->assertSee('Dekwerk');
        $this->get('/admin/tools')->assertOk()->assertSee(route('admin.branding.edit'));

        $this->put('/admin/branding', ['provider' => 'Zeewind Hosting'])->assertRedirect('/admin/branding')->assertSessionHasNoErrors();
        $this->assertSame('Zeewind Hosting', Brand::provider());

        $html = view('dns-mail::partials.provider-pill', ['provider' => 'rhis'])->render();
        $this->assertStringContainsString('Zeewind Hosting Mail', $html);
        $this->assertSame('Laat Zeewind Hosting je DNS verzorgen', __('Let :provider run your DNS', ['provider' => Brand::provider()], 'nl'));
    }

    public function test_the_hosting_company_name_is_validated(): void
    {
        $this->actingAsAdmin();
        $this->put('/admin/branding', ['provider' => ''])->assertSessionHasErrors('provider');
        $this->put('/admin/branding', ['provider' => '<script>'])->assertSessionHasErrors('provider');
        $this->put('/admin/branding', ['provider' => str_repeat('a', 41)])->assertSessionHasErrors('provider');
    }

    public function test_customers_cannot_change_branding(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_CLIENT]));
        $this->get('/admin/branding')->assertForbidden();
    }

    public function test_branding_page_is_in_dutch(): void
    {
        $this->actingAsAdmin(User::factory()->admin()->create(['locale' => 'nl']));
        $this->get('/admin/branding')->assertOk()->assertSee('Huisstijl')->assertSee('Bedrijfsnaam');
    }
}
