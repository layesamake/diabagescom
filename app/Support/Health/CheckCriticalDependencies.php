<?php

namespace App\Support\Health;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Exécuté par la route /up : toute exception rend le contrôle de santé en échec
 * (HTTP 500), ce qui bloque la bascule de trafic lors d'un déploiement.
 */
class CheckCriticalDependencies
{
    public function handle(DiagnosingHealth $event): void
    {
        DB::connection()->select('select 1');

        if (in_array('redis', [config('cache.default'), config('queue.default'), config('session.driver')], true)) {
            Redis::connection()->ping();
        }
    }
}
