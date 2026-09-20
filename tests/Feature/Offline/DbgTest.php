<?php

namespace Tests\Feature\Offline;

use App\Models\Device;
use App\Models\Hospital;
use App\Models\User;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DbgTest extends TestCase
{
    use RefreshDatabase;

    public function test_dbg(): void
    {
        $this->seed(RbacSeeder::class);
        $h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($h->id);
        $c = User::factory()->create(['hospital_id' => $h->id, 'role' => 'receptionist']);
        $c->syncSpatieRole();
        $d = Device::create(['hospital_id' => $h->id, 'user_id' => $c->id, 'device_uuid' => (string) Str::uuid(), 'label' => 'x', 'registered_at' => now()]);
        Sanctum::actingAs($c);
        $uuid = (string) Str::uuid();
        $r = $this->withHeader('X-Device-Id', $d->device_uuid)->postJson(route('api.sync.push'), ['operations' => [[
            'operation_id' => strtoupper((string) Str::ulid()), 'entity' => 'patients', 'entity_uuid' => $uuid,
            'operation' => 'create', 'payload' => ['uuid' => $uuid, 'first_name' => 'Amina', 'last_name' => 'Nakato'],
        ]]]);
        dump($r->json('data.results'));
        dump('raw rows: '.DB::table('patients')->count());
        dump(DB::table('patients')->get(['id', 'uuid', 'hospital_id', 'patient_no', 'deleted_at'])->toArray());
        dump('CurrentHospital after: '.var_export(app(CurrentHospital::class)->id(), true).' / test hospital '.$h->id);
        $this->assertTrue(true);
    }
}
