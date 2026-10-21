<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmailConnectionTestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $senderEmail, public string $systemName)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'MasterExam - Email Connection Test');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.email-connection-test', with: [
            'senderEmail' => $this->senderEmail,
            'systemName' => $this->systemName,
        ]);
    }
}
