<?php

namespace App\Console\Commands;

use App\Domain\Audit\AuditLog;
use App\Domain\Identity\PermissionCatalog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Crée ou promeut un super-administrateur. Le mot de passe est saisi de façon
 * masquée : il n'apparaît ni dans l'historique du shell, ni dans le dépôt, ni dans les logs.
 */
#[Signature('diaba:create-platform-admin {email? : Adresse e-mail du compte}')]
#[Description('Crée ou promeut un super-administrateur DIABA (MFA exigée à la première connexion).')]
class CreatePlatformAdminCommand extends Command
{
    public function handle(AuditLog $audit): int
    {
        $email = mb_strtolower(trim((string) ($this->argument('email') ?? text('Adresse e-mail', required: true))));
        $user = User::query()->where('email', $email)->first();

        $name = $user?->name;
        $plainPassword = null;

        if ($user === null) {
            $name = text('Nom complet', required: true);
            $plainPassword = password('Mot de passe (saisie masquée)', required: true);
            $confirmation = password('Confirmer le mot de passe', required: true);

            $validator = Validator::make(
                ['email' => $email, 'password' => $plainPassword, 'password_confirmation' => $confirmation],
                ['email' => ['required', 'email', 'max:255'], 'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->letters()->numbers()->symbols()]],
            );

            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $error) {
                    $this->components->error($error);
                }

                return self::FAILURE;
            }
        }

        $role = Role::system(PermissionCatalog::SCOPE_PLATFORM, PermissionCatalog::SUPER_ADMIN_ROLE);

        DB::transaction(function () use (&$user, $email, $name, $plainPassword, $role, $audit): void {
            if ($user === null) {
                $user = new User(['name' => $name, 'email' => $email, 'password' => $plainPassword]);
                $user->email_verified_at = now();
            }

            $user->platform_role_id = $role->id;
            $user->save();

            $audit->record('platform.admin_granted', $user, after: ['role' => PermissionCatalog::SUPER_ADMIN_ROLE], metadata: ['via' => 'console']);
        });

        $this->components->info("Super-administrateur prêt : {$email}. La MFA sera exigée à la première connexion.");

        return self::SUCCESS;
    }
}
