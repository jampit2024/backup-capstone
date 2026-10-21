# Offline-first examination (no room PC)

Admin prepares data in the **cloud** (Chrome). Phones **download a cache** while
online, then run the exam **without internet**. When online again, **sync**
results to the admin database.

```text
[Admin Chrome + Cloud DB]
        │  download pack (school Wi‑Fi / any internet)
        ▼
[Student + Proctor phones · local cache]
        │  exam with mobile data OFF
        │
        │  sync when online again
        ▼
[Cloud DB · admin sees results]
```

## Flow

1. **Admin (online):** import schedules, student names, questions in the web dashboard.
2. **Phones (online once):** open app → **Offline pack** → **Download exam pack**.
3. **Exam (offline):**  
   - Proctor tells students the code `OFF-{scheduleId}` (example `OFF-12`).  
   - Students: Enter code → choose name → take exam (answers stored on the phone).  
   - Proctor can browse cached schedules offline after download.
4. **After exam (online):** Offline pack screen → **Sync results to Admin**.

## Env (mobile)

```env
EXPO_PUBLIC_API_URL=https://your-cloud/api/v1
EXPO_PUBLIC_CLOUD_API_URL=https://your-cloud/api/v1
EXPO_PUBLIC_SYNC_TOKEN=same-as-ADMIN_SYNC_TOKEN
```

## Env (cloud Laravel)

```env
ADMIN_SYNC_TOKEN=same-token-as-mobile
```

Endpoints:

- `GET /api/v1/sync/exam-day-pack` — download cache  
- `POST /api/v1/sync/offline-results` — upload queued scores  

## Notes

- No room PC required for pure pack mode (`OFF-*` codes).  
- Each phone that will take or grade offline must download the pack first.  
- Live multi-phone lobby monitoring (Taking / Disconnected / Done) needs a shared
  LAN exam API — see **Campus Wi‑Fi anti-cheat** below.  
- Turn **mobile data OFF** during the exam to reduce online cheating.

## Campus Wi‑Fi anti-cheat (no internet)

Use this when students take the exam on campus Wi‑Fi that has **no internet**,
and the proctor must see live **Taking / Disconnected / Done** status.

```text
[Local Laravel on campus Wi‑Fi · no internet uplink]
        ▲
        │  heartbeats + reconnect codes
[Student phones]     [Proctor phone]
  Wi‑Fi must stay ON     lobby shows status
```

1. Run Laravel on a laptop/PC on the **same campus Wi‑Fi**:  
   `php artisan serve --host=0.0.0.0 --port=8000`
2. Point phones at that host (`EXPO_PUBLIC_API_URL` or proctor **Find servers**).
3. Students keep **Wi‑Fi ON** and **mobile data OFF** during the exam.
4. If a student turns Wi‑Fi off, the exam locks. Proctor taps the student →
   **Allow reconnect** → tells them the 6-digit code → student enters it to continue.
5. Missed heartbeats (~15s) also show as **Disconnected** on the proctor lobby.

See also LAN Option B in [`LAN_EXAMINATION.md`](./LAN_EXAMINATION.md).
