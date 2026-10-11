<?php

use Illuminate\Support\Facades\Schedule;

// Cycle des abonnements : transitions essai → lecture seule → suspension, puis rappels.
Schedule::command('diaba:subscriptions:tick')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Comptabilisation automatique des opérations validées (idempotente).
Schedule::command('diaba:accounting:post')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();
