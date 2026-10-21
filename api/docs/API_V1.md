# METCC Mobile / Admin API v1

Base URL: `{APP_URL}/api/v1`  
Auth: `Authorization: Bearer {sanctum_token}` unless marked Public

## Proctor auth (mobile)
| Method | Path | Access |
|--------|------|--------|
| POST | `/proctor/login` | Public `{ email\|username, password }` → Sanctum token (proctor/admin) |
| POST | `/proctor/logout` | Sanctum |
| GET | `/proctor/me` | Sanctum |

Web `POST /api/auth/login` remains **admin-only**.

## Proctor exam session
| Method | Path | Access |
|--------|------|--------|
| GET | `/proctor/schedules` | Sanctum proctor |
| GET | `/proctor/question-banks` | Sanctum proctor |
| POST | `/proctor/sessions` | Sanctum `{ examination_schedule_id, question_bank_id? }` opens lobby + QR code |
| GET | `/proctor/sessions/{id}` | Sanctum lobby snapshot |
| GET | `/proctor/schedules/{schedule}/lobby` | Sanctum |
| POST | `/proctor/sessions/{id}/regenerate-code` | Sanctum |
| POST | `/proctor/sessions/{id}/start` | Sanctum |
| POST | `/proctor/sessions/{id}/end` | Sanctum |
| POST | `/proctor/registrations/{id}/terminate` | Sanctum |

## Public student exam flow
| Method | Path | Access |
|--------|------|--------|
| POST | `/exam/resolve` | Public `{ code\|qr }` |
| GET | `/exam/students?code=` | Public roster for name selection |
| POST | `/exam/join` | Public `{ code, applicant_id, gmail? }` → `participation_token` |
| GET | `/exam/lobby` | Public `code` or `participation_token` |
| GET | `/exam/questions?participation_token=` | Public (after start; no correct answers) |
| POST | `/exam/submit` | Public `{ participation_token, answers:[{exam_question_id,selected_answer}] }` |

Submitted results appear in admin **Examination Results** (`GET /api/exam-results`).

## Question Banks
| Method | Path | Access |
|--------|------|--------|
| GET | `/question-banks` | Sanctum |
| GET | `/question-banks/{id}` | Sanctum |
| POST | `/question-banks` | Admin |
| PUT/PATCH | `/question-banks/{id}` | Admin |
| DELETE | `/question-banks/{id}` | Admin |

## Questions
| Method | Path | Access |
|--------|------|--------|
| GET | `/questions` | Sanctum (`selected_only`, `question_bank_id`, `exam_subject_id`) |
| GET | `/questions/{id}` | Sanctum |
| POST | `/questions` | Admin |
| PUT/PATCH | `/questions/{id}` | Admin |
| DELETE | `/questions/{id}` | Admin |

## Examination Schedules
| Method | Path | Access |
|--------|------|--------|
| GET | `/examination-schedules` | Sanctum |
| GET | `/examination-schedules/{id}` | Sanctum |

## Applicants
| Method | Path | Access |
|--------|------|--------|
| POST | `/applicants` | Public (no email; name, address, contact_number, birth_date, gender, desired_program) |
| GET | `/applicants` | Sanctum |
| GET | `/applicants/{id}` | Sanctum |
| PATCH | `/applicants/{id}` | Admin |
| POST | `/applicants/{id}/gmail` | Sanctum (post-exam Gmail capture) |
| POST | `/applicants/import` | Admin (multipart `file`: `.csv`, `.xlsx`, `.xls`) |

## Results
| Method | Path | Access |
|--------|------|--------|
| GET | `/results` | Sanctum |
| GET | `/results/{id}` | Sanctum |
| POST | `/registrations/{registration}/submit` | Sanctum (submit answers → score) |
| POST | `/results/send-batch` | Admin `{ registration_ids: [] }` |
| POST | `/results/resend-failed` | Admin |
| GET | `/results/email-status` | Sanctum |
| GET | `/results/batch-progress/{batch}` | Sanctum |

## Legacy admin aliases (web dashboard)
Same features also available under `/api/applicants`, `/api/exam-results/*` for the React admin app.
