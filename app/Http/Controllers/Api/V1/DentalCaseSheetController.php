<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DentalXray;
use App\Models\Service;
use App\Models\Visit;
use App\Services\CaseSheetService;
use App\Services\DentalCaseSheetService;
use App\Services\OrderAttachmentService;
use App\Support\ApiResponse;
use App\Support\CaseSheet\DentalCaseSheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The dental case sheet for the app (docs/api/dental.md, "The case sheet"):
 * the same CaseSheetService and DentalCaseSheetService as the web's visit page.
 */
class DentalCaseSheetController extends Controller
{
    public function __construct(
        private readonly CaseSheetService $sections,
        private readonly DentalCaseSheetService $sheets,
    ) {}

    /** GET visits/{visit}/dental-case-sheet */
    public function show(Request $request, Visit $visit): JsonResponse
    {
        $this->read($request, $visit);

        return ApiResponse::success($this->payload($request, $visit));
    }

    /** PUT visits/{visit}/dental-case-sheet/sections/{section} - a visit section or the patient's own history. */
    public function section(Request $request, Visit $visit, string $section): JsonResponse
    {
        $this->read($request, $visit);
        $definition = DentalCaseSheet::section($section);
        abort_if($definition === null, 404);
        $this->write($request, $section);
        $data = $request->validate([
            'data' => ['present', 'array'],
            'base_version' => ['nullable', 'integer', 'min:0'],
            'uuid' => ['nullable', 'uuid'],
            'seen' => ['nullable', 'array'],
        ]);
        $base = isset($data['base_version']) ? (int) $data['base_version'] : null;

        if ($definition['scope'] === 'patient') {
            $patient = $visit->patient()->firstOrFail();
            $this->sections->savePatientHistory($patient, $section, $data['data'], $visit, $request->user(), $base, $data['uuid'] ?? null, $data['seen'] ?? null);
        } else {
            $this->sections->save($visit, $section, $data['data'], $request->user(), $base, $data['uuid'] ?? null);
        }

        return ApiResponse::success($this->payload($request, $visit), 'Saved.');
    }

    /** POST visits/{visit}/dental-case-sheet/sections/{section}/normal */
    public function normal(Request $request, Visit $visit, string $section): JsonResponse
    {
        $this->read($request, $visit);
        abort_if(DentalCaseSheet::section($section) === null, 404);
        $this->write($request, $section);
        $this->sections->markNormal($visit, $section, $request->user(), $request->filled('base_version') ? (int) $request->input('base_version') : null);

        return ApiResponse::success($this->payload($request, $visit), 'Normal.');
    }

    /** POST visits/{visit}/dental-case-sheet/history/{section}/confirm - "Still correct". */
    public function confirm(Request $request, Visit $visit, string $section): JsonResponse
    {
        $this->read($request, $visit);
        $definition = DentalCaseSheet::section($section);
        abort_if($definition === null || $definition['scope'] !== 'patient', 404);
        $this->write($request, $section);
        $this->sections->confirmPatientHistory($visit->patient()->firstOrFail(), $section, $visit, $request->user());

        return ApiResponse::success($this->payload($request, $visit), 'Confirmed.');
    }

    /** POST visits/{visit}/dental-case-sheet/diagnoses - `condition` from the dental list, or `code`; `tooth?`, `surfaces?`. */
    public function diagnose(Request $request, Visit $visit): JsonResponse
    {
        $this->read($request, $visit);
        $this->authorize('diagnose', $visit);
        $data = $request->validate([
            'condition' => ['required_without:code', 'nullable', 'string', 'max:40'],
            'code' => ['required_without:condition', 'nullable', 'string', 'max:12'],
            'tooth' => ['nullable', 'integer'],
            'surfaces' => ['nullable', 'string', 'max:12'],
            'suspected' => ['nullable', 'boolean'],
        ]);
        $tooth = isset($data['tooth']) ? (int) $data['tooth'] : null;
        $suspected = (bool) ($data['suspected'] ?? false);

        $d = filled($data['condition'] ?? null)
            ? $this->sheets->addDiagnosis($visit, (string) $data['condition'], $tooth, $data['surfaces'] ?? null, $request->user(), $suspected)
            : $this->sheets->addCoded($visit, (string) $data['code'], $tooth, $data['surfaces'] ?? null, $request->user(), $suspected);

        return ApiResponse::success(['diagnosis_uuid' => $d->uuid] + $this->payload($request, $visit), 'Diagnosis added.', 201);
    }

    /** DELETE visits/{visit}/dental-case-sheet/diagnoses/{uuid} */
    public function undiagnose(Request $request, Visit $visit, string $diagnosis): JsonResponse
    {
        $this->read($request, $visit);
        $this->authorize('diagnose', $visit);
        $row = collect($this->sheets->diagnosisRows($visit))->firstWhere('uuid', $diagnosis);
        abort_if($row === null, 404);
        $this->sheets->removeDiagnosis($visit, (int) $row['id'], $request->user());

        return ApiResponse::success($this->payload($request, $visit), 'Diagnosis removed.');
    }

    /** POST visits/{visit}/dental-case-sheet/xrays - multipart when it carries the image. */
    public function xray(Request $request, Visit $visit): JsonResponse
    {
        $this->read($request, $visit);
        $this->authorize('diagnose', $visit);
        $data = $request->validate([
            'kind' => ['required', 'string', 'max:20'],
            'teeth' => ['nullable', 'array', 'max:32'],
            'teeth.*' => ['integer'],
            'finding' => ['required', 'string', 'max:500'],
            'service_id' => ['nullable', 'integer'],
            'image' => ['nullable', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,pdf'],
            'uuid' => ['nullable', 'uuid'],
        ]);
        $service = isset($data['service_id']) ? Service::query()->find((int) $data['service_id']) : null;
        if (isset($data['service_id']) && $service === null) {
            throw ValidationException::withMessages(['service_id' => 'That is not on the price list.']);
        }

        $xray = $this->sheets->recordXray($visit, $data['kind'], $data['teeth'] ?? [], $data['finding'], $service, $request->file('image'), $request->user(), $data['uuid'] ?? null);

        return ApiResponse::success(['xray_uuid' => $xray->uuid] + $this->payload($request, $visit), 'X-ray recorded.', 201);
    }

    /** GET dental/xrays/{xray}/image - the image, streamed behind the visit policy. */
    public function image(Request $request, DentalXray $xray, OrderAttachmentService $files): StreamedResponse
    {
        $visit = Visit::query()->findOrFail($xray->visit_id);
        $this->read($request, $visit);
        $attachment = $xray->order?->attachments()->oldest('id')->first();
        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = $files->disk();
        abort_if($attachment === null || ! $disk->exists($attachment->file_path), 404);

        // The type is the server's reading of the file, never what the uploader claimed.
        return $disk->response($attachment->file_path, $attachment->original_name, [
            'Content-Type' => $disk->mimeType($attachment->file_path) ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** POST visits/{visit}/dental-case-sheet/review - `date` (Y-m-d), `time` (HH:MM), `doctor_id?`, `reason?`. */
    public function review(Request $request, Visit $visit): JsonResponse
    {
        $this->read($request, $visit);
        abort_unless($request->user()->can('appointments.manage'), 403, 'Your role cannot do that.');
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'doctor_id' => ['nullable', 'integer'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        if (isset($data['doctor_id']) && ! \App\Models\User::currentHospital()->whereKey((int) $data['doctor_id'])->exists()) {
            throw ValidationException::withMessages(['doctor_id' => 'That is not one of this hospital\'s doctors.']);
        }

        $appointment = $this->sheets->bookReview($visit, $data['date'], $data['time'], $request->user(), isset($data['doctor_id']) ? (int) $data['doctor_id'] : null, $data['reason'] ?? null);

        return ApiResponse::success(['appointment_uuid' => $appointment->uuid] + $this->payload($request, $visit), 'Review booked.', 201);
    }

    private function read(Request $request, Visit $visit): void
    {
        $this->authorize('view', $visit);
        abort_unless(DentalCaseSheetService::canRead($request->user()), 403, 'Your role cannot do that.');
    }

    private function write(Request $request, string $section): void
    {
        $user = $request->user();
        $ok = $user->can('visits.diagnose') || ($section === 'financial' && $user->can('billing.manage'));
        abort_unless($ok, 403, 'Your role cannot do that.');
        if ($section === 'financial') {
            abort_unless($user->can('billing.view'), 403, 'Your role cannot do that.');
        }
    }

    /** @return array<string,mixed> */
    private function payload(Request $request, Visit $visit): array
    {
        $sheet = $this->sheets->sheet($visit->fresh() ?? $visit, $request->user());
        $hospital = app(\App\Support\HospitalSettings::class)->hospital();
        $sheet['chart'] = array_values($sheet['chart']); // a list, as GET patients/{uuid}/dental gives it
        foreach ($sheet['xrays'] as $i => $x) {
            if ($x['image'] !== null) {
                $sheet['xrays'][$i]['image']['url'] = route('api.dental.xray.image', $x['uuid']);
            }
        }

        return [
            'visit_uuid' => $visit->uuid,
            'applies' => DentalCaseSheetService::appliesTo($visit, $hospital),
            'sections' => array_values($sheet['sections']),
            'definitions' => $this->definitions(),
            'diagnosis_list' => collect(DentalCaseSheet::DIAGNOSES)->map(fn (array $d, string $k) => ['key' => $k] + $d)->values(),
            'xray_kinds' => DentalCaseSheet::XRAY_KINDS,
            'instructions_url' => route('api.dental.case-sheet.instructions', $visit),
        ] + collect($sheet)->except(['sections', 'groups', 'template'])->all();
    }

    /** The field definitions, so the app draws the same form. @return list<array<string,mixed>> */
    private function definitions(): array
    {
        $out = [];
        foreach (DentalCaseSheet::sections() as $key => $s) {
            $fields = [];
            foreach ($s['fields'] as $name => $f) {
                $fields[] = ['key' => $name] + $f;
            }
            $out[] = ['key' => $key, 'label' => $s['label'], 'group' => $s['group'], 'scope' => $s['scope'], 'has_normal' => ! empty($s['normal']), 'fields' => $fields];
        }

        return $out;
    }
}
