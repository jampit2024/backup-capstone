<?php

namespace Tests\Feature;

use App\Mail\ExaminationPasskeyMail;
use App\Models\EmailTemplateVersion;
use App\Models\Role;
use App\Models\User;
use App\Services\EmailTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailTemplateTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $slug): User
    {
        $role = Role::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_admin_can_save_preview_and_publish_without_draft_leaking_to_outgoing_mail(): void
    {
        $admin = $this->user('admin');
        $content = app(EmailTemplateService::class)->defaults('passkey');
        $content['greeting'] = 'Welcome, {{student_name}}!';
        $content['instructions'] = ['Read **all** directions.', 'Visit [TCC](https://tcc.edu.ph).'];
        $content['show_app_download'] = true;
        $content['app_download_url'] = 'https://tcc.edu.ph/app';

        $draft = $this->actingAs($admin)->putJson('/api/email-templates/passkey/draft', $content);
        $draft->assertOk()->assertJsonPath('data.draft.greeting', $content['greeting']);
        $draftId = $draft->json('data.draft_id');
        $this->assertSame('Hello, {{student_name}}!', app(EmailTemplateService::class)->published('passkey')['greeting']);

        $preview = $this->actingAs($admin)->postJson('/api/email-templates/passkey/preview', $content);
        $preview->assertOk()->assertJsonPath('data.sample', true);
        $this->assertStringContainsString('Welcome, Sample Student!', $preview->json('data.html'));
        $this->assertStringContainsString('https://tcc.edu.ph/app', $preview->json('data.html'));
        $this->assertStringContainsString('<strong>all</strong>', $preview->json('data.html'));

        $this->actingAs($admin)->postJson('/api/email-templates/passkey/publish', ['draft_id' => $draftId])->assertOk();
        $this->assertSame($content['greeting'], app(EmailTemplateService::class)->published('passkey')['greeting']);
        $this->assertDatabaseHas('email_template_versions', ['id' => $draftId, 'status' => 'published']);
    }

    public function test_result_template_can_be_published_and_restored_as_draft(): void
    {
        $admin = $this->user('admin');
        $content = app(EmailTemplateService::class)->defaults('result');
        $content['subject'] = 'Result for {{student_name}}: {{examination_title}}';
        $draftId = $this->actingAs($admin)->putJson('/api/email-templates/result/draft', $content)->json('data.draft_id');
        $this->actingAs($admin)->postJson('/api/email-templates/result/publish', ['draft_id' => $draftId])->assertOk();
        $this->assertSame($content['subject'], app(EmailTemplateService::class)->published('result')['subject']);
        $this->actingAs($admin)->postJson('/api/email-templates/result/restore', [])->assertOk()->assertJsonPath('data.draft.subject', app(EmailTemplateService::class)->defaults('result')['subject']);
        $this->assertSame($content['subject'], app(EmailTemplateService::class)->published('result')['subject']);
    }

    public function test_rejects_executable_content_unknown_placeholders_and_unsafe_download_urls(): void
    {
        $admin = $this->user('admin');
        $content = app(EmailTemplateService::class)->defaults('passkey');
        $content['introduction'] = '<script>alert(1)</script>';
        $this->actingAs($admin)->putJson('/api/email-templates/passkey/draft', $content)->assertUnprocessable();
        $content['introduction'] = '{{another_student_secret}}';
        $this->actingAs($admin)->putJson('/api/email-templates/passkey/draft', $content)->assertUnprocessable();
        $content['introduction'] = 'Please review the details.';
        $content['show_app_download'] = true;
        $content['app_download_url'] = 'http://example.com/app';
        $this->actingAs($admin)->putJson('/api/email-templates/passkey/draft', $content)->assertUnprocessable();
        $this->assertSame(0, EmailTemplateVersion::query()->count());
    }

    public function test_non_admin_cannot_access_template_management(): void
    {
        $student = $this->user('student');
        $this->actingAs($student)->getJson('/api/email-templates')->assertForbidden();
        $this->actingAs($student)->putJson('/api/email-templates/passkey/draft', app(EmailTemplateService::class)->defaults('passkey'))->assertForbidden();
        $this->actingAs($student)->postJson('/api/email-templates/passkey/preview', app(EmailTemplateService::class)->defaults('passkey'))->assertForbidden();
    }

    public function test_template_test_email_uses_existing_mailer_and_records_history(): void
    {
        Mail::fake();
        $admin = $this->user('admin');
        $content = app(EmailTemplateService::class)->defaults('result');
        $this->actingAs($admin)->postJson('/api/email-templates/result/test', [
            'recipient' => 'admin@example.com', 'content' => $content,
        ])->assertOk();
        Mail::assertSent(\App\Mail\ExaminationResultMail::class, 1);
        $this->assertDatabaseHas('email_notifications', [
            'recipient_email' => 'admin@example.com', 'type' => 'template_test', 'status' => 'sent',
        ]);
    }
}
