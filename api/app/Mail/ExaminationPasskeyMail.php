<?php

namespace App\Mail;

use App\Models\EmailConfiguration;
use App\Models\ExaminationRegistration;
use App\Services\EmailTemplateService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class ExaminationPasskeyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public ExaminationRegistration $registration, public string $passkey, public ?array $templateOverride = null)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: app(EmailTemplateService::class)->plainText(
                ($this->templateOverride ?? app(EmailTemplateService::class)->published('passkey'))['subject'],
                $this->placeholders()
            ),
        );
    }

    public function content(): Content
    {
        $applicant = $this->registration->applicant;
        $schedule = $this->registration->schedule;

        $examDate = optional($schedule?->exam_date)?->format('F j, Y') ?: '—';
        $timeSlot = $schedule?->time_slot;
        if (! $timeSlot && $schedule) {
            $start = $schedule->start_time
                ? Carbon::parse($schedule->start_time)->format('g:i A')
                : '';
            $end = $schedule->end_time
                ? Carbon::parse($schedule->end_time)->format('g:i A')
                : '';
            $timeSlot = trim($start.' – '.$end, ' –');
        }

        $template = $this->templateOverride ?? app(EmailTemplateService::class)->published('passkey');

        $logoPath = public_path('tcc_logo.png');
        $logoDataUri = file_exists($logoPath)
            ? 'data:image/png;base64,'.base64_encode(file_get_contents($logoPath))
            : null;

        $roomNames = $schedule?->rooms?->pluck('room_name')->filter()->implode(', ');
        $venue = $this->registration->assignedRoom?->room_name ?: $schedule?->venue ?: ($roomNames ?: '—');

        $activeConfig = EmailConfiguration::query()->latest()->first();
        $supportEmail = $activeConfig?->sender_email ?: config('mail.from.address', 'guidance@tcc.edu.ph');

        return new Content(
            view: 'mail.examination-notification',
            text: 'mail.examination-notification-text',
            with: [
                'type' => 'passkey',
                'template' => $template,
                'values' => $this->placeholders(),
                'name' => $applicant?->name ?: 'Applicant',
                'applicantCode' => $applicant?->applicant_code,
                'program' => $applicant?->course_applied,
                'examTitle' => $schedule?->title ?: 'Entrance Examination',
                'examDate' => $examDate,
                'timeSlot' => $timeSlot ?: '—',
                'duration' => $schedule?->start_time && $schedule?->end_time
                    ? max(1, Carbon::parse($schedule->start_time)->diffInMinutes(Carbon::parse($schedule->end_time))).' minutes'
                    : '—',
                'venue' => $venue,
                'passkey' => $this->passkey,
                'instructions' => $template['instructions'],
                'includeAppDownload' => $template['show_app_download'],
                'appDownloadName' => $template['app_name'],
                'appDownloadUrl' => $template['app_download_url'],
                'appDownloadInstructions' => $template['app_instructions'],
                'systemName' => config('app.name', 'MasterExam'),
                'supportEmail' => $template['contact_email'] ?: $supportEmail,
                'logoDataUri' => $logoDataUri,
            ],
        );
    }

    private function placeholders(): array
    {
        $schedule = $this->registration->schedule;
        $start = $schedule?->start_time ? Carbon::parse($schedule->start_time)->format('g:i A') : '—';
        $end = $schedule?->end_time ? Carbon::parse($schedule->end_time)->format('g:i A') : '—';
        return [
            'student_name' => $this->registration->applicant?->name ?: 'Applicant',
            'examination_title' => $schedule?->title ?: '—',
            'examination_date' => optional($schedule?->exam_date)?->format('F j, Y') ?: '—',
            'start_time' => $start,
            'end_time' => $end,
            'duration' => $schedule?->start_time && $schedule?->end_time ? Carbon::parse($schedule->start_time)->diffInMinutes(Carbon::parse($schedule->end_time)).' minutes' : '—',
            'room_location' => $this->registration->assignedRoom?->room_name ?: $schedule?->venue ?: ($schedule?->rooms?->pluck('room_name')->filter()->implode(', ') ?: '—'),
            'program' => $this->registration->applicant?->course_applied ?: '—',
            'examination_passkey' => $this->passkey,
        ];
    }
}
