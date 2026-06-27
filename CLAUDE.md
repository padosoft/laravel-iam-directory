# CLAUDE.md — laravel-iam-directory

Guida per agenti AI che lavorano in questo repo (package dell'ecosistema **Laravel IAM**). Prima di
qualsiasi lavoro leggi `LESSON.md`, `RULES.md` e questa pagina. Skill: `laravel-iam-package-workflow`.

## Cos'è questo package

Modulo **opzionale Directory** di Laravel IAM: integra una directory enterprise (LDAP/Active Directory
via LdapRecord in v1; SCIM in v2) per autenticazione, **JIT provisioning** e **group mapping → ruoli IAM**.

- **Composer:** `padosoft/laravel-iam-directory`
- **Namespace:** `Padosoft\Iam\Directory\`
- **Ruolo nell'ecosistema:** modulo opzionale che porta gli utenti di una directory dentro al server IAM —
  login directory, provisioning al primo accesso, sincronizzazione idempotente dei ruoli dai gruppi.
- **Dipende da:** `padosoft/laravel-iam-contracts` + `padosoft/laravel-iam-server`. **Suggerisce**
  `directorytree/ldaprecord-laravel` (richiede `ext-ldap`); senza, il core resta usabile con un
  `DirectoryConnector` custom.

## Architettura del package

Il core è **agnostico dal trasporto**: tutta la logica (group mapping + JIT + sync) lavora su una
`DirectoryUser` normalizzata, prodotta da un `DirectoryConnector`. L'implementazione LDAP reale è isolata
e opzionale.

- **`Contracts\DirectoryConnector`** — il seam di trasporto: `authenticate(user,pass): ?DirectoryUser` e
  `find(user): ?DirectoryUser`. **Fail-closed**: `null` = accesso negato, mai un'eccezione opaca.
- **`DirectoryUser`** (`final readonly`) — identità normalizzata (username, email, emailVerified,
  displayName, `groups` = DN o CN). Helper `normalizedEmail()` / `emailDomain()`.
- **`GroupMapper`** — traduce i gruppi directory dell'utente in ruoli IAM (`full_key`), accettando DN e
  CN case-insensitive. I gruppi non mappati vengono **ignorati** (default-deny: nessun ruolo implicito).
- **`DirectoryJitPolicy`** (`final readonly`) — policy secure-by-default: `requireVerifiedEmail`,
  `allowedDomains`, `approvalRequired`, `defaultRoles`, `groupMapping`, **`protectedRoles`**.
- **`DirectoryProvisioner`** — JIT al primo accesso (crea User + Membership + Grant) e **sync autoritativo**
  idempotente agli accessi successivi: aggiunge i grant mancanti e **revoca** quelli directory-sourced non
  più mappati, senza toccare i grant manuali.
- **`DirectoryAuthenticator`** — orchestratore del login: `authenticate` → `GroupMapper` → `provision`.
- **`DirectoryOutcome`** (`final readonly`) — esito tipizzato: `provisioned` | `linked` | `conflict` |
  `pending` | `denied`, con `roles` garantiti nel passaggio.
- **`Ldap\LdapConnector`** — implementazione LDAP/AD (LdapRecord), **opzionale e isolata** in `Ldap/`
  (esclusa da PHPStan via `excludePaths`, perché `ext-ldap` non è disponibile in Herd/CI).
- **`IamDirectoryServiceProvider`** + `config/iam-directory.php` (`organization_id`, `jit`, `group_map`).

## Invarianti (NON violare)
1. **Mai bypassare il PDP.** La directory concede *ruoli*; allow/deny resta deciso dal PDP deterministico.
2. **Fail-closed** sull'autenticazione: credenziali non valide / connettore in errore → `denied`, mai allow.
3. **Anti-takeover**: un'email già appartenente a un account **non-directory** non si linka in automatico
   (`conflict`) — un entry LDAP non deve poter rubare un account locale. Si riusa solo un account già
   `source=directory`.
4. **No privilege persistence**: il sync **revoca** i ruoli directory-sourced non più presenti (utente
   tolto da un gruppo LDAP → ruolo revocato). I grant manuali non vengono toccati.
5. **`protected_roles` mai concedibili via group mapping**: una riga errata/compromessa in `group_map` non
   deve poter scalare un utente a super-admin. Restano solo-assegnazione-manuale.
6. **Niente segreti/PII nei log** (password directory mai loggate).
7. **`ext-ldap` opzionale**: il core deve restare analizzabile/usabile senza LDAP; il connettore reale è in
   `suggest` e isolato.

## Convenzioni codice
- `declare(strict_types=1)`, classi `final` di default, DTO `final readonly`.
- Namespace radice **`Padosoft\Iam\`** (PSR-4: `Padosoft\Iam\Directory\` → `src/`).
- **PHPStan max** (con `excludePaths` su `Ldap/`), **Pest**, **Pint**. Test negativi obbligatori:
  takeover negato (`conflict`), revoca su gruppo rimosso, `protected_roles` mai concessi, fail-closed login.

## Gate (in locale, con PHP 8.5 Herd)
```bash
php vendor/bin/pint
php vendor/bin/phpstan analyse --memory-limit=1G
php vendor/bin/pest
```
> Nota: la suite di test completa è stata sviluppata nel monorepo originale ed è in migrazione per-repo
> (vedi `LESSON.md`).

## Loop di lavoro
Branch per task → gate locale (test + advisory `copilot -p`, **mai `--yolo`**) → PR → CI + Copilot review
→ merge → tag. Aggiorna `LESSON.md` ad ogni fix. Dettaglio: la skill `laravel-iam-package-workflow`.
