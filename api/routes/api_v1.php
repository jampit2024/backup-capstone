<?php

use App\Http\Controllers\Applicant\Api\V1\ApplicantController;
use App\Http\Controllers\Applicant\Api\V1\PublicExamController;
use App\Http\Controllers\Examination\Api\V1\ExaminationScheduleController;
use App\Http\Controllers\Proctor\Api\V1\ExamSessionController;
use App\Http\Controllers\Proctor\Api\V1\ProctorAuthController;
use App\Http\Controllers\QuestionBank\Api\V1\QuestionBankController;
use App\Http\Controllers\QuestionBank\Api\V1\QuestionController;
use App\Http\Controllers\Result\Api\V1\ResultController;
use App\Http\Controllers\Synchronization\Api\V1\OfflineResultController;
use App\Http\Controllers\Synchronization\Api\V1\ResultSyncController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Unique session join flow
    Route::prefix('sessions')->group(function () {
        Route::post('open', [\App\Http\Controllers\Examination\Api\ExamSessionController::class, 'open'])->middleware(\App\Http\Middleware\LockExaminationControl::class);
        Route::get('resolve', [\App\Http\Controllers\Examination\Api\ExamSessionController::class, 'resolve']);
        Route::post('{session}/start', [\App\Http\Controllers\Examination\Api\ExamSessionController::class, 'start'])->middleware(\App\Http\Middleware\LockExaminationControl::class);
        Route::post('{session}/close', [\App\Http\Controllers\Examination\Api\ExamSessionController::class, 'close'])->middleware(\App\Http\Middleware\LockExaminationControl::class);
    });

    // Public applicant registration (no email)
    Route::post('applicants', [ApplicantController::class, 'store'])
        ->middleware('throttle:20,1');

    // Mobile proctor authentication
    Route::post('proctor/login', [ProctorAuthController::class, 'login'])
        ->middleware('throttle:login');

    // Public student exam flow (QR / code)
    Route::middleware('throttle:60,1')->prefix('exam')->group(function () {
        Route::post('resolve', [PublicExamController::class, 'resolve']);
        Route::get('students', [PublicExamController::class, 'students']);
        Route::post('claim', [PublicExamController::class, 'claim']);
        Route::post('release-claim', [PublicExamController::class, 'releaseClaim']);
        Route::post('join', [PublicExamController::class, 'join']);
        Route::post('passkey/validate', [PublicExamController::class, 'validatePasskey']);
        Route::post('join-passkey', [PublicExamController::class, 'joinPasskey']);
        Route::post('violation', [PublicExamController::class, 'reportViolation']);
        Route::get('lobby', [PublicExamController::class, 'lobbyStatus']);
        Route::get('questions', [PublicExamController::class, 'questions']);
        Route::post('save', [PublicExamController::class, 'saveAnswers']);
        Route::post('submit', [PublicExamController::class, 'submit']);
        Route::post('heartbeat', [PublicExamController::class, 'heartbeat']);
        Route::post('wifi-disconnect', [PublicExamController::class, 'wifiDisconnect']);
        Route::post('reconnect', [PublicExamController::class, 'reconnect']);
        Route::post('agree', [PublicExamController::class, 'agree']);
    });

    // Central admin cloud: ingest LAN results + export exam-day pack (Bearer ADMIN_SYNC_TOKEN).
    Route::post('sync/exam-results', [ResultSyncController::class, 'ingest'])
        ->middleware('throttle:30,1');
    Route::get('sync/exam-day-pack', [ResultSyncController::class, 'exportPack'])
        ->middleware('throttle:20,1');
    Route::get('sync/exam-day-pack-status', [ResultSyncController::class, 'packStatus'])
        ->middleware('throttle:60,1');
    Route::post('sync/offline-results', [OfflineResultController::class, 'ingest'])
        ->middleware('throttle:30,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('proctor/logout', [ProctorAuthController::class, 'logout']);
        Route::get('proctor/me', [ProctorAuthController::class, 'me']);
        Route::get('proctor/schedules', [ExamSessionController::class, 'schedules']);
        Route::get('proctor/schedules/{examinationSchedule}/rooms', [ExamSessionController::class, 'rooms']);
        Route::get('proctor/question-banks', [ExamSessionController::class, 'questionBanks']);
        Route::post('proctor/sessions', [ExamSessionController::class, 'open'])->middleware(\App\Http\Middleware\LockExaminationControl::class);
        Route::get('proctor/sessions/{examSession}', [ExamSessionController::class, 'show']);
        Route::get('proctor/schedules/{examinationSchedule}/lobby', [ExamSessionController::class, 'lobbyBySchedule']);
        Route::post('proctor/sessions/{examSession}/regenerate-code', [ExamSessionController::class, 'regenerate'])->middleware(\App\Http\Middleware\LockExaminationControl::class);
        Route::post('proctor/sessions/{examSession}/network', [ExamSessionController::class, 'updateNetwork'])->middleware(\App\Http\Middleware\LockExaminationControl::class);
        Route::post('proctor/sessions/{examSession}/start', [ExamSessionController::class, 'start'])->middleware(\App\Http\Middleware\LockExaminationControl::class);
        Route::post('proctor/sessions/{examSession}/close', [ExamSessionController::class, 'close'])->middleware(\App\Http\Middleware\LockExaminationControl::class);
        Route::post('proctor/schedules/{examinationSchedule}/close', [ExamSessionController::class, 'closeBySchedule'])->middleware(\App\Http\Middleware\LockExaminationControl::class);
        Route::post('proctor/sessions/{examSession}/end', [ExamSessionController::class, 'end'])->middleware(\App\Http\Middleware\LockExaminationControl::class);
        Route::post('proctor/registrations/{registration}/terminate', [ExamSessionController::class, 'terminateStudent'])->middleware(\App\Http\Middleware\LockExaminationControl::class);
        Route::post('proctor/registrations/{registration}/remove', [ExamSessionController::class, 'removeRegistration'])->middleware(\App\Http\Middleware\LockExaminationControl::class);
        Route::post('proctor/registrations/{registration}/allow-reconnect', [ExamSessionController::class, 'allowReconnect'])->middleware(\App\Http\Middleware\LockExaminationControl::class);
        Route::get('proctor/sync/pending', [ResultSyncController::class, 'pending']);
        Route::post('proctor/sync/push', [ResultSyncController::class, 'push']);
        Route::post('proctor/sync/pull', [ResultSyncController::class, 'pull']);
        Route::post('proctor/sync/offline-results', [OfflineResultController::class, 'ingest']);

        Route::apiResource('question-banks', QuestionBankController::class);
        Route::apiResource('questions', QuestionController::class)
            ->parameters(['questions' => 'examQuestion']);

        Route::get('examination-schedules', [ExaminationScheduleController::class, 'index']);
        Route::get('examination-schedules/{examinationSchedule}', [ExaminationScheduleController::class, 'show']);

        Route::get('applicants', [ApplicantController::class, 'index']);
        Route::post('applicants/import', [ApplicantController::class, 'import']);
        Route::get('applicants/{applicant}', [ApplicantController::class, 'show']);
        Route::patch('applicants/{applicant}', [ApplicantController::class, 'update']);
        Route::post('applicants/{applicant}/gmail', [ApplicantController::class, 'submitGmail']);

        Route::get('students', [ApplicantController::class, 'index']);
        Route::post('students/import', [ApplicantController::class, 'import']);
        Route::get('students/{applicant}', [ApplicantController::class, 'show']);
        Route::patch('students/{applicant}', [ApplicantController::class, 'update']);

        Route::get('results', [ResultController::class, 'index']);
        Route::get('results/email-status', [ResultController::class, 'emailStatus']);
        Route::get('results/batch-progress/{batch}', [ResultController::class, 'batchProgress']);
        Route::get('results/{result}', [ResultController::class, 'show']);
        Route::post('results/send-batch', [ResultController::class, 'sendBatch']);
        Route::post('results/send-batch-email/{batch}', [ResultController::class, 'sendBatchEmail']);
        Route::post('results/resend-failed', [ResultController::class, 'resendFailed']);
        Route::post('registrations/{registration}/submit', [ResultController::class, 'submitAnswers']);
    });
});
