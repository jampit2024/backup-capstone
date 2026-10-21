<?php

namespace App\Mail;

use App\Models\ExaminationRegistration;
use App\Models\EmailConfiguration;
use App\Services\EmailTemplateService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ExaminationResultMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public ExaminationRegistration $registration, public ?array $templateOverride = null)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: app(EmailTemplateService::class)->plainText(
                ($this->templateOverride ?? app(EmailTemplateService::class)->published('result'))['subject'],
                $this->placeholders()
            ),
        );
    }

    public function content(): Content
    {
        $applicant = $this->registration->applicant;
        $schedule = $this->registration->schedule;

        $logoPath = public_path('tcc_logo.png');
        $logoDataUri = file_exists($logoPath)
            ? 'data:image/png;base64,'.base64_encode(file_get_contents($logoPath))
            : null;

        $itemsTotal = $this->registration->items_total ?? $this->registration->manual_max_score;
        $itemsCorrect = $this->registration->items_correct ?? $this->registration->manual_score;
        $score = $this->registration->score ?? $itemsCorrect;

        $percentage = null;
        if ($this->registration->score !== null && is_numeric($this->registration->score)) {
            $percentage = round((float) $this->registration->score, 2);
        } elseif ($itemsCorrect !== null && $itemsTotal && $itemsTotal > 0) {
            $percentage = round(((float) $itemsCorrect / (float) $itemsTotal) * 100, 2);
        }

        $rawRemarks = $this->registration->result_status?->value ?? (string) $this->registration->result_status;
        $remarks = $rawRemarks ? ucfirst($rawRemarks) : 'Completed';

        $template = $this->templateOverride ?? app(EmailTemplateService::class)->published('result');
        return new Content(
            view: 'mail.examination-notification',
            text: 'mail.examination-notification-text',
            with: [
                'type' => 'result',
                'template' => $template,
                'values' => $this->placeholders(),
                'name' => $applicant?->name ?: 'Applicant',
                'applicantCode' => $applicant?->applicant_code,
                'program' => $applicant?->course_applied,
                'examTitle' => $schedule?->title ?: 'Entrance Examination',
                'examDate' => optional($schedule?->exam_date)?->format('F j, Y') ?: '—',
                'score' => $score,
                'itemsCorrect' => $itemsCorrect,
                'itemsTotal' => $itemsTotal,
                'percentage' => $percentage,
                'remarks' => $remarks,
                'systemName' => config('app.name', 'MasterExam'),
                'supportEmail' => $template['contact_email'] ?: (EmailConfiguration::query()->first()?->sender_email ?: config('mail.from.address')),
                'logoDataUri' => $logoDataUri,
            ],
        );
    }

    private function placeholders(): array
    {
        $applicant = $this->registration->applicant;
        $schedule = $this->registration->schedule;
        $correct = $this->registration->items_correct ?? $this->registration->manual_score;
        $total = $this->registration->items_total ?? $this->registration->manual_max_score;
        $percentage = $this->registration->score;
        if ($percentage === null && $correct !== null && $total > 0) {
            $percentage = round($correct / $total * 100, 2);
        }
        return [
            'student_name' => $applicant?->name ?: 'Applicant',
            'examination_title' => $schedule?->title ?: '—',
            'examination_date' => optional($schedule?->exam_date)?->format('F j, Y') ?: '—',
            'program' => $applicant?->course_applied ?: '—',
            'student_score' => $correct ?? '—',
            'total_score' => $total ?? '—',
            'percentage' => $percentage === null ? '—' : $percentage.'%',
            'remarks' => ucfirst((string) ($this->registration->result_status?->value ?? $this->registration->result_status ?? 'Completed')),
        ];
    }
}
