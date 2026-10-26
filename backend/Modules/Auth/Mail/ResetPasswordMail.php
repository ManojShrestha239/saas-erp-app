<?php

declare(strict_types=1);

namespace Modules\Auth\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class ResetPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $token,
        public readonly string $email,
        public readonly string $tenantSlug,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reset Your Password',
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: sprintf(
                '<p>You requested a password reset for %s. Use this token to reset your password: <strong>%s</strong></p>',
                e($this->tenantSlug),
                e($this->token)
            ),
        );
    }
}
