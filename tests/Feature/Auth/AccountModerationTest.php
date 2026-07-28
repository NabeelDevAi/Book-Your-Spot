<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-3.4 -- account suspension, and what it means for a live session.
 */
class AccountModerationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_suspended_account_cannot_log_in(): void
    {
        $user = User::factory()->suspended('Repeated no-shows')->create([
            'email' => 'banned@example.com',
        ]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    #[Test]
    public function the_suspension_reason_is_shown_at_login(): void
    {
        $user = User::factory()->suspended('Spam reservations')->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrorsIn('default', ['email']);

        $this->assertStringContainsString(
            'Spam reservations',
            session('errors')->first('email')
        );
    }

    #[Test]
    public function a_session_suspended_mid_use_is_terminated_on_the_next_request(): void
    {
        // The case that actually matters: suspension usually follows abuse in
        // progress, so waiting for the user to log out voluntarily is useless.
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertOk();

        $user->forceFill([
            'status' => UserStatus::Suspended,
            'suspension_reason' => 'Abuse reported',
        ])->save();

        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    #[Test]
    public function an_admin_can_suspend_a_customer_and_it_is_audited(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.users.suspend', $user), ['reason' => 'Repeated no-shows'])
            ->assertRedirect();

        $user->refresh();
        $this->assertSame(UserStatus::Suspended, $user->status);
        $this->assertSame('Repeated no-shows', $user->suspension_reason);
        $this->assertSame($admin->id, $user->suspended_by);

        // NFR-6: every moderation action leaves a trail with actor and reason.
        $log = AuditLog::where('action', AuditLogger::USER_SUSPENDED)->sole();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame('Repeated no-shows', $log->reason);
        $this->assertSame($user->id, $log->target_id);
    }

    #[Test]
    public function suspension_requires_a_reason(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.users.suspend', $user), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertSame(UserStatus::Active, $user->fresh()->status);
    }

    #[Test]
    public function an_admin_account_cannot_be_suspended(): void
    {
        // Admin accounts cannot be self-registered, so suspending the last one
        // would lock the platform out of its own moderation tools permanently.
        $admin = User::factory()->admin()->create();
        $otherAdmin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.users.suspend', $otherAdmin), ['reason' => 'Test'])
            ->assertRedirect();

        $this->assertSame(UserStatus::Active, $otherAdmin->fresh()->status);
    }

    #[Test]
    public function an_admin_can_reinstate_a_suspended_account(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->suspended()->create();

        $this->actingAs($admin)->post(route('admin.users.reinstate', $user))->assertRedirect();

        $user->refresh();
        $this->assertSame(UserStatus::Active, $user->status);
        $this->assertNull($user->suspension_reason);
        $this->assertNull($user->suspended_at);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLogger::USER_REINSTATED,
            'target_id' => $user->id,
        ]);
    }

    #[Test]
    public function a_reinstated_account_can_log_in_again(): void
    {
        $user = User::factory()->suspended()->create();

        $user->forceFill([
            'status' => UserStatus::Active,
            'suspension_reason' => null,
        ])->save();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
    }

    #[Test]
    public function a_non_admin_cannot_suspend_anyone(): void
    {
        $owner = User::factory()->owner()->create();
        $victim = User::factory()->create();

        $this->actingAs($owner)
            ->post(route('admin.users.suspend', $victim), ['reason' => 'Because'])
            ->assertForbidden();

        $this->assertSame(UserStatus::Active, $victim->fresh()->status);
    }
}
