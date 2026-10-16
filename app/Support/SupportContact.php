<?php

namespace App\Support;

use App\Models\Hospital;
use App\Support\Licensing\InstalledLicence;
use Illuminate\Support\Facades\Lang;

/**
 * Who to ask for help, in one place.
 *
 * Every screen, email and PDF that says "WhatsApp us" reads this class and
 * nothing else; the number is never typed into a view. The values come from
 * Settings → Site (super admin) with the env fallbacks in config/support.php.
 *
 * A standalone copy may be supported by somebody other than us, so
 * {@see forHospital()} answers in this order: the signed licence's `support`
 * block (a partner who sold and installed it), then the organisation's own IT
 * contact (hospital settings `support`), then the platform default.
 *
 * A prefilled message never carries a patient or a record: only a page or
 * screen name, the hospital name, a role, an error reference or a version.
 */
final class SupportContact
{
    /** What the person sees about who answers: platform, partner or own IT. */
    public const WHO_PLATFORM = 'platform';

    public const WHO_PARTNER = 'partner';

    public const WHO_OWN = 'own';

    /** @param array<string,string> $values */
    private function __construct(private readonly array $values, private readonly string $who) {}

    /** The platform's own contact (us). */
    public static function platform(): self
    {
        return new self([
            'whatsapp' => self::pick('contact_whatsapp', 'support.whatsapp'),
            'phone' => self::pick('contact_phone', 'support.phone'),
            'email' => self::pick('contact_email', 'support.email'),
            'hours' => self::pick('support_hours', 'support.hours'),
        ], self::WHO_PLATFORM);
    }

    /**
     * The contact for this hospital's copy: licence partner, own IT, platform.
     * Only a standalone copy has anyone but us to ask.
     */
    public static function forHospital(?Hospital $hospital = null): self
    {
        $platform = self::platform();
        if (! Edition::isStandalone()) {
            return $platform;
        }

        $partner = app(InstalledLicence::class)->support();
        if ($partner !== []) {
            return $platform->overlaid($partner, self::WHO_PARTNER);
        }

        $own = $hospital?->settings['support'] ?? null;
        if (is_array($own)) {
            $own = array_filter($own, fn ($v) => is_string($v) && trim($v) !== '');
            if ($own !== []) {
                return $platform->overlaid($own, self::WHO_OWN);
            }
        }

        return $platform;
    }

    /** The contact for whoever is looking now: their hospital, else the platform. */
    public static function current(): self
    {
        try {
            return self::forHospital(app(HospitalSettings::class)->hospital());
        } catch (\Throwable) {
            return self::platform();
        }
    }

    /** @param array<string,string> $over */
    private function overlaid(array $over, string $who): self
    {
        $merged = $this->values;
        foreach (['whatsapp', 'phone', 'email', 'hours'] as $key) {
            if (isset($over[$key])) {
                $merged[$key] = $over[$key];
            }
        }
        // A partner who gave a phone but no WhatsApp must not leave ours behind.
        if ($who !== self::WHO_PLATFORM && isset($over['phone']) && ! isset($over['whatsapp'])) {
            $merged['whatsapp'] = '';
        }

        return new self($merged, $who);
    }

    private static function pick(string $setting, string $config): string
    {
        // An error page asks too: with the database down the setting is
        // unreachable and the configured fallback must still answer.
        try {
            $value = Settings::get($setting);
        } catch (\Throwable) {
            $value = null;
        }

        return trim(is_string($value) && trim($value) !== '' ? $value : (string) config($config));
    }

    /** The digits for a link, country code first. Empty when there is no WhatsApp line. */
    public function whatsappDigits(): string
    {
        return preg_replace('/\D+/', '', $this->values['whatsapp'] ?? '') ?? '';
    }

    /** The number as people read it: +CCC NNN NNN NNN. */
    public function whatsappDisplay(): string
    {
        $digits = $this->whatsappDigits();
        if ($digits === '') {
            return '';
        }

        // Uganda and its neighbours are all three-digit country codes with nine-digit numbers.
        if (preg_match('/^(\d{3})(\d{3})(\d{3})(\d{3})$/', $digits, $m)) {
            return "+{$m[1]} {$m[2]} {$m[3]} {$m[4]}";
        }

        return '+'.$digits;
    }

    public function hasWhatsapp(): bool
    {
        return $this->whatsappDigits() !== '';
    }

    public function phone(): string
    {
        return $this->values['phone'] ?? '';
    }

    /** The phone as a `tel:` target. */
    public function phoneLink(): string
    {
        $phone = preg_replace('/[^\d+]/', '', $this->phone()) ?? '';

        return $phone === '' ? '' : 'tel:'.$phone;
    }

    public function email(): string
    {
        return $this->values['email'] ?? '';
    }

    public function hours(): string
    {
        return $this->values['hours'] ?? '';
    }

    /** platform, partner or own: who answers. */
    public function who(): string
    {
        return $this->who;
    }

    /**
     * The message for a context, filled from $vars. Unknown placeholders stay
     * out: a missing value becomes an empty string, never ":name".
     *
     * @param  array<string,scalar|null>  $vars
     */
    public function message(string $context, array $vars = []): string
    {
        $text = (string) Lang::get('support.'.$context, [], 'en');
        if ($text === 'support.'.$context) {
            $text = (string) Lang::get('support.site.general', [], 'en');
        }

        return trim(preg_replace('/\s{2,}/', ' ', preg_replace_callback(
            '/:([a-z_]+)/',
            fn ($m) => (string) ($vars[$m[1]] ?? ''),
            $text,
        ) ?? $text) ?? $text);
    }

    /** `https://wa.me/<digits>?text=...` for a named context; empty when there is no WhatsApp line. */
    public function waLink(string $context, array $vars = []): string
    {
        if (! $this->hasWhatsapp()) {
            return '';
        }

        return 'https://wa.me/'.$this->whatsappDigits().'?text='.rawurlencode($this->message($context, $vars));
    }

    /**
     * One markdown line for the foot of a notification email: WhatsApp (with
     * the context's prefilled message), the phone and the email. Empty when
     * there is nothing to offer.
     *
     * @param  array<string,scalar|null>  $vars
     */
    public function mailLine(string $context, array $vars = []): string
    {
        $parts = [];
        if ($this->hasWhatsapp()) {
            $parts[] = '[WhatsApp us on '.$this->whatsappDisplay().']('.$this->waLink($context, $vars).')';
        }
        if ($this->phone() !== '') {
            $parts[] = 'call '.$this->phone();
        }
        if ($this->email() !== '') {
            $parts[] = 'email '.$this->email();
        }
        if ($parts === []) {
            return '';
        }

        $line = 'Need a hand? '.implode(', ', array_slice($parts, 0, -1)).(count($parts) > 1 ? ' or ' : '').end($parts).'.';
        if ($this->hours() !== '') {
            $line .= ' '.$this->hours().'.';
        }

        return $line;
    }

    /**
     * What the API gives the app: the contact, who answers, and the prefilled
     * messages with their placeholders, so the app fills them in itself.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $templates = [];
        foreach (Lang::getLoader()->load('en', 'support') as $key => $text) {
            if (is_string($text) && str_contains($key, '.')) {
                $templates[$key] = $text;
            }
        }

        return [
            'whatsapp' => $this->whatsappDigits() ?: null,
            'whatsapp_display' => $this->whatsappDisplay() ?: null,
            'phone' => $this->phone() ?: null,
            'email' => $this->email() ?: null,
            'hours' => $this->hours() ?: null,
            'who' => $this->who,
            'templates' => $templates,
        ];
    }
}
