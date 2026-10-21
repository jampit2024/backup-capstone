<?php

namespace App\Services;

use App\Models\EmailTemplateVersion;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class EmailTemplateService
{
    public const TYPES = ['passkey', 'result'];

    public function defaults(string $type): array
    {
        $shared = [
            'contact_email' => '',
            'footer' => 'This is an official automated examination notification from Tagoloan Community College Guidance Office. Please keep this email for your records.',
        ];

        if ($type === 'passkey') {
            return array_merge($shared, [
                'subject' => 'TCC Entrance Examination – Schedule and Passkey: {{examination_title}}',
                'greeting' => 'Hello, {{student_name}}!',
                'introduction' => 'You are scheduled for an examination through the TCC Guidance Office Examination Management System. Please see the details below.',
                'instructions' => [
                    'Arrive at least 15 to 30 minutes before your scheduled examination time.',
                    'Bring your examination passkey and valid photo identification.',
                    'Follow all room assignments and directives given by the assigned proctors.',
                    'Keep your examination passkey strictly confidential — it is valid only for your schedule.',
                    'Install the official MasterExam mobile application if taking the exam on a mobile device.',
                ],
                'additional_reminders' => '',
                'closing' => 'Regards,',
                'show_app_download' => false,
                'app_name' => 'MasterExam Mobile Application',
                'app_download_url' => '',
                'app_instructions' => 'Download and install the official examination application before your scheduled examination, if required.',
                'app_button_text' => 'Download Application',
            ]);
        }

        return array_merge($shared, [
            'subject' => 'TCC Examination Result: {{examination_title}}',
            'greeting' => 'Hello, {{student_name}}!',
            'introduction' => 'Your official examination result for {{examination_title}} is now available. Please review your assessment below.',
            'instructions' => [],
            'additional_reminders' => 'Keep this email for your admissions records.',
            'closing' => 'Regards,',
            'show_app_download' => false,
            'app_name' => '',
            'app_download_url' => '',
            'app_instructions' => '',
            'app_button_text' => '',
        ]);
    }

    public function published(string $type): array
    {
        $saved = EmailTemplateVersion::query()->where('type', $type)->where('status', 'published')->latest('id')->first();
        return array_replace($this->defaults($type), $saved?->content ?? []);
    }

    public function overview(string $type): array
    {
        $draft = EmailTemplateVersion::query()->where('type', $type)->where('status', 'draft')->latest('id')->first();
        $published = EmailTemplateVersion::query()->where('type', $type)->where('status', 'published')->latest('id')->first();

        return [
            'type' => $type,
            'draft' => $draft?->content,
            'draft_id' => $draft?->id,
            'published' => array_replace($this->defaults($type), $published?->content ?? []),
            'published_id' => $published?->id,
            'published_at' => $published?->published_at,
            'versions' => EmailTemplateVersion::query()->where('type', $type)->whereIn('status', ['published', 'archived'])->latest('id')->limit(10)->get(['id', 'status', 'created_at', 'published_at']),
        ];
    }

    public function validate(string $type, array $input): array
    {
        if (! in_array($type, self::TYPES, true)) {
            throw ValidationException::withMessages(['type' => 'Unsupported email template.']);
        }

        $data = Validator::make($input, [
            'subject' => ['required', 'string', 'max:200'],
            'greeting' => ['required', 'string', 'max:300'],
            'introduction' => ['required', 'string', 'max:3000'],
            'instructions' => ['present', 'array', 'max:20'],
            'instructions.*' => ['required', 'string', 'max:600'],
            'additional_reminders' => ['nullable', 'string', 'max:3000'],
            'closing' => ['required', 'string', 'max:1000'],
            'contact_email' => ['nullable', 'email:rfc', 'max:254'],
            'footer' => ['required', 'string', 'max:1000'],
            'show_app_download' => ['required', 'boolean'],
            'app_name' => ['nullable', 'string', 'max:200'],
            'app_download_url' => ['nullable', 'url:http,https', 'max:1000'],
            'app_instructions' => ['nullable', 'string', 'max:1000'],
            'app_button_text' => ['nullable', 'string', 'max:100'],
        ])->validate();

        if ($type === 'passkey' && ($data['show_app_download'] ?? false) && empty($data['app_download_url'])) {
            throw ValidationException::withMessages(['app_download_url' => 'Enter an HTTPS download URL before enabling this section.']);
        }
        if ($type === 'passkey' && ($data['show_app_download'] ?? false) && ! str_starts_with(strtolower($data['app_download_url']), 'https://')) {
            throw ValidationException::withMessages(['app_download_url' => 'The application download URL must use HTTPS.']);
        }
        if ($type === 'passkey' && ($data['show_app_download'] ?? false)) {
            foreach (['app_name', 'app_instructions', 'app_button_text'] as $field) {
                if (empty(trim((string) ($data[$field] ?? '')))) {
                    throw ValidationException::withMessages([$field => 'Complete all application download fields when this section is enabled.']);
                }
            }
        }

        $allowed = $type === 'passkey'
            ? ['student_name', 'examination_title', 'examination_date', 'start_time', 'end_time', 'duration', 'room_location', 'program', 'examination_passkey']
            : ['student_name', 'examination_title', 'examination_date', 'program', 'student_score', 'total_score', 'percentage', 'remarks'];

        foreach ($data as $field => $value) {
            foreach (is_array($value) ? $value : (is_string($value) ? [$value] : []) as $text) {
                if (! is_string($text)) continue;
                if (preg_match('/<[^>]*>|\{[%!]|\{!!|@(?:php|include|eval)/i', $text)) {
                    throw ValidationException::withMessages([$field => 'HTML and executable template code are not allowed. Use plain text with **bold** and supported placeholders.']);
                }
                preg_match_all('/\{\{\s*([^{}]+)\s*\}\}/', $text, $matches);
                foreach ($matches[1] as $placeholder) {
                    if (! in_array(trim($placeholder), $allowed, true)) {
                        throw ValidationException::withMessages([$field => 'Unsupported placeholder: '.trim($placeholder)]);
                    }
                }
                if (str_contains($text, '{{') && count($matches[0]) !== substr_count($text, '{{')) {
                    throw ValidationException::withMessages([$field => 'A placeholder is incomplete.']);
                }
                preg_match_all('/\[([^\]]+)\]\(([^)]+)\)/', $text, $links);
                foreach ($links[2] as $url) {
                    if (! filter_var($url, FILTER_VALIDATE_URL) || ! str_starts_with(strtolower($url), 'https://')) {
                        throw ValidationException::withMessages([$field => 'Instruction links must use a valid HTTPS URL.']);
                    }
                }
            }
        }

        if (preg_match('/[\r\n]/', $data['subject'])) {
            throw ValidationException::withMessages(['subject' => 'The email subject must be one line.']);
        }
        if (preg_match('/\{\{\s*(examination_passkey|student_score|total_score|percentage|remarks)\s*\}\}/', $data['subject'])) {
            throw ValidationException::withMessages(['subject' => 'Private examination values cannot appear in the email subject.']);
        }

        return array_replace($this->defaults($type), $data);
    }

    public function renderText(string $text, array $values): string
    {
        $escaped = e(preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', fn ($match) => (string) ($values[$match[1]] ?? '—'), $text));
        $escaped = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $escaped);
        $escaped = preg_replace_callback('/\[([^\]]+)\]\((https:\/\/[^)\s]+)\)/', function ($match) {
            $url = html_entity_decode($match[2], ENT_QUOTES);
            return filter_var($url, FILTER_VALIDATE_URL)
                ? '<a href="'.e($url).'" style="color:#501C30;text-decoration:underline;">'.$match[1].'</a>'
                : $match[0];
        }, $escaped);
        return nl2br($escaped, false);
    }

    public function plainText(string $text, array $values): string
    {
        return str_replace('**', '', preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', fn ($match) => (string) ($values[$match[1]] ?? '—'), $text));
    }
}
