<?php

namespace App\Support\Compliance;

use App\Models\Hospital;
use App\Support\Edition;
use App\Support\SupportContact;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * The data protection pack (docs/compliance/README.md).
 *
 * One source per document: the markdown in docs/compliance/, filled in with
 * the clinic's own details and the figures in config/compliance.php, then
 * printed as a plain PDF. Editing the markdown edits the PDF.
 */
class ComplianceDocuments
{
    /**
     * key => [title, one line on what it is for, online service only].
     *
     * @var array<string, array{0:string,1:string,2:bool}>
     */
    public const DOCUMENTS = [
        'data-processing-agreement' => ['Data processing agreement', 'Between your clinic (controller) and us (processor) for the online service.', true],
        'privacy-notice' => ['Privacy notice for patients', 'Print it for the waiting room; give a copy to any patient who asks.', false],
        'data-subject-rights' => ['Patients\' rights procedure', 'How to handle a request to see, correct or object to the use of personal data.', false],
        'retention-schedule' => ['Retention schedule', 'How long each kind of record is kept, and why.', false],
        'breach-runbook' => ['Data breach procedure', 'What to do in the first hours after data is lost, stolen or seen by the wrong person.', false],
        'dpia' => ['Data protection impact assessment', 'The risks to patients\' data in this system and how each is controlled.', false],
        'pdpo-registration' => ['PDPO registration checklist', 'What you need to register with the Personal Data Protection Office.', false],
    ];

    /** @return array<string, array{0:string,1:string,2:bool}> the documents this edition offers */
    public static function available(): array
    {
        return array_filter(self::DOCUMENTS, fn (array $d) => ! $d[2] || Edition::isSaas());
    }

    public static function exists(string $key): bool
    {
        return isset(self::available()[$key]);
    }

    public static function title(string $key): string
    {
        return self::DOCUMENTS[$key][0] ?? $key;
    }

    public static function path(string $key): string
    {
        return base_path('docs/compliance/'.$key.'.md');
    }

    /** The document as HTML, filled in for this clinic. */
    public static function html(string $key, ?Hospital $hospital): string
    {
        $markdown = (string) File::get(self::path($key));
        // The PDF prints its own title; the markdown's heading is for reading it on GitHub.
        $markdown = (string) preg_replace('/\A#\s[^\n]*\n/', '', ltrim($markdown));
        $markdown = strtr($markdown, self::values($hospital));

        return Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }

    /** @return array<string,string> placeholder => value */
    public static function values(?Hospital $hospital): array
    {
        $profile = (array) ($hospital?->settings['profile'] ?? []);
        $contact = implode(', ', array_filter([
            is_string($profile['phone'] ?? null) ? $profile['phone'] : null,
            is_string($profile['email'] ?? null) ? $profile['email'] : null,
        ]));
        $processorEmail = (string) (config('compliance.processor.email') ?: SupportContact::platform()->email());

        return [
            '{clinic}' => self::plain($hospital?->name ?: 'the clinic'),
            '{clinic_address}' => self::plain($hospital?->address ?: 'the clinic\'s address'),
            '{clinic_contact}' => self::plain($contact !== '' ? $contact : 'the clinic\'s reception'),
            '{processor}' => self::plain((string) config('compliance.processor.name')),
            '{product}' => self::plain((string) config('compliance.processor.trading_as')),
            '{processor_email}' => self::plain($processorEmail !== '' ? $processorEmail : 'the support contact on the website'),
            '{pdpo_registration}' => self::plain((string) (config('compliance.pdpo_registration') ?: 'to be added once issued')),
            '{dpa_version}' => (string) config('compliance.dpa_version'),
            '{response_days}' => (string) config('compliance.response_days'),
            '{export_hours}' => (string) config('compliance.export_hours'),
            '{date}' => now()->format('j F Y'),
            '{retention_table}' => self::retentionTable(),
        ];
    }

    /** Clinic-typed text must not become markdown (a name with an asterisk, a bracket). */
    private static function plain(string $value): string
    {
        return addcslashes(str_replace(["\r", "\n"], ' ', $value), '\\`*_{}[]()#+!|<>');
    }

    private static function retentionTable(): string
    {
        $rows = ['| Record | Kept for | Why |', '|---|---|---|'];
        foreach ((array) config('compliance.retention') as $r) {
            $rows[] = '| '.self::plain((string) $r['record']).' | '.self::plain((string) $r['keep']).' | '.self::plain((string) $r['source']).' |';
        }

        return implode("\n", $rows);
    }
}
