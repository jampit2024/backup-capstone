@php($service = app(\App\Services\EmailTemplateService::class))
TAGOLOAN COMMUNITY COLLEGE
TCC GUIDANCE OFFICE
MasterExam Examination Management System

{{ $service->plainText($template['greeting'], $values) }}
{{ $service->plainText($template['introduction'], $values) }}

EXAMINATION DETAILS
Examination: {{ $values['examination_title'] }}
Date: {{ $values['examination_date'] }}
@if($type === 'passkey')
Time: {{ $values['start_time'] }} – {{ $values['end_time'] }}
Duration: {{ $values['duration'] }}
Room / Location: {{ $values['room_location'] }}
Program: {{ $values['program'] }}

YOUR EXAMINATION PASSKEY
{{ $values['examination_passkey'] }}
Keep your passkey confidential. It is valid only for your assigned schedule.
@else
Program: {{ $values['program'] }}
Student score: {{ $values['student_score'] }}
Total score: {{ $values['total_score'] }}
Percentage: {{ $values['percentage'] }}
Remarks: {{ $values['remarks'] }}
@endif

@if(count($template['instructions']))
IMPORTANT INSTRUCTIONS
@foreach($template['instructions'] as $index => $instruction)
{{ $index + 1 }}. {{ $service->plainText($instruction, $values) }}
@endforeach
@endif
@if($template['additional_reminders'])
{{ $service->plainText($template['additional_reminders'], $values) }}
@endif
@if($type === 'passkey' && $template['show_app_download'] && $template['app_download_url'])
{{ $template['app_name'] }}
{{ $service->plainText($template['app_instructions'], $values) }}
{{ $template['app_button_text'] }}: {{ $template['app_download_url'] }}
@endif

{{ $service->plainText($template['closing'], $values) }}
TCC Guidance Office
MasterExam Examination Management System
@if($supportEmail)Need assistance? {{ $supportEmail }}@endif
{{ $service->plainText($template['footer'], $values) }}
