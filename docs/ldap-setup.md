---
title: LDAP setup
description: Wire the optional LDAP/Active Directory transport (LdapRecord, ext-ldap) — or provide a custom DirectoryConnector instead.
---

# LDAP setup

The LDAP/Active Directory transport is **optional**. The core (group mapping + JIT + sync) runs against any
`DirectoryConnector`; the built-in `Ldap\LdapConnector` is only needed when you want to bind against a real
directory.

::: callout warning "ext-ldap is required for the real connector"
`Ldap\LdapConnector` depends on PHP's `ext-ldap` and `directorytree/ldaprecord-laravel`. These are kept in
`suggest` (not `require`) so the package installs and analyses cleanly in environments without `ext-ldap`
(e.g. CI). It's deliberately isolated under `src/Ldap/` and excluded from static analysis.
:::

## Enable it

::: steps
1. **Install the extension** — ensure `ext-ldap` is enabled in your PHP build (`php -m | grep ldap`).
2. **Require LdapRecord**
   ```bash
   composer require directorytree/ldaprecord-laravel
   ```
3. **Configure the connection** — follow LdapRecord's connection config (host, base DN, bind user). The
   `Ldap\LdapConnector` adapts an authenticated LDAP entry into a normalized `DirectoryUser` (username,
   email, verified flag, group DNs/CNs).
4. **Map groups → roles** — fill `group_map` in `config/iam-directory.php` with the group DNs or CNs your
   directory exposes.
:::

## No ext-ldap? Use a custom connector

Any class implementing `DirectoryConnector` plugs into the exact same hardened JIT/sync path:

::: tabs
== tab "Custom connector"
```php
use Padosoft\Iam\Directory\Contracts\DirectoryConnector;
use Padosoft\Iam\Directory\DirectoryUser;

final class ApiDirectoryConnector implements DirectoryConnector
{
    public function authenticate(string $username, string $password): ?DirectoryUser
    {
        $row = $this->api->verify($username, $password);   // your source
        if ($row === null) {
            return null;                                    // → denied (fail-closed)
        }

        return new DirectoryUser(
            username: $row['uid'],
            email: $row['mail'],
            emailVerified: true,
            displayName: $row['cn'],
            groups: $row['memberOf'],                       // DNs or CNs
        );
    }

    public function find(string $username): ?DirectoryUser { /* ... */ }
}
```
== tab "Bind it"
```php
// In a service provider:
$this->app->bind(
    \Padosoft\Iam\Directory\Contracts\DirectoryConnector::class,
    \App\Directory\ApiDirectoryConnector::class,
);
```
:::

## Group identifiers

`GroupMapper` accepts **both** the full DN and the short CN, case-insensitively, because directories expose
groups in either form:

```php
'group_map' => [
    'cn=warehouse-admins,ou=groups,dc=acme,dc=com' => 'warehouse:admin', // full DN
    'developers' => ['app:developer', 'app:deployer'],                   // short CN
],
```

Unmapped groups are simply ignored — there are no implicit roles.
