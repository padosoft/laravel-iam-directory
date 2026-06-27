<?php

declare(strict_types=1);

namespace Padosoft\Iam\Directory\Contracts;

use Padosoft\Iam\Directory\DirectoryUser;

/**
 * Trasporto verso una directory enterprise (doc 10 §6). L'implementazione di v1 è LDAP/AD via
 * LdapRecord (`Ldap\LdapConnector`, opzionale), ma il contract è agnostico: un'app può fornire un
 * connettore custom. `authenticate` verifica le credenziali e ritorna l'utente directory, oppure
 * null se le credenziali non sono valide (fail-closed: null = accesso negato, mai un'eccezione opaca).
 */
interface DirectoryConnector
{
    public function authenticate(string $username, string $password): ?DirectoryUser;

    /** Lookup senza credenziali (per sync/lookup amministrativo); null se non trovato. */
    public function find(string $username): ?DirectoryUser;
}
