<?php

namespace App\Support;

use App\Models\Visit;

/**
 * Words to reach for while writing a visit, and what should be in front of
 * whoever is writing it.
 *
 * Shared by the web's open-visit dialog, its clinical notes panel and the
 * app, so a suggestion offered in one is offered in all of them. Every
 * suggestion is a typing aid that lands in the box as editable text — never a
 * menu to pick a diagnosis from.
 */
final class VisitPhrases
{
    /** The fields a clinician writes, in the order the notes form asks for them. */
    public const NOTE_FIELDS = ['complaints', 'diagnosis', 'doctor_remarks'];

    /** Long enough for a phrase, short enough that it is not somebody's paragraph. */
    public const PHRASE_MAX = 60;

    /** Enough to recognise one, not so many that reading them is work. */
    public const PHRASE_LIMIT = 8;

    /** Reasons for a visit are shorter lists — the desk picks one, maybe two. */
    public const REASON_LIMIT = 6;

    /**
     * Reasons for a visit, this hospital's own words first.
     *
     * @return list<string>
     */
    public static function reasons(): array
    {
        $out = [];
        foreach ([...self::mostWritten('reason', self::REASON_LIMIT), ...SampleCatalogue::visitReasons()] as $phrase) {
            $phrase = trim((string) $phrase);
            if ($phrase === '' || mb_strlen($phrase) > self::PHRASE_MAX) {
                continue;
            }
            // Case-insensitively unique: "Follow-up" twice is one suggestion.
            $out[mb_strtolower($phrase)] ??= $phrase;
        }

        return array_slice(array_values($out), 0, self::REASON_LIMIT);
    }

    /**
     * What the desk is offered for complaints and a diagnosis.
     *
     * @return array{complaints:list<string>,diagnosis:list<string>}
     */
    public static function desk(): array
    {
        $curated = SampleCatalogue::clinicalPhrases();

        return [
            'complaints' => $curated['complaints'] ?? [],
            'diagnosis' => $curated['diagnosis'] ?? [],
        ];
    }

    /**
     * Words for the notes on this visit, per field.
     *
     * Three sources in order of authority: what reception already wrote on
     * THIS visit, what this hospital writes most often, and a curated set to
     * top it up.
     *
     * @return array<string, list<array{value:string,label:string,source:string}>>
     */
    public static function forNotes(Visit $visit): array
    {
        $curated = SampleCatalogue::clinicalPhrases();

        $out = [];
        foreach (self::NOTE_FIELDS as $field) {
            $rows = [];
            $seen = [];

            $add = function (?string $value, string $source) use (&$rows, &$seen) {
                $value = trim((string) $value);
                // Long narratives are not phrases; offering one would paste
                // somebody else's paragraph into this patient's record.
                if ($value === '' || mb_strlen($value) > self::PHRASE_MAX) {
                    return;
                }
                $key = mb_strtolower($value);
                if (isset($seen[$key])) {
                    return;
                }
                $seen[$key] = true;
                $rows[] = ['value' => $value, 'label' => $value, 'source' => $source];
            };

            // What reception wrote, for the field it answers.
            if ($field === 'complaints') {
                $add($visit->reason, 'reception');
            }

            foreach (self::mostWritten($field, self::PHRASE_LIMIT * 4) as $value) {
                $add($value, 'hospital');
            }

            foreach ($curated[$field] ?? [] as $value) {
                $add($value, 'curated');
            }

            $out[$field] = array_slice($rows, 0, self::PHRASE_LIMIT);
        }

        return $out;
    }

    /**
     * What should be in front of whoever is writing the notes.
     *
     * Allergies lead, because prescribing against one is the mistake this
     * screen is closest to. Everything here is already on the record; the only
     * change is showing it at the moment it is needed.
     *
     * @return array{allergies:list<string>,conditions:list<string>,reason:?string,vitals:list<string>,previous:?array{diagnosis:string,when:?string}}
     */
    public static function context(Visit $visit): array
    {
        $patient = $visit->patient;

        $previous = Visit::where('patient_id', $visit->patient_id)
            ->whereKeyNot($visit->id)
            ->whereNotNull('diagnosis')
            ->where('diagnosis', '!=', '')
            ->latest('id')
            ->first();

        $vitals = array_values(array_filter([
            $visit->temperature !== null ? $visit->temperature.'°C' : null,
            $visit->blood_pressure,
            $visit->pulse !== null ? $visit->pulse.' bpm' : null,
            $visit->spo2 !== null ? 'SpO₂ '.$visit->spo2.'%' : null,
            $visit->respiratory_rate !== null ? 'RR '.$visit->respiratory_rate : null,
        ]));

        return [
            'allergies' => $patient === null ? [] : array_values(array_filter((array) $patient->allergies)),
            'conditions' => $patient === null ? [] : array_values(array_filter((array) $patient->chronic_conditions)),
            'reason' => $visit->reason,
            'vitals' => $vitals,
            'previous' => $previous === null ? null : [
                'diagnosis' => (string) $previous->diagnosis,
                'when' => $previous->created_at?->format('d M Y'),
            ],
        ];
    }

    /**
     * What this hospital actually writes in that field.
     *
     * More than once, or it is not a house phrase — it is one doctor's
     * sentence about one patient, and offering it to the next would be
     * pasting someone else's note into this record. Fetched wide and trimmed
     * by the callers: the length cap is on CHARACTERS, and the two engines
     * this runs on do not agree on how to count them.
     *
     * @return list<string>
     */
    private static function mostWritten(string $field, int $limit): array
    {
        return Visit::query()
            ->whereNotNull($field)
            ->where($field, '!=', '')
            ->selectRaw($field.', count(*) as n')
            ->groupBy($field)
            ->havingRaw('count(*) > 1')
            ->orderByDesc('n')
            ->limit($limit)
            ->pluck($field)
            ->map(fn ($v) => (string) $v)
            ->values()
            ->all();
    }
}
