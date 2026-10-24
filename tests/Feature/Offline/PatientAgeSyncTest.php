<?php

namespace Tests\Feature\Offline;

use App\Models\Device;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use App\Services\PatientService;
use App\Support\BirthDate;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * An age typed into the app instead of a date of birth (docs/patients.md,
 * "Age or date of birth"): the device works the date out and sends it marked
 * `dob_estimated`, as the API's store takes it. Re-sending the record without
 * the flag (an older app correcting a phone number) never turns an estimated
 * birthday into a known one.
 */
class PatientAgeSyncTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private User $clerk;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->clerk = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'receptionist']);
        $this->clerk->syncSpatieRole();
        $this->device = Device::create([
            'hospital_id' => $this->hospital->id, 'user_id' => $this->clerk->id,
            'device_uuid' => (string) Str::uuid(), 'label' => 'Desk phone', 'registered_at' => now(),
        ]);
        Sanctum::actingAs($this->clerk);
    }

    private function push(string $operation, array $payload, array $extra = [])
    {
        return $this->withHeader('X-Device-Id', $this->device->device_uuid)->postJson(route('api.sync.push'), ['operations' => [array_merge([
            'operation_id' => strtoupper((string) Str::ulid()),
            'entity' => 'patients',
            'entity_uuid' => $payload['uuid'],
            'operation' => $operation,
            'payload' => $payload,
            'client_created_at' => now()->toIso8601String(),
        ], $extra)]])->assertOk()->json('data.results.0');
    }

    public function test_a_patient_registered_offline_by_age_arrives_with_an_estimated_date(): void
    {
        $uuid = (string) Str::uuid();
        $dob = BirthDate::fromAge(34);

        $result = $this->push('create', ['uuid' => $uuid, 'first_name' => 'Ruth', 'last_name' => 'Akello', 'dob' => $dob, 'dob_estimated' => true]);

        $this->assertSame('accepted', $result['status']);
        $patient = Patient::where('uuid', $uuid)->sole();
        $this->assertSame($dob, $patient->dob->toDateString());
        $this->assertTrue($patient->dob_estimated);
        $this->assertSame(34, $patient->age());

        // And a known birthday registered the same way stays exact.
        $exact = (string) Str::uuid();
        $this->push('create', ['uuid' => $exact, 'first_name' => 'Grace', 'last_name' => 'Atim', 'dob' => '1988-05-09', 'dob_estimated' => false]);
        $this->assertFalse(Patient::where('uuid', $exact)->sole()->dob_estimated);
    }

    public function test_the_flag_is_validated_like_the_online_form(): void
    {
        $uuid = (string) Str::uuid();

        $result = $this->push('create', ['uuid' => $uuid, 'first_name' => 'Ruth', 'last_name' => 'Akello', 'dob' => '1990-07-01', 'dob_estimated' => 'perhaps']);

        $this->assertSame('rejected', $result['status']);
        $this->assertArrayHasKey('dob_estimated', $result['errors']);
        $this->assertNull(Patient::where('uuid', $uuid)->first());
    }

    public function test_resending_the_record_without_the_flag_keeps_the_estimate(): void
    {
        // Registered at the desk by age, so estimated.
        $patient = app(PatientService::class)->register(['first_name' => 'Ruth', 'last_name' => 'Akello', 'age_years' => 40], $this->clerk->id);
        $dob = $patient->dob->toDateString();
        $this->assertTrue($patient->dob_estimated);

        // An older app corrects the phone number, sending the whole record it holds (no flag in it).
        $result = $this->push('update', ['uuid' => $patient->uuid, 'first_name' => 'Ruth', 'last_name' => 'Akello', 'dob' => $dob, 'phone_1' => '0700111222'],
            ['base_version' => (int) ($patient->version ?? 1), 'base_fields' => ['first_name' => 'Ruth', 'last_name' => 'Akello', 'dob' => $dob, 'phone_1' => null]]);

        $this->assertSame('accepted', $result['status']);
        $patient->refresh();
        $this->assertSame('0700111222', $patient->phone_1);
        $this->assertSame($dob, $patient->dob->toDateString());
        $this->assertTrue($patient->dob_estimated, 'saving a phone number must not make the birthday known');

        // The app says the date is the real one now (the patient brought a card).
        $this->push('update', ['uuid' => $patient->uuid, 'first_name' => 'Ruth', 'last_name' => 'Akello', 'dob' => '1986-03-02', 'dob_estimated' => false, 'phone_1' => '0700111222'],
            ['base_version' => (int) $patient->version, 'base_fields' => ['dob' => $dob, 'dob_estimated' => true, 'phone_1' => '0700111222']]);
        $patient->refresh();
        $this->assertSame('1986-03-02', $patient->dob->toDateString());
        $this->assertFalse($patient->dob_estimated);
    }

    public function test_a_merged_age_stays_an_estimate_when_someone_else_edited_meanwhile(): void
    {
        $patient = app(PatientService::class)->register(['first_name' => 'Ruth', 'last_name' => 'Akello', 'age_years' => 40], $this->clerk->id);
        $patient->forceFill(['version' => 1])->saveQuietly();
        $base = $patient->dob->toDateString();

        // The web corrects the address; the version moves on.
        $patient->update(['address' => 'Kololo']);
        $patient->forceFill(['version' => 2])->saveQuietly();

        // A phone still on version 1 changes the age from 40 to 45.
        $newDob = BirthDate::fromAge(45);
        $result = $this->push('update', ['uuid' => $patient->uuid, 'first_name' => 'Ruth', 'last_name' => 'Akello', 'dob' => $newDob, 'dob_estimated' => true],
            ['base_version' => 1, 'base_fields' => ['first_name' => 'Ruth', 'last_name' => 'Akello', 'dob' => $base, 'dob_estimated' => true]]);

        $this->assertSame('accepted', $result['status']);
        $patient->refresh();
        $this->assertSame($newDob, $patient->dob->toDateString());
        $this->assertTrue($patient->dob_estimated, 'the flag travels with the merged date');
        $this->assertSame('Kololo', $patient->address);
    }

    public function test_the_pull_carries_the_flag_so_the_device_holds_its_base(): void
    {
        app(PatientService::class)->register(['first_name' => 'Ruth', 'last_name' => 'Akello', 'age_years' => 40], $this->clerk->id);

        $record = collect($this->withHeader('X-Device-Id', $this->device->device_uuid)->getJson(route('api.sync.pull'))->assertOk()->json('data.changes'))
            ->firstWhere('entity', 'patients')['record'];

        $this->assertTrue($record['dob_estimated']);
    }

    public function test_the_service_keeps_the_flag_for_an_unchanged_date_and_drops_it_for_a_new_one(): void
    {
        $patient = app(PatientService::class)->register(['first_name' => 'Ruth', 'last_name' => 'Akello', 'age_years' => '1', 'age_months' => '3'], $this->clerk->id);
        $dob = $patient->dob->toDateString();

        $patient = app(PatientService::class)->update($patient, ['dob' => $dob, 'phone_1' => '0700']);
        $this->assertTrue($patient->dob_estimated);
        $this->assertSame($dob, $patient->dob->toDateString());

        $patient = app(PatientService::class)->update($patient, ['dob' => $dob, 'dob_estimated' => false]);
        $this->assertFalse($patient->dob_estimated, 'said outright: the date is known');

        $patient = app(PatientService::class)->update($patient, ['dob' => '2025-01-01']);
        $this->assertFalse($patient->dob_estimated);
    }
}
