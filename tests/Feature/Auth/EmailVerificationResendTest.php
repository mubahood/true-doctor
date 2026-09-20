<?php

namespace Tests\Feature\Auth;

use App\Models\Hospital;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * Asking for the verification email again.
 *
 * A mail server refusing a login used to produce a 500 and a Symfony stack
 * trace for somebody who had clicked "resend". That is the wrong outcome twice
 * over: they cannot act on it, and it exposes the mail account's address to
 * anybody who triggers it.
 */
class EmailVerificationResendTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($hospital->id);

        $this->user = User::factory()->create([
            'hospital_id' => $hospital->id,
            'role' => 'hospital_admin',
            'email_verified_at' => null,
        ]);
        $this->user->syncSpatieRole();
    }

    public function test_it_sends_the_verification_email(): void
    {
        Notification::fake();

        $this->actingAs($this->user)
            ->post(route('verification.send'))
            ->assertRedirect()
            ->assertSessionHas('status', 'verification-link-sent');

        Notification::assertSentTo($this->user, VerifyEmailNotification::class);
    }

    /**
     * The bug this test exists for.
     *
     * With `QUEUE_CONNECTION=sync` — which is what the test suite and some
     * small installations use — the send happens inside the request, so a
     * refused SMTP login reaches the controller.
     */
    public function test_a_mail_server_refusing_the_login_does_not_produce_a_stack_trace(): void
    {
        // Exactly what Gmail answers when the password is not an app password.
        Mail::shouldReceive('mailer')->andThrow(new TransportException(
            'Failed to authenticate on SMTP server with username "info@example.test": '
            .'535-5.7.8 Username and Password not accepted.',
        ));

        $response = $this->actingAs($this->user)->post(route('verification.send'));

        $response->assertRedirect()->assertSessionHas('status', 'verification-link-failed');
        $response->assertSessionMissing('errors');
    }

    public function test_the_failure_message_says_nothing_about_the_mail_account(): void
    {
        Mail::shouldReceive('mailer')->andThrow(new TransportException(
            'Failed to authenticate with username "secret-mailbox@example.test" password "hunter2".',
        ));

        $this->actingAs($this->user)->post(route('verification.send'));

        $page = $this->actingAs($this->user)->get(route('verification.notice'))->getContent();

        // The person reading this cannot fix a mail-server setting, and the
        // credentials in the exception are none of their business.
        $this->assertStringNotContainsString('secret-mailbox', $page);
        $this->assertStringNotContainsString('hunter2', $page);
        $this->assertStringNotContainsString('SMTP', $page);
        $this->assertStringContainsString('could not send the email', $page);
        $this->assertStringContainsString('nothing has been lost', strtolower($page));
    }

    public function test_an_already_verified_user_is_simply_sent_on(): void
    {
        $this->user->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($this->user)
            ->post(route('verification.send'))
            ->assertRedirect(route('admin.dashboard', absolute: false));
    }

    // ── The real fix: it should not be in the request at all ─────────────

    public function test_the_verification_notification_is_queued(): void
    {
        // Laravel's built-in VerifyEmail is not queued, which is what put an
        // SMTP round trip inside a web request in the first place.
        $this->assertInstanceOf(ShouldQueue::class, new VerifyEmailNotification);
    }

    public function test_the_password_reset_notification_is_queued_too(): void
    {
        // Same class of bug: "I forgot my password" must not 500 because a
        // mail provider is having a bad day.
        $this->assertInstanceOf(
            ShouldQueue::class,
            new \App\Notifications\ResetPasswordNotification('a-token'),
        );
    }

    public function test_every_notification_in_this_system_is_queued(): void
    {
        $unqueued = [];

        foreach (glob(app_path('Notifications/*.php')) as $file) {
            $class = 'App\\Notifications\\'.basename($file, '.php');

            if (! class_exists($class) || (new \ReflectionClass($class))->isAbstract()) {
                continue;
            }

            if (! is_subclass_of($class, ShouldQueue::class)) {
                $unqueued[] = class_basename($class);
            }
        }

        $this->assertSame(
            [],
            $unqueued,
            "These send inside the request, so a mail failure becomes a 500:\n".implode("\n", $unqueued),
        );
    }
}
