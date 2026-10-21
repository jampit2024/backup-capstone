<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{ $type === 'passkey' ? 'Examination Schedule and Passkey' : 'Examination Result' }}</title>
  <style>
    @media screen and (max-width: 620px) {
      .mail-shell { width: 100% !important; }
      .mail-pad { padding: 18px !important; }
      .brand-name { font-size: 22px !important; }
      .detail-value { font-size: 16px !important; }
      .key-value { font-size: 30px !important; letter-spacing: 2px !important; }
      .key-symbol { display: none !important; }
      .header-note { display: none !important; }
    }
  </style>
</head>
@php
  $service = app(\App\Services\EmailTemplateService::class);
  $render = fn ($text) => $service->renderText((string) $text, $values);
  $logoSrc = $logoDataUri ?? null;
  if (isset($message) && is_object($message) && method_exists($message, 'embed') && file_exists(public_path('tcc_logo.png'))) {
      try { $logoSrc = $message->embed(public_path('tcc_logo.png')); } catch (\Throwable) { /* Browser preview uses the data URI. */ }
  }
  $rows = $type === 'passkey' ? [
      ['Examination', $values['examination_title']], ['Date', $values['examination_date']],
      ['Time', $values['start_time'].' – '.$values['end_time']], ['Duration', $values['duration']],
      ['Room / Location', $values['room_location']], ['Program', $values['program']],
  ] : [
      ['Examination', $values['examination_title']], ['Date', $values['examination_date']],
      ['Program', $values['program']], ['Student Score', $values['student_score']],
      ['Total Score', $values['total_score']], ['Percentage', $values['percentage']],
      ['Remarks', $values['remarks']],
  ];
@endphp
<body style="margin:0;padding:0;background:#F6EFE5;color:#071D38;font-family:Poppins,Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F6EFE5;"><tr><td align="center" style="padding:20px 8px;">
<table role="presentation" class="mail-shell" width="620" cellpadding="0" cellspacing="0" style="width:620px;max-width:100%;background:#fffaf5;border-collapse:separate;border-spacing:0;">
  <tr><td class="mail-pad" style="padding:24px 28px 18px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
      <td width="88" style="vertical-align:middle;">@if($logoSrc)<img src="{{ $logoSrc }}" width="76" height="76" alt="Tagoloan Community College seal" style="display:block;width:76px;height:76px;border-radius:50%;border:2px solid #CE9186;">@endif</td>
      <td style="vertical-align:middle;"><div class="brand-name" style="font-size:27px;line-height:1.08;font-weight:800;color:#501C30;letter-spacing:-.4px;">TCC GUIDANCE OFFICE</div><div style="font-size:15px;letter-spacing:2.2px;color:#071D38;">Examination Management System</div><div style="width:52px;height:3px;background:#CE9186;margin-top:10px;"></div></td>
      <td class="header-note" width="95" style="border-left:1px solid #CE9186;padding-left:14px;font-size:9px;letter-spacing:2px;line-height:1.5;color:#103E5A;">GUIDE<br>SUPPORT<br>EMPOWER<br>FOR A BRIGHTER TOMORROW</td>
    </tr></table>
  </td></tr>
  <tr><td class="mail-pad" style="padding:0 22px 8px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F7E4E1;border-radius:12px;"><tr><td style="padding:20px 22px;">
      <div style="font-size:26px;line-height:1.25;font-weight:800;color:#501C30;">{!! $render($template['greeting']) !!}</div>
      <div style="font-size:14px;line-height:1.65;margin-top:7px;color:#071D38;">{!! $render($template['introduction']) !!}</div>
    </td></tr></table>
  </td></tr>
  <tr><td class="mail-pad" style="padding:0 22px 9px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e9c9c8;border-radius:11px;overflow:hidden;">
      <tr><td colspan="2" style="background:#501C30;color:#fff;padding:12px 20px;font-size:17px;font-weight:800;letter-spacing:2px;">▦ &nbsp; EXAMINATION DETAILS</td></tr>
      @foreach($rows as [$label, $value])
      <tr><td width="34%" style="background:#F5E7E2;padding:8px 12px;border-bottom:2px solid #fff;font-size:13px;color:#071D38;">{{ $label }}</td><td class="detail-value" style="background:#FBF1EC;padding:8px 12px;border-bottom:2px solid #fff;font-size:18px;font-weight:800;color:#071D38;overflow-wrap:anywhere;">{{ $value }}</td></tr>
      @endforeach
    </table>
  </td></tr>
  @if($type === 'passkey')
  <tr><td class="mail-pad" style="padding:0 22px 9px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F7E4E1;border-radius:11px;"><tr>
      <td class="key-symbol" align="center" width="85" style="padding:16px 8px;font-size:43px;color:#501C30;">●</td>
      <td style="padding:15px 18px;"><div style="font-size:12px;font-weight:800;letter-spacing:2px;color:#501C30;">YOUR EXAMINATION PASSKEY</div><div class="key-value" style="margin-top:6px;padding:6px 10px;background:#fffaf5;border:1px solid #CE9186;border-radius:8px;font-size:35px;font-weight:800;letter-spacing:3px;color:#071D38;text-align:center;overflow-wrap:anywhere;">{{ $values['examination_passkey'] }}</div></td>
    </tr><tr><td colspan="2" style="padding:0 18px 13px;font-size:11px;line-height:1.5;color:#103E5A;text-align:center;">Keep your passkey confidential. It is valid only for your assigned examination schedule.</td></tr></table>
  </td></tr>
  @endif
  @if(count($template['instructions']))
  <tr><td class="mail-pad" style="padding:0 22px 9px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e9c9c8;border-radius:11px;overflow:hidden;">
      <tr><td colspan="2" style="background:#103E5A;color:#fff;padding:12px 20px;font-size:16px;font-weight:800;letter-spacing:2px;">▤ &nbsp; IMPORTANT INSTRUCTIONS</td></tr>
      @foreach($template['instructions'] as $index => $instruction)
      <tr><td width="40" align="center" style="padding:7px 5px;background:#FBF1EC;border-bottom:2px solid #fff;"><span style="display:inline-block;border-radius:50%;background:#EBC3BF;color:#401725;width:25px;line-height:25px;font-weight:800;">{{ $index + 1 }}</span></td><td style="padding:8px;background:#FBF1EC;border-bottom:2px solid #fff;font-size:13px;line-height:1.5;">{!! $render($instruction) !!}</td></tr>
      @endforeach
    </table>
  </td></tr>
  @endif
  @if($template['additional_reminders'])<tr><td class="mail-pad" style="padding:4px 28px 12px;font-size:13px;line-height:1.6;">{!! $render($template['additional_reminders']) !!}</td></tr>@endif
  @if($type === 'passkey' && $template['show_app_download'] && $template['app_download_url'])
  <tr><td class="mail-pad" style="padding:2px 22px 12px;"><table role="presentation" width="100%" style="background:#F7E4E1;border-radius:10px;"><tr><td style="padding:18px;text-align:center;"><div style="font-size:15px;font-weight:800;color:#501C30;">{{ $template['app_name'] }}</div><p style="font-size:13px;line-height:1.5;">{!! $render($template['app_instructions']) !!}</p><a href="{{ $template['app_download_url'] }}" style="display:inline-block;background:#501C30;color:#fff;padding:10px 22px;border-radius:6px;text-decoration:none;font-weight:700;font-size:13px;">{{ $template['app_button_text'] }}</a></td></tr></table></td></tr>
  @endif
  <tr><td class="mail-pad" style="padding:12px 28px 18px;font-size:13px;line-height:1.5;">{!! $render($template['closing']) !!}<br><strong style="font-size:15px;color:#071D38;">TCC Guidance Office</strong><br>MasterExam Examination Management System</td></tr>
  <tr><td class="mail-pad" style="padding:14px 28px;background:#F5EEE6;border-top:1px solid #e9c9c8;font-size:12px;line-height:1.5;"><strong>TAGOLOAN COMMUNITY COLLEGE</strong><br>@if($supportEmail)Need assistance? <a href="mailto:{{ $supportEmail }}" style="color:#501C30;font-weight:700;">{{ $supportEmail }}</a><br>@endif{!! $render($template['footer']) !!}</td></tr>
  <tr><td style="height:8px;background:#103E5A;font-size:1px;line-height:8px;">&nbsp;</td></tr>
</table></td></tr></table>
</body></html>
