<?php

namespace App\Services\Audit;

use App\Models\Patient;
use App\Models\RecordAccess;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The read audit (roadmap 07 §3, docs/audit.md): who opened or printed a
 * patient's record, when, on what, from where.
 *
 * One rule for web and API. A person opening the same record again within
 * WINDOW_MINUTES is the same look, not a new one: the patient page re-renders,
 * the app pulls the summary on every resume, and a trail of forty identical
 * rows hides the one that matters. A print is its own action, and a print of
 * a different document is its own entry.
 */
final class RecordAccessLog
{
    public const WINDOW_MINUTES = 10;

    public function opened(Patient $patient, ?User $user, ?Request $request = null, ?string $what = null): ?RecordAccess
    {
        return $this->record($patient, $user, 'opened', $what, $request);
    }

    public function printed(Patient $patient, ?User $user, string $what, ?Request $request = null): ?RecordAccess
    {
        return $this->record($patient, $user, 'printed', $what, $request);
    }

    /** Write one entry, unless the same person did the same thing to this record a moment ago. */
    public function record(Patient $patient, ?User $user, string $action, ?string $context = null, ?Request $request = null): ?RecordAccess
    {
        if ($user === null) {
            return null;
        }

        $request ??= request();
        $context = $context === null ? null : Str::limit($context, 117);

        $recent = RecordAccess::query()
            ->where('patient_id', $patient->id)
            ->where('user_id', $user->id)
            ->where('action', $action)
            ->where('created_at', '>=', now()->subMinutes(self::WINDOW_MINUTES))
            ->when($context === null, fn (Builder $q) => $q->whereNull('context'), fn (Builder $q) => $q->where('context', $context))
            ->exists();

        if ($recent) {
            return null;
        }

        return RecordAccess::create([
            'hospital_id' => $patient->hospital_id,
            'user_id' => $user->id,
            'patient_id' => $patient->id,
            'action' => $action,
            'channel' => $request->is('api/*') ? 'api' : 'web',
            'context' => $context,
            'ip' => $request->ip(),
            'device' => self::device($request),
            'created_at' => now(),
        ]);
    }

    /**
     * Opens and prints, newest first.
     *
     * @param  array{patient?:int|null,user?:int|null,action?:string,from?:string,to?:string}  $filters
     * @return Builder<RecordAccess>
     */
    public function query(array $filters = []): Builder
    {
        return RecordAccess::query()
            ->with(['user:id,name,role', 'patient:id,uuid,patient_no,first_name,last_name'])
            ->when(! empty($filters['patient']), fn (Builder $q) => $q->where('patient_id', $filters['patient']))
            ->when(! empty($filters['user']), fn (Builder $q) => $q->where('user_id', $filters['user']))
            ->when(($filters['action'] ?? '') !== '', fn (Builder $q) => $q->where('action', $filters['action']))
            ->when(($filters['from'] ?? '') !== '', fn (Builder $q) => $q->where('created_at', '>=', $filters['from'].' 00:00:00'))
            ->when(($filters['to'] ?? '') !== '', fn (Builder $q) => $q->where('created_at', '<=', $filters['to'].' 23:59:59'))
            ->latest('id');
    }

    /** @return array<string,mixed> the shape the API returns for one entry */
    public static function toArray(RecordAccess $a): array
    {
        return [
            'at' => $a->created_at?->toIso8601String(),
            'action' => $a->action,
            'action_label' => $a->actionLabel(),
            'what' => $a->context,
            'who' => $a->user?->name,
            'role' => $a->user?->role,
            'channel' => $a->channel,
            'device' => $a->device,
            'ip' => $a->ip,
        ];
    }

    /**
     * What the person was using, in a few words. The app says who it is in
     * its version header; a browser is named from its user agent.
     */
    private static function device(Request $request): ?string
    {
        if ($version = $request->header('X-App-Version')) {
            return Str::limit('True-Doctor app '.$version, 188);
        }

        $agent = (string) $request->userAgent();
        if ($agent === '') {
            return null;
        }

        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Safari/') => 'Safari',
            default => null,
        };
        $os = match (true) {
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };

        return $browser !== null || $os !== null
            ? trim(($browser ?? 'Browser').' on '.($os ?? 'unknown system'))
            : Str::limit($agent, 188);
    }
}
