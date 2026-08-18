<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Notifications\OwnerAccountApproved;
use App\Notifications\OwnerAccountRejected;
use App\Services\Admin\OwnerApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Owner-account approval gate (SRS amendment): a new Owner registers
 * `pending_approval` and cannot log in until an Admin approves or rejects
 * the account here.
 */
class OwnerApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $pendingOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->pendingOwner = User::factory()->owner()->create(['status' => UserStatus::PendingApproval]);
    }

    #[Test]
    public function an_admin_can_approve_a_pending_owner(): void
    {
        Notification::fake();

        $this->actingAs($this->admin)
            ->post(route('admin.users.approve-owner', $this->pendingOwner))
            ->assertRedirect();

        $this->pendingOwner->refresh();
        $this->assertSame(UserStatus::Active, $this->pendingOwner->status);
        $this->assertSame($this->admin->id, $this->pendingOwner->reviewed_by);
        Notification::assertSentTo($this->pendingOwner, OwnerAccountApproved::class);
    }

    #[Test]
    public function an_approved_owner_can_then_log_in(): void
    {
        // Approved directly through the service rather than actingAs(admin)
        // + a real HTTP request here: chaining a simulated admin session into
        // a genuine /login POST in the same test conflates two guards' state
        // and isn't what this test is checking -- the HTTP approval endpoint
        // itself is covered by an_admin_can_approve_a_pending_owner above.
        app(OwnerApprovalService::class)->approve($this->pendingOwner, $this->admin);

        $this->post('/login', [
            'email' => $this->pendingOwner->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($this->pendingOwner->fresh());
    }

    #[Test]
    public function an_admin_can_reject_a_pending_owner_with_a_reason(): void
    {
        Notification::fake();

        $this->actingAs($this->admin)
            ->post(route('admin.users.reject-owner', $this->pendingOwner), [
                'reason' => 'Could not verify the details provided.',
            ])
            ->assertRedirect();

        $this->pendingOwner->refresh();
        $this->assertSame(UserStatus::Rejected, $this->pendingOwner->status);
        $this->assertSame('Could not verify the details provided.', $this->pendingOwner->rejection_reason);
        Notification::assertSentTo($this->pendingOwner, OwnerAccountRejected::class);
    }

    #[Test]
    public function rejecting_requires_a_reason(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.users.reject-owner', $this->pendingOwner), [])
            ->assertSessionHasErrors('reason');

        $this->assertSame(UserStatus::PendingApproval, $this->pendingOwner->fresh()->status);
    }

    #[Test]
    public function a_rejected_owner_cannot_log_in(): void
    {
        app(OwnerApprovalService::class)->reject($this->pendingOwner, $this->admin, 'Not a real business.');

        $response = $this->post('/login', [
            'email' => $this->pendingOwner->email,
            'password' => 'password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString('Not a real business.', session('errors')->first('email'));
    }

    #[Test]
    public function a_previously_rejected_owner_can_still_be_approved(): void
    {
        // Mirrors BusinessController::approve() -- Admin can reverse a
        // rejection (an appeal, new information, a mistake) the same way.
        app(OwnerApprovalService::class)->reject($this->pendingOwner, $this->admin, 'Could not verify.');

        $this->actingAs($this->admin)
            ->post(route('admin.users.approve-owner', $this->pendingOwner))
            ->assertRedirect();

        $this->pendingOwner->refresh();
        $this->assertSame(UserStatus::Active, $this->pendingOwner->status);
        $this->assertNull($this->pendingOwner->rejection_reason);
    }

    #[Test]
    public function approval_only_applies_to_owners_actually_pending(): void
    {
        $activeOwner = User::factory()->owner()->create(); // already active

        $this->actingAs($this->admin)
            ->post(route('admin.users.approve-owner', $activeOwner))
            ->assertNotFound();
    }

    #[Test]
    public function a_non_admin_cannot_approve_owners(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('admin.users.approve-owner', $this->pendingOwner))
            ->assertForbidden();
    }

    #[Test]
    public function existing_owners_are_untouched_by_the_approval_gate(): void
    {
        // Grandfathering decision: an Owner account created before this
        // feature shipped defaults to `active` and is never routed through
        // the approval queue.
        $existingOwner = User::factory()->owner()->create();

        $this->assertSame(UserStatus::Active, $existingOwner->status);
        $this->assertTrue($existingOwner->isActive());
    }
}
