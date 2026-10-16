<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Une session de caisse fermée ne reçoit plus de mouvement d'espèces ni de règlement.
 *
 * Le service verrouille déjà une session ouverte avant chaque écriture ; ces triggers sont
 * le filet de sécurité de la base contre un appel interne oublieux, un script ou un futur
 * chemin applicatif. Le contrôle prend un verrou partagé sur la session : il s'ordonne
 * avec une fermeture concurrente (FOR UPDATE) au lieu de la croiser.
 *
 * Seule exception : la restauration d'une sauvegarde, qui réinsère l'historique d'une
 * entreprise dont les sessions sont déjà fermées. Elle l'annonce pour sa transaction
 * uniquement (set_config('diaba.restore', 'on', true)).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION diaba_require_open_cash_session() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                session_id uuid;
                session_status text;
            BEGIN
                IF current_setting('diaba.restore', true) = 'on' THEN
                    RETURN NEW;
                END IF;

                IF TG_TABLE_NAME = 'cash_movements' THEN
                    session_id := NEW.session_id;
                ELSE
                    session_id := NEW.cash_session_id;
                END IF;

                IF session_id IS NULL THEN
                    RETURN NEW;
                END IF;

                SELECT status INTO session_status FROM cash_sessions WHERE id = session_id FOR SHARE;

                IF session_status IS DISTINCT FROM 'open' THEN
                    RAISE EXCEPTION 'closed cash session %: no movement or payment can be added', session_id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END $$;

            CREATE TRIGGER cash_movements_open_session
                BEFORE INSERT ON cash_movements
                FOR EACH ROW EXECUTE FUNCTION diaba_require_open_cash_session();

            CREATE TRIGGER payments_open_cash_session
                BEFORE INSERT ON payments
                FOR EACH ROW EXECUTE FUNCTION diaba_require_open_cash_session();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS payments_open_cash_session ON payments;
            DROP TRIGGER IF EXISTS cash_movements_open_session ON cash_movements;
            DROP FUNCTION IF EXISTS diaba_require_open_cash_session();
        SQL);
    }
};
