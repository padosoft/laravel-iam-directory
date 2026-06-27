<?php

declare(strict_types=1);

/*
 * Modulo Directory (doc 10 §6 — solo LDAP/AD in v1). Mappa le identità di una directory enterprise
 * su utenti/membership/ruoli IAM al primo accesso (JIT), con group mapping LDAP→ruoli. Il connettore
 * LDAP reale (LdapRecord) è opzionale: senza, il core resta usabile con un DirectoryConnector custom.
 */
return [
    // Organizzazione di destinazione del provisioning (null = utenti globali senza membership).
    'organization_id' => env('IAM_DIRECTORY_ORG'),

    // Policy JIT (secure-by-default, coerente con la federazione doc 10 §6).
    'jit' => [
        'require_verified_email' => true,
        'allowed_domains' => [],        // [] = nessun vincolo di dominio
        'approval_required' => false,
        'default_roles' => [],          // ruoli bootstrap full_key (es. ['iam:tenant_member'])
        'group_mapping' => true,        // applica la mappa gruppi→ruoli sotto
        // Ruoli MAI concedibili via group mapping (solo assegnazione manuale): una riga errata o una
        // compromissione di `group_map` non deve poter scalare gli utenti a questi ruoli.
        'protected_roles' => [],        // es. ['iam:super_admin']
    ],

    // Mappa gruppi directory → ruoli IAM (full_key). Chiave = DN completo o nome corto del gruppo
    // (case-insensitive); valore = ruolo o lista di ruoli.
    'group_map' => [
        // 'cn=warehouse-admins,ou=groups,dc=acme,dc=com' => 'warehouse:admin',
        // 'developers' => ['app:developer', 'app:deployer'],
    ],
];
