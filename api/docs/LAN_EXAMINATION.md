# Option B — Hybrid LAN exam + Internet admin

> **Prefer no room PC?** Use offline phone cache instead:  
> [`docs/OFFLINE_FIRST.md`](./OFFLINE_FIRST.md) — download pack → exam offline → sync to cloud.

This document describes the **room PC / LAN** model.

```text
[Admin web + Central API]  ← internet →  prepare schedules, banks, applicants
                │
                │  (before exam) LAN PC pulls exam-day pack
                ▼
[Room PC · Laravel on Wi‑Fi]  ←── same Wi‑Fi ──►  Proctor phone
                ▲                                 Student phones (no internet)
                │
                │  (after exam) push results when PC/proctor has internet
                ▼
[Central API] → Admin sees results
```

| Actor | Device | Network during exam |
|-------|--------|---------------------|
| Admin | Browser | Internet (cloud API) |
| Proctor | Cellphone | Same Wi‑Fi as room PC (internet optional until Sync) |
| Student | Cellphone | Same Wi‑Fi only — no internet required |
| Room PC | Runs Laravel | Wi‑Fi host for phones; internet only for Pull/Push |

---

## Deploy roles (two Laravel env profiles)

### Central (cloud) host

```env
ADMIN_SYNC_TOKEN=shared-long-secret
# ADMIN_SYNC_URL not required on central for ingest/export
```

Serves admin web API +:

- `GET /api/v1/sync/exam-day-pack` (export for LAN)
- `POST /api/v1/sync/exam-results` (ingest from LAN)

### LAN (room PC) host

```env
ADMIN_SYNC_URL=https://metccapi.example.com/api/v1
ADMIN_SYNC_TOKEN=shared-long-secret
```

```bash
php artisan serve --host=0.0.0.0 --port=8000
```

Phones use: `http://<ROOM_PC_LAN_IP>:8000/api/v1`

---

## Exam-day checklist (Option B)

1. **Admin (online):** create schedules, rooms, applicants, question bank, proctors on central.
2. **Room PC (online briefly):** configure `ADMIN_SYNC_*`, start Laravel on `0.0.0.0`.
3. **Proctor phone:** set LAN server URL → login → **Pull exam data from Admin**.
4. **Disconnect internet** on room PC if desired; keep Wi‑Fi for phones.
5. **Students:** join via QR/code on same Wi‑Fi (mobile data off OK).
6. **Proctor:** run lobby → start → end on phone.
7. **After exam (online):** proctor opens ended lobby → **Sync results to Admin**.

---

## Step-by-step: run the entire system (local)

Run these from `Documents/GitHub` (or your clone root). Use three terminals.

### 1. Backend API (`metcc_backend`)

```bash
cd metcc_backend
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve --host=0.0.0.0 --port=8000
```

For local Option B self-test (pull/push against the same API):

```env
ADMIN_SYNC_URL=http://127.0.0.1:8000/api/v1
ADMIN_SYNC_TOKEN=<generate-a-random-secret>
```

Find your PC LAN IP (`ipconfig` → IPv4) for phones.

**Demo accounts after seed**

| Role    | Email                 | Password   |
|---------|-----------------------|------------|
| Admin   | `admin@example.com`   | `password` |
| Proctor | `proctor@example.com` | `password` |

### 2. Admin web dashboard (`metcc_frontend`)

Admin only. Proctors do not use the website.

```bash
cd metcc_frontend
npm install
```

Create `.env` if missing:

```env
VITE_APP_NAME=METCC
VITE_API_URL=http://localhost:8000/api
```

Then:

```bash
npm run dev
```

Open the URL Vite prints (usually `http://localhost:5173`).

### 3. Mobile app — proctor + student (`mobileapptcc_frontend`)

```bash
cd mobileapptcc_frontend
npm install
```

```env
# Default LAN exam server (Option B)
EXPO_PUBLIC_API_URL=http://<YOUR_LAN_IP>:8000/api/v1
# Optional label for docs / future dual-host builds
# EXPO_PUBLIC_CLOUD_API_URL=https://metccapi.example.com/api/v1
```

```bash
npx expo start --lan
```

On proctor login, confirm/set **LAN exam server URL**, then sign in.  
Use **Pull exam data from Admin** on the schedules screen when the PC can reach the central API.

---

## Typical local day (all three running)

| App        | Command | URL / notes |
|------------|---------|-------------|
| Backend    | `php artisan serve --host=0.0.0.0 --port=8000` | `http://<LAN_IP>:8000` |
| Admin web  | `npm run dev` in `metcc_frontend` | `http://localhost:5173` |
| Mobile     | `npx expo start --lan` | Expo Go; Metro must show `exp://<LAN_IP>:8081` |

---

## How to test if LAN already works (locally)

Use two phones (or one phone + Expo web) on the **same Wi‑Fi** as the PC.

### 1. Restart backend for LAN

```bash
cd metcc_backend
php artisan serve --host=0.0.0.0 --port=8000
```

### 2. Connectivity check from the phone browser

```text
http://<YOUR_LAN_IP>:8000
```

### 3. Point mobile at that IP

Set `EXPO_PUBLIC_API_URL` and/or proctor login **LAN exam server URL**, then restart Expo if you changed `.env`.

### 4–5. Proctor + student flows

Proctor login → Pull (optional) → Open Lobby → Student join → Start → Submit/End → Sync.

**Strong proof:** student phone with **mobile data off**.

### Viewing an ended exam later

Schedules → room → **View results & sync** → student list + Sync to Admin.

---

## APIs added for Option B

| Direction | Endpoint | Purpose |
|-----------|----------|---------|
| Cloud → LAN | `GET /api/v1/sync/exam-day-pack` | Export schedules, rooms, applicants, banks, proctors |
| LAN | `POST /api/v1/proctor/sync/pull` | Download pack into room DB (needs internet on PC) |
| LAN → Cloud | `POST /api/v1/proctor/sync/push` | Upload graded results |
| Cloud | `POST /api/v1/sync/exam-results` | Ingest results |

Shared secret: `ADMIN_SYNC_TOKEN` on both hosts.

---

## Checklist

- [ ] Central API deployed with `ADMIN_SYNC_TOKEN`
- [ ] Room PC Laravel with `ADMIN_SYNC_URL` + same token
- [ ] `php artisan serve --host=0.0.0.0 --port=8000` (or production LAN bind)
- [ ] Phones on same Wi‑Fi; API URL = room PC IP
- [ ] Pull before exam; Sync after exam
- [ ] Firewall allows TCP 8000 from phones
