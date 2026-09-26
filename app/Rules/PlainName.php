<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A name as a person writes one — a hospital's, or their own.
 *
 * What it refuses is what spam puts in a name field and no person ever does:
 * a web address ("https://…", "www.…", "5a48ca64.nip.io"), an email address,
 * markup, or a jumble of letters and digits in one word
 * ("NAEWTRER2536325NEYRTHYT"). Initials, apostrophes, hyphens, "St.", "Dr.",
 * "Ward 3" and names in any alphabet all pass.
 */
class PlainName implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $v = trim($value);

        $link = '~(https?:|ftp:|www\.|//|\b[a-z0-9-]{2,}\.(com|net|org|io|ru|xyz|top|info|biz|site|online|click|link|shop|ly|me|co|cc|tk|ml|ga|cf|gq|app|dev)\b|\b\d{1,3}(\.\d{1,3}){3}\b|\.nip\.io|t\.me/)~iu';
        if (preg_match($link, $v)) {
            $fail('A name cannot contain a web address.');

            return;
        }

        if (str_contains($v, '@')) {
            $fail('A name cannot contain an email address — there is a separate box for that.');

            return;
        }

        if (preg_match('/[<>{}\[\]\\\\]/', $v)) {
            $fail('A name cannot contain < > { } [ ] or \\.');

            return;
        }

        if (! preg_match('/\p{L}/u', $v)) {
            $fail('A name needs at least one letter.');

            return;
        }

        foreach (preg_split('/[\s\-\/]+/u', $v) ?: [] as $word) {
            $digits = preg_match_all('/\d/u', $word);
            $letters = preg_match_all('/\p{L}/u', $word);
            if ($digits >= 4 && $letters >= 4) {
                $fail('That does not look like a name. Please write it the way it appears on your signboard or ID.');

                return;
            }
        }
    }
}
