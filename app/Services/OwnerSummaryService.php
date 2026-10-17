<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\InvoiceStatus;
use App\Models\Appointment;
use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\OwnerSummary;
use App\Models\Payment;
use App\Models\User;
use App\Models\Visit;
use App\Services\Messaging\MessagingSettings;
use App\Services\Messaging\Owner\EmailOwnerChannel;
use App\Services\Messaging\Owner\OwnerChannel;
use App\Services\Messaging\Owner\SmsOwnerChannel;
use App\Support\CurrentHospital;
use App\Support\HospitalSettings;
use App\Support\Insights\InsightEngine;
use App\Support\Practice\Practice;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The owner's daily summary (roadmap 09 §2): yesterday's figures for a
 * hospital owner who asked for them, once a day, at the time they chose,
 * never inside the hospital's quiet hours.
 *
 * What it says: patients seen, money in by method (and how much of it paid
 * older bills), what was left unpaid and what is owed in total, the stock
 * alerts, the top three "worth a look" items, and today's appointments.
 *
 * Who chose what lives in `hospitals.settings['owner_summary'][user id]`.
 * What was sent lives in `owner_summaries`, one row per owner per day: that
 * unique key is the dedupe, so a scheduler running every half hour, or two
 * running at once, still sends one summary.
 */
final class OwnerSummaryService
{
    /** name => channel. WhatsApp (Phase 3) is one more line here. */
    public const CHANNELS = [
        'email' => EmailOwnerChannel::class,
        'sms' => SmsOwnerChannel::class,
    ];

    /** In the morning, about the day before: the day is complete and there is a day ahead to act in. */
    public const DEFAULT_AT = '07:30';

    /** A send left "sending" this long was interrupted; it is marked failed and tried again. */
    private const STUCK_MINUTES = 30;

    public function __construct(
        private readonly ReminderService $zones,
        private readonly CashierDaySummary $cashier,
        private readonly CurrentHospital $tenant,
    ) {}

    /** Only the hospital's owner (its administrator) gets the summary: it is the whole hospital's money. */
    public static function isOwner(?User $user): bool
    {
        return $user !== null && $user->hospital_id !== null && $user->isHospitalAdmin();
    }

    public function channel(string $name): OwnerChannel
    {
        return app(self::CHANNELS[$name] ?? self::CHANNELS['email']);
    }

    /** @return array<string,string> name => label */
    public function channels(): array
    {
        $out = [];
        foreach (array_keys(self::CHANNELS) as $name) {
            $out[$name] = $this->channel($name)->label();
        }

        return $out;
    }

    // ── The owner's choice ──────────────────────────────────────────────

    /** @return array{enabled:bool,channel:string,at:string} */
    public function preferences(User $owner): array
    {
        $hospital = Hospital::query()->find($owner->hospital_id);
        $all = is_array($hospital?->settings['owner_summary'] ?? null) ? $hospital->settings['owner_summary'] : [];
        $mine = is_array($all[(string) $owner->id] ?? null) ? $all[(string) $owner->id] : [];

        $channel = (string) ($mine['channel'] ?? 'email');
        $at = (string) ($mine['at'] ?? self::DEFAULT_AT);

        return [
            'enabled' => (bool) ($mine['enabled'] ?? false),
            'channel' => isset(self::CHANNELS[$channel]) ? $channel : 'email',
            'at' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $at) === 1 ? $at : self::DEFAULT_AT,
        ];
    }

    /**
     * Turn the summary on or off and say how and when.
     *
     * @param  array{enabled?:mixed,channel?:mixed,at?:mixed}  $values
     * @return array{enabled:bool,channel:string,at:string}
     *
     * @throws ValidationException
     */
    public function savePreferences(User $owner, array $values): array
    {
        if (! self::isOwner($owner)) {
            throw ValidationException::withMessages(['enabled' => ['Only the hospital\'s administrator can get the daily summary.']]);
        }

        $enabled = filter_var($values['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $channel = (string) ($values['channel'] ?? 'email');
        $at = (string) ($values['at'] ?? self::DEFAULT_AT);

        if (! isset(self::CHANNELS[$channel])) {
            throw ValidationException::withMessages(['channel' => ['Choose email or text message.']]);
        }
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $at) !== 1) {
            throw ValidationException::withMessages(['at' => ['Give a time like 07:30.']]);
        }

        $hospital = Hospital::query()->findOrFail($owner->hospital_id);
        if ($enabled && $this->channel($channel)->address($owner, $hospital) === null) {
            throw ValidationException::withMessages(['channel' => [$channel === 'sms'
                ? 'Your profile has no mobile number. Add one, or choose email.'
                : 'Your account has no email address. Add one, or choose a text message.']]);
        }

        DB::transaction(function () use ($owner, $enabled, $channel, $at) {
            // The hospital row holds every setting: lock it so two saves cannot drop each other's change.
            $locked = Hospital::query()->whereKey($owner->hospital_id)->lockForUpdate()->firstOrFail();
            $settings = is_array($locked->settings) ? $locked->settings : [];
            $all = is_array($settings['owner_summary'] ?? null) ? $settings['owner_summary'] : [];
            $all[(string) $owner->id] = ['enabled' => $enabled, 'channel' => $channel, 'at' => $at];
            $settings['owner_summary'] = $all;
            $locked->forceFill(['settings' => $settings])->save();
        });

        return $this->preferences($owner);
    }

    // ── What it says ────────────────────────────────────────────────────

    /**
     * The summary of one hospital day (the hospital's calendar, its time zone).
     *
     * @return array{subject:string,lines:list<string>,link:string,figures:array<string,mixed>}
     */
    public function compose(Hospital $hospital, User $owner, string $date): array
    {
        return $this->tenant->within($hospital->id, function () use ($hospital, $owner, $date) {
            $tz = $this->zones->zone($hospital);
            $day = Carbon::createFromFormat('Y-m-d', $date, $tz)->startOfDay();
            $from = $day->copy()->setTimezone((string) config('app.timezone'));
            $to = $from->copy()->addDay();
            $money = fn ($v) => HospitalSettings::money((string) $v);
            // A SUM comes back as a decimal string on MySQL and a number on SQLite: always two-decimal strings here.
            $dec = fn ($v) => is_string($v) && is_numeric($v) ? bcadd($v, '0', 2) : number_format((float) ($v ?? 0), 2, '.', '');

            $seen = Visit::query()->where('created_at', '>=', $from)->where('created_at', '<', $to)->distinct()->count('patient_id');

            $till = $this->cashier->forDate($hospital, $date);
            $oldBills = $dec(Payment::query()
                ->where('payments.created_at', '>=', $from)->where('payments.created_at', '<', $to)
                ->whereHas('invoice', fn ($q) => $q->where('created_at', '<', $from))
                ->sum('amount'));

            $leftUnpaid = $dec(Invoice::query()
                ->where('created_at', '>=', $from)->where('created_at', '<', $to)
                ->whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Void->value])
                // A balance brought forward is an old debt, not today's bill left unpaid.
                ->where('opening_balance', false)
                ->where('balance', '>', 0)
                ->sum('balance'));
            $owed = app(ReportService::class)->outstanding()['total'];

            $stock = null;
            if (Practice::of($hospital)->uses('pharmacy')) {
                $board = new StockAlertBoard;
                $stock = ['out' => $board->outOfStock()->count(), 'low' => $board->lowStock()->count(), 'expired' => $board->expired()->count()];
            }

            $insights = array_values(array_filter(
                app(InsightEngine::class)->for($owner, 3),
                fn (array $i) => $i['key'] !== 'all-clear',
            ));

            $todayFrom = Carbon::now($tz)->startOfDay()->setTimezone((string) config('app.timezone'));
            $appointments = Appointment::query()
                ->where('scheduled_at', '>=', $todayFrom)->where('scheduled_at', '<', $todayFrom->copy()->addDay())
                ->whereNotIn('status', [AppointmentStatus::Cancelled->value, AppointmentStatus::NoShow->value])
                ->count();

            $methods = array_map(
                fn (array $m) => (\App\Enums\PaymentMethod::tryFrom($m['method'])?->label() ?? $m['method']).' '.$money($m['amount']),
                $till['totals_by_method'],
            );

            $lines = [$hospital->name.', '.$day->format('D j M Y')];
            $lines[] = 'Patients seen: '.$seen.'.';
            $lines[] = 'Money in: '.$money($till['total']).($methods !== [] ? ' ('.implode(', ', $methods).')' : '')
                .(bccomp($oldBills, '0', 2) > 0 ? '. '.$money($oldBills).' of it paid older bills.' : '.');
            $lines[] = 'Left unpaid that day: '.$money($leftUnpaid).'. Owed in total: '.$money($owed).'.';
            if ($stock !== null) {
                $lines[] = 'Stock: '.$stock['out'].' out, '.$stock['low'].' low, '.$stock['expired'].' expired.';
            }
            if ($insights !== []) {
                $lines[] = 'Worth a look: '.implode('; ', array_map(fn (array $i) => $i['title'], $insights)).'.';
            }
            $lines[] = 'Today: '.$appointments.' '.($appointments === 1 ? 'appointment' : 'appointments').'.';

            return [
                'subject' => 'Your day at '.$hospital->name.': '.$day->format('D j M'),
                'lines' => $lines,
                'link' => route('admin.dashboard'),
                'figures' => [
                    'date' => $date,
                    'patients_seen' => $seen,
                    'money_in' => $till['total'],
                    'money_in_by_method' => $till['totals_by_method'],
                    'older_bills_paid' => $oldBills,
                    'left_unpaid' => $leftUnpaid,
                    'owed_total' => $owed,
                    'stock' => $stock,
                    'insights' => array_map(fn (array $i) => ['key' => $i['key'], 'tone' => $i['tone'], 'title' => $i['title']], $insights),
                    'appointments_today' => $appointments,
                ],
            ];
        });
    }

    /** What the owner would get for yesterday, as their channel would carry it. */
    public function preview(User $owner): string
    {
        $hospital = Hospital::query()->findOrFail($owner->hospital_id);
        $prefs = $this->preferences($owner);
        $s = $this->compose($hospital, $owner, $this->yesterday($hospital));

        return $this->channel($prefs['channel'])->format($s['subject'], $s['lines'], $s['link']);
    }

    public function yesterday(Hospital $hospital, ?Carbon $now = null): string
    {
        return ($now ?? Carbon::now())->copy()->setTimezone($this->zones->zone($hospital))->subDay()->toDateString();
    }

    // ── Sending ─────────────────────────────────────────────────────────

    /**
     * Every summary that is due now: for each owner who asked, once their time
     * of day has come and the hospital is outside its quiet hours. A time chosen
     * inside the quiet hours (22:30 with quiet from 21:00) means "as soon as they
     * end". Safe to run as often as the scheduler likes: the day's row is the
     * dedupe key.
     *
     * Delivery is at least once, not exactly once: a process that dies after
     * the message left but before its row says so is retried after half an hour.
     *
     * @return array{sent:int,failed:int}
     */
    public function sendDue(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $sent = 0;
        $failed = 0;

        // A worker that died mid-send leaves "sending": mark it failed, so it is tried again.
        OwnerSummary::query()->withoutGlobalScopes()
            ->where('status', OwnerSummary::SENDING)
            ->where('updated_at', '<', $now->copy()->subMinutes(self::STUCK_MINUTES))
            ->update(['status' => OwnerSummary::FAILED, 'error' => 'The send was interrupted.']);

        // A suspended hospital hears nothing (and is charged nothing).
        foreach (Hospital::query()->where('status', 'active')->get() as $hospital) {
            $wanted = is_array($hospital->settings['owner_summary'] ?? null) ? $hospital->settings['owner_summary'] : [];
            $ids = array_keys(array_filter($wanted, fn ($p) => is_array($p) && ! empty($p['enabled'])));
            if ($ids === [] || $this->isQuiet($hospital, $now)) {
                continue;
            }

            $local = $now->copy()->setTimezone($this->zones->zone($hospital));
            $owners = User::query()->where('hospital_id', $hospital->id)->where('role', 'hospital_admin')
                ->where('is_active', true)->whereIn('id', array_map('intval', $ids))->get();

            foreach ($owners as $owner) {
                $at = $this->preferences($owner)['at'];
                if ($local->format('H:i') < $at && ! $this->inQuietHours($hospital, $at)) {
                    continue;
                }

                $row = $this->send($hospital, $owner, $local->copy()->subDay()->toDateString());
                if ($row === null) {
                    continue;
                }
                $row->status === OwnerSummary::SENT ? $sent++ : $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * Send one owner the summary of one day, unless it has already gone (or is
     * going). Null when there was nothing to do.
     */
    public function send(Hospital $hospital, User $owner, string $date): ?OwnerSummary
    {
        $prefs = $this->preferences($owner);
        $channel = $this->channel($prefs['channel']);
        $to = $channel->address($owner, $hospital);

        $row = $this->claim($hospital, $owner, $date, $channel->name(), $to);
        if ($row === null) {
            return null;
        }

        try {
            $summary = $this->compose($hospital, $owner, $date);
            $body = $channel->format($summary['subject'], $summary['lines'], $summary['link']);
            $row->body = $body;

            if ($to === null) {
                $error = $channel->name() === 'sms' ? 'No mobile number on your profile.' : 'No email address on your account.';
                $row->attempts = OwnerSummary::MAX_ATTEMPTS; // nothing to retry until the profile changes
            } else {
                $error = $channel->send($hospital, $to, $summary['subject'], $body);
            }
        } catch (Throwable $e) {
            report($e);
            $error = 'The summary could not be sent.';
        }

        $row->forceFill([
            'status' => $error === null ? OwnerSummary::SENT : OwnerSummary::FAILED,
            'error' => $error === null ? null : mb_substr($error, 0, 255),
            'sent_at' => $error === null ? Carbon::now() : null,
        ])->save();

        return $row;
    }

    /**
     * Take the day for this owner: a new row, or a failed one with tries left.
     * The unique key on (owner, day) means only one process ever wins it.
     */
    private function claim(Hospital $hospital, User $owner, string $date, string $channel, ?string $to): ?OwnerSummary
    {
        $existing = OwnerSummary::query()->withoutGlobalScopes()
            ->where('user_id', $owner->id)->whereDate('summary_date', $date)->first();

        if ($existing !== null) {
            if ($existing->status !== OwnerSummary::FAILED || $existing->attempts >= OwnerSummary::MAX_ATTEMPTS) {
                return null;
            }
            // Conditional: of two runs retrying the same failure, one wins.
            $won = OwnerSummary::query()->withoutGlobalScopes()->whereKey($existing->id)
                ->where('status', OwnerSummary::FAILED)->where('attempts', $existing->attempts)
                ->update(['status' => OwnerSummary::SENDING, 'attempts' => $existing->attempts + 1, 'channel' => $channel, 'recipient' => $to, 'updated_at' => Carbon::now()]);

            return $won === 1 ? $existing->refresh() : null;
        }

        $row = new OwnerSummary([
            'user_id' => $owner->id, 'summary_date' => $date, 'channel' => $channel, 'recipient' => $to,
            'body' => '', 'status' => OwnerSummary::SENDING, 'attempts' => 1,
        ]);
        $row->forceFill(['hospital_id' => $hospital->id]);

        try {
            $row->save();
        } catch (QueryException) {
            return null; // another run took this day a moment ago
        }

        return $row;
    }

    /** Inside the hospital's quiet hours (Settings, Messages), when nothing is sent. */
    public function isQuiet(Hospital $hospital, Carbon $now): bool
    {
        return $this->inQuietHours($hospital, $now->copy()->setTimezone($this->zones->zone($hospital))->format('H:i'));
    }

    /** Whether a clock time ("22:30") falls in the hospital's quiet hours, which may run past midnight. */
    private function inQuietHours(Hospital $hospital, string $time): bool
    {
        [$start, $end] = MessagingSettings::of($hospital)->quietHours();

        return $start <= $end ? ($time >= $start && $time < $end) : ($time >= $start || $time < $end);
    }
}
