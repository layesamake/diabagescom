<?php

// FICHIER DE DIAGNOSTIC TEMPORAIRE
// À ajouter temporairement pour déboguer l'erreur 500 en production

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DiagnosticController extends Controller
{
    /**
     * Endpoint de diagnostic pour l'erreur 500 extend_trial
     * URL: GET /diagnostic/trial-extend
     */
    public function trialExtendDiagnostic(Request $request)
    {
        return response()->json([
            'timestamp' => now(),
            'environment' => app()->environment(),
            'config' => [
                'subscriptions_trial_days' => config('diaba.subscriptions.trial_days'),
                'cache_driver' => config('cache.default'),
                'queue_driver' => config('queue.default'),
            ],
            'database' => [
                'connection' => DB::connection()->getName(),
                'status' => $this->checkDatabase(),
            ],
            'services' => [
                'audit_log_enabled' => true,
                'cache_accessible' => $this->checkCache(),
            ],
            'validation_rules' => [
                'days_field' => "['required_if:action,extend_trial', 'integer', 'min:1', 'max:60']",
                'note' => 'No nullable here - fix is applied',
            ],
        ]);
    }

    /**
     * Endpoint de test : simule la soumission du formulaire
     * POST /diagnostic/trial-extend-test
     */
    public function testTrialExtend(Request $request)
    {
        $data = $request->validate([
            'action' => 'required|in:extend_trial',
            'reason' => 'required|string|min:5|max:500',
            'days' => 'required_if:action,extend_trial|integer|min:1|max:60',
            'subscription_id' => 'required|uuid',
        ]);

        return response()->json([
            'status' => 'validation_passed',
            'data' => $data,
            'message' => 'Validation successful - no errors here',
        ]);
    }

    /**
     * Endpoint pour lire les erreurs les plus récentes des logs
     * GET /diagnostic/recent-errors?limit=20
     */
    public function recentErrors(Request $request)
    {
        $limit = (int) $request->get('limit', 10);
        $logFile = storage_path('logs/laravel.log');

        if (!file_exists($logFile)) {
            return response()->json(['error' => 'Log file not found'], 404);
        }

        $lines = array_reverse(file($logFile, FILE_SKIP_EMPTY_LINES));
        $errors = [];
        $count = 0;

        foreach ($lines as $line) {
            if ($count >= $limit) break;
            
            // Cherche les lignes avec ERROR ou Exception
            if (strpos($line, 'ERROR') !== false || strpos($line, 'Exception') !== false) {
                $errors[] = trim($line);
                $count++;
            }
        }

        return response()->json([
            'count' => count($errors),
            'errors' => $errors,
        ]);
    }

    /**
     * Endpoint pour chercher les erreurs "extend_trial" spécifiquement
     * GET /diagnostic/extend-trial-errors
     */
    public function extendTrialErrors()
    {
        $logFile = storage_path('logs/laravel.log');

        if (!file_exists($logFile)) {
            return response()->json(['error' => 'Log file not found'], 404);
        }

        $lines = file($logFile, FILE_SKIP_EMPTY_LINES);
        $relevant = [];

        foreach ($lines as $line) {
            if (strpos($line, 'extend_trial') !== false || 
                strpos($line, 'extendTrial') !== false ||
                strpos($line, 'tenants/') !== false && strpos($line, 'subscriptions') !== false) {
                $relevant[] = trim($line);
            }
        }

        return response()->json([
            'count' => count($relevant),
            'lines' => array_slice($relevant, -50), // Dernières 50 lignes
        ]);
    }

    private function checkDatabase()
    {
        try {
            DB::connection()->getPdo();
            return 'OK';
        } catch (\Exception $e) {
            return 'FAILED: ' . $e->getMessage();
        }
    }

    private function checkCache()
    {
        try {
            cache()->put('test', 'ok', 60);
            $val = cache()->get('test');
            cache()->forget('test');
            return $val === 'ok' ? 'OK' : 'FAILED';
        } catch (\Exception $e) {
            return 'FAILED: ' . $e->getMessage();
        }
    }
}
