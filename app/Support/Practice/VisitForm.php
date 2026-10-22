<?php

namespace App\Support\Practice;

use App\Models\Hospital;
use App\Services\DentalService;
use App\Support\HospitalSettings;

/**
 * How a hospital writes a visit down (docs/practice.md, "The visit form"):
 * which vital signs it records, and, where it does dentistry, whether a
 * dental visit takes simple notes or the detailed case sheet.
 *
 * Stored in `settings.clinical` as `vitals` (a list, or null to follow the
 * practice) and `dental_notes` (`simple`, `detailed`, or null to follow the
 * practice). A dental clinic records blood pressure only and writes simple
 * notes; every other practice records every vital and keeps the detailed
 * case sheet, which is what every hospital had before there was a choice.
 *
 * `visit_report` (`summary`, `full`, or null to follow the practice) is what
 * the printed visit report shows: a dental clinic hands the patient a summary
 * (treatment, dosage, bill, appointments) and keeps its examinations in the
 * system; every other practice prints the full clinical record.
 */
final class VisitForm
{
    /** Every vital sign the system can record, in the order a form asks for them. */
    public const VITALS = [
        'blood_pressure' => 'Blood pressure',
        'temperature' => 'Temperature',
        'pulse' => 'Pulse',
        'respiratory_rate' => 'Respiratory rate',
        'spo2' => 'Oxygen saturation (SpO₂)',
        'weight' => 'Weight',
        'height' => 'Height',
    ];

    public const SIMPLE = 'simple';

    public const DETAILED = 'detailed';

    /** How a dental visit is written down. */
    public const DENTAL_NOTES = [
        self::SIMPLE => 'Simple notes',
        self::DETAILED => 'Detailed case sheet',
    ];

    public const SUMMARY = 'summary';

    public const FULL = 'full';

    /** What the printed visit report shows. */
    public const VISIT_REPORTS = [
        self::SUMMARY => 'Patient summary',
        self::FULL => 'Full clinical record',
    ];

    /** The vitals a dental clinic records unless it chooses otherwise. */
    private const DENTAL_VITALS = ['blood_pressure'];

    /** @param list<string> $vitals */
    public function __construct(
        public readonly array $vitals,
        public readonly string $dentalNotes,
        public readonly bool $vitalsCustomised,
        public readonly bool $dentalNotesCustomised,
        public readonly bool $dentalClinic,
        public readonly bool $dentistry,
        public readonly string $visitReport = self::FULL,
        public readonly bool $visitReportCustomised = false,
    ) {}

    /** The visit form of the hospital in context (a general hospital's where there is none). */
    public static function current(): self
    {
        return self::of(app(HospitalSettings::class)->hospital());
    }

    public static function of(?Hospital $hospital): self
    {
        $stored = is_array($hospital?->settings['clinical'] ?? null) ? $hospital->settings['clinical'] : [];
        $practice = Practice::of($hospital);
        $dentalClinic = $practice->sector->key === 'dental_clinic';

        $vitals = is_array($stored['vitals'] ?? null) ? self::validVitals($stored['vitals']) : null;
        $notes = in_array($stored['dental_notes'] ?? null, array_keys(self::DENTAL_NOTES), true) ? (string) $stored['dental_notes'] : null;
        $report = in_array($stored['visit_report'] ?? null, array_keys(self::VISIT_REPORTS), true) ? (string) $stored['visit_report'] : null;

        return new self(
            $vitals ?? self::defaultVitals($practice),
            $notes ?? self::defaultDentalNotes($practice),
            $vitals !== null,
            $notes !== null,
            $dentalClinic,
            DentalService::enabledFor($hospital),
            $report ?? self::defaultVisitReport($practice),
            $report !== null,
        );
    }

    /** @return list<string> */
    public static function defaultVitals(Practice $practice): array
    {
        return $practice->sector->key === 'dental_clinic' ? self::DENTAL_VITALS : array_keys(self::VITALS);
    }

    public static function defaultDentalNotes(Practice $practice): string
    {
        return $practice->sector->key === 'dental_clinic' ? self::SIMPLE : self::DETAILED;
    }

    public static function defaultVisitReport(Practice $practice): string
    {
        return $practice->sector->key === 'dental_clinic' ? self::SUMMARY : self::FULL;
    }

    /** @param array<mixed> $vitals @return list<string> in the order a form asks for them */
    public static function validVitals(array $vitals): array
    {
        return array_values(array_intersect(array_keys(self::VITALS), $vitals));
    }

    public function records(string $vital): bool
    {
        return in_array($vital, $this->vitals, true);
    }

    /** Whether the BMI is worked out: only from a weight and a height both recorded. */
    public function showsBmi(): bool
    {
        return $this->records('weight') && $this->records('height');
    }

    /** The heading of the vitals step: "Blood pressure" when that is all it holds. */
    public function vitalsTitle(): string
    {
        return count($this->vitals) === 1 ? self::VITALS[$this->vitals[0]] : 'Vital signs';
    }

    /**
     * A dental clinic writes the presenting complaint, not a separate reason:
     * the visit's reason is filled from the complaint's first words.
     */
    public function asksReason(): bool
    {
        return ! $this->dentalClinic;
    }

    /** No suggestion pills on the new visit form of a dental clinic. */
    public function suggests(): bool
    {
        return ! $this->dentalClinic;
    }

    /** The new visit form asks for the dental History and Oral examination. */
    public function dentalVisitForm(): bool
    {
        return $this->dentalClinic && $this->dentalNotes === self::SIMPLE;
    }

    /**
     * A dental clinic writes the medicine as a dosage (docs/pharmacy.md,
     * "Dosage"): picked from stock, the dose, how often, for how many days.
     */
    public function writesDosage(): bool
    {
        return $this->dentalClinic;
    }

    /** The printed visit report is the patient summary, not the clinical record. */
    public function printsSummary(): bool
    {
        return $this->visitReport === self::SUMMARY;
    }

    /**
     * The reason a visit lists under, from the first words of the presenting
     * complaint: its first line or sentence, at most ten words and 60
     * characters, so a list, the queue and a report still show something.
     */
    public static function reasonFrom(?string $complaint): ?string
    {
        $text = trim((string) $complaint);
        if ($text === '') {
            return null;
        }

        $first = trim((string) preg_split('/[\r\n]+|(?<=[.;!?])\s/u', $text, 2)[0]);
        $words = preg_split('/\s+/u', $first) ?: [];
        $short = implode(' ', array_slice($words, 0, 10));
        if (mb_strlen($short) > 60) {
            $short = rtrim(mb_substr($short, 0, 60));
        }

        return rtrim($short, ' .;,!?:') ?: null;
    }

    /** @return array<string,mixed> the shape the app and the settings page read */
    public function toArray(): array
    {
        return [
            'vitals' => $this->vitals,
            'vitals_title' => $this->vitalsTitle(),
            'vitals_customised' => $this->vitalsCustomised,
            'shows_bmi' => $this->showsBmi(),
            'dental_notes' => $this->dentalNotes,
            'dental_notes_customised' => $this->dentalNotesCustomised,
            'dentistry' => $this->dentistry,
            'asks_reason' => $this->asksReason(),
            'suggests' => $this->suggests(),
            'dental_visit_form' => $this->dentalVisitForm(),
            'dosage' => $this->writesDosage(),
            'visit_report' => $this->visitReport,
            'visit_report_customised' => $this->visitReportCustomised,
        ];
    }
}
