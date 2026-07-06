<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Game Rate Limiting
    |--------------------------------------------------------------------------
    |
    | These settings control the "game" rate limiter which is applied to all
    | authenticated game routes (galaxy, fleet, espionage, phalanx, etc.).
    | The limiter protects expensive endpoints against bots and DoS abuse.
    |
    | game_per_minute: maximum number of requests per minute before a
    |                  429 Too Many Requests response is returned.
    |
    | game_by: how requests are counted. "user" limits per authenticated
    |          user id (falling back to IP for guests), "ip" limits
    |          strictly per IP address.
    |
    */

    'game_per_minute' => (int) env('THROTTLE_GAME_PER_MINUTE', 120),

    'game_by' => env('THROTTLE_GAME_BY', 'user'),
];
