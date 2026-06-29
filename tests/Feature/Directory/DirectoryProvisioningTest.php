<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\Iam\Directory\Contracts\DirectoryConnector;
use Padosoft\Iam\Directory\DirectoryAuthenticator;
use Padosoft\Iam\Directory\DirectoryJitPolicy;
use Padosoft\Iam\Directory\DirectoryProvisioner;
use Padosoft\Iam\Directory\DirectoryUser;
use Padosoft\Iam\Directory\GroupMapper;
use Padosoft\Iam\Domain\Authorization\Models\Grant;
use Padosoft\Iam\Domain\Identity\Models\User;
use Padosoft\Iam\Domain\Organizations\Models\Membership;
use Padosoft\Iam\Domain\Organizations\Models\Organization;

uses(RefreshDatabase::class);

function org(): Organization
{
    return Organization::create(['key' => 'acme', 'name' => 'Acme']);
}

function dirUser(array $overrides = []): DirectoryUser
{
    return new DirectoryUser(
        username: $overrides['username'] ?? 'jdoe',
        email: $overrides['email'] ?? 'jdoe@acme.com',
        emailVerified: $overrides['emailVerified'] ?? true,
        displayName: $overrides['displayName'] ?? 'John Doe',
        groups: $overrides['groups'] ?? [],
    );
}

it('JIT crea utente, membership e ruoli (default + mappati dai gruppi)', function () {
    $o = org();
    $policy = DirectoryJitPolicy::fromArray(['default_roles' => ['iam:member'], 'group_mapping' => true]);

    $outcome = (new DirectoryProvisioner)->provision(dirUser(), $policy, $o->id, ['warehouse:admin']);

    expect($outcome->status)->toBe('provisioned')
        ->and($outcome->roles)->toContain('iam:member', 'warehouse:admin');

    $user = User::query()->where('email', 'jdoe@acme.com')->first();
    expect($user)->not->toBeNull();
    expect(Membership::query()->where('organization_id', $o->id)->where('user_id', $user->id)->exists())->toBeTrue();
    expect(Grant::query()->where('subject_id', $user->id)->where('privilege_key', 'warehouse:admin')->exists())->toBeTrue();
});

it('email non verificata → pending (secure-by-default), nessun utente creato', function () {
    $o = org();
    $policy = DirectoryJitPolicy::fromArray([]);

    $outcome = (new DirectoryProvisioner)->provision(dirUser(['emailVerified' => false]), $policy, $o->id, []);

    expect($outcome->status)->toBe('pending')
        ->and($outcome->reason)->toBe('jit_requires_verified_email')
        ->and(User::query()->count())->toBe(0);
});

it('dominio non in allowlist → pending', function () {
    $o = org();
    $policy = DirectoryJitPolicy::fromArray(['allowed_domains' => ['corp.com']]);

    $outcome = (new DirectoryProvisioner)->provision(dirUser(['email' => 'x@acme.com']), $policy, $o->id, []);

    expect($outcome->reason)->toBe('jit_domain_not_allowed');
});

it('approval_required → pending', function () {
    $o = org();
    $policy = DirectoryJitPolicy::fromArray(['approval_required' => true]);

    expect((new DirectoryProvisioner)->provision(dirUser(), $policy, $o->id, [])->reason)->toBe('jit_approval_required');
});

it('il re-sync è idempotente: utente esistente → linked, nessun grant duplicato', function () {
    $o = org();
    $policy = DirectoryJitPolicy::fromArray(['default_roles' => ['iam:member']]);
    $provisioner = new DirectoryProvisioner;

    $first = $provisioner->provision(dirUser(), $policy, $o->id, ['warehouse:admin']);
    $second = $provisioner->provision(dirUser(), $policy, $o->id, ['warehouse:admin']);

    expect($first->status)->toBe('provisioned')
        ->and($second->status)->toBe('linked')
        ->and(User::query()->count())->toBe(1)
        ->and(Grant::query()->where('privilege_key', 'warehouse:admin')->count())->toBe(1)
        ->and(Membership::query()->count())->toBe(1);
});

it('group_mapping=false ignora i ruoli dei gruppi (solo i default)', function () {
    $o = org();
    $policy = DirectoryJitPolicy::fromArray(['default_roles' => ['iam:member'], 'group_mapping' => false]);

    $outcome = (new DirectoryProvisioner)->provision(dirUser(), $policy, $o->id, ['warehouse:admin']);

    expect($outcome->roles)->toBe(['iam:member']);
});

it('NON fa takeover di un account non-directory con la stessa email → conflict', function () {
    $o = org();
    // Account preesistente NON creato dalla directory (nessuna membership source=directory).
    User::query()->create(['email' => 'ceo@acme.com', 'name' => 'CEO', 'email_verified_at' => now()]);
    $policy = DirectoryJitPolicy::fromArray(['default_roles' => ['iam:admin']]);

    $outcome = (new DirectoryProvisioner)->provision(dirUser(['email' => 'ceo@acme.com']), $policy, $o->id, ['warehouse:admin']);

    expect($outcome->status)->toBe('conflict')
        ->and($outcome->reason)->toBe('email_taken_non_directory')
        // Nessun grant concesso all'account preesistente (no escalation via takeover).
        ->and(Grant::query()->where('privilege_key', 'warehouse:admin')->count())->toBe(0);
});

it('il sync è autoritativo: un ruolo non più mappato viene revocato', function () {
    $o = org();
    $policy = DirectoryJitPolicy::fromArray([]);
    $provisioner = new DirectoryProvisioner;

    $provisioner->provision(dirUser(), $policy, $o->id, ['warehouse:admin']);
    expect(Grant::query()->where('privilege_key', 'warehouse:admin')->whereNull('revoked_at')->count())->toBe(1);

    // Utente tolto dal gruppo LDAP → nessun ruolo mappato → il grant directory viene revocato.
    $provisioner->provision(dirUser(), $policy, $o->id, []);

    expect(Grant::query()->where('privilege_key', 'warehouse:admin')->whereNull('revoked_at')->count())->toBe(0);
});

it('i protected_roles non sono concedibili via group mapping', function () {
    $o = org();
    $policy = DirectoryJitPolicy::fromArray(['protected_roles' => ['iam:super_admin']]);

    $outcome = (new DirectoryProvisioner)->provision(dirUser(), $policy, $o->id, ['iam:super_admin', 'app:dev']);

    expect($outcome->roles)->toBe(['app:dev'])
        ->and(Grant::query()->where('privilege_key', 'iam:super_admin')->exists())->toBeFalse();
});

it('DirectoryAuthenticator: credenziali valide → provisioned con ruoli mappati; invalide → denied', function () {
    $o = org();
    $connector = new class implements DirectoryConnector
    {
        public function authenticate(string $username, string $password): ?DirectoryUser
        {
            return $password === 'good'
                ? new DirectoryUser('jdoe', 'jdoe@acme.com', true, 'John', ['cn=warehouse-admins,dc=acme,dc=com'])
                : null;
        }

        public function find(string $username): ?DirectoryUser
        {
            return null;
        }
    };
    $auth = new DirectoryAuthenticator(
        $connector,
        new GroupMapper(['warehouse-admins' => 'warehouse:admin']),
        new DirectoryProvisioner,
        ['organization_id' => $o->id, 'jit' => ['group_mapping' => true]],
    );

    $ok = $auth->login('jdoe', 'good');
    expect($ok->status)->toBe('provisioned')
        ->and($ok->roles)->toContain('warehouse:admin');

    expect($auth->login('jdoe', 'wrong')->status)->toBe('denied');
});
