<?php

namespace Tests\Unit;

use App\Support\PasswordPolicy;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Tests\TestCase;

class PasswordPolicyTest extends TestCase
{
    private function passes(string $password): bool
    {
        return Validator::make(['p' => $password], ['p' => Password::defaults()])->passes();
    }

    public function test_eight_characters_with_letters_and_numbers(): void
    {
        $this->assertFalse($this->passes('abc123'), 'six is no longer enough');
        $this->assertFalse($this->passes('abcdefgh'), 'letters alone');
        $this->assertFalse($this->passes('12345678'), 'numbers alone');
        $this->assertTrue($this->passes('kampala7'));
    }

    public function test_the_words_on_every_form_name_the_same_minimum_as_the_rule(): void
    {
        $this->assertStringContainsString((string) PasswordPolicy::MIN, PasswordPolicy::hint());

        $doc = (string) file_get_contents(base_path('docs/CAPABILITIES.md'));
        $this->assertMatchesRegularExpression('/Password policy\*\*: minimum '.PasswordPolicy::MIN.' characters/', $doc, 'CAPABILITIES.md must state the rule the code enforces');
    }

    public function test_the_breach_check_is_on_when_configured_and_fails_open(): void
    {
        config(['auth.password_breach_check' => true]);
        \Illuminate\Support\Facades\Http::fake(['api.pwnedpasswords.com/*' => \Illuminate\Support\Facades\Http::response('', 500)]);

        $this->assertTrue($this->passes('kampala7'), 'an unreachable service never blocks a password');
    }

    public function test_a_breached_password_is_refused(): void
    {
        config(['auth.password_breach_check' => true]);
        $hash = strtoupper(sha1('password1'));
        \Illuminate\Support\Facades\Http::fake(['api.pwnedpasswords.com/range/'.substr($hash, 0, 5) => \Illuminate\Support\Facades\Http::response(substr($hash, 5).':24000')]);

        $this->assertFalse($this->passes('password1'));
    }
}
