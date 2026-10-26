<?php

declare(strict_types=1);

namespace Modules\Auth\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class VerifyEmailMail extends Mailable
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
            subject: 'Verify Your Email Address',
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: sprintf(
                '<p>Please verify your email address for %s. Use this token: <strong>%s</strong></p>',
                e($this->tenantSlug),
                e($this->token)
            ),
        );
    }
}
