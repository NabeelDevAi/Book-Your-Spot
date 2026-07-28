<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ali Raza',
            'email' => 'ali@example.com',
            'phone' => '+923001234567',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'user',
        ], $overrides);
    }

    #[Test]
    public function the_registration_screen_renders(): void
    {
        $this->get('/register')->assertOk();
    }

    #[Test]
    public function a_customer_can_register_and_lands_on_their_dashboard(): void
    {
        $response = $this->post('/register', $this->validPayload());

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard'));

        $user = User::where('email', 'ali@example.com')->sole();
        $this->assertSame(UserRole::User, $user->role);
        $this->assertSame(UserStatus::Active, $user->status);
    }

    #[Test]
    public function an_owner_can_register_and_lands_on_the_owner_console(): void
    {
        // FR-1.2: the owner track is a separate flow behind the same form, and
        // drops them straight into their console to add a venue.
        $response = $this->post('/register', $this->validPayload(['role' => 'owner']));

        $this->assertAuthenticated();
        $response->assertRedirect(route('owner.dashboard'));

        $this->assertSame(UserRole::Owner, User::where('email', 'ali@example.com')->sole()->role);
    }

    #[Test]
    public function an_admin_account_cannot_be_self_registered(): void
    {
        // FR-1.3. The important one: role arrives from a client-side radio, so
        // a hand-crafted POST must not be able to mint an administrator.
        $response = $this->post('/register', $this->validPayload(['role' => 'admin']));

        $response->assertSessionHasErrors('role');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    #[Test]
    public function an_unknown_role_is_rejected(): void
    {
        $this->post('/register', $this->validPayload(['role' => 'superuser']))
            ->assertSessionHasErrors('role');

        $this->assertDatabaseCount('users', 0);
    }

    #[Test]
    public function a_role_is_required(): void
    {
        $payload = $this->validPayload();
        unset($payload['role']);

        $this->post('/register', $payload)->assertSessionHasErrors('role');
    }

    #[Test]
    public function a_phone_number_is_required(): void
    {
        $this->post('/register', $this->validPayload(['phone' => '']))
            ->assertSessionHasErrors('phone');
    }

    #[Test]
    public function a_phone_number_is_normalised_before_the_uniqueness_check(): void
    {
        // "+92 300 1234567" and "+923001234567" are the same number. Without
        // normalisation both register as separate accounts, and a venue calling
        // the customer has no idea which record is current.
        User::factory()->create(['phone' => '+923001234567']);

        $this->post('/register', $this->validPayload(['phone' => '+92 300 123-4567']))
            ->assertSessionHasErrors('phone');

        $this->assertDatabaseCount('users', 1);
    }

    #[Test]
    public function the_normalised_phone_number_is_what_gets_stored(): void
    {
        $this->post('/register', $this->validPayload(['phone' => '+92 300 123-4567']));

        $this->assertSame('+923001234567', User::where('email', 'ali@example.com')->sole()->phone);
    }

    #[Test]
    public function a_malformed_phone_number_is_rejected(): void
    {
        $this->post('/register', $this->validPayload(['phone' => 'not-a-number']))
            ->assertSessionHasErrors('phone');
    }

    #[Test]
    public function a_duplicate_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'ali@example.com']);

        $this->post('/register', $this->validPayload())->assertSessionHasErrors('email');
    }

    #[Test]
    public function registration_does_not_gate_on_verification(): void
    {
        // FR-1.4 is waived for V1 (no email delivery). The column is still
        // stamped so the gate can be switched on later without a backfill.
        $this->post('/register', $this->validPayload());

        $user = User::where('email', 'ali@example.com')->sole();

        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($user->canBook());
    }
}
