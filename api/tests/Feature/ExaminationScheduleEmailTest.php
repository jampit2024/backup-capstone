<?php

namespace Tests\Feature;

use App\Mail\ExaminationPasskeyMail;
use App\Mail\ExaminationResultMail;
use App\Models\Applicant;
use App\Models\EmailTemplateVersion;
use App\Models\ExaminationRegistration;
use App\Models\ExaminationSchedule;
use App\Models\Role;
use App\Models\User;
use App\Services\EmailTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExaminationScheduleEmailTest extends TestCase
{
    use RefreshDatabase;

    private function registration(): ExaminationRegistration
    {
        $schedule = ExaminationSchedule::query()->create([
            'title' => 'TCC Entrance Examination 2026', 'exam_date' => '2026-11-20',
            'start_time' => '09:00:00', 'end_time' => '11:00:00', 'status' => 'scheduled',
            'email_instructions' => 'Old schedule-specific wording must not be used.',
        ]);
        $applicant = Applicant::query()->create([
            'applicant_code' => 'TCC-2026-5555', 'name' => 'Sample Student',
            'course_applied' => 'BS Information Technology', 'gmail' => 'sample@example.com', 'email' => 'sample@example.com',
        ]);
        return ExaminationRegistration::query()->create([
            'examination_schedule_id' => $schedule->id, 'applicant_id' => $applicant->id,
            'attendance_status' => 'pending', 'result_status' => 'passed',
            'manual_score' => 42, 'manual_max_score' => 50, 'score' => 84,
        ]);
    }

    public function test_schedule_customization_endpoints_are_removed(): void
    {
        $role = Role::query()->firstOrCreate(['slug' => 'admin'], ['name' => 'Administrator']);
        $admin = User::factory()->create(['role_id' => $role->id]);
        $scheduleId = $this->registration()->schedule->id;
        $this->actingAs($admin)->patchJson("/api/examination-schedules/{$scheduleId}/email-content", [])->assertNotFound();
        $this->actingAs($admin)->getJson("/api/examination-schedules/{$scheduleId}/email-preview")->assertNotFound();
    }

    public function test_published_passkey_template_uses_real_registration_values_and_ignores_legacy_schedule_wording(): void
    {
        $registration = $this->registration();
        $content = app(EmailTemplateService::class)->defaults('passkey');
        $content['instructions'] = ['Bring your **valid ID**.'];
        EmailTemplateVersion::query()->create(['type' => 'passkey', 'status' => 'published', 'content' => $content, 'published_at' => now()]);
        $mail = new ExaminationPasskeyMail($registration->fresh(['applicant', 'schedule']), 'SAMPLE-KEY-123');
        $html = $mail->render();

        $this->assertTrue(str_contains($html, 'TCC GUIDANCE OFFICE'));
        $this->assertTrue(str_contains($html, 'TAGOLOAN COMMUNITY COLLEGE'));
        $this->assertTrue(str_contains($html, 'Sample Student'));
        $this->assertTrue(str_contains($html, 'SAMPLE-KEY-123'));
        $this->assertTrue(str_contains($html, 'November 20, 2026'));
        $this->assertTrue(str_contains($html, '<strong>valid ID</strong>'));
        $this->assertFalse(str_contains($html, 'Old schedule-specific wording'));
        $this->assertFalse(str_contains($html, 'Tanauan'));
        $plainText = view($mail->content()->text, $mail->content()->with)->render();
        $this->assertTrue(str_contains($plainText, 'SAMPLE-KEY-123'));
        $this->assertTrue(str_contains($plainText, 'Bring your valid ID.'));
    }

    public function test_result_email_uses_finalized_manual_score_and_published_result_wording(): void
    {
        $registration = $this->registration();
        $content = app(EmailTemplateService::class)->defaults('result');
        $content['introduction'] = 'Your result is {{percentage}} for {{examination_title}}.';
        EmailTemplateVersion::query()->create(['type' => 'result', 'status' => 'published', 'content' => $content, 'published_at' => now()]);
        $mail = new ExaminationResultMail($registration->fresh(['applicant', 'schedule']));
        $html = $mail->render();

        $this->assertTrue(str_contains($html, 'Your result is 84.00%'));
        $this->assertTrue(str_contains($html, 'Sample Student'));
        $this->assertTrue(str_contains($html, '42'));
        $this->assertTrue(str_contains($html, '50'));
        $this->assertTrue(str_contains($html, 'Passed'));
        $this->assertFalse(str_contains($html, 'Tanauan'));
    }
}
