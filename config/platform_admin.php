<?php

return [
    // Fail closed: BOTH values must identify the ONE administrator account.
    // Store these in platform/.env, not in source control.
    'user_id' => env('PLATFORM_ADMIN_USER_ID'),
    'email' => env('PLATFORM_ADMIN_EMAIL'),
];
