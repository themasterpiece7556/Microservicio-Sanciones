<?php

return [
    'secret' => env('JWT_SECRET'),
    'ttl' => (int) env('JWT_TTL', 3600),
    'issuer' => env('JWT_ISSUER', 'sanciones'),
    'audience' => env('JWT_AUDIENCE', 'prestamo-soap'),
];
