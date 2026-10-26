<?php

declare(strict_types=1);

namespace Shared\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

final class PasswordRule implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a string.');

            return;
        }

        if (strlen($value) < 12) {
            $fail('The :attribute must be at least 12 characters.');

            return;
        }

        if (! preg_match('/[a-zA-Z]/', $value)) {
            $fail('The :attribute must contain at least one letter.');

            return;
        }

        if (! preg_match('/\d/', $value)) {
            $fail('The :attribute must contain at least one number.');

            return;
        }
    }
}
