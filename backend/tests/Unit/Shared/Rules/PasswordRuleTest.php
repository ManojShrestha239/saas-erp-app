<?php

declare(strict_types=1);

use Shared\Rules\PasswordRule;

test('password rule rejects passwords under 12 characters', function (): void {
    $rule = new PasswordRule;
    $failed = false;
    $message = '';

    $rule->validate('password', 'Short1!', function (string $msg) use (&$failed, &$message): void {
        $failed = true;
        $message = $msg;
    });

    expect($failed)->toBeTrue();
    expect($message)->toBe('The :attribute must be at least 12 characters.');
});

test('password rule rejects passwords with no letters', function (): void {
    $rule = new PasswordRule;
    $failed = false;
    $message = '';

    $rule->validate('password', '123456789012345!', function (string $msg) use (&$failed, &$message): void {
        $failed = true;
        $message = $msg;
    });

    expect($failed)->toBeTrue();
    expect($message)->toBe('The :attribute must contain at least one letter.');
});

test('password rule rejects passwords with no numbers', function (): void {
    $rule = new PasswordRule;
    $failed = false;
    $message = '';

    $rule->validate('password', 'abcdefghijklmnop!', function (string $msg) use (&$failed, &$message): void {
        $failed = true;
        $message = $msg;
    });

    expect($failed)->toBeTrue();
    expect($message)->toBe('The :attribute must contain at least one number.');
});

test('password rule rejects non-string values', function (): void {
    $rule = new PasswordRule;
    $failed = false;
    $message = '';

    $rule->validate('password', 123456789012, function (string $msg) use (&$failed, &$message): void {
        $failed = true;
        $message = $msg;
    });

    expect($failed)->toBeTrue();
    expect($message)->toBe('The :attribute must be a string.');
});

test('password rule accepts passwords meeting all criteria', function (): void {
    $rule = new PasswordRule;
    $failed = false;

    $rule->validate('password', 'ValidPassword123!', function (): void {
        $failed = true;
    });

    expect($failed)->toBeFalse();
});
