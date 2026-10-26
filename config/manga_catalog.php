<?php

return [
    // Fail-closed: with no trusted territory, the public catalog is empty.
    // Development only: this fixed territory does NOT geolocate users.
    // Public multi-country launch requires server-side audience location
    // enforcement; never accept an arbitrary client-provided territory.
    'public_territory' => env('MANGA_PUBLIC_TERRITORY'),
];
