<?php

return [
    'invitation_ttl_hours' => (int) env('LABORATORY_USER_INVITATION_TTL_HOURS', 72),
    'invitation_frontend_url' => env('LABORATORY_USER_INVITATION_URL', rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/').'/accept-laboratory-invitation'),
];
