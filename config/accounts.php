<?php

declare(strict_types=1);

/*
 * The accounts the application has, created by the database seeder: nobody can register.
 *
 * A password left empty means "password" locally; in production the seeder refuses to run
 * without one, so that an account with a well-known password is never created by accident.
 */
return [

    'demo' => [
        'email' => env('DEMO_USER_EMAIL', 'demo@example.com'),
        'password' => env('DEMO_USER_PASSWORD'),
        // Credit given once, in dollars, when the demo account is first seeded.
        'credit' => env('DEMO_USER_CREDIT', '5.00'),
    ],

    'admin' => [
        'email' => env('ADMIN_EMAIL', 'admin@example.com'),
        'password' => env('ADMIN_PASSWORD'),
    ],

];
