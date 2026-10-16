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

    // ── What the page says when it refuses ───────────────────────────────
    // The errors used to go to a named bag the page never read, so every
    // refusal reloaded the form in silence.

    private function forced(string $password = 'garden123', array $extra = []): User
    {
        return User::factory()->create([
            'password' => bcrypt($password),
            'password_change_required' => true,
            'is_admin' => true,
        ] + $extra);
    }

    /** @return array<string, array{0: array<string,string>, 1: string, 2: string}> */
    public static function refusals(): array
    {
        return [
            'wrong current password' => [['current_password' => 'nope', 'password' => 'garden12', 'password_confirmation' => 'garden12'], 'current_password', 'That is not the password you signed in with.'],
            'too short' => [['current_password' => 'garden123', 'password' => 'abc', 'password_confirmation' => 'abc'], 'password', 'The new password needs at least 8 characters.'],
            'not confirmed' => [['current_password' => 'garden123', 'password' => 'garden12', 'password_confirmation' => 'gardens12'], 'password', 'The two new passwords do not match.'],
            'same as before' => [['current_password' => 'garden123', 'password' => 'garden123', 'password_confirmation' => 'garden123'], 'password', 'Choose a password different from the one you signed in with.'],
        ];
    }

    /** @dataProvider refusals */
    public function test_a_refused_password_says_why_on_the_page(array $input, string $field, string $message): void
    {
        $user = $this->forced();

        $this->actingAs($user)
            ->from(route('password.change'))
            ->put(route('password.update'), $input)
            ->assertRedirect(route('password.change'))
            ->assertSessionHasErrors([$field => $message]);

        // …and the page it goes back to actually prints it.
        $this->actingAs($user)->get(route('password.change'))
            ->assertOk()
            ->assertSee($message)
            ->assertSee('Your password was not changed');

        $this->assertTrue($user->fresh()->password_change_required);
    }

    /** The only rule is length: no digit, capital or symbol demanded. */
    public function test_eight_characters_with_letters_and_numbers_are_enough_and_less_is_refused(): void
    {
        foreach (['garden12', '1234abcd', 'ABCDEFG7', 'my dog 22'] as $i => $password) {
            $user = $this->forced('temporary-'.$i);

            $this->actingAs($user)->put(route('password.update'), [
                'current_password' => 'temporary-'.$i,
                'password' => $password,
                'password_confirmation' => $password,
            ])->assertSessionHasNoErrors();

            $this->assertTrue(\Illuminate\Support\Facades\Hash::check($password, $user->fresh()->password), "{$password} was refused");
        }

        foreach (['garden', '12345678', 'ABCDEFGH'] as $i => $password) {
            $user = $this->forced('temporary-x'.$i);

            $this->actingAs($user)->put(route('password.update'), [
                'current_password' => 'temporary-x'.$i,
                'password' => $password,
                'password_confirmation' => $password,
            ])->assertSessionHasErrors('password');
        }
    }

    /** The case that was stuck in production: a super admin on a temporary password. */
    public function test_a_super_admin_gets_through_to_a_working_page(): void
    {
        $admin = $this->forced('garden123', ['hospital_id' => null, 'role' => 'super_admin']);

        $this->actingAs($admin)->get('/admin')->assertRedirect(route('password.change'));

        $this->actingAs($admin)->followingRedirects()->put(route('password.update'), [
            'current_password' => 'garden123',
            'password' => 'kampala7',
            'password_confirmation' => 'kampala7',
        ])->assertOk()->assertDontSee('Set your password');

        $this->assertFalse($admin->fresh()->password_change_required);
        $this->actingAs($admin)->get('/super/hospitals')->assertOk();
    }

    public function test_the_page_offers_a_way_to_sign_out(): void
    {
        $this->actingAs($this->forced())->get(route('password.change'))
            ->assertOk()
            ->assertSee(route('admin.logout'), false)
            ->assertSee('At least 8 characters, with letters and numbers');
    }
}
