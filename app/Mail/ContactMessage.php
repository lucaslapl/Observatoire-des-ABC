<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContactMessage extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly string $message,
    ) {
        $this->replyTo($this->email, $this->name ?: null);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Observatoire des ABC — nouveau message de contact',
        );
    }

    public function content(): Content
    {
        $name = e($this->name !== '' ? $this->name : 'Anonyme');
        $email = e($this->email);
        $message = nl2br(e($this->message));

        return new Content(htmlString: <<<HTML
        <!DOCTYPE html>
        <html lang="fr">
        <body style="font-family: sans-serif; color: #1f2937;">
            <h2 style="margin-bottom: 4px;">Nouveau message via le formulaire de contact</h2>
            <p style="color: #6b7280; margin-top: 0;">Envoyé depuis observatoire-abc.fr</p>
            <p><strong>De&nbsp;:</strong> {$name} &lt;{$email}&gt;</p>
            <p style="border-left: 3px solid #14532d; padding-left: 12px;">{$message}</p>
            <p style="color: #6b7280; font-size: 13px;">Répondez directement à cet email pour contacter l'expéditeur.</p>
        </body>
        </html>
        HTML);
    }
}
