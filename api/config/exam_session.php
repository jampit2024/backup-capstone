<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Exam Session Reopen Behavior
    |--------------------------------------------------------------------------
    |
    | When a proctor attempts to open a session while another is already open:
    | - 'auto_close': Close the old session and mint a brand-new one (default).
    | - 'return_existing': Return the existing open session without creating a duplicate.
    |
    */
    'reopen_behavior' => env('EXAM_SESSION_REOPEN_BEHAVIOR', 'auto_close'),

    /*
    |--------------------------------------------------------------------------
    | Session TTL
    |--------------------------------------------------------------------------
    |
    | Number of hours after which an open session expires if not started or closed.
    |
    */
    'ttl_hours' => (int) env('EXAM_SESSION_TTL_HOURS', 2),

    /*
    |--------------------------------------------------------------------------
    | LAN Handshake Passkey TTL
    |--------------------------------------------------------------------------
    |
    | Number of minutes a one-time LAN handshake token remains valid.
    |
    */
    'handshake_ttl_minutes' => (int) env('EXAM_SESSION_HANDSHAKE_TTL', 10),

    /*
    |--------------------------------------------------------------------------
    | Pending Agreement Reservation TTL
    |--------------------------------------------------------------------------
    |
    | Number of minutes an unagreed passkey reservation remains bound to a
    | device before the key can be used to create a fresh reservation.
    |
    */
    'pending_agreement_timeout_minutes' => (int) env('EXAM_SESSION_PENDING_AGREEMENT_TTL', 15),
];
