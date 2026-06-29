<?php

declare(strict_types=1);

use Padosoft\Iam\Directory\GroupMapper;

it('mappa i gruppi per DN completo e per CN corto (case-insensitive)', function () {
    $mapper = new GroupMapper([
        'cn=warehouse-admins,ou=groups,dc=acme,dc=com' => 'warehouse:admin',
        'developers' => ['app:developer', 'app:deployer'],
    ]);

    // Per DN completo
    expect($mapper->rolesFor(['CN=Warehouse-Admins,OU=Groups,DC=Acme,DC=Com']))->toBe(['warehouse:admin']);
    // Per CN estratto dal DN (il gruppo "developers" mappato col nome corto)
    expect($mapper->rolesFor(['cn=developers,ou=groups,dc=acme,dc=com']))->toBe(['app:deployer', 'app:developer']);
});

it('unisce i ruoli da più gruppi, deduplica e ignora i gruppi non mappati', function () {
    $mapper = new GroupMapper([
        'admins' => 'app:admin',
        'staff' => ['app:admin', 'app:viewer'],
    ]);

    $roles = $mapper->rolesFor(['cn=admins,dc=x', 'cn=staff,dc=x', 'cn=unknown,dc=x']);

    expect($roles)->toBe(['app:admin', 'app:viewer']);
});

it('senza gruppi o senza mappa non concede nulla (default-deny)', function () {
    expect((new GroupMapper([]))->rolesFor(['cn=admins,dc=x']))->toBe([])
        ->and((new GroupMapper(['admins' => 'app:admin']))->rolesFor([]))->toBe([]);
});
