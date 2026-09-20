<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Notifications\Index;
use App\Models\Hospital;
use App\Models\User;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationsIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    private function staff(string $role = 'doctor', ?Hospital $hospital = null): User
    {
        $hospital ??= Hospital::factory()->create();
        $u = User::factory()->create(['hospital_id' => $hospital->id, 'role' => $role]);
        $u->syncSpatieRole();
        $this->actingAs($u);
        app(CurrentHospital::class)->set($hospital->id);

        return $u;
    }

    private function note(User $user, string $title, ?\DateTimeInterface $readAt = null): DatabaseNotification
    {
        return DatabaseNotification::create([
            'id' => (string) Str::uuid(), 'type' => 'test',
            'notifiable_type' => User::class, 'notifiable_id' => $user->id,
            'data' => ['title' => $title, 'message' => "About {$title}"],
            'read_at' => $readAt,
        ]);
    }

    public function test_it_lists_the_signed_in_users_notifications(): void
    {
        $u = $this->staff();
        $this->note($u, 'Lab result ready');

        Livewire::test(Index::class)
            ->assertOk()
            ->assertSee('Notifications')
            ->assertSee('Lab result ready')
            ->assertSee('Mark all read')
            ->assertSeeHtml('wire:poll.60s.visible');
    }

    public function test_the_page_renders_over_http(): void
    {
        $this->staff();

        $this->get('/admin/notifications')->assertOk()->assertSee('Notifications');
    }

    public function test_it_never_shows_another_users_notifications(): void
    {
        $mine = $this->staff();
        $other = User::factory()->create(['hospital_id' => $mine->hospital_id, 'role' => 'nurse']);
        $this->note($other, 'Someone elses alert');

        Livewire::test(Index::class)->assertDontSee('Someone elses alert');
    }

    public function test_mark_read_marks_one_and_refreshes_the_bell(): void
    {
        $u = $this->staff();
        $n = $this->note($u, 'Low stock');

        Livewire::test(Index::class)
            ->call('markRead', $n->id)
            ->assertDispatched('notifications-read')
            ->assertDispatched('toast');

        $this->assertNotNull($n->fresh()->read_at);
    }

    public function test_mark_read_cannot_touch_another_users_notification(): void
    {
        $mine = $this->staff();
        $other = User::factory()->create(['hospital_id' => $mine->hospital_id, 'role' => 'nurse']);
        $foreign = $this->note($other, 'Not yours');

        Livewire::test(Index::class)->call('markRead', $foreign->id);

        $this->assertNull($foreign->fresh()->read_at);
    }

    public function test_mark_all_read_clears_every_unread_row(): void
    {
        $u = $this->staff();
        $this->note($u, 'One');
        $this->note($u, 'Two');

        Livewire::test(Index::class)
            ->call('markAllRead')
            ->assertDispatched('notifications-read');

        $this->assertSame(0, $u->fresh()->unreadNotifications()->count());
    }

    public function test_it_paginates_twenty_per_page(): void
    {
        $u = $this->staff();
        for ($i = 1; $i <= 25; $i++) {
            $this->note($u, "Alert {$i}");
        }

        Livewire::test(Index::class)
            ->assertSet('perPage', 20)
            ->assertViewHas('rows', fn ($rows) => $rows->count() === 20 && $rows->total() === 25);
    }
}
