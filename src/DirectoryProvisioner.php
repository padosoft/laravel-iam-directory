<?php

declare(strict_types=1);

namespace Padosoft\Iam\Directory;

use Illuminate\Support\Facades\DB;
use Padosoft\Iam\Domain\Authorization\Models\Grant;
use Padosoft\Iam\Domain\Identity\Models\User;
use Padosoft\Iam\Domain\Organizations\Models\Membership;

/**
 * JIT provisioning + sync da directory (doc 10 §6). Al primo accesso crea utente + membership +
 * ruoli (default + mappati dai gruppi); agli accessi successivi RI-SINCRONIZZA in modo idempotente
 * (nessun grant duplicato). Secure-by-default: email verificata, allowlist domini, approval. Mai
 * sovrascrivere un account: un'email già presente viene RIUSATA (link), non duplicata.
 */
final class DirectoryProvisioner
{
    /**
     * @param  list<string>  $mappedRoles  ruoli derivati dai gruppi (full_key), già risolti dal GroupMapper
     */
    public function provision(DirectoryUser $user, DirectoryJitPolicy $policy, ?string $organizationId, array $mappedRoles): DirectoryOutcome
    {
        if ($policy->requireVerifiedEmail && !$user->emailVerified) {
            return DirectoryOutcome::pending('jit_requires_verified_email');
        }

        if ($policy->allowedDomains !== []) {
            $domain = $user->emailDomain();
            if ($domain === null || !in_array($domain, $policy->allowedDomains, true)) {
                return DirectoryOutcome::pending('jit_domain_not_allowed');
            }
        }

        if ($policy->approvalRequired) {
            return DirectoryOutcome::pending('jit_approval_required');
        }

        $roles = $this->rolesToGrant($policy, $mappedRoles);
        $email = $user->normalizedEmail();
        $existing = $email !== null ? User::query()->where('email', $email)->first() : null;

        if ($existing instanceof User) {
            // Anti-takeover: si RIUSA solo un account che la directory ha già provisionato (membership
            // source=directory). Un'email che appartiene a un account locale/altro NON si linka in
            // automatico — sarebbe un takeover via entry LDAP. Serve un link manuale verificato.
            if (!$this->isDirectorySourced($existing->id, $organizationId)) {
                return DirectoryOutcome::conflict('email_taken_non_directory');
            }
            $this->sync($existing->id, $organizationId, $roles);

            return DirectoryOutcome::linked($existing->id, $roles);
        }

        $userId = '';
        DB::transaction(function () use ($user, $email, $organizationId, $roles, &$userId): void {
            $model = User::query()->create([
                'email' => $email,
                'name' => $user->displayName,
                'email_verified_at' => $user->emailVerified ? now() : null,
            ]);
            $userId = $model->id;
            $this->sync($model->id, $organizationId, $roles);
        });

        return DirectoryOutcome::provisioned($userId, $roles);
    }

    /**
     * Sync AUTORITATIVO dei ruoli directory-sourced per (org, user): aggiunge i grant mancanti e
     * REVOCA quelli directory-sourced non più presenti (es. utente tolto da un gruppo LDAP) — senza
     * toccare i grant assegnati manualmente (source ≠ directory). Idempotente: niente duplicati.
     *
     * @param  list<string>  $roles
     */
    private function sync(string $userId, ?string $organizationId, array $roles): void
    {
        if ($organizationId === null) {
            return; // senza organizzazione non c'è membership né grant scope (utente globale)
        }

        Membership::query()->firstOrCreate(
            ['organization_id' => $organizationId, 'user_id' => $userId],
            ['source' => 'directory', 'joined_at' => now()],
        );

        $wanted = array_flip($roles);

        // Revoca i ruoli directory-sourced non più mappati (privilege persistence → no).
        $activeDirectoryGrants = Grant::query()
            ->where('organization_id', $organizationId)
            ->where('subject_type', 'user')
            ->where('subject_id', $userId)
            ->where('privilege_type', 'role')
            ->where('source', 'directory')
            ->whereNull('revoked_at')
            ->get();
        foreach ($activeDirectoryGrants as $grant) {
            if (!isset($wanted[$grant->privilege_key])) {
                $grant->revoke('directory_sync_removed');
            }
        }

        foreach ($roles as $role) {
            $exists = Grant::query()
                ->where('organization_id', $organizationId)
                ->where('subject_type', 'user')
                ->where('subject_id', $userId)
                ->where('privilege_type', 'role')
                ->where('privilege_key', $role)
                ->whereNull('revoked_at')
                ->exists();

            if (!$exists) {
                Grant::query()->create([
                    'organization_id' => $organizationId,
                    'subject_type' => 'user',
                    'subject_id' => $userId,
                    'privilege_type' => 'role',
                    'privilege_key' => $role,
                    'source' => 'directory',
                    'valid_from' => now(),
                ]);
            }
        }
    }

    private function isDirectorySourced(string $userId, ?string $organizationId): bool
    {
        return Membership::query()
            ->where('user_id', $userId)
            ->where('source', 'directory')
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->exists();
    }

    /**
     * Ruoli da garantire = default ∪ (mappati se group_mapping), MENO i `protected_roles`: questi
     * ultimi non sono mai concedibili via directory (una riga errata/compromessa in `group_map` non
     * deve poter scalare gli utenti a super-admin), restano solo-assegnazione-manuale.
     *
     * @param  list<string>  $mappedRoles
     * @return list<string>
     */
    private function rolesToGrant(DirectoryJitPolicy $policy, array $mappedRoles): array
    {
        $protected = array_flip($policy->protectedRoles);
        $mapped = $policy->groupMapping
            ? array_values(array_filter($mappedRoles, static fn (string $r): bool => !isset($protected[$r])))
            : [];

        return array_values(array_unique(array_merge($policy->defaultRoles, $mapped)));
    }
}
