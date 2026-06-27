# LESSON.md — lezioni dell'ecosistema Laravel IAM

> Lezioni **generali** valide per ogni package, accumulate costruendo Laravel IAM v1.0 (16 milestone,
> TDD + loop advisory). Sotto, la sezione **specifica di questo package**. Aggiorna ad ogni scoperta.

## Generali — toolchain & PHPStan max

- **Test con PHP 8.5 (Herd)**: `~/.config/herd/bin/php85/php.exe`. Su Windows, PHPStan vuole
  `--memory-limit=1G` e, prima di Pest/testbench, `attrib -R` sulla dir
  `vendor/orchestra/testbench-core/laravel/bootstrap/cache` (bug `is_writable()`). `.gitattributes eol=lf`.
- **PHPStan crash transitorio** ("Result is incomplete because of severe errors"): ri-eseguire risolve.
- **Mai cast su `mixed`**: usare guardie `is_int`/`is_string`/`is_numeric`, non `(string)`/`(int)`.
- **`@property` sui Model invece di castare nel chiamante**: una colonna castata letta da un servizio
  esterno al model fa fallire PHPStan (`property.notFound` → `Cannot cast mixed`). Dichiarare
  `@property Carbon|null` sul model; poi un `?->` su valore ora non-null diventa `nullsafe.neverNull` → `->`.
- **Mai `*/` dentro un docblock**: `decided_*/granted_id` in `/** */` CHIUDE il commento → ParseError.
- **`@phpstan-impure`** per i metodi con side-effect osservabili (mutano una proprietà pubblica e vengono
  chiamati due volte): senza, PHPStan crede il secondo valore immutato (`booleanOr.leftAlwaysFalse`).
- **Config da `mixed` → `array<string,mixed>` provabile**: `is_array($x) ? $x : []` resta `array<mixed>`;
  ricostruire con un `foreach` che casta le chiavi a stringa per soddisfare la firma.
- **larastan + generics Eloquent + closure**: `Builder<User>` non è assegnabile a `Builder<Model>`
  (invariante) e `get()` perde `TModel`. Per un paginator generico: `@param Builder<covariant Model>` +
  `callable(Model): array` con narrowing `instanceof` al call-site.

## Generali — sicurezza & processo

- **Fail-closed sempre**: default-deny, deny-overrides; un errore (transport, PDP, parsing) → deny, mai un
  allow né un 500 opaco. Vale per PDP, client, directory, AI.
- **Il loop advisory trova bug reali ad ogni slice**: TOCTOU, fail-open, takeover, info-disclosure,
  escalation. `copilot -p` (advisory), **mai** `--autopilot --yolo`. Ogni fix → qui.
- **TOCTOU sulle transizioni di stato**: leggere-poi-scrivere uno stato senza `DB::transaction` +
  `lockForUpdate` + re-check sotto lock = last-write-wins (grant orfano, doppia approvazione).
- **Snapshot vs dato vivo**: la governance congela i segnali/policy al momento giusto; l'esito non deve
  dipendere da una modifica successiva (un ruolo tolto dal catalogo non deve creare grant permanenti).
- **Tenant isolation = 404, non 403**: il cross-tenant deve essere indistinguibile da "non esiste",
  altrimenti il 403 conferma l'esistenza dell'UUID (enumerazione).
- **Deps pesanti in `suggest`, non `require`**: `aws-sdk-php`, `ldaprecord` (ext-ldap), `laravel/ai`
  rallentano/ rompono install e CI. Il core resta usabile senza; l'adapter reale è opzionale e, se non
  installabile in dev, va isolato (sottospazio + `excludePaths` PHPStan).
- **Commit message via file** se l'here-string fallisce su Windows: scrivere su file e `git commit -F`.

## Specifiche di questo package (directory)

- **`ext-ldap` non c'è in Herd/CI** → `directorytree/ldaprecord-laravel` resta in `suggest`, non in
  `require`. Il connettore reale (`Ldap\LdapConnector`) è **isolato** in `src/Ldap/` ed **escluso da
  PHPStan** (`excludePaths`), così il core (group mapping + JIT) resta analizzabile e usabile LDAP-free con
  un `DirectoryConnector` custom. Tutta la logica lavora su `DirectoryUser` normalizzata, mai su tipi LDAP.
- **Account-takeover via email collision (advisory M13)**: provisionare un utente la cui email appartiene
  già a un account **non-directory** = takeover via entry LDAP. Fix: `DirectoryProvisioner` riusa solo
  account `source=directory` (`isDirectorySourced()`); altrimenti ritorna `DirectoryOutcome::conflict` e
  pretende un link manuale verificato. Test negativo obbligatorio.
- **Privilege persistence (advisory M13)**: se un utente esce da un gruppo LDAP, il suo ruolo NON deve
  restare. Il `sync()` è **autoritativo**: revoca i grant `source=directory` non più mappati (idempotente),
  senza toccare i grant manuali (`source ≠ directory`).
- **Group-map escalation (advisory M13)**: una riga errata o compromessa in `group_map` non deve poter
  scalare un utente a super-admin. I `protected_roles` della `DirectoryJitPolicy` sono **filtrati via** dai
  ruoli mappati in `rolesToGrant()` → mai concedibili dalla directory, solo assegnazione manuale.
- **Secure-by-default JIT**: `requireVerifiedEmail=true`, opzionale `allowed_domains`, opzionale
  `approval_required`; un blocco di policy → `DirectoryOutcome::pending(reason)`, mai un provisioning parziale.
- **`GroupMapper` default-deny**: gruppi non mappati ignorati (nessun ruolo implicito); chiavi DN **e** CN,
  case-insensitive, perché le directory espongono i gruppi in entrambe le forme.
- **Fail-closed sul trasporto**: `DirectoryConnector::authenticate` ritorna `null` (= `denied`) su
  credenziali invalide — mai un'eccezione opaca, mai un utente IAM toccato.
