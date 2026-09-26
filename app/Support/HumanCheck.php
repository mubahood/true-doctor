<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Are you a person?" on the public forms — drawn here, by this server, with
 * no third party watching our visitors (no Google reCAPTCHA).
 *
 * Four layers, each cheap for a person and expensive for a script:
 *
 *  1. An image of five characters, warped, rotated, crossed and speckled,
 *     drawn fresh for every challenge (image()). The alphabet leaves out the
 *     look-alikes — 0/O, 1/I/L — so a person never fails on a glyph.
 *  2. Single use: a challenge is consumed by the first answer, right or
 *     wrong, and lives ten minutes. A solved image cannot be replayed.
 *  3. A field no person can see (the honeypot) that must stay empty.
 *  4. Time on the form: a signed timestamp from when the form was drawn. A
 *     human does not fill a sign-up form in under three seconds.
 *
 * Challenges live in the cache under a random id, so two tabs each have
 * their own and nothing depends on the session surviving.
 */
final class HumanCheck
{
    /** No 0/O, 1/I/L — a person should never fail on a look-alike. */
    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const LENGTH = 5;

    /** How long a picture may be answered. */
    public const TTL = 600;

    /** Faster than this is not somebody reading a form. */
    public const MIN_SECONDS = 3;

    /** Older than this, the form was left open a very long time: draw a new one. */
    public const MAX_SECONDS = 7200;

    public const WIDTH = 240;

    public const HEIGHT = 80;

    /** Issue a challenge for one form; returns its id. */
    public static function issue(string $form): string
    {
        $id = Str::random(32);
        $code = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        Cache::put(self::key($id), ['form' => $form, 'code' => $code], self::TTL);

        return $id;
    }

    /** The code behind a challenge, for drawing it — null once used or expired. */
    public static function code(string $id): ?string
    {
        $entry = Cache::get(self::key($id));

        return is_array($entry) ? (string) $entry['code'] : null;
    }

    /**
     * Whether this is the right answer to this form's challenge. The
     * challenge is spent either way: a wrong guess gets a new picture, not
     * another try at the same one.
     */
    public static function verify(string $form, ?string $id, ?string $answer): bool
    {
        if ($id === null || $id === '' || ! preg_match('/^[A-Za-z0-9]{32}$/', $id)) {
            return false;
        }

        $entry = Cache::pull(self::key($id));

        if (! is_array($entry) || ($entry['form'] ?? null) !== $form) {
            return false;
        }

        return hash_equals((string) $entry['code'], self::normalise((string) $answer));
    }

    /** What a person types, read generously: any case, any spacing. */
    public static function normalise(string $answer): string
    {
        return strtoupper(preg_replace('/\s+/', '', $answer) ?? '');
    }

    /** A signed "this form was drawn at" stamp. */
    public static function stamp(string $form): string
    {
        return Crypt::encryptString($form.'|'.time());
    }

    /** Seconds since the form was drawn, or null if the stamp is not ours. */
    public static function age(string $form, ?string $stamp): ?int
    {
        if ($stamp === null || $stamp === '') {
            return null;
        }

        try {
            [$f, $at] = explode('|', Crypt::decryptString($stamp), 2);
        } catch (Throwable) {
            return null;
        }

        return $f === $form && ctype_digit($at) ? time() - (int) $at : null;
    }

    /**
     * The validation rules a form adds to its own: the answer, the honeypot
     * and the stamp. One place, so every public form asks the same way.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(string $form): array
    {
        return [
            'human_id' => ['required', 'string'],
            'human_answer' => ['required', 'string', 'max:20', function (string $attribute, mixed $value, Closure $fail) use ($form) {
                if (! self::verify($form, (string) request()->input('human_id'), (string) $value)) {
                    $fail('The characters did not match the picture. Here is a new one — try again.');
                }
            }],
            // A person never sees this field, so a person never fills it.
            'website' => ['prohibited'],
            'human_started' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) use ($form) {
                $age = self::age($form, (string) $value);
                if ($age === null || $age < self::MIN_SECONDS || $age > self::MAX_SECONDS) {
                    $fail('Please take a moment over the form and send it again.');
                }
            }],
        ];
    }

    /** @return array<string,string> */
    public static function messages(): array
    {
        return [
            'human_answer.required' => 'Type the characters you see in the picture.',
            'human_id.required' => 'The picture has expired — here is a new one.',
            'human_started.required' => 'Please take a moment over the form and send it again.',
            'website.prohibited' => 'Something went wrong. Please try again.',
        ];
    }

    /**
     * Draw the picture: PNG bytes.
     *
     * Every choice below is random per image — background, colours, sizes,
     * angles, the path of the lines, the phase of the warp — so no two
     * images of the same code look alike, and nothing about one image helps
     * read the next.
     */
    public static function image(string $code): string
    {
        $w = self::WIDTH;
        $h = self::HEIGHT;
        $fonts = [resource_path('fonts/human-check.ttf'), resource_path('fonts/human-check-serif.ttf')];

        $canvas = imagecreatetruecolor($w, $h);
        imagealphablending($canvas, true);

        // A soft two-tone gradient, never the same twice.
        [$r1, $g1, $b1] = [random_int(228, 250), random_int(232, 250), random_int(236, 252)];
        [$r2, $g2, $b2] = [random_int(206, 236), random_int(214, 240), random_int(222, 246)];
        for ($x = 0; $x < $w; $x++) {
            $t = $x / $w;
            $c = imagecolorallocate($canvas, (int) ($r1 + ($r2 - $r1) * $t), (int) ($g1 + ($g2 - $g1) * $t), (int) ($b1 + ($b2 - $b1) * $t));
            imageline($canvas, $x, 0, $x, $h, $c);
        }

        // Faint background marks: shapes a segmenter mistakes for strokes.
        for ($i = 0; $i < 14; $i++) {
            $c = imagecolorallocatealpha($canvas, random_int(120, 200), random_int(130, 210), random_int(150, 220), random_int(85, 110));
            imagefilledellipse($canvas, random_int(0, $w), random_int(0, $h), random_int(8, 30), random_int(8, 30), $c);
        }
        for ($i = 0; $i < 5; $i++) {
            $c = imagecolorallocatealpha($canvas, random_int(90, 170), random_int(100, 180), random_int(120, 200), 70);
            imagesetthickness($canvas, random_int(1, 2));
            imagearc($canvas, random_int(0, $w), random_int(0, $h), random_int(60, 220), random_int(30, 120), random_int(0, 360), random_int(0, 360), $c);
        }

        // The characters: each its own font, size, tilt, height and colour,
        // spaced unevenly and allowed to crowd one another.
        $palette = [[10, 60, 130], [14, 90, 160], [40, 40, 90], [20, 100, 80], [110, 30, 60], [60, 20, 110]];
        $x = random_int(12, 24);
        $len = strlen($code);
        $colors = [];
        for ($i = 0; $i < $len; $i++) {
            [$cr, $cg, $cb] = $palette[array_rand($palette)];
            $color = imagecolorallocate($canvas, $cr, $cg, $cb);
            $colors[] = $color;
            $size = random_int(25, 32);
            $angle = random_int(-24, 24);
            $y = random_int(46, 62);
            $font = $fonts[array_rand($fonts)];
            // A faint offset shadow blurs the edge a contour-tracer relies on.
            $shadow = imagecolorallocatealpha($canvas, $cr, $cg, $cb, 95);
            imagettftext($canvas, $size, $angle, $x + random_int(-2, 2), $y + random_int(-2, 2), $shadow, $font, $code[$i]);
            $box = imagettftext($canvas, $size, $angle, $x, $y, $color, $font, $code[$i]);
            $x = max($box[2], $box[4]) + random_int(-3, 6);
        }

        // Two lines through the text, in the text's own colours, so they
        // cannot be filtered out by colour.
        for ($l = 0; $l < 2; $l++) {
            imagesetthickness($canvas, random_int(1, 2));
            $amp = random_int(6, 14);
            $freq = random_int(12, 26) / 1000;
            $phase = random_int(0, 628) / 100;
            $base = random_int(28, 54);
            $color = $colors[array_rand($colors)];
            $px = 0;
            $py = (int) ($base + $amp * sin($phase));
            for ($lx = 4; $lx <= $w; $lx += 4) {
                $ly = (int) ($base + $amp * sin($lx * $freq + $phase));
                imageline($canvas, $px, $py, $lx, $ly, $color);
                [$px, $py] = [$lx, $ly];
            }
        }
        imagesetthickness($canvas, 1);

        // The warp: every column slides by a sine of its position, and every
        // row by another — straight baselines are what OCR looks for first.
        $warped = imagecreatetruecolor($w, $h);
        $bg = imagecolorallocate($warped, $r2, $g2, $b2);
        imagefill($warped, 0, 0, $bg);
        $ax = random_int(3, 6);
        $fx = random_int(20, 38) / 1000;
        $phx = random_int(0, 628) / 100;
        for ($cx = 0; $cx < $w; $cx++) {
            $shift = (int) round($ax * sin($cx * $fx + $phx));
            imagecopy($warped, $canvas, $cx, $shift, $cx, 0, 1, $h);
        }
        $final = imagecreatetruecolor($w, $h);
        imagefill($final, 0, 0, $bg);
        $ay = random_int(2, 4);
        $fy = random_int(60, 110) / 1000;
        $phy = random_int(0, 628) / 100;
        for ($cy = 0; $cy < $h; $cy++) {
            $shift = (int) round($ay * sin($cy * $fy + $phy));
            imagecopy($final, $warped, $shift, $cy, 0, $cy, $w, 1);
        }

        // Speckle on top.
        for ($i = 0; $i < 260; $i++) {
            $c = imagecolorallocatealpha($final, random_int(40, 200), random_int(40, 200), random_int(60, 220), random_int(40, 90));
            imagesetpixel($final, random_int(0, $w - 1), random_int(0, $h - 1), $c);
        }

        ob_start();
        imagepng($final, null, 6);
        $png = (string) ob_get_clean();

        imagedestroy($canvas);
        imagedestroy($warped);
        imagedestroy($final);

        return $png;
    }

    private static function key(string $id): string
    {
        return 'human-check:'.$id;
    }
}
