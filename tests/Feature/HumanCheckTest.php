<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\Plan;
use App\Models\User;
use App\Support\HumanCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/** The picture check on the public forms, and what it keeps out. */
class HumanCheckTest extends TestCase
{
    use RefreshDatabase;

    private function registration(array $overrides = []): array
    {
        $plan = Plan::query()->where('is_active', true)->first() ?? Plan::factory()->create(['is_active' => true]);

        return array_merge([
            'hospital_name' => 'St. Mary\'s Clinic', 'name' => 'Dr. Sarah Nakato', 'email' => 'sarah@stmarys.test',
            'password' => 'secret12', 'password_confirmation' => 'secret12', 'plan_id' => $plan->id,
        ], $overrides);
    }

    public function test_the_picture_is_a_png_drawn_fresh_and_never_cached(): void
    {
        $id = HumanCheck::issue('register');

        $res = $this->get(route('human-check.image', $id))->assertOk()->assertHeader('content-type', 'image/png');
        $this->assertStringContainsString('no-store', (string) $res->headers->get('cache-control'));
        $this->assertSame("\x89PNG", substr($res->getContent(), 0, 4));

        $this->get('/human-check/new?form=register')->assertOk()->assertJsonStructure(['id', 'src']);
        $this->get('/human-check/new?form=anything')->assertNotFound();
        $this->get('/human-check/'.str_repeat('a', 32).'.png')->assertNotFound();
    }

    public function test_an_answer_is_read_generously_but_counts_once(): void
    {
        $id = HumanCheck::issue('contact');
        $code = HumanCheck::code($id);

        $this->assertFalse(HumanCheck::verify('register', $id, $code), 'another form\'s picture is not this one');
        $id = HumanCheck::issue('contact');
        $code = HumanCheck::code($id);
        $this->assertTrue(HumanCheck::verify('contact', $id, ' '.strtolower(substr($code, 0, 2)).' '.substr($code, 2).' '));
        $this->assertFalse(HumanCheck::verify('contact', $id, $code), 'a solved picture cannot be replayed');

        $id = HumanCheck::issue('contact');
        $this->assertFalse(HumanCheck::verify('contact', $id, 'WRONG'));
        $this->assertNull(HumanCheck::code($id), 'a wrong guess spends the picture too');

        foreach (str_split(HumanCheck::ALPHABET) as $c) {
            $this->assertNotContains($c, ['0', 'O', '1', 'I', 'L']);
        }
    }

    public function test_registration_without_the_check_creates_nothing(): void
    {
        $this->post('/register', $this->registration())->assertSessionHasErrors(['human_answer', 'human_started']);

        $wrong = ['human_answer' => 'ZZZZZ'] + $this->humanCheck('register');
        $this->post('/register', $this->registration($wrong))->assertSessionHasErrors('human_answer');

        $this->assertSame(0, Hospital::count());
    }

    public function test_a_bot_is_caught_by_the_hidden_field_and_by_its_speed(): void
    {
        $this->post('/register', $this->registration(['website' => 'http://spam.test'] + $this->humanCheck('register')))
            ->assertSessionHasErrors('website');

        $tooFast = ['human_started' => Crypt::encryptString('register|'.time())] + $this->humanCheck('register');
        $this->post('/register', $this->registration($tooFast))->assertSessionHasErrors('human_started');

        $forged = ['human_started' => 'not-ours'] + $this->humanCheck('register');
        $this->post('/register', $this->registration($forged))->assertSessionHasErrors('human_started');

        $this->assertSame(0, Hospital::count());
    }

    /** The spam that got in: a phishing link as the hospital and owner name. */
    public function test_a_link_or_a_jumble_is_not_a_name(): void
    {
        $spam = 'Вам перевод 188242 руб. получить тут https://5a48ca64.nip.io/W4RJzDbz TUJE5470MTGJNF';

        $this->post('/register', $this->registration(['hospital_name' => $spam, 'name' => $spam] + $this->humanCheck('register')))
            ->assertSessionHasErrors(['hospital_name', 'name']);
        $this->post('/register', $this->registration(['hospital_name' => 'NAEWTRER2536325NEYRTHYT'] + $this->humanCheck('register')))
            ->assertSessionHasErrors('hospital_name');
        $this->post('/register', $this->registration(['name' => 'me@spam.test'] + $this->humanCheck('register')))
            ->assertSessionHasErrors('name');
        $this->assertSame(0, Hospital::count());

        // Real names pass — in any alphabet, with initials, numbers and punctuation.
        $this->post('/register', $this->registration(['hospital_name' => 'Ward 3 — Mbarara Referral (MRRH)', 'name' => 'Nakato Sarah-Jane O\'Brien'] + $this->humanCheck('register')))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, Hospital::count());
    }

    public function test_the_forms_draw_the_check(): void
    {
        foreach (['/register', '/contact', '/forgot-password'] as $page) {
            $this->get($page)->assertOk()->assertSee('data-human-check', false)->assertSee('name="human_answer"', false);
        }
        // Sign-in asks only after wrong passwords.
        $this->get('/admin/login')->assertOk()->assertDontSee('data-human-check', false);
    }

    public function test_sign_in_asks_after_two_wrong_passwords_and_forgets_on_success(): void
    {
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $hospital = Hospital::factory()->create();
        $user = User::factory()->create(['hospital_id' => $hospital->id, 'role' => 'hospital_admin', 'password' => bcrypt('password1')]);
        $user->syncSpatieRole();

        $this->post('/admin/login', ['email' => $user->email, 'password' => 'wrong']);
        $this->post('/admin/login', ['email' => $user->email, 'password' => 'wrong']);
        $this->get('/admin/login')->assertSee('data-human-check', false);

        // The right password alone is not enough now.
        $this->post('/admin/login', ['email' => $user->email, 'password' => 'password1'])->assertSessionHasErrors('human_answer');
        $this->assertGuest();

        $this->post('/admin/login', ['email' => $user->email, 'password' => 'password1'] + $this->humanCheck('login'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_forgot_password_needs_the_check(): void
    {
        $this->post('/forgot-password', ['email' => 'someone@test.test'])->assertSessionHasErrors('human_answer');
        $user = User::factory()->create();
        $this->post('/forgot-password', ['email' => $user->email] + $this->humanCheck('forgot-password'))->assertSessionHasNoErrors();
    }
}
