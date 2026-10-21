<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicateRoom = DB::table('exam_rooms')
            ->select(['examination_schedule_id', 'room_name'])
            ->groupBy('examination_schedule_id', 'room_name')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicateRoom) {
            throw new \RuntimeException('Cannot enforce unique room names: duplicate room name found within a schedule.');
        }

        Schema::table('exam_registrations', function (Blueprint $table) {
            $table->index('examination_schedule_id', 'ix_exam_registrations_schedule');
            $table->dropUnique('exam_reg_unique');
            $table->unique(
                ['examination_schedule_id', 'applicant_id'],
                'uq_exam_registrations_schedule_applicant'
            );
            $table->dropIndex('examination_registrations_attendance_status_index');
            $table->index('attendance_status', 'ix_exam_registrations_attendance_status');
            $table->dropIndex('examination_registrations_result_status_index');
            $table->index('result_status', 'ix_exam_registrations_result_status');
            $table->dropIndex('examination_registrations_email_status_index');
            $table->index('email_status', 'ix_exam_registrations_email_status');
        });

        Schema::table('exam_answers', function (Blueprint $table) {
            $table->index('examination_registration_id', 'ix_exam_answers_registration');
            $table->dropUnique('exam_answer_unique');
            $table->unique(
                ['examination_registration_id', 'exam_question_id'],
                'uq_exam_answers_registration_question'
            );
        });

        Schema::table('exam_packs', function (Blueprint $table) {
            $table->dropUnique('question_banks_school_year_unique');
            $table->unique('school_year', 'uq_exam_packs_school_year');
        });

        Schema::table('exam_subjects', function (Blueprint $table) {
            $table->index('question_bank_id', 'ix_exam_subjects_pack');
            $table->dropUnique('exam_subjects_question_bank_id_code_unique');
            $table->unique(['question_bank_id', 'code'], 'uq_exam_subjects_pack_code');
        });

        Schema::table('exam_schedules', function (Blueprint $table) {
            $table->dropIndex('examination_schedules_exam_date_status_index');
            $table->index(['exam_date', 'status'], 'ix_exam_schedules_date_status');
        });

        Schema::table('exam_rooms', function (Blueprint $table) {
            $table->index('examination_schedule_id', 'ix_exam_rooms_schedule');
            $table->dropIndex('examination_rooms_examination_schedule_id_index');
            $table->unique(
                ['examination_schedule_id', 'room_name'],
                'uq_exam_rooms_schedule_name'
            );
        });

        Schema::table('exam_sessions', function (Blueprint $table) {
            $table->dropIndex('exam_sessions_schedule_room_status_index');
            $table->index(
                ['examination_schedule_id', 'examination_room_id', 'status'],
                'ix_exam_sessions_schedule_room_status'
            );
        });

        Schema::table('exam_session_join_records', function (Blueprint $table) {
            $table->dropUnique('exam_session_joins_exam_session_id_student_id_unique');
            $table->unique(
                ['exam_session_id', 'student_id'],
                'uq_exam_session_join_records_session_student'
            );
            $table->dropIndex('exam_session_joins_exam_session_id_joined_at_index');
            $table->index(
                ['exam_session_id', 'joined_at'],
                'ix_exam_session_join_records_session_joined'
            );
        });
    }

    public function down(): void
    {
        Schema::table('exam_session_join_records', function (Blueprint $table) {
            $table->dropUnique('uq_exam_session_join_records_session_student');
            $table->unique(['exam_session_id', 'student_id'], 'exam_session_joins_exam_session_id_student_id_unique');
            $table->dropIndex('ix_exam_session_join_records_session_joined');
            $table->index(['exam_session_id', 'joined_at'], 'exam_session_joins_exam_session_id_joined_at_index');
        });

        Schema::table('exam_sessions', function (Blueprint $table) {
            $table->dropIndex('ix_exam_sessions_schedule_room_status');
            $table->index(
                ['examination_schedule_id', 'examination_room_id', 'status'],
                'exam_sessions_schedule_room_status_index'
            );
        });

        Schema::table('exam_rooms', function (Blueprint $table) {
            $table->dropUnique('uq_exam_rooms_schedule_name');
            $table->index('examination_schedule_id', 'examination_rooms_examination_schedule_id_index');
            $table->dropIndex('ix_exam_rooms_schedule');
        });

        Schema::table('exam_schedules', function (Blueprint $table) {
            $table->dropIndex('ix_exam_schedules_date_status');
            $table->index(['exam_date', 'status'], 'examination_schedules_exam_date_status_index');
        });

        Schema::table('exam_subjects', function (Blueprint $table) {
            $table->dropUnique('uq_exam_subjects_pack_code');
            $table->unique(['question_bank_id', 'code'], 'exam_subjects_question_bank_id_code_unique');
            $table->dropIndex('ix_exam_subjects_pack');
        });

        Schema::table('exam_packs', function (Blueprint $table) {
            $table->dropUnique('uq_exam_packs_school_year');
            $table->unique('school_year', 'question_banks_school_year_unique');
        });

        Schema::table('exam_answers', function (Blueprint $table) {
            $table->dropUnique('uq_exam_answers_registration_question');
            $table->unique(['examination_registration_id', 'exam_question_id'], 'exam_answer_unique');
            $table->dropIndex('ix_exam_answers_registration');
        });

        Schema::table('exam_registrations', function (Blueprint $table) {
            $table->dropUnique('uq_exam_registrations_schedule_applicant');
            $table->unique(['examination_schedule_id', 'applicant_id'], 'exam_reg_unique');
            $table->dropIndex('ix_exam_registrations_attendance_status');
            $table->index('attendance_status', 'examination_registrations_attendance_status_index');
            $table->dropIndex('ix_exam_registrations_result_status');
            $table->index('result_status', 'examination_registrations_result_status_index');
            $table->dropIndex('ix_exam_registrations_email_status');
            $table->index('email_status', 'examination_registrations_email_status_index');
            $table->dropIndex('ix_exam_registrations_schedule');
        });
    }
};