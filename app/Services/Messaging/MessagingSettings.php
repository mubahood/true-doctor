<?php

namespace App\Services\Messaging;

use App\Models\Hospital;
use App\Support\Edition;

/**
 * A hospital's choices about messaging its patients (Settings → Messages),
 * kept in `hospitals.settings['messaging']`: whether text messages are on,
 * which events send one, and its quiet hours.
 *
 * Off until a person turns it on. And on the online service, never "on"
 * through the log driver in production: a clinic must not believe reminders
 * are going out when the server has no gateway behind it.
 */
final class MessagingSettings
{
    /** event => [label, on by default] */
    public const EVENTS = [
        'appointment_booked' => ['Appointment booked', true],
        'appointment_day_before' => ['The day before an appointment', true],
        'receipt' => ['Receipt when a visit is paid', true],
        'dental_recall' => ['Dental check-up due', true],
        // Patient links (roadmap 05 §4): staff send a result, summary, prescription or quote.
        'document_link' => ['A document sent by link', true],
    ];

    /** @param  array<string,mixed>  $stored */
    private function __construct(private readonly array $stored) {}

    public static function of(?Hospital $hospital): self
    {
        $stored = is_array($hospital?->settings['messaging'] ?? null) ? $hospital->settings['messaging'] : [];

        return new self($stored);
    }

    /** Whether this server can send text messages at all. */
    public static function available(): bool
    {
        return app(SmsTransport::class)->live() || ! app()->isProduction();
    }

    public function smsEnabled(): bool
    {
        return self::available() && (bool) ($this->stored['sms'] ?? false);
    }

    public function eventOn(string $event): bool
    {
        if (! isset(self::EVENTS[$event])) {
            return false;
        }
        $events = is_array($this->stored['events'] ?? null) ? $this->stored['events'] : [];

        return (bool) ($events[$event] ?? self::EVENTS[$event][1]);
    }

    /** @return array{0:string,1:string} the hours, hospital time, when nothing is sent ("21:00", "07:00") */
    public function quietHours(): array
    {
        $ok = fn ($v) => is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) === 1;

        return [
            $ok($this->stored['quiet_start'] ?? null) ? $this->stored['quiet_start'] : '21:00',
            $ok($this->stored['quiet_end'] ?? null) ? $this->stored['quiet_end'] : '07:00',
        ];
    }

    /** Messages are counted against a balance on the online service; a hospital's own server pays its gateway directly. */
    public static function metered(): bool
    {
        return Edition::isSaas();
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'sms' => (bool) ($this->stored['sms'] ?? false),
            'events' => array_map(fn (string $e) => $this->eventOn($e), array_combine(array_keys(self::EVENTS), array_keys(self::EVENTS))),
            'quiet_start' => $this->quietHours()[0],
            'quiet_end' => $this->quietHours()[1],
        ];
    }

    /** @param  array<string,mixed>  $values */
    public static function save(Hospital $hospital, array $values): void
    {
        $settings = is_array($hospital->settings) ? $hospital->settings : [];
        $settings['messaging'] = [
            'sms' => (bool) ($values['sms'] ?? false),
            'events' => array_map(fn ($on) => (bool) $on, array_intersect_key((array) ($values['events'] ?? []), self::EVENTS)),
            'quiet_start' => (string) ($values['quiet_start'] ?? '21:00'),
            'quiet_end' => (string) ($values['quiet_end'] ?? '07:00'),
        ];
        $hospital->forceFill(['settings' => $settings])->save();
    }
}
