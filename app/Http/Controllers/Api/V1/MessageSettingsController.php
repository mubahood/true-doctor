<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiErrorCode;
use App\Enums\MessageStatus;
use App\Http\Controllers\Api\V1\Concerns\ReadsAdminInput;
use App\Http\Controllers\Controller;
use App\Models\Hospital;
use App\Models\PatientMessage;
use App\Services\Messaging\MessageCredits;
use App\Services\Messaging\MessagingSettings;
use App\Services\Messaging\PatientMessenger;
use App\Services\OwnerSummaryService;
use App\Support\ApiResponse;
use App\Support\CurrentHospital;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Settings, Messages for the app (docs/api/admin.md): whether patients get
 * text messages, which ones, the quiet hours, the message balance, and the
 * log of every message with what became of it. The same MessagingSettings,
 * MessageCredits and PatientMessenger as the web page, behind the same
 * permission (`manage-settings`).
 */
class MessageSettingsController extends Controller
{
    use ReadsAdminInput;

    /** GET admin/settings/messages */
    public function show(Request $request): JsonResponse
    {
        $this->authorize('manage-settings');

        return ApiResponse::success($this->document($request));
    }

    /** PUT admin/settings/messages - partial: what is not sent keeps its stored value. */
    public function update(Request $request): JsonResponse
    {
        $this->authorize('manage-settings');
        $hospital = $this->hospital();
        if ($hospital === null) {
            return ApiResponse::error(ApiErrorCode::NotFound, 'Messages are set for one hospital: sign in to it first.', 404);
        }

        $data = $request->validate([
            'sms' => ['sometimes', 'boolean'],
            'events' => ['sometimes', 'array'],
            'events.*' => ['boolean'],
            'quiet_start' => ['sometimes', 'required', 'date_format:H:i'],
            'quiet_end' => ['sometimes', 'required', 'date_format:H:i'],
        ]);

        $current = MessagingSettings::of($hospital)->toArray();
        // The web's box cannot be ticked on a server with no gateway; asked for here, it is said, not ignored.
        $asked = array_key_exists('sms', $data) ? filter_var($data['sms'], FILTER_VALIDATE_BOOLEAN) : null;
        if ($asked === true && ! MessagingSettings::available()) {
            $message = 'Text messages are not switched on for this server yet.';

            return ApiResponse::error(ApiErrorCode::ValidationFailed, $message, 422, ['sms' => [$message]]);
        }
        $sms = ($asked ?? (bool) $current['sms']) && MessagingSettings::available();

        MessagingSettings::save($hospital, [
            'sms' => $sms,
            'events' => array_merge($current['events'], array_intersect_key((array) ($data['events'] ?? []), MessagingSettings::EVENTS)),
            'quiet_start' => $data['quiet_start'] ?? $current['quiet_start'],
            'quiet_end' => $data['quiet_end'] ?? $current['quiet_end'],
        ]);

        return ApiResponse::success(
            $this->document($request),
            $sms ? 'Saved. Patients who agreed will get these messages.' : 'Saved. No text messages go to patients.',
        );
    }

    /** GET admin/messages?status= - every message to a patient, newest first, and what became of it. */
    public function log(Request $request): JsonResponse
    {
        $this->authorize('manage-settings');
        $data = $request->validate([
            'status' => ['nullable', Rule::enum(MessageStatus::class)],
            // The phone's search box: the patient's name or number, or the number it went to.
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $hospital = $this->hospital();
        $search = trim((string) ($data['q'] ?? ''));
        // Numbers are kept as +256…: a local number's leading 0 is not part of it.
        $digits = ltrim((string) preg_replace('/\D+/', '', $search), '0');

        // With no hospital the tenant scope filters nothing: show nothing, never every clinic's log.
        $page = PatientMessage::query()->with('patient:id,uuid,first_name,last_name,patient_no')
            ->when($hospital === null, fn ($q) => $q->whereRaw('1 = 0'))
            ->when(($data['status'] ?? null) !== null, fn ($q) => $q->where('status', $data['status']))
            ->when($search !== '', fn ($q) => $q->where(function ($w) use ($search, $digits) {
                // Only a search with digits in it is a phone number.
                if (strlen($digits) >= 3) {
                    $w->where('to_phone', 'like', "%{$digits}%");
                }
                $w->orWhereHas('patient', fn ($p) => $p->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")->orWhere('patient_no', 'like', "%{$search}%"));
            }))
            ->latest('id')
            ->paginate((int) ($data['per_page'] ?? 25));

        return ApiResponse::paginated($page, collect($page->items())->map(fn (PatientMessage $m) => [
            'uuid' => $m->uuid,
            'at' => $m->created_at?->toIso8601String(),
            'patient' => $m->patient === null ? null : [
                'uuid' => $m->patient->uuid,
                'name' => $m->patient->full_name,
                'patient_no' => $m->patient->patient_no,
            ],
            'event' => $m->event,
            'event_label' => MessagingSettings::EVENTS[$m->event][0] ?? $m->event,
            'body' => $m->logBody(),
            'to' => $m->to_phone,
            'status' => $m->status->value,
            'status_label' => $m->status->label(),
            'status_tone' => $m->status->tone(),
            'note' => $m->statusNote(),
            'segments' => $m->segments,
            'sent_at' => $m->sent_at?->toIso8601String(),
            'delivered_at' => $m->delivered_at?->toIso8601String(),
        ])->all());
    }

    /** @return array<string,mixed> what the web page shows above its log */
    private function document(Request $request): array
    {
        $hospital = $this->hospital();
        $metered = MessagingSettings::metered();
        $balance = $hospital !== null ? app(MessageCredits::class)->balance($hospital->id) : 0;
        $month = PatientMessage::monthByStatus($hospital?->id);
        $previews = app(PatientMessenger::class)->previews($hospital ?? new Hospital(['name' => (string) config('app.name')]));

        $events = [];
        foreach (MessagingSettings::EVENTS as $event => [$label, $default]) {
            $events[] = ['key' => $event, 'label' => $label, 'default' => $default, 'preview' => $previews[$event] ?? ''];
        }

        return [
            'available' => MessagingSettings::available(),
            'metered' => $metered,
            // On a hospital's own server the gateway is paid directly: there is no balance.
            'balance' => $metered ? $balance : null,
            'balance_low' => $metered && $balance < MessageCredits::LOW_BALANCE,
            'settings' => MessagingSettings::of($hospital)->toArray(),
            'events' => $events,
            'month' => [
                'sent' => ($month['sent'] ?? 0) + ($month['delivered'] ?? 0),
                'delivered' => $month['delivered'] ?? 0,
                'not_sent' => ($month['failed'] ?? 0) + ($month['skipped'] ?? 0),
                'by_status' => (object) $month,
            ],
            'statuses' => array_map(fn (MessageStatus $s) => ['value' => $s->value, 'label' => $s->label(), 'tone' => $s->tone()], MessageStatus::cases()),
            // The owner's daily summary sits on this page for the hospital admin (docs/api/owner-summary.md).
            'owner_summary' => OwnerSummaryService::isOwner($request->user()),
        ];
    }

    /** The clinic being configured; null for a platform account outside any hospital (it sees the defaults). */
    private function hospital(): ?Hospital
    {
        $id = app(CurrentHospital::class)->id();

        return $id !== null ? Hospital::query()->find($id) : null;
    }
}
