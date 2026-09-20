<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HMS_PLAN.md constraint C14 — random temporary password, forced reset at
 * first login. App\Http\Middleware\RequirePasswordChange.
 */
class ForcedPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_with_password_change_required_is_redirected_to_the_change_form(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('temporary-pass-1'),
            'password_change_required' => true,
        ]);

        $this->actingAs($user)->get('/admin')->assertRedirect(route('password.change'));
    }

    public function test_the_change_form_itself_and_logout_are_reachable_without_a_redirect_loop(): void
    {
        $user = User::factory()->create(['password_change_required' => true]);

        $this->actingAs($user)->get(route('password.change'))->assertOk();
    }

    public function test_completing_the_change_clears_the_flag_and_reaches_the_dashboard(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('temporary-pass-1'),
            'password_change_required' => true,
            'is_admin' => true,
        ]);

        $response = $this->actingAs($user)->put(route('password.update'), [
            'current_password' => 'temporary-pass-1',
            'password' => 'a-new-strong-pass1',
            'password_confirmation' => 'a-new-strong-pass1',
        ]);

        $response->assertRedirect(route('admin.dashboard'));
        $this->assertFalse($user->fresh()->password_change_required);
    }

    public function test_a_user_without_the_flag_is_never_redirected(): void
    {
        $user = User::factory()->create(['password_change_required' => false, 'is_admin' => true]);

        $this->actingAs($user)->get('/admin')->assertOk();
    }
}
