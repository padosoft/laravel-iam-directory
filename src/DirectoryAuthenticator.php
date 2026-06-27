<?php

declare(strict_types=1);

namespace Padosoft\Iam\Directory;

use Padosoft\Iam\Directory\Contracts\DirectoryConnector;

/**
 * Orchestratore del login directory (doc 10 §6): autentica le credenziali contro la directory →
 * mappa i gruppi in ruoli → JIT provisioning/sync. È il punto d'ingresso che un'app usa al posto del
 * guard locale per gli utenti enterprise. Fail-closed: credenziali non valide → `denied` (nessun
 * utente IAM toccato).
 */
final class DirectoryAuthenticator
{
    /** @param array<string, mixed> $config sezione `iam-directory` */
    public function __construct(
        private readonly DirectoryConnector $connector,
        private readonly GroupMapper $mapper,
        private readonly DirectoryProvisioner $provisioner,
        private readonly array $config = [],
    ) {}

    public function login(string $username, string $password): DirectoryOutcome
    {
        $user = $this->connector->authenticate($username, $password);
        if ($user === null) {
            return DirectoryOutcome::denied();
        }

        return $this->sync($user);
    }

    /** Sync amministrativo (senza credenziali) di un utente directory già risolto. */
    public function sync(DirectoryUser $user): DirectoryOutcome
    {
        $policy = DirectoryJitPolicy::fromArray($this->jitConfig());
        $mappedRoles = $policy->groupMapping ? $this->mapper->rolesFor($user->groups) : [];

        return $this->provisioner->provision($user, $policy, $this->organizationId(), $mappedRoles);
    }

    /** @return array<array-key, mixed> */
    private function jitConfig(): array
    {
        $jit = $this->config['jit'] ?? null;

        return is_array($jit) ? $jit : [];
    }

    private function organizationId(): ?string
    {
        $org = $this->config['organization_id'] ?? null;

        return is_string($org) && $org !== '' ? $org : null;
    }
}
