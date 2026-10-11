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

// Facturation SaaS : renouvellements et relances (une fois par jour).
Schedule::command('diaba:billing:remind')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->onOneServer();

// Sauvegardes d'entreprise : suppression des archives expirées (7 jours).
Schedule::command('diaba:backups:prune')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();
