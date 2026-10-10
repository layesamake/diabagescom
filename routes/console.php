<?php

use Illuminate\Support\Facades\Schedule;

// Cycle des abonnements : transitions essai → lecture seule → suspension, puis rappels.
Schedule::command('diaba:subscriptions:tick')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();
