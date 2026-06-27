<?php

declare(strict_types=1);

namespace Padosoft\Iam\Directory\Ldap;

use LdapRecord\Connection;
use LdapRecord\Models\Model;
use Padosoft\Iam\Directory\Contracts\DirectoryConnector;
use Padosoft\Iam\Directory\DirectoryUser;

/**
 * Connettore LDAP/Active Directory reale (doc 10 §6) basato su LdapRecord. È l'UNICA classe del
 * modulo accoppiata a LdapRecord (dipendenza opzionale, richiede ext-ldap): per questo vive nel
 * sottospazio `Ldap\` ed è esclusa dall'analisi statica del monorepo. Il core directory (group
 * mapping + JIT) non dipende da essa.
 *
 * Mappa gli attributi LDAP sul `DirectoryUser` normalizzato: `mail`→email, `cn`→displayName,
 * `memberOf`→groups (DN). L'autenticazione fa un bind con il DN dell'utente: credenziali errate o
 * utente assente → null (fail-closed), mai un'eccezione opaca verso il chiamante.
 */
final class LdapConnector implements DirectoryConnector
{
    /**
     * @param  class-string<Model>  $model  modello LdapRecord (es. LdapRecord\Models\ActiveDirectory\User)
     * @param  string  $usernameAttribute  attributo di ricerca (samaccountname | uid)
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly string $model,
        private readonly string $usernameAttribute = 'samaccountname',
    ) {}

    public function authenticate(string $username, string $password): ?DirectoryUser
    {
        $model = $this->query($username);
        if ($model === null || $password === '') {
            return null;
        }

        try {
            if (!$this->connection->auth()->attempt($model->getDn() ?? '', $password)) {
                return null;
            }
        } catch (\Throwable) {
            return null; // errore di bind/trasporto → accesso negato
        }

        return $this->toUser($model);
    }

    public function find(string $username): ?DirectoryUser
    {
        $model = $this->query($username);

        return $model !== null ? $this->toUser($model) : null;
    }

    private function query(string $username): ?Model
    {
        try {
            return $this->model::query()
                ->where($this->usernameAttribute, '=', $username)
                ->first();
        } catch (\Throwable) {
            return null;
        }
    }

    private function toUser(Model $model): DirectoryUser
    {
        $email = $this->firstString($model->getAttribute('mail'));
        $name = $this->firstString($model->getAttribute('cn'));

        $groups = [];
        foreach ((array) $model->getAttribute('memberof') as $dn) {
            if (is_string($dn) && $dn !== '') {
                $groups[] = $dn;
            }
        }

        return new DirectoryUser(
            username: $this->firstString($model->getAttribute($this->usernameAttribute)) ?? $model->getDn() ?? '',
            email: $email,
            emailVerified: $email !== null, // l'email della directory enterprise è considerata verificata
            displayName: $name,
            groups: $groups,
        );
    }

    private function firstString(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
