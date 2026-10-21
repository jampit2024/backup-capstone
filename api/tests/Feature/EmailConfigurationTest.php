<?php

namespace Tests\Feature;

use App\Models\EmailConfiguration;
use App\Models\EmailNotification;
use App\Models\Role;
use App\Models\User;
use App\Services\ConfiguredMailer;
use App\Services\EmailFailureClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class EmailConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleSeeder::class);

        $adminRole = Role::where('slug', 'admin')->first();
        $this->admin = User::create([
            'role_id' => $adminRole->id,
            'name' => 'Admin User',
            'email' => 'admin@tcc.edu.ph',
            'password' => Hash::make('password123'),
            'status' => 'active',
        ]);
    }

    public function test_show_configuration_returns_null_when_not_configured(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/email-configuration');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', null);
    }

    public function test_save_gmail_configuration_strips_spaces_from_password_and_normalizes_host_and_port(): void
    {
        $response = $this->actingAs($this->admin)->putJson('/api/email-configuration', [
            'provider' => 'gmail',
            'sender_email' => 'examoffice@gmail.com',
            'sender_name' => 'TCC Exam Office',
            'host' => 'smtp.gmail.com',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'examoffice@gmail.com',
            'password' => 'abcd efgh ijkl mnop', // user copies with spaces from Google
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.provider', 'gmail')
            ->assertJsonPath('data.sender_email', 'examoffice@gmail.com')
            ->assertJsonPath('data.host', 'smtp.gmail.com')
            ->assertJsonPath('data.port', 587)
            ->assertJsonPath('data.encryption', 'tls')
            ->assertJsonPath('data.username', 'examoffice@gmail.com')
            ->assertJsonPath('data.has_password', true)
            ->assertJsonMissing(['password']);

        $config = EmailConfiguration::first();
        $this->assertNotNull($config);
        // Verify spaces were stripped in the decrypted database value
        $this->assertSame('abcdefghijklmnop', $config->password);
    }

    public function test_save_gmail_configuration_on_port_465_sets_ssl(): void
    {
        $response = $this->actingAs($this->admin)->putJson('/api/email-configuration', [
            'provider' => 'gmail',
            'sender_email' => 'examoffice@gmail.com',
            'sender_name' => 'TCC Exam Office',
            'host' => 'smtp.gmail.com',
            'port' => 465,
            'encryption' => 'tls',
            'username' => 'examoffice@gmail.com',
            'password' => 'wxyz abcd 1234 5678',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.port', 465)
            ->assertJsonPath('data.encryption', 'ssl');

        $config = EmailConfiguration::first();
        $this->assertSame('wxyzabcd12345678', $config->password);
    }

    public function test_show_configuration_never_exposes_saved_password(): void
    {
        EmailConfiguration::create([
            'provider' => 'gmail',
            'sender_email' => 'admin@gmail.com',
            'sender_name' => 'Admin',
            'host' => 'smtp.gmail.com',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'admin@gmail.com',
            'password' => 'secretapppass123',
            'is_connected' => true,
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/email-configuration');

        $response->assertOk()
            ->assertJsonPath('data.sender_email', 'admin@gmail.com')
            ->assertJsonPath('data.has_password', true)
            ->assertJsonMissing(['password']);
    }

    public function test_email_failure_classifier_handles_google_535_authentication_error(): void
    {
        $classifier = new EmailFailureClassifier();
        $exception = new TransportException('Failed to authenticate on SMTP server with username "admin@gmail.com" using the following authenticators: "LOGIN", "PLAIN", "XOAUTH2". Authenticator "LOGIN" returned "535-5.7.8 Username and Password not accepted."');

        $result = $classifier->classify($exception, 'gmail');

        $this->assertSame('authentication_failed', $result['code']);
        $this->assertSame('Authentication failed', $result['title']);
        $this->assertStringContainsString('Google rejected the sending account credentials', $result['message']);
        $this->assertStringContainsString('16-character Google App Password', $result['message']);
        $this->assertSame('https://support.google.com/accounts/answer/185833', $result['help_url']);
    }

    public function test_email_failure_classifier_handles_timeout_and_unavailability(): void
    {
        $classifier = new EmailFailureClassifier();

        $timeout = $classifier->classify('Connection timed out after 20 seconds');
        $this->assertSame('connection_timeout', $timeout['code']);

        $refused = $classifier->classify('Connection refused by host smtp.school.edu:587');
        $this->assertSame('provider_unavailable', $refused['code']);

        $decrypt = $classifier->classify('The MAC is invalid.');
        $this->assertSame('decryption_failed', $decrypt['code']);
    }

    public function test_test_email_records_failure_and_returns_clear_message_when_smtp_fails(): void
    {
        EmailConfiguration::create([
            'provider' => 'gmail',
            'sender_email' => 'admin@gmail.com',
            'sender_name' => 'Admin',
            'host' => 'smtp.gmail.com',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'admin@gmail.com',
            'password' => 'badpassword',
            'is_connected' => false,
        ]);

        // Mock ConfiguredMailer to simulate 535 rejection
        $this->app->bind(ConfiguredMailer::class, function () {
            return new class extends ConfiguredMailer {
                public function send(string $recipient, object $mailable, bool $allowUnverified = false): void
                {
                    throw new TransportException('Failed to authenticate on SMTP server with username "admin@gmail.com" using authenticators LOGIN. Authenticator LOGIN returned "535-5.7.8 Username and Password not accepted."');
                }
            };
        });

        $response = $this->actingAs($this->admin)->postJson('/api/email-configuration/test', [
            'recipient' => 'test@example.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'authentication_failed')
            ->assertJsonPath('help_url', 'https://support.google.com/accounts/answer/185833');

        $this->assertDatabaseHas('email_notifications', [
            'recipient_email' => 'test@example.com',
            'type' => 'test',
            'status' => 'failed',
        ]);

        $notification = EmailNotification::where('type', 'test')->first();
        $this->assertStringContainsString('Google rejected the sending account credentials', $notification->error_details);
    }

    public function test_test_email_records_success_when_mailer_accepts(): void
    {
        EmailConfiguration::create([
            'provider' => 'gmail',
            'sender_email' => 'admin@gmail.com',
            'sender_name' => 'Admin',
            'host' => 'smtp.gmail.com',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'admin@gmail.com',
            'password' => 'goodpassword',
            'is_connected' => false,
        ]);

        // Mock ConfiguredMailer to succeed
        $this->app->bind(ConfiguredMailer::class, function () {
            return new class extends ConfiguredMailer {
                public function send(string $recipient, object $mailable, bool $allowUnverified = false): void
                {
                    // Success
                }
            };
        });

        $response = $this->actingAs($this->admin)->postJson('/api/email-configuration/test', [
            'recipient' => 'recipient@example.com',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $config = EmailConfiguration::first();
        $this->assertTrue((bool) $config->is_connected);
        $this->assertNotNull($config->verified_at);
        $this->assertNotNull($config->last_success_at);

        $this->assertDatabaseHas('email_notifications', [
            'recipient_email' => 'recipient@example.com',
            'type' => 'test',
            'status' => 'sent',
        ]);
    }

    public function test_retry_failed_test_email_succeeds(): void
    {
        $config = EmailConfiguration::create([
            'provider' => 'gmail',
            'sender_email' => 'admin@gmail.com',
            'sender_name' => 'Admin',
            'host' => 'smtp.gmail.com',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'admin@gmail.com',
            'password' => 'goodpassword',
            'is_connected' => false,
        ]);

        $notification = EmailNotification::create([
            'requested_by' => $this->admin->id,
            'recipient_email' => 'retry@example.com',
            'type' => 'test',
            'status' => 'failed',
            'error_details' => 'Previous failure',
        ]);

        $this->app->bind(ConfiguredMailer::class, function () {
            return new class extends ConfiguredMailer {
                public function send(string $recipient, object $mailable, bool $allowUnverified = false): void
                {
                    // Retried send succeeds
                }
            };
        });

        $response = $this->actingAs($this->admin)->postJson("/api/email-notifications/{$notification->id}/retry");

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('email_notifications', [
            'id' => $notification->id,
            'status' => 'sent',
            'error_details' => null,
        ]);
    }

    public function test_configured_mailer_strips_spaces_even_if_database_has_them(): void
    {
        // Suppose DB had spaces saved by an old version
        $config = EmailConfiguration::create([
            'provider' => 'gmail',
            'sender_email' => 'admin@gmail.com',
            'sender_name' => 'Admin',
            'host' => 'smtp.gmail.com',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'admin@gmail.com',
            'password' => 'aaaa bbbb cccc dddd',
            'is_connected' => true,
        ]);

        $mailer = new ConfiguredMailer();
        $mailable = new \App\Mail\EmailConnectionTestMail('admin@gmail.com', 'MasterExam');

        // Mail fake to capture the send call
        Mail::fake();

        $mailer->send('student@example.com', $mailable);

        $mailConfig = config('mail.mailers.masterexam');
        $this->assertNotNull($mailConfig);
        $this->assertSame('aaaabbbbccccdddd', $mailConfig['password']);
        $this->assertSame('smtp', $mailConfig['scheme']);
        $this->assertSame(587, $mailConfig['port']);
        $this->assertTrue($mailConfig['auto_tls']);
    }
}

