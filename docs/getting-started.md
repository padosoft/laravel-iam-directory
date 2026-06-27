---
title: Getting started
description: Install laravel-iam-directory, configure the JIT policy and group map, and authenticate a directory user.
---

# Getting started

## Requirements

- PHP **8.3+**, Laravel **13**
- A running **Laravel IAM server** (`padosoft/laravel-iam-server`)
- For the real LDAP/AD transport: PHP `ext-ldap` + `directorytree/ldaprecord-laravel` (optional)

## Install

::: steps
1. **Require the package**
   ```bash
   composer require padosoft/laravel-iam-directory
   ```
2. **(Optional) add the LDAP transport**
   Only if you want the built-in LDAP/Active Directory connector:
   ```bash
   composer require directorytree/ldaprecord-laravel
   ```
   Without it, the core works against any `DirectoryConnector` you provide.
3. **Publish the config**
   ```bash
   php artisan vendor:publish --tag=iam-directory-config
   ```
:::

## Configure

Edit `config/iam-directory.php`:

```php
return [
    'organization_id' => env('IAM_DIRECTORY_ORG'),   // null = global users (no membership)

    'jit' => [
        'require_verified_email' => true,
        'allowed_domains'        => ['acme.com'],     // [] = no restriction
        'approval_required'      => false,
        'default_roles'          => ['iam:tenant_member'],
        'group_mapping'          => true,
        'protected_roles'        => ['iam:super_admin'], // never granted via the directory
    ],

    'group_map' => [
        'cn=warehouse-admins,ou=groups,dc=acme,dc=com' => 'warehouse:admin',
        'developers' => ['app:developer', 'app:deployer'],
    ],
];
```

::: callout warning "Set protected_roles before you map anything"
List every high-privilege role here. A single wrong row in `group_map` (or a compromised directory) must
never be able to escalate a user to admin — `protected_roles` is the guardrail that makes that impossible.
:::

## Authenticate

```php
use Padosoft\Iam\Directory\DirectoryAuthenticator;

$outcome = app(DirectoryAuthenticator::class)->login($username, $password);

match ($outcome->status) {
    'provisioned', 'linked' => Auth::loginUsingId($outcome->userId),
    'pending'  => back()->withErrors("Access pending: {$outcome->reason}"),
    'conflict' => back()->withErrors('Email belongs to an existing account — manual link required.'),
    'denied'   => back()->withErrors('Invalid credentials.'),
};
```

## Expected outcome

- **First login** → `provisioned`: a new IAM user + membership + the granted roles.
- **Subsequent logins** → `linked`: the same user, roles re-synced to the current directory groups.
- **Policy blocked** (unverified email, disallowed domain, approval) → `pending` with a `reason`.
- **Email already taken by a non-directory account** → `conflict` (no takeover).
- **Bad credentials** → `denied` (no IAM user touched).

## Common errors

- *Everyone comes back `pending: jit_requires_verified_email`* → your connector returns
  `emailVerified: false`. Verify upstream or relax the policy deliberately.
- *Roles aren't granted* → `organization_id` is `null`; grants are scoped to a membership, so a global user
  gets none. Set the target org.
