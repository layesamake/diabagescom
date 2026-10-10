<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EPIC-01 — identité, entreprises (tenants), rôles, permissions, dépôts et audit.
 *
 * Les invariants d'isolation sont doublés en base :
 *  - les liaisons inter-tables d'un même tenant passent par des clés étrangères
 *    composites (tenant_id, id), impossibles à satisfaire avec une ligne d'un autre tenant ;
 *  - un membre ne peut recevoir qu'un rôle de portée tenant, global ou de son tenant ;
 *  - audit_events est append-only (UPDATE/DELETE/TRUNCATE refusés par trigger).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->string('code', 64)->primary();
            $table->string('scope', 16);
            $table->string('module', 32);
            $table->string('action', 48);
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE permissions ADD CONSTRAINT permissions_scope_check CHECK (scope IN ('tenant', 'platform'))");

        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('legal_name', 160);
            $table->string('display_name', 80);
            $table->char('country_code', 2)->default('SN');
            $table->char('currency_code', 3)->default('XOF');
            $table->string('timezone', 64)->default('Africa/Dakar');
            $table->string('locale', 8)->default('fr');
            $table->string('status', 16)->default('active');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_status_check CHECK (status IN ('active', 'suspended', 'archived'))");
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_currency_check CHECK (currency_code = 'XOF')");
        DB::statement('ALTER TABLE tenants ADD CONSTRAINT tenants_country_check CHECK (country_code ~ \'^[A-Z]{2}$\')');

        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('scope', 16);
            $table->string('code', 48);
            $table->string('name', 80);
            $table->boolean('system')->default(false);
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE roles ADD CONSTRAINT roles_scope_check CHECK (scope IN ('tenant', 'platform'))");
        // Un rôle plateforme n'appartient jamais à un tenant.
        DB::statement("ALTER TABLE roles ADD CONSTRAINT roles_platform_global_check CHECK (scope = 'tenant' OR tenant_id IS NULL)");
        DB::statement('CREATE UNIQUE INDEX roles_global_code_unique ON roles (scope, code) WHERE tenant_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX roles_tenant_code_unique ON roles (tenant_id, code) WHERE tenant_id IS NOT NULL');

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->foreignUuid('role_id')->constrained()->cascadeOnDelete();
            $table->string('permission_code', 64);
            $table->foreign('permission_code')->references('code')->on('permissions')->restrictOnDelete();
            $table->primary(['role_id', 'permission_code']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('platform_role_id')->references('id')->on('roles')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('active', 'suspended'))");
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_email_lowercase_check CHECK (email = lower(email))');

        Schema::create('tenant_memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('role_id')->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('active');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'user_id']);
            $table->unique(['tenant_id', 'id']);
            $table->index('user_id');
        });
        DB::statement("ALTER TABLE tenant_memberships ADD CONSTRAINT tenant_memberships_status_check CHECK (status IN ('active', 'suspended', 'revoked'))");

        Schema::create('warehouses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->string('code', 16);
            $table->string('name', 80);
            $table->text('address')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'id']);
        });
        DB::statement("ALTER TABLE warehouses ADD CONSTRAINT warehouses_status_check CHECK (status IN ('active', 'archived'))");
        DB::statement('ALTER TABLE warehouses ADD CONSTRAINT warehouses_code_format_check CHECK (code ~ \'^[A-Z0-9][A-Z0-9_-]{0,15}$\')');

        Schema::create('warehouse_memberships', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->uuid('warehouse_id');
            $table->uuid('membership_id');
            $table->timestampTz('created_at')->useCurrent();

            $table->primary(['warehouse_id', 'membership_id']);
            $table->index(['tenant_id', 'membership_id']);
            // Clés composites : dépôt et membre appartiennent forcément au même tenant.
            $table->foreign(['tenant_id', 'warehouse_id'])->references(['tenant_id', 'id'])->on('warehouses')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'membership_id'])->references(['tenant_id', 'id'])->on('tenant_memberships')->cascadeOnDelete();
        });

        Schema::create('audit_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 64);
            $table->string('entity_type', 64)->nullable();
            $table->string('entity_id', 64)->nullable();
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->ipAddress('ip')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['actor_id', 'created_at']);
            $table->index(['entity_type', 'entity_id']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION diaba_check_membership_role() RETURNS trigger AS $$
            DECLARE
                r roles%ROWTYPE;
            BEGIN
                SELECT * INTO r FROM roles WHERE id = NEW.role_id;
                IF r.scope <> 'tenant' OR (r.tenant_id IS NOT NULL AND r.tenant_id <> NEW.tenant_id) THEN
                    RAISE EXCEPTION 'role % cannot be assigned to a membership of tenant %', NEW.role_id, NEW.tenant_id
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER tenant_memberships_role_check
                BEFORE INSERT OR UPDATE OF role_id, tenant_id ON tenant_memberships
                FOR EACH ROW EXECUTE FUNCTION diaba_check_membership_role();

            CREATE OR REPLACE FUNCTION diaba_check_platform_role() RETURNS trigger AS $$
            BEGIN
                IF NEW.platform_role_id IS NOT NULL
                   AND NOT EXISTS (SELECT 1 FROM roles WHERE id = NEW.platform_role_id AND scope = 'platform') THEN
                    RAISE EXCEPTION 'role % is not a platform role', NEW.platform_role_id
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER users_platform_role_check
                BEFORE INSERT OR UPDATE OF platform_role_id ON users
                FOR EACH ROW EXECUTE FUNCTION diaba_check_platform_role();

            CREATE OR REPLACE FUNCTION diaba_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION '% is append-only: % refused', TG_TABLE_NAME, TG_OP
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER audit_events_append_only
                BEFORE UPDATE OR DELETE ON audit_events
                FOR EACH ROW EXECUTE FUNCTION diaba_append_only();

            CREATE TRIGGER audit_events_no_truncate
                BEFORE TRUNCATE ON audit_events
                FOR EACH STATEMENT EXECUTE FUNCTION diaba_append_only();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('warehouse_memberships');
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('tenant_memberships');
        DB::unprepared('DROP TRIGGER IF EXISTS users_platform_role_check ON users');
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['platform_role_id']);
        });
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_status_check, DROP CONSTRAINT users_email_lowercase_check');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('tenants');
        Schema::dropIfExists('permissions');
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS diaba_append_only();
            DROP FUNCTION IF EXISTS diaba_check_platform_role();
            DROP FUNCTION IF EXISTS diaba_check_membership_role();
        SQL);
    }
};
