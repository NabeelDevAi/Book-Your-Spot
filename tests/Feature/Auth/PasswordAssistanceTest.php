<?php

namespace Tests\Feature\Auth;

use App\Enums\PasswordResetStatus;
use App\Models\PasswordResetRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The admin-mediated password reset that replaces Breeze's emailed link
 * (FR-1.5 amendment -- V1 delivers no email).
 */
class PasswordAssistanceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_forgot_password_screen_renders(): void
    {
        $this->get('/forgot-password')->assertOk();
    }

    #[Test]
    public function submitting_a_known_email_files_a_request_for_an_admin(): void
    {
        $user = User::factory()->create(['email' => 'ali@example.com']);

        $this->post('/forgot-password', ['email' => 'ali@example.com'])
            ->assertRedirect(route('password.requested'));

        $this->assertDatabaseHas('password_reset_requests', [
            'user_id' => $user->id,
            'submitted_email' => 'ali@example.com',
            'status' => PasswordResetStatus::Open->value,
        ]);
    }

    #[Test]
    public function an_unknown_email_gets_the_same_response_and_creates_nothing(): void
    {
        // Differing responses would turn this form into an account-enumeration
        // oracle -- an attacker could harvest which addresses are registered.
        $this->post('/forgot-password', ['email' => 'nobody@example.com'])
            ->assertRedirect(route('password.requested'));

        $this->assertDatabaseCount('password_reset_requests', 0);
    }

    #[Test]
    public function repeat_submissions_refresh_the_existing_request_instead_of_piling_up(): void
    {
        $user = User::factory()->create(['email' => 'ali@example.com']);

        $this->post('/forgot-password', ['email' => 'ali@example.com']);
        $this->post('/forgot-password', ['email' => 'ali@example.com']);
        $this->post('/forgot-password', ['email' => 'ali@example.com']);

        // A frustrated user clicking three times should not bury the admin
        // queue under three identical rows.
        $this->assertDatabaseCount('password_reset_requests', 1);
        $this->assertSame(1, $user->passwordResetRequests()->open()->count());
    }

    #[Test]
    public function the_request_is_audited(): void
    {
        User::factory()->create(['email' => 'ali@example.com']);

        $this->post('/forgot-password', ['email' => 'ali@example.com']);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLogger::PASSWORD_RESET_REQUESTED,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Admin issuing a temporary password
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_admin_can_issue_a_temporary_password(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $resetRequest = PasswordResetRequest::create([
            'user_id' => $user->id,
            'submitted_email' => $user->email,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.password-requests.issue', $resetRequest), [
                'temporary_password' => 'TempPass2026',
                'note' => 'Verified by phone',
            ])
            ->assertRedirect();

        $user->refresh();
        $this->assertTrue(Hash::check('TempPass2026', $user->password));
        $this->assertTrue($user->must_change_password);

        $this->assertSame(PasswordResetStatus::Resolved, $resetRequest->fresh()->status);
        $this->assertSame($admin->id, $resetRequest->fresh()->resolved_by);
    }

    #[Test]
    public function the_temporary_password_is_never_written_to_the_audit_log(): void
    {
        // The log records that a reset happened, not the credential itself.
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $resetRequest = PasswordResetRequest::create([
            'user_id' => $user->id,
            'submitted_email' => $user->email,
        ]);

        $this->actingAs($admin)->post(route('admin.password-requests.issue', $resetRequest), [
            'temporary_password' => 'SuperSecret99',
        ]);

        $logs = \App\Models\AuditLog::all();
        foreach ($logs as $log) {
            $this->assertStringNotContainsString('SuperSecret99', json_encode($log->toArray()));
        }
    }

    #[Test]
    public function an_already_handled_request_cannot_be_issued_twice(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $resetRequest = PasswordResetRequest::create([
            'user_id' => $user->id,
            'submitted_email' => $user->email,
        ]);
        $resetRequest->forceFill(['status' => PasswordResetStatus::Resolved])->save();

        $originalPassword = $user->password;

        $this->actingAs($admin)->post(route('admin.password-requests.issue', $resetRequest), [
            'temporary_password' => 'AnotherPass99',
        ]);

        $this->assertSame($originalPassword, $user->fresh()->password);
    }

    #[Test]
    public function an_admin_can_dismiss_a_request_without_changing_the_password(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $resetRequest = PasswordResetRequest::create([
            'user_id' => $user->id,
            'submitted_email' => $user->email,
        ]);

        $original = $user->password;

        $this->actingAs($admin)
            ->post(route('admin.password-requests.dismiss', $resetRequest), ['note' => 'Could not verify'])
            ->assertRedirect();

        $this->assertSame(PasswordResetStatus::Dismissed, $resetRequest->fresh()->status);
        $this->assertSame($original, $user->fresh()->password);
        $this->assertFalse($user->fresh()->must_change_password);
    }

    /*
    |--------------------------------------------------------------------------
    | The forced change that follows
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_user_with_a_temporary_password_is_sent_to_the_change_screen_at_login(): void
    {
        $user = User::factory()->mustChangePassword()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('password.change'));
    }

    #[Test]
    public function every_other_route_redirects_back_to_the_change_screen(): void
    {
        $user = User::factory()->mustChangePassword()->create();

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('password.change'));
        $this->actingAs($user)->get('/profile')->assertRedirect(route('password.change'));
        $this->actingAs($user)->get('/')->assertRedirect(route('password.change'));
    }

    #[Test]
    public function the_change_screen_itself_is_reachable(): void
    {
        // Without this exemption the middleware would redirect the user away
        // from the only form that can clear the flag -- an infinite loop.
        $user = User::factory()->mustChangePassword()->create();

        $this->actingAs($user)->get(route('password.change'))->assertOk();
    }

    #[Test]
    public function logging_out_is_still_possible(): void
    {
        $user = User::factory()->mustChangePassword()->create();

        $this->actingAs($user)->post('/logout')->assertRedirect(route('home'));
        $this->assertGuest();
    }

    #[Test]
    public function changing_the_password_clears_the_flag_and_releases_the_user(): void
    {
        $user = User::factory()->mustChangePassword()->create([
            'password' => Hash::make('TempPass2026'),
        ]);

        $this->actingAs($user)
            ->put(route('password.change.update'), [
                'current_password' => 'TempPass2026',
                'password' => 'MyOwnPassword99',
                'password_confirmation' => 'MyOwnPassword99',
            ])
            ->assertRedirect(route('dashboard'));

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('MyOwnPassword99', $user->password));
    }

    #[Test]
    public function the_new_password_cannot_be_the_temporary_one(): void
    {
        // Re-submitting the temporary password would leave the account on a
        // credential a second person has already seen -- exactly the problem
        // this screen exists to solve.
        $user = User::factory()->mustChangePassword()->create([
            'password' => Hash::make('TempPass2026'),
        ]);

        $this->actingAs($user)
            ->put(route('password.change.update'), [
                'current_password' => 'TempPass2026',
                'password' => 'TempPass2026',
                'password_confirmation' => 'TempPass2026',
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue($user->fresh()->must_change_password);
    }

    #[Test]
    public function the_wrong_current_password_is_rejected(): void
    {
        $user = User::factory()->mustChangePassword()->create([
            'password' => Hash::make('TempPass2026'),
        ]);

        $this->actingAs($user)
            ->put(route('password.change.update'), [
                'current_password' => 'wrong-guess',
                'password' => 'MyOwnPassword99',
                'password_confirmation' => 'MyOwnPassword99',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertTrue($user->fresh()->must_change_password);
    }

    #[Test]
    public function an_owner_is_returned_to_their_own_console_after_changing(): void
    {
        $owner = User::factory()->owner()->mustChangePassword()->create([
            'password' => Hash::make('TempPass2026'),
        ]);

        $this->actingAs($owner)
            ->put(route('password.change.update'), [
                'current_password' => 'TempPass2026',
                'password' => 'MyOwnPassword99',
                'password_confirmation' => 'MyOwnPassword99',
            ])
            ->assertRedirect(route('owner.dashboard'));
    }
}
