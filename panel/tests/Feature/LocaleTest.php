<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Support\Locales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_english_and_dutch_are_available(): void
    {
        $this->assertSame(['en' => 'English', 'nl' => 'Nederlands'], array_intersect_key(Locales::available(), ['en' => 1, 'nl' => 1]));
    }

    public function test_guest_can_pick_dutch_on_login_page(): void
    {
        $this->post('/locale', ['locale' => 'nl'])->assertRedirect();
        $this->get('/login')->assertSee('Inloggen');
    }

    public function test_user_language_is_stored_and_used(): void
    {
        $customer = Customer::create(['name' => 'X', 'email' => 'x@example.test']);
        $user = User::factory()->create(['customer_id' => $customer->id, 'locale' => 'en']);

        $this->actingAs($user)->get('/dashboard')->assertSee('Overview');
        $this->actingAs($user)->post('/locale', ['locale' => 'nl'])->assertRedirect();
        $this->assertSame('nl', $user->fresh()->locale);
        $this->actingAs($user->fresh())->get('/dashboard')->assertSee('Overzicht');
        $this->assertSame('nl', $user->fresh()->preferredLocale());
    }

    public function test_unknown_locale_is_rejected(): void
    {
        $this->post('/locale', ['locale' => 'xx'])->assertSessionHasErrors('locale');
    }

    public function test_every_dutch_translation_keeps_its_placeholders(): void
    {
        $nl = json_decode(file_get_contents(lang_path('nl.json')), true);
        foreach ($nl as $en => $text) {
            preg_match_all('/:[a-z_]+/', $en, $a);
            preg_match_all('/:[a-z_]+/', $text, $b);
            sort($a[0]);
            sort($b[0]);
            $this->assertSame($a[0], $b[0], "Placeholders differ for: {$en}");
        }
    }
}
