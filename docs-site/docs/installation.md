---
title: Installation
description: Requirements, composer install, the optional LDAP transport, publishing the config, and what the service provider registers.
---

# Installation

## Requirements

| Requirement | Version / note |
|---|---|
| PHP | **8.3+** (`"php": "^8.3"`) |
| Laravel | **13** |
| Laravel IAM server | A running [`padosoft/laravel-iam-server`](https://doc.laravel-iam-server.padosoft.com) (`^1.0`) |
| Contracts | [`padosoft/laravel-iam-contracts`](https://doc.laravel-iam-contracts.padosoft.com) (`^1.0`, pulled transitively) |
| Package tooling | `spatie/laravel-package-tools` (`^1.16`) |
| **LDAP transport** | *Optional* — `directorytree/ldaprecord-laravel` + PHP `ext-ldap` |

The directory module is a **server-side** companion: it writes IAM users, memberships and grants directly
through the server's Eloquent models (`User`, `Membership`, `Grant`). It must run in the same application as
the IAM server.

## Install the package

```bash
composer require padosoft/laravel-iam-directory
```

The package auto-registers `IamDirectoryServiceProvider` via Laravel package discovery.

## Add the LDAP transport (optional)

The real LDAP/Active Directory connector depends on PHP's `ext-ldap` and LdapRecord. Both are kept in
`suggest`, **not** `require`, so the package installs and passes static analysis in environments without
`ext-ldap` (CI, Herd, containers):

```bash
composer require directorytree/ldaprecord-laravel   # enables Ldap\LdapConnector
```

::: callout warning "ext-ldap is a build-time gotcha"
`directorytree/ldaprecord-laravel` requires the `ldap` PHP extension. Many local stacks (Herd) and CI
runners ship without it, which is exactly why it lives in `suggest`. The core (group mapping + JIT + sync)
works against any `DirectoryConnector` you provide, so you can develop and test the security logic with no
LDAP at all. See [Custom connector](/guides/custom-connector).
:::

Check the extension is present:

```bash
php -m | grep ldap
```

## Publish the config

```bash
php artisan vendor:publish --tag=iam-directory-config
```

This writes `config/iam-directory.php`. Every key is documented in the [Config reference](/reference/config).

## What the service provider registers

`IamDirectoryServiceProvider` (a `spatie/laravel-package-tools` provider) wires three singletons:

```php
$this->app->singleton(GroupMapper::class, fn ($app) =>
    new GroupMapper(config('iam-directory.group_map', [])));

$this->app->singleton(DirectoryProvisioner::class);

$this->app->singleton(DirectoryAuthenticator::class, fn ($app) =>
    new DirectoryAuthenticator(
        $app->make(DirectoryConnector::class),   // ← YOU must bind this
        $app->make(GroupMapper::class),
        $app->make(DirectoryProvisioner::class),
        config('iam-directory'),                 // the whole config section
    ));
```

::: callout danger "You must bind a DirectoryConnector"
The provider deliberately does **not** bind `DirectoryConnector` — there is no sensible default (LDAP is
optional, and a custom source is app-specific). Until you bind one, resolving `DirectoryAuthenticator`
throws a container binding error. Bind it in your `AppServiceProvider`:

```php
$this->app->bind(
    \Padosoft\Iam\Directory\Contracts\DirectoryConnector::class,
    \App\Directory\MyConnector::class,
);
```
:::

## Verify the install

```php
// tinker
app(\Padosoft\Iam\Directory\GroupMapper::class)->rolesFor(['developers']);
// → e.g. ['app:deployer', 'app:developer']  (sorted, from your group_map)
```

If that returns your mapped roles, the core is wired correctly. Resolving `DirectoryAuthenticator` next
verifies your connector binding.

## Next steps

- [Quickstart](/quickstart) — the five-step happy path.
- [LDAP / Active Directory setup](/operations/ldap-setup) — wire the real connector.
- [Configuration](/operations/configuration) — every policy and mapping knob.
