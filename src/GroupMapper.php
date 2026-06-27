<?php

declare(strict_types=1);

namespace Padosoft\Iam\Directory;

/**
 * Group mapping (doc 10 §6): traduce i gruppi di directory di un utente nei ruoli IAM (full_key) da
 * concedere via JIT. La mappa accetta sia il DN completo del gruppo sia il nome corto (CN), in modo
 * case-insensitive, perché le directory espongono i gruppi in entrambe le forme. Un valore può essere
 * un singolo ruolo o una lista. I gruppi non mappati vengono semplicemente ignorati (default-deny:
 * nessun ruolo "implicito").
 */
final class GroupMapper
{
    /** @var array<string, list<string>> mappa normalizzata: chiave-lower => list<full_key> */
    private array $map;

    /** @param array<array-key, mixed> $map DN-o-CN => full_key | list<full_key> */
    public function __construct(array $map)
    {
        $normalized = [];
        foreach ($map as $group => $roles) {
            $key = strtolower(trim((string) $group));
            if ($key === '') {
                continue;
            }
            $list = is_array($roles) ? $roles : [$roles];
            $normalized[$key] = array_values(array_filter(
                array_map(static fn (mixed $r): string => is_string($r) ? $r : '', $list),
                static fn (string $r): bool => $r !== '',
            ));
        }
        $this->map = $normalized;
    }

    /**
     * @param  list<string>  $groups  DN o CN dei gruppi dell'utente
     * @return list<string> ruoli IAM (full_key) unici, in ordine deterministico
     */
    public function rolesFor(array $groups): array
    {
        $roles = [];
        foreach ($groups as $group) {
            foreach ($this->candidates($group) as $candidate) {
                foreach ($this->map[$candidate] ?? [] as $role) {
                    $roles[$role] = true;
                }
            }
        }

        $out = array_keys($roles);
        sort($out);

        return $out;
    }

    /**
     * Forme con cui un gruppo può comparire nella mappa: il DN completo e il CN estratto da esso
     * (es. `cn=admins,ou=groups,dc=acme,dc=com` → anche `admins`).
     *
     * @return list<string>
     */
    private function candidates(string $group): array
    {
        $dn = strtolower(trim($group));
        if ($dn === '') {
            return [];
        }

        $candidates = [$dn];
        if (preg_match('/^cn=([^,]+)/', $dn, $m) === 1) {
            $candidates[] = $m[1];
        }

        return $candidates;
    }
}
