<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-1.6 -- role separation is enforced server-side on every route, regardless
 * of what the UI renders.
 *
 * These tests hit the URLs directly rather than clicking through the interface,
 * because the requirement is specifically about someone who bypasses the UI.
 */
class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public static function ownerRoutes(): array
    {
        return [
            'owner dashboard' => ['/owner'],
        ];
    }

    public static function adminRoutes(): array
    {
        return [
            'admin dashboard' => ['/admin'],
            'admin users' => ['/admin/users'],
            'admin password requests' => ['/admin/password-requests'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Customers
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('ownerRoutes')]
    public function a_customer_cannot_reach_owner_routes(string $url): void
    {
        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
    }

    #[Test]
    #[DataProvider('adminRoutes')]
    public function a_customer_cannot_reach_admin_routes(string $url): void
    {
        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
    }

    #[Test]
    public function a_customer_can_reach_their_own_dashboard(): void
    {
        $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | Owners
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('adminRoutes')]
    public function an_owner_cannot_reach_admin_routes(string $url): void
    {
        $this->actingAs(User::factory()->owner()->create())->get($url)->assertForbidden();
    }

    #[Test]
    public function an_owner_cannot_reach_the_customer_dashboard(): void
    {
        // Owners cannot book at all (SRS 9.14), so the customer dashboard would
        // be a dead end showing them a booking history they can never have.
        $this->actingAs(User::factory()->owner()->create())->get('/dashboard')->assertForbidden();
    }

    #[Test]
    public function an_owner_can_reach_their_console(): void
    {
        $this->actingAs(User::factory()->owner()->create())->get('/owner')->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | Admins
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('adminRoutes')]
    public function an_admin_can_reach_admin_routes(string $url): void
    {
        $this->actingAs(User::factory()->admin()->create())->get($url)->assertOk();
    }

    #[Test]
    public function an_admin_cannot_reach_the_owner_console(): void
    {
        // Admin override applies to data (FR-3.6), not to impersonating another
        // role's interface. Admin moderation happens in the admin console.
        $this->actingAs(User::factory()->admin()->create())->get('/owner')->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Guests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_guest_is_redirected_to_login_not_shown_a_403(): void
    {
        // A guest should be invited to log in; only a wrong-role authenticated
        // user gets a hard 403.
        $this->get('/owner')->assertRedirect('/login');
        $this->get('/admin')->assertRedirect('/login');
        $this->get('/dashboard')->assertRedirect('/login');
    }

    #[Test]
    public function a_guest_can_still_browse_public_pages(): void
    {
        // SRS 9.13: browsing is open, only booking requires an account.
        $this->get('/')->assertOk();
        $this->get('/login')->assertOk();
        $this->get('/register')->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | Profile is shared by all roles
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function every_role_can_reach_their_own_profile(): void
    {
        foreach ([
            User::factory()->create(),
            User::factory()->owner()->create(),
            User::factory()->admin()->create(),
        ] as $user) {
            $this->actingAs($user)->get('/profile')->assertOk();
        }
    }
}
