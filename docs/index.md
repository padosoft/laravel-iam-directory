---
title: Home
description: The optional Directory module of Laravel IAM — LDAP/AD login, JIT provisioning and safe group→role mapping.
---

# Laravel IAM — Directory

`padosoft/laravel-iam-directory` is the **optional Directory module** of the
[Laravel IAM](https://github.com/padosoft) ecosystem. It brings the users you already have in **LDAP or
Active Directory** into a Laravel IAM server: directory login, **Just-In-Time provisioning** on first
access, and **group → role mapping** kept in sync on every login.

::: callout tip "Safe by design"
The module refuses to take over local accounts by email collision, **revokes** stale directory roles when a
user leaves a group, and **never** grants `protected_roles` (like `iam:super_admin`) through group mapping.
It assigns *roles*; the PDP — not this module — decides allow/deny.
:::

## What it does

- **Authenticate** enterprise users against a directory (LDAP/AD via LdapRecord, or any custom connector).
- **Provision** an IAM user + membership + roles Just-In-Time on first login (secure-by-default policy).
- **Map** the user's directory groups to IAM roles, and **re-sync authoritatively** on every login.

## The LDAP transport is optional

All the logic works on a normalized `DirectoryUser` produced by a `DirectoryConnector`. The real LDAP/AD
connector (LdapRecord, `ext-ldap`) is an optional `suggest` — the core installs, tests and runs without it.

## Next

- [Getting started](getting-started.md) — install, configure, authenticate.
- [Concepts](concepts.md) — the mental model: connector, user, mapper, policy, provisioner, outcome.
- [Provisioning & security](provisioning-and-security.md) — anti-takeover, stale-role revocation, protected roles.
- [LDAP setup](ldap-setup.md) — the optional `ext-ldap` / LdapRecord wiring.
- [Reference](reference.md) — every class, signature and config key.
