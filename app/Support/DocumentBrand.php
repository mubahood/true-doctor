<?php

namespace App\Support;

use App\Models\Hospital;
use Illuminate\Support\Facades\Storage;

/**
 * Who the hospital is, as a document says it.
 *
 * Every PDF this system prints leaves the building: an invoice a patient keeps,
 * a receipt they argue with, a lab result another clinic reads, a usage
 * statement an insurer files. Until now each template wrote its own header —
 * `$hospital?->name` and `$hospital?->address`, in whatever size that template
 * happened to use — so seven documents from one hospital looked like seven
 * documents from seven hospitals, and none of them carried a phone number
 * anybody could call about it.
 *
 * This resolves the letterhead once. Every template renders the same
 * `<x-pdf.letterhead>` from it, so a hospital that sets its logo sets it
 * everywhere, and adding a line here adds it to every document at once.
 *
 * THE LOGO IS EMBEDDED, NOT LINKED. DomPDF runs with `enable_remote = false`
 * and a chroot on the project root, so a URL fetches nothing and a path outside
 * the root is refused. A base64 data URI is neither — it is already in the
 * document by the time DomPDF reads it.
 */
class DocumentBrand
{
    /** Big enough to stay sharp on paper, small enough not to bloat every PDF. */
    private const LOGO_MAX_BYTES = 512 * 1024;

    /** What a browser will actually draw inside a PDF. */
    private const LOGO_TYPES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
    ];

    private ?array $memo = null;

    public function __construct(private readonly HospitalSettings $settings) {}

    /**
     * The letterhead, resolved once per request.
     *
     * @return array{
     *     name:string, address:?string, phone:?string, email:?string,
     *     website:?string, registration:?string, footer:?string, logo:?string
     * }
     */
    public function resolve(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $hospital = $this->settings->hospital();
        $profile = $this->profileOf($hospital);

        return $this->memo = [
            'name' => $this->clean($hospital?->name)
                ?? $this->clean(config('app.name'))
                ?? 'Hospital',
            'address' => $this->clean($hospital?->address),
            'phone' => $this->clean($profile['phone'] ?? null),
            'email' => $this->clean($profile['email'] ?? null),
            'website' => $this->clean($profile['website'] ?? null),
            'registration' => $this->clean($profile['registration'] ?? null),
            // The one line every document ends on — terms, a thank-you, a
            // legal notice. It was already a billing setting; it belongs on
            // everything, not only on invoices.
            'footer' => $this->clean($this->settings->get('invoice_footer')),
            'logo' => $this->logo($hospital),
        ];
    }

    /**
     * The contact lines, kept in `hospitals.settings['profile']`.
     *
     * A column each would be five migrations for five strings that nothing
     * queries, joins or sorts by — they are printed and nothing else.
     *
     * @return array<string,mixed>
     */
    public function profileOf(?Hospital $hospital): array
    {
        // One reader, shared with setup. A hospital that gave its phone number
        // in the wizard had it written under a different key, so every document
        // it printed carried no way to ring anybody — through here, details
        // given either way reach the letterhead.
        return $hospital === null
            ? []
            : app(\App\Support\OnboardingStatus::class)->contact($hospital);
    }

    /** One line: everything that identifies the hospital, comma separated. */
    public function oneLine(): string
    {
        $brand = $this->resolve();

        $parts = array_filter([
            $brand['address'],
            $brand['phone'],
            $brand['email'],
            $brand['website'],
        ]);

        return implode('  ·  ', $parts);
    }

    /**
     * The logo as a data URI, or null when there is none to draw.
     *
     * Silent on every failure — a document must still print for a hospital
     * whose logo file was deleted from under it, and an exception here would
     * take the invoice with it.
     */
    private function logo(?Hospital $hospital): ?string
    {
        $path = $this->clean($hospital?->logo);

        if ($path === null) {
            return null;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = self::LOGO_TYPES[$extension] ?? null;

        if ($mime === null) {
            return null;
        }

        try {
            $disk = Storage::disk('public');

            if (! $disk->exists($path) || $disk->size($path) > self::LOGO_MAX_BYTES) {
                return null;
            }

            return 'data:'.$mime.';base64,'.base64_encode((string) $disk->get($path));
        } catch (\Throwable) {
            return null;
        }
    }

    private function clean(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
