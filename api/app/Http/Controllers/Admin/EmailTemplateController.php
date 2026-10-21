<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\ExaminationPasskeyMail;
use App\Mail\ExaminationResultMail;
use App\Models\ActivityLog;
use App\Models\Applicant;
use App\Models\EmailNotification;
use App\Models\EmailConfiguration;
use App\Models\EmailTemplateVersion;
use App\Models\ExaminationRegistration;
use App\Models\ExaminationSchedule;
use App\Services\ConfiguredMailer;
use App\Services\EmailTemplateService;
use App\Services\EmailFailureClassifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class EmailTemplateController extends Controller
{
    public function index(EmailTemplateService $templates): JsonResponse
    {
        return response()->json(['data' => array_map(fn ($type) => $templates->overview($type), EmailTemplateService::TYPES)]);
    }

    public function saveDraft(Request $request, string $type, EmailTemplateService $templates): JsonResponse
    {
        $content = $templates->validate($type, $request->all());
        $version = EmailTemplateVersion::query()->create([
            'type' => $type, 'status' => 'draft', 'content' => $content, 'created_by' => $request->user()->id,
        ]);
        EmailTemplateVersion::query()->where('type', $type)->where('status', 'draft')->whereKeyNot($version->id)->update(['status' => 'discarded']);
        $this->audit($request, 'email_template_draft_saved', $version);
        return response()->json(['data' => $templates->overview($type), 'message' => 'Draft saved. Published emails are unchanged.']);
    }

    public function publish(Request $request, string $type, EmailTemplateService $templates): JsonResponse
    {
        abort_unless(in_array($type, EmailTemplateService::TYPES, true), 404);
        $request->validate(['draft_id' => ['required', 'integer']]);
        $version = DB::transaction(function () use ($request, $type, $templates) {
            $draft = EmailTemplateVersion::query()->where('type', $type)->where('status', 'draft')->whereKey($request->integer('draft_id'))->lockForUpdate()->firstOrFail();
            $templates->validate($type, $draft->content);
            EmailTemplateVersion::query()->where('type', $type)->where('status', 'published')->update(['status' => 'archived']);
            $draft->update(['status' => 'published', 'published_at' => now()]);
            return $draft;
        });
        $this->audit($request, 'email_template_published', $version);
        return response()->json(['data' => $templates->overview($type), 'message' => 'Template published for future emails.']);
    }

    public function restore(Request $request, string $type, EmailTemplateService $templates): JsonResponse
    {
        abort_unless(in_array($type, EmailTemplateService::TYPES, true), 404);
        $request->validate(['version_id' => ['nullable', 'integer']]);
        $content = $request->integer('version_id')
            ? EmailTemplateVersion::query()->where('type', $type)->whereIn('status', ['published', 'archived'])->findOrFail($request->integer('version_id'))->content
            : $templates->defaults($type);
        $version = EmailTemplateVersion::query()->create(['type' => $type, 'status' => 'draft', 'content' => $content, 'created_by' => $request->user()->id]);
        EmailTemplateVersion::query()->where('type', $type)->where('status', 'draft')->whereKeyNot($version->id)->update(['status' => 'discarded']);
        $this->audit($request, 'email_template_draft_restored', $version);
        return response()->json(['data' => $templates->overview($type), 'message' => 'A restored draft is ready for review. Publish it to use it for future emails.']);
    }

    public function preview(Request $request, string $type, EmailTemplateService $templates): JsonResponse
    {
        $content = $templates->validate($type, $request->all());
        $mail = $this->sampleMail($type, $content);
        return response()->json(['data' => ['subject' => $mail->envelope()->subject, 'html' => $mail->render(), 'sample' => true]]);
    }

    public function sendTest(Request $request, string $type, EmailTemplateService $templates, ConfiguredMailer $mailer): JsonResponse
    {
        $request->validate(['recipient' => ['required', 'email:rfc', 'max:254']]);
        $content = $templates->validate($type, $request->input('content', []));
        $notification = EmailNotification::query()->create([
            'requested_by' => $request->user()->id,
            'recipient_email' => $request->input('recipient'),
            'recipient_name' => 'Template test recipient',
            'type' => 'template_test', 'status' => 'pending',
        ]);
        try {
            $mailer->send($request->input('recipient'), $this->sampleMail($type, $content));
            $notification->update(['status' => 'sent', 'sent_at' => now()]);
            return response()->json(['message' => 'The configured provider accepted the template test with clearly labeled sample examination data. Check the recipient inbox.']);
        } catch (Throwable $e) {
            $failure = app(EmailFailureClassifier::class)->classify($e, EmailConfiguration::query()->first()?->provider);
            $notification->update(['status' => 'failed', 'error_details' => $failure['message']]);
            return response()->json(['message' => $failure['title'].': '.$failure['message']], 422);
        }
    }

    private function sampleMail(string $type, array $content): ExaminationPasskeyMail|ExaminationResultMail
    {
        $schedule = new ExaminationSchedule([
            'title' => 'SAMPLE Entrance Examination', 'exam_date' => '2026-11-12',
            'start_time' => '08:00:00', 'end_time' => '09:00:00', 'venue' => 'SAMPLE Room 01',
        ]);
        $applicant = new Applicant(['name' => 'Sample Student', 'course_applied' => 'SAMPLE BSIT']);
        $registration = new ExaminationRegistration([
            'items_correct' => 42, 'items_total' => 50, 'score' => 84, 'result_status' => 'passed',
        ]);
        $registration->setRelation('schedule', $schedule);
        $registration->setRelation('applicant', $applicant);
        return $type === 'passkey'
            ? new ExaminationPasskeyMail($registration, 'SAMPLE-KEY', $content)
            : new ExaminationResultMail($registration, $content);
    }

    private function audit(Request $request, string $action, EmailTemplateVersion $version): void
    {
        ActivityLog::query()->create([
            'user_id' => $request->user()->id, 'action' => $action,
            'description' => "Administrator changed {$version->type} email template version {$version->id}.",
            'subject_type' => EmailTemplateVersion::class, 'subject_id' => $version->id,
        ]);
    }
}
