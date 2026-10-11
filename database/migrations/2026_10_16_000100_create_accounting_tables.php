<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EPIC-07 — comptabilité en partie double (SYSCOHADA).
 *
 * Invariants garantis en base :
 * - une écriture validée est équilibrée (contrainte différée : vérifiée à la fin
 *   de la transaction), comporte au moins deux lignes et ne change plus ;
 * - aucune écriture n'est validée dans une période verrouillée ou clôturée ;
 * - exercices sans chevauchement ; une opération source n'est comptabilisée
 *   qu'une fois (accounting_postings) ;
 * - montants XOF en bigint, chaque ligne au débit OU au crédit.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        Schema::create('accounting_settings', function (Blueprint $table) {
            $table->uuid('tenant_id')->primary();
            $table->date('posting_starts_on');
            $table->string('expert_name', 120)->nullable();
            $table->timestampTz('expert_validated_at')->nullable();
            $table->foreignUuid('expert_validated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('initialized_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
        });

        Schema::create('chart_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('code', 20);
            $table->string('name', 160);
            $table->unsignedSmallInteger('class');
            $table->string('party_type', 16)->nullable();
            $table->boolean('reconcilable')->default(false);
            $table->boolean('active')->default(true);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'code']);
        });
        DB::statement('ALTER TABLE chart_accounts ADD CONSTRAINT chart_accounts_class CHECK (class BETWEEN 1 AND 9)');
        DB::statement("ALTER TABLE chart_accounts ADD CONSTRAINT chart_accounts_code CHECK (code ~ '^[1-9][0-9A-Z]{1,19}$' AND substr(code, 1, 1)::int = class)");
        DB::statement("ALTER TABLE chart_accounts ADD CONSTRAINT chart_accounts_party CHECK (party_type IS NULL OR party_type IN ('customer', 'supplier'))");

        Schema::create('accounting_journals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('code', 8);
            $table->string('name', 80);
            $table->string('type', 16);
            $table->boolean('active')->default(true);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'code']);
        });
        DB::statement("ALTER TABLE accounting_journals ADD CONSTRAINT accounting_journals_type CHECK (type IN ('sales', 'purchases', 'cash', 'bank', 'inventory', 'misc', 'opening'))");

        Schema::create('account_mappings', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->string('key', 40);
            $table->uuid('account_id');
            $table->timestampsTz();

            $table->primary(['tenant_id', 'key']);
            $table->foreign(['tenant_id', 'account_id'])->references(['tenant_id', 'id'])->on('chart_accounts')->restrictOnDelete();
        });

        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name', 40);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 16)->default('open');
            $table->foreignUuid('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
        });
        DB::statement("ALTER TABLE fiscal_years ADD CONSTRAINT fiscal_years_status CHECK (status IN ('open', 'closed'))");
        DB::statement('ALTER TABLE fiscal_years ADD CONSTRAINT fiscal_years_dates CHECK (ends_on > starts_on)');
        DB::statement("ALTER TABLE fiscal_years ADD CONSTRAINT fiscal_years_no_overlap EXCLUDE USING gist (tenant_id WITH =, daterange(starts_on, ends_on, '[]') WITH &&)");

        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('fiscal_year_id');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 16)->default('open');
            $table->foreignUuid('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'fiscal_year_id'])->references(['tenant_id', 'id'])->on('fiscal_years')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['fiscal_year_id', 'starts_on']);
        });
        DB::statement("ALTER TABLE accounting_periods ADD CONSTRAINT accounting_periods_status CHECK (status IN ('open', 'locked', 'closed'))");
        DB::statement("ALTER TABLE accounting_periods ADD CONSTRAINT accounting_periods_no_overlap EXCLUDE USING gist (tenant_id WITH =, daterange(starts_on, ends_on, '[]') WITH &&)");

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('journal_id');
            $table->uuid('period_id');
            $table->string('number', 32)->nullable();
            $table->date('entry_date');
            $table->string('status', 16)->default('draft');
            $table->string('description', 255);
            $table->string('source_type', 64)->nullable();
            $table->uuid('source_id')->nullable();
            $table->string('source_kind', 40)->nullable();
            $table->uuid('reversal_of_id')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('posted_at')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'journal_id'])->references(['tenant_id', 'id'])->on('accounting_journals')->restrictOnDelete();
            $table->foreign(['tenant_id', 'period_id'])->references(['tenant_id', 'id'])->on('accounting_periods')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'number']);
            $table->foreign(['tenant_id', 'reversal_of_id'])->references(['tenant_id', 'id'])->on('journal_entries')->restrictOnDelete();
            $table->index(['tenant_id', 'entry_date']);
            $table->index(['source_type', 'source_id']);
        });
        DB::statement("ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_status CHECK (status IN ('draft', 'posted'))");
        DB::statement("ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_posted CHECK (status = 'draft' OR (number IS NOT NULL AND posted_at IS NOT NULL))");
        DB::statement('CREATE UNIQUE INDEX journal_entries_one_reversal ON journal_entries (reversal_of_id) WHERE reversal_of_id IS NOT NULL');

        Schema::create('journal_entry_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('entry_id');
            $table->unsignedSmallInteger('position');
            $table->uuid('account_id');
            $table->bigInteger('debit')->default(0);
            $table->bigInteger('credit')->default(0);
            $table->string('label', 255);
            $table->string('party_type', 16)->nullable();
            $table->uuid('party_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(['tenant_id', 'entry_id'])->references(['tenant_id', 'id'])->on('journal_entries')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'account_id'])->references(['tenant_id', 'id'])->on('chart_accounts')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->index('entry_id');
            $table->index(['tenant_id', 'account_id']);
            $table->index(['tenant_id', 'party_type', 'party_id']);
        });
        DB::statement('ALTER TABLE journal_entry_lines ADD CONSTRAINT journal_entry_lines_amounts CHECK (debit >= 0 AND credit >= 0 AND (debit = 0) <> (credit = 0))');
        DB::statement("ALTER TABLE journal_entry_lines ADD CONSTRAINT journal_entry_lines_party CHECK ((party_type IS NULL) = (party_id IS NULL) AND (party_type IS NULL OR party_type IN ('customer', 'supplier')))");

        // Une opération source n'est traitée qu'une fois, même si elle ne produit aucune écriture.
        Schema::create('accounting_postings', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->string('source_type', 64);
            $table->uuid('source_id');
            $table->string('kind', 40);
            $table->uuid('entry_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->primary(['tenant_id', 'source_type', 'source_id', 'kind']);
            $table->foreign(['tenant_id', 'entry_id'])->references(['tenant_id', 'id'])->on('journal_entries')->restrictOnDelete();
        });

        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('account_id');
            $table->date('statement_date');
            $table->bigInteger('statement_balance');
            $table->string('reference', 64)->nullable();
            $table->string('status', 16)->default('draft');
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'account_id'])->references(['tenant_id', 'id'])->on('chart_accounts')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
        });
        DB::statement("ALTER TABLE bank_reconciliations ADD CONSTRAINT bank_reconciliations_status CHECK (status IN ('draft', 'completed'))");

        Schema::create('bank_reconciliation_lines', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->uuid('reconciliation_id');
            $table->uuid('line_id');

            $table->primary(['reconciliation_id', 'line_id']);
            $table->unique('line_id'); // une ligne n'est rapprochée qu'une fois
            $table->foreign(['tenant_id', 'reconciliation_id'])->references(['tenant_id', 'id'])->on('bank_reconciliations')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'line_id'])->references(['tenant_id', 'id'])->on('journal_entry_lines')->restrictOnDelete();
        });

        $this->createTriggers();
    }

    private function createTriggers(): void
    {
        DB::unprepared(<<<'SQL'
            -- Écriture validée : immuable ; validation seulement dans une période ouverte.
            CREATE OR REPLACE FUNCTION diaba_journal_entry_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                period_status text;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status = 'posted' THEN
                        RAISE EXCEPTION 'posted journal entry % cannot be deleted', OLD.id USING ERRCODE = 'check_violation';
                    END IF;
                    RETURN OLD;
                END IF;
                IF TG_OP = 'UPDATE' AND OLD.status = 'posted' THEN
                    RAISE EXCEPTION 'posted journal entry % is immutable', OLD.id USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.status = 'posted' THEN
                    SELECT status INTO period_status FROM accounting_periods WHERE id = NEW.period_id;
                    IF period_status IS DISTINCT FROM 'open' THEN
                        RAISE EXCEPTION 'accounting period is % : no posting allowed', period_status USING ERRCODE = 'check_violation';
                    END IF;
                    IF NOT EXISTS (SELECT 1 FROM accounting_periods WHERE id = NEW.period_id
                                   AND NEW.entry_date BETWEEN starts_on AND ends_on) THEN
                        RAISE EXCEPTION 'entry date outside its period' USING ERRCODE = 'check_violation';
                    END IF;
                END IF;
                RETURN NEW;
            END $$;

            CREATE TRIGGER journal_entries_guard
                BEFORE INSERT OR UPDATE OR DELETE ON journal_entries
                FOR EACH ROW EXECUTE FUNCTION diaba_journal_entry_guard();

            -- Lignes figées dès que l'écriture est validée.
            CREATE OR REPLACE FUNCTION diaba_journal_line_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                entry_status text;
            BEGIN
                SELECT status INTO entry_status FROM journal_entries WHERE id = COALESCE(NEW.entry_id, OLD.entry_id);
                IF entry_status = 'posted' THEN
                    RAISE EXCEPTION 'lines of a posted journal entry are immutable' USING ERRCODE = 'check_violation';
                END IF;
                RETURN COALESCE(NEW, OLD);
            END $$;

            CREATE TRIGGER journal_entry_lines_guard
                BEFORE INSERT OR UPDATE OR DELETE ON journal_entry_lines
                FOR EACH ROW EXECUTE FUNCTION diaba_journal_line_guard();

            -- Équilibre : SUM(débit) = SUM(crédit), au moins deux lignes, vérifié en fin de transaction.
            CREATE OR REPLACE FUNCTION diaba_journal_entry_balanced() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                total_debit bigint;
                total_credit bigint;
                line_count int;
            BEGIN
                IF NEW.status <> 'posted' THEN
                    RETURN NULL;
                END IF;
                SELECT COALESCE(SUM(debit), 0), COALESCE(SUM(credit), 0), COUNT(*)
                    INTO total_debit, total_credit, line_count
                    FROM journal_entry_lines WHERE entry_id = NEW.id;
                IF line_count < 2 OR total_debit <> total_credit OR total_debit = 0 THEN
                    RAISE EXCEPTION 'journal entry % is not balanced (debit %, credit %, % lines)', NEW.id, total_debit, total_credit, line_count
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NULL;
            END $$;

            CREATE CONSTRAINT TRIGGER journal_entries_balanced
                AFTER INSERT OR UPDATE ON journal_entries
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION diaba_journal_entry_balanced();

            CREATE TRIGGER accounting_postings_append_only
                BEFORE UPDATE OR DELETE ON accounting_postings
                FOR EACH ROW EXECUTE FUNCTION diaba_append_only();
        SQL);
    }

    public function down(): void
    {
        foreach (['bank_reconciliation_lines', 'bank_reconciliations', 'accounting_postings', 'journal_entry_lines', 'journal_entries',
            'accounting_periods', 'fiscal_years', 'account_mappings', 'accounting_journals', 'chart_accounts', 'accounting_settings'] as $table) {
            Schema::dropIfExists($table);
        }

        DB::unprepared('DROP FUNCTION IF EXISTS diaba_journal_entry_guard(); DROP FUNCTION IF EXISTS diaba_journal_line_guard(); DROP FUNCTION IF EXISTS diaba_journal_entry_balanced();');
    }
};
