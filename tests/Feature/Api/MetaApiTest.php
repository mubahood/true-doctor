<?php

namespace Tests\Feature\Api;

use App\Models\Hospital;
use App\Models\User;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/v1/meta — what the app draws the system from. Every assertion
 * here is "the same as the web", checked against the web's own sources.
 */
class MetaApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    private function staff(string $role, array $hospital = []): User
    {
        $user = User::factory()->create([
            'hospital_id' => Hospital::factory()->create($hospital)->id,
            'role' => $role,
            'is_active' => true,
        ]);
        $user->syncSpatieRole();

        return $user;
    }

    /** @return list<string> every route in a published menu */
    private function routes(array $navigation): array
    {
        $routes = [];
        foreach ($navigation as $section) {
            foreach ($section['entries'] as $entry) {
                foreach ($entry['type'] === 'group' ? $entry['items'] : [$entry] as $item) {
                    $routes[] = $item['route'];
                }
            }
        }

        return $routes;
    }

    public function test_it_needs_a_token(): void
    {
        $this->getJson('/api/v1/meta')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
    }

    public function test_it_describes_the_user_and_the_hospital(): void
    {
        $user = $this->staff('doctor', ['name' => 'Mulago Clinic', 'currency' => 'UGX']);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/meta')
            ->assertOk()
            ->assertJsonPath('data.api_version', 1)
            ->assertJsonPath('data.sync_protocol', 1)
            ->assertJsonPath('data.user.role', 'doctor')
            ->assertJsonPath('data.user.role_label', 'Doctor')
            ->assertJsonPath('data.hospital.name', 'Mulago Clinic')
            ->assertJsonPath('data.hospital.money.code', 'UGX')
            ->assertJsonPath('data.hospital.money.symbol', 'USh')
            ->assertJsonPath('data.hospital.money.position', 'before')
            ->assertJsonPath('data.hospital.money.decimals', 0)
            ->assertJsonStructure(['data' => ['server_time', 'web_url', 'subscription' => ['status', 'grants_access', 'badge'], 'navigation', 'enums']]);
    }

    /**
     * The menu is the web's own, person for person: a nurse and a doctor see
     * exactly what the web sidebar shows each of them — no more, no less.
     */
    public function test_the_menu_is_exactly_the_web_sidebar_for_each_role(): void
    {
        foreach (['doctor', 'nurse', 'receptionist', 'pharmacist', 'lab_technician', 'accountant'] as $role) {
            $user = $this->staff($role);
            Sanctum::actingAs($user);

            $published = $this->routes($this->getJson('/api/v1/meta')->assertOk()->json('data.navigation'));

            $web = [];
            foreach (Navigation::for($user, false)['sections'] as $section) {
                foreach ($section['entries'] as $entry) {
                    foreach ($entry['type'] === 'group' ? $entry['items'] : [$entry] as $item) {
                        $web[] = $item['route'];
                    }
                }
            }

            $this->assertSame($web, $published, "{$role}: the app's menu differs from the web sidebar");
        }
    }

    public function test_a_nurse_is_not_offered_billing_or_administration(): void
    {
        Sanctum::actingAs($this->staff('nurse'));

        $routes = $this->routes($this->getJson('/api/v1/meta')->json('data.navigation'));

        $this->assertContains('admin.visits.index', $routes);
        $this->assertNotContains('admin.invoices.index', $routes);
        $this->assertNotContains('admin.users.index', $routes);
        $this->assertNotContains('super.hospitals.index', $routes);
    }

    /** Every enum the web labels, with the web's label and colour. */
    public function test_statuses_come_with_the_webs_labels_and_tones(): void
    {
        Sanctum::actingAs($this->staff('doctor'));

        $enums = $this->getJson('/api/v1/meta')->json('data.enums');

        $this->assertSame(
            [
                ['value' => 'pending', 'label' => 'Pending', 'tone' => 'warn', 'icon' => null],
                ['value' => 'ongoing', 'label' => 'Ongoing', 'tone' => 'info', 'icon' => null],
                ['value' => 'completed', 'label' => 'Completed', 'tone' => 'success', 'icon' => null],
            ],
            $enums['visit_status'],
        );

        // Every labelled enum in app/Enums is published, none silently dropped.
        foreach (glob(app_path('Enums/*.php')) as $file) {
            $class = 'App\\Enums\\'.basename($file, '.php');
            if (enum_exists($class) && method_exists($class, 'label')) {
                $key = \Illuminate\Support\Str::snake(basename($file, '.php'));
                $this->assertArrayHasKey($key, $enums);
                $this->assertCount(count($class::cases()), $enums[$key], "{$key} lost a value");
            }
        }

        $this->assertArrayNotHasKey('api_error_code', $enums, 'error codes are not a status list');
        $this->assertSame('fa-flask', collect($enums['order_type'])->firstWhere('value', 'lab')['icon']);
        $this->assertSame('success', collect($enums['patient_status'])->firstWhere('value', 'active')['tone']);
    }
}
