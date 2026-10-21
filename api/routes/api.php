<?php

use App\Http\Controllers\Admin\CourseGradingSettingController;
use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EmailConfigurationController;
use App\Http\Controllers\Admin\ExaminationSettingController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\TestItemController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Applicant\Api\V1\ApplicantController as V1ApplicantController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Examination\Api\ExamSessionController;
use App\Http\Controllers\Examination\ExaminationPasskeyController;
use App\Http\Controllers\Examination\ExaminationRegistrationController;
use App\Http\Controllers\Examination\ExaminationRoomController;
use App\Http\Controllers\Examination\ExaminationScheduleController;
use App\Http\Controllers\PublicSite\LandingScheduleController;
use App\Http\Controllers\QuestionBank\QuestionBankController;
use App\Http\Controllers\Result\Api\V1\ResultController as V1ResultController;
use App\Http\Controllers\Result\ExaminationAnalyticsController;
use App\Http\Controllers\Result\ExaminationResultController;
use App\Http\Controllers\Result\ManualScoreController;
use App\Http\Controllers\Result\ResultsDashboardController;
use Illuminate\Support\Facades\Route;

Route::get('public/schedule-search', [LandingScheduleController::class, 'search'])
    ->middleware('throttle:30,1');

Route::prefix('sessions')->group(function () {
    Route::post('open', [ExamSessionController::class, 'open'])->middleware(\App\Http\Middleware\LockExaminationControl::class);
    Route::get('resolve', [ExamSessionController::class, 'resolve']);
    Route::post('{session}/start', [ExamSessionController::class, 'start'])->middleware(\App\Http\Middleware\LockExaminationControl::class);
    Route::post('{session}/close', [ExamSessionController::class, 'close'])->middleware(\App\Http\Middleware\LockExaminationControl::class);
});

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:forgot-password');
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
    Route::get('google/redirect', [GoogleAuthController::class, 'redirect']);
    Route::get('google/callback', [GoogleAuthController::class, 'callback']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::post('change-password', [AuthController::class, 'changePassword']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index']);

    Route::get('profile', [ProfileController::class, 'show']);
    Route::match(['put', 'patch'], 'profile/update', [ProfileController::class, 'update']);
    Route::post('profile/photo', [ProfileController::class, 'uploadPhoto']);
    Route::delete('profile/photo', [ProfileController::class, 'removePhoto']);

    Route::middleware('role:admin')->group(function () {
        Route::apiResource('rooms', \App\Http\Controllers\Examination\RoomCatalogController::class)->except('show');
        Route::get('examination-schedules/{examinationSchedule}/room-assignments', [\App\Http\Controllers\Examination\ScheduleRoomAssignmentController::class, 'show']);
        Route::put('examination-schedules/{examinationSchedule}/room-assignments', [\App\Http\Controllers\Examination\ScheduleRoomAssignmentController::class, 'update']);
        Route::patch('examination-schedules/{examinationSchedule}/room-assignments/students', [\App\Http\Controllers\Examination\ScheduleRoomAssignmentController::class, 'move']);
        Route::post('examination-schedules/{examinationSchedule}/room-assignments/publish', [\App\Http\Controllers\Examination\ScheduleRoomAssignmentController::class, 'publish']);
        Route::post('examination-rooms/{examinationRoom}/recover', [\App\Http\Controllers\Examination\ScheduleRoomAssignmentController::class, 'recover']);
        Route::get('email-configuration', [EmailConfigurationController::class, 'show']);
        Route::put('email-configuration', [EmailConfigurationController::class, 'save']);
        Route::post('email-configuration/test', [EmailConfigurationController::class, 'test'])->middleware('throttle:6,1');
        Route::delete('email-configuration', [EmailConfigurationController::class, 'disconnect']);
        Route::get('email-notifications', [EmailConfigurationController::class, 'history']);
        Route::post('email-notifications/{notification}/retry', [EmailConfigurationController::class, 'retry']);
        Route::get('email-templates', [\App\Http\Controllers\Admin\EmailTemplateController::class, 'index']);
        Route::put('email-templates/{type}/draft', [\App\Http\Controllers\Admin\EmailTemplateController::class, 'saveDraft']);
        Route::post('email-templates/{type}/publish', [\App\Http\Controllers\Admin\EmailTemplateController::class, 'publish']);
        Route::post('email-templates/{type}/restore', [\App\Http\Controllers\Admin\EmailTemplateController::class, 'restore']);
        Route::post('email-templates/{type}/preview', [\App\Http\Controllers\Admin\EmailTemplateController::class, 'preview']);
        Route::post('email-templates/{type}/test', [\App\Http\Controllers\Admin\EmailTemplateController::class, 'sendTest'])->middleware('throttle:6,1');
        Route::get('activity-logs/export', [ActivityLogController::class, 'export']);
        Route::get('activity-logs', [ActivityLogController::class, 'index']);
        Route::get('results/years', [ResultsDashboardController::class, 'years']);
        Route::get('results/search', [ResultsDashboardController::class, 'search']);
        Route::get('results/export', [ResultsDashboardController::class, 'export']);
        Route::post('results/send', [ResultsDashboardController::class, 'send']);
        Route::post('results/{applicantId}/send', [ResultsDashboardController::class, 'sendApplicant']);
        Route::get('results/{applicantId}', [ResultsDashboardController::class, 'show']);
        Route::get('results', [ResultsDashboardController::class, 'index']);

        Route::get('roles', [RoleController::class, 'index']);
        Route::patch('users/{user}/status', [UserController::class, 'updateStatus']);
        Route::apiResource('users', UserController::class);
        Route::get('examination-schedules/test-mode', [ExaminationPasskeyController::class, 'testMode']);
        Route::get('examination-schedules/test-students', [ExaminationPasskeyController::class, 'testStudents']);
        Route::post('examination-schedules/test-schedule', [ExaminationRegistrationController::class, 'createTestSchedule'])
            ->middleware('throttle:admin-passkeys');
        Route::get('examination-schedules', [ExaminationScheduleController::class, 'index']);
        Route::get('examination-schedules/{examinationSchedule}', [ExaminationScheduleController::class, 'show'])
            ->middleware('throttle:admin-passkeys');
        Route::post('examination-schedules/passkeys/generate-by-date', [ExaminationPasskeyController::class, 'generateByDate'])
            ->middleware('throttle:admin-passkeys');
        Route::post('examination-schedules/passkeys/send-by-date', [ExaminationPasskeyController::class, 'sendByDate'])
            ->middleware('throttle:admin-passkeys');
        Route::get('examination-schedules/passkeys/by-date', [ExaminationPasskeyController::class, 'indexByDate'])
            ->middleware('throttle:admin-passkeys');
        Route::post('examination-schedules/{examinationSchedule}/passkeys/generate', [ExaminationPasskeyController::class, 'generate'])
            ->middleware('throttle:admin-passkeys');
        Route::post('examination-schedules/{examinationSchedule}/passkeys/send', [ExaminationPasskeyController::class, 'send'])
            ->middleware('throttle:admin-passkeys');
        Route::get('examination-schedules/{examinationSchedule}/passkeys', [ExaminationPasskeyController::class, 'index'])
            ->middleware('throttle:admin-passkeys');
        Route::post('examination-schedules/{examinationSchedule}/passkeys/regenerate-batch', [ExaminationPasskeyController::class, 'regenerateBatch'])
            ->middleware('throttle:admin-passkeys');
        Route::post('examination-registrations/{registration}/passkeys/regenerate', [ExaminationPasskeyController::class, 'regenerate'])
            ->middleware('throttle:admin-passkeys');
        Route::post('examination-registrations/{registration}/passkeys/reset-for-testing', [ExaminationPasskeyController::class, 'resetForTesting'])
            ->middleware('throttle:admin-passkeys');
        Route::post('examination-registrations/{registration}/passkeys/revoke', [ExaminationPasskeyController::class, 'revoke'])
            ->middleware('throttle:admin-passkeys');
        Route::post('examination-registrations/{registration}/passkeys/resend', [ExaminationPasskeyController::class, 'resend'])
            ->middleware('throttle:admin-passkeys');
        Route::get('examination-schedules/{examinationSchedule}/rooms', [ExaminationRoomController::class, 'index']);
        Route::post('examination-schedules/{examinationSchedule}/rooms', [ExaminationRoomController::class, 'store']);
        Route::post('examination-schedules/{examinationSchedule}/rooms/seed-defaults', [ExaminationRoomController::class, 'seedDefaults']);
        Route::post('examination-rooms/seed-defaults-all', [ExaminationRoomController::class, 'seedDefaultsForAll']);
        Route::patch('examination-rooms/{examinationRoom}', [ExaminationRoomController::class, 'update']);
        Route::delete('examination-rooms/{examinationRoom}', [ExaminationRoomController::class, 'destroy']);
        Route::post('examination-registrations/{registration}/reschedule', [ExaminationRegistrationController::class, 'reschedule']);
        Route::get('exam-results', [ExaminationResultController::class, 'index']);
        Route::get('exam-results/lookup', [ExaminationResultController::class, 'lookup']);
        Route::get('exam-results/analytics', [ExaminationAnalyticsController::class, 'index']);
        Route::get('exam-results/export', [ExaminationResultController::class, 'export']);
        Route::get('exam-results/manual-entry/schedules', [ManualScoreController::class, 'schedules']);
        Route::get('exam-results/manual-entry/students', [ManualScoreController::class, 'students']);
        Route::post('exam-results/manual-entry', [ManualScoreController::class, 'store']);
        Route::get('exam-results/manual-entry/{registration}/history', [ManualScoreController::class, 'history']);
        Route::put('exam-results/manual-entry/{registration}', [ManualScoreController::class, 'update']);
        Route::get('exam-results/{registration}', [ExaminationResultController::class, 'show']);
        Route::post('exam-results/send-batch', [V1ResultController::class, 'sendBatch']);
        Route::post('exam-results/send-batch-email/{batch}', [V1ResultController::class, 'sendBatchEmail']);
        Route::post('exam-results/resend-failed', [V1ResultController::class, 'resendFailed']);
        Route::get('exam-results/email-status', [V1ResultController::class, 'emailStatus']);
        Route::get('exam-results/batch-progress/{batch}', [V1ResultController::class, 'batchProgress']);

        Route::get('course-grading-settings', [CourseGradingSettingController::class, 'index']);
        Route::post('course-grading-settings', [CourseGradingSettingController::class, 'store']);
        Route::patch('course-grading-settings/{courseGradingSetting}', [CourseGradingSettingController::class, 'update']);
        Route::delete('course-grading-settings/{courseGradingSetting}', [CourseGradingSettingController::class, 'destroy']);

        Route::get('examination-settings', [ExaminationSettingController::class, 'show']);
        Route::put('examination-settings', [ExaminationSettingController::class, 'update']);

        // Student List aliases (domain model remains Applicant)
        Route::get('students', [V1ApplicantController::class, 'index']);
        Route::post('students/import', [V1ApplicantController::class, 'import']);
        Route::get('students/{applicant}', [V1ApplicantController::class, 'show']);
        Route::post('students', [V1ApplicantController::class, 'store']);
        Route::patch('students/{applicant}', [V1ApplicantController::class, 'update']);

        Route::get('applicants', [V1ApplicantController::class, 'index']);
        Route::post('applicants/import', [V1ApplicantController::class, 'import']);
        Route::get('applicants/{applicant}', [V1ApplicantController::class, 'show']);
        Route::post('applicants', [V1ApplicantController::class, 'store']);
        Route::patch('applicants/{applicant}', [V1ApplicantController::class, 'update']);

        Route::get('question-banks', [QuestionBankController::class, 'indexBanks']);
        Route::post('question-banks', [QuestionBankController::class, 'storeBank']);
        Route::post('question-banks/{questionBank}/duplicate', [QuestionBankController::class, 'duplicateBank']);
        Route::get('exam-questions/review', [QuestionBankController::class, 'reviewQuestions']);
        Route::post('exam-questions/bulk', [QuestionBankController::class, 'bulkQuestions']);
        Route::get('question-banks/{questionBank}', [QuestionBankController::class, 'showBank']);
        Route::patch('question-banks/{questionBank}', [QuestionBankController::class, 'updateBank']);
        Route::post('question-banks/{questionBank}/activate', [QuestionBankController::class, 'activateBank']);
        Route::post('question-banks/{questionBank}/import-questions', [QuestionBankController::class, 'importQuestions']);
        Route::get('question-banks/{questionBank}/exam-preview', [QuestionBankController::class, 'examPreview']);
        Route::delete('question-banks/{questionBank}', [QuestionBankController::class, 'destroyBank']);
        Route::post('question-banks/{questionBank}/subjects', [QuestionBankController::class, 'storeSubject']);
        Route::get('subjects/{examSubject}', [QuestionBankController::class, 'showSubject']);
        Route::get('subjects/{examSubject}/questions', [QuestionBankController::class, 'subjectQuestions']);
        Route::get('subjects/{examSubject}/export', [QuestionBankController::class, 'exportSubjectQuestions']);
        Route::post('subjects/{examSubject}/fill-exam-slots', [QuestionBankController::class, 'fillExamSlots']);
        Route::patch('subjects/{examSubject}', [QuestionBankController::class, 'updateSubject']);
        Route::delete('subjects/{examSubject}', [QuestionBankController::class, 'destroySubject']);
        Route::post('subjects/{examSubject}/questions', [QuestionBankController::class, 'storeQuestion']);
        Route::patch('exam-questions/{examQuestion}', [QuestionBankController::class, 'updateQuestion']);
        Route::post('exam-questions/{examQuestion}/toggle-selection', [QuestionBankController::class, 'toggleQuestionSelection']);
        Route::delete('exam-questions/{examQuestion}', [QuestionBankController::class, 'destroyQuestion']);
    });

    Route::apiResource('test-items', TestItemController::class);
});
