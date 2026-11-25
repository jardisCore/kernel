## Kernel-Entkopplung (2026-07) — what changed

`jardiscore/kernel` is now a pure **Application-layer offering**, outside the
hexagonal inner rings. The former domain-side classes are **deleted**:
`DomainApp`, `ServiceRegistry`, `BoundedContext`, `Response/*`. What replaced them:

- **The Jardis Builder now generates** the domain's Context body
  (`{Domain}Context`, carries
  `handle()`/`context()` (now `protected`), `resource()/payload()/version()/
  result()`). Answers are built from the package `jardissupport/response`
  (`ContextResponse`, `TransformResponse`, `BuildResponseData`, `ProjectOutcome`;
  envelope `{status,data,errors,meta}`, process answers carry `@outcome`) — see
  the `support-response` skill. See the `generated-code-extend` skill for the
  generated-code contract — this package does **not** define those classes.
- **Shared vocabulary moved to `jardissupport/contracts`:** `ResponseStatus`
  (enum), `GeneratedContextInterface` (the D5 marker every generated
  `{Domain}Context` implements), `DomainKernelInterface` (15 methods incl.
  `container()`, `eventListenerRegistry()`, `messaging()`, `auth()`, `http()` and `mail()`).
- **This package keeps exactly two things:** the immutable `DomainKernel`
  (implements `DomainKernelInterface`) and an optional ENV-driven packer
  (`Bootstrap\BuildDomainKernelFromEnv`) that builds one. Generated domains
  take the `DomainKernel` via constructor injection — `new {Domain}($kernel)`
  — nothing extends anything in this package anymore.
- **No more static shared state.** `ServiceRegistry`'s first-write-wins
  sharing is gone (G11) — sharing services across domains is now explicit:
  pass the **same** `DomainKernel` instance to every domain facade that should
  share it; build a separate `DomainKernel` for a domain that needs its own.

## Env-Konfiguration (2026-08, R1–R4) — what changed since v1

Second breaking wave, tracked in `docs/env-konfiguration/` (repo
`devops/jardis-app-template`, the run's home):

- **`domainRoot()` → `projectRoot()` rename** (contracts v2.0.0). The packer
  takes the **project root**, and since v2.4.0 that root is also where the ONE
  `.env` lives (see the v2.4.0 section below). `DomainKernel::projectRoot()`
  returns exactly the path passed to the packer's `__invoke()`.
- **Dead `$_ENV` fallback removed.** `DomainKernel::env()` and the packer's
  internal `$envGet` closure read **only** the private ENV loaded from files
  — no lowercase-key lookup against the process environment (it never
  matched Docker's UPPERCASE `environment:` entries anyway). An explicitly
  empty value (`KEY=`) is treated as "missing" (`null`), uniformly, in both
  places.
- **Bool ENV values are normalized, not string-compared.** `NormalizeEnvBool`
  is the one shared unit every boolean-shaped Handler ENV key goes through
  (`bool`/`0`/`1`/`filter_var(FILTER_NULL_ON_FAILURE)`); an unparsable value
  (e.g. `HTTP_VERIFY_SSL=maybe`) throws `InvalidEnvConfigurationException`
  instead of silently degrading. Fixes a prior security bug where
  `HTTP_VERIFY_SSL=true` (the string, cast to `bool(true)`) compared `===
  'true'` and disabled SSL verification.
- **Invalid config never degrades.** A typo in
  `DB_POOL_LOAD_BALANCING_STRATEGY` or an unreachable **configured** Redis host
  fails loudly with `InvalidEnvConfigurationException` (missing config still
  degrades to `null`, that distinction is intentional). `BuildConnectionFromEnv`
  has no `catch (\Throwable)` and no plain-PDO fallback any more (Entscheid O23
  2026-10-16): a configured database without `jardisadapter/dbconnection` (^1.4)
  throws `InvalidEnvConfigurationException` while building, without I/O.
- **Credential-shaped ENV keys survive the cast chain as strings.**
  `Bootstrap\Data\CredentialEnvKeySuffixes` (`_PASSWORD`, `_USER`, `_SECRET`,
  `_TOKEN`) are registered via `DotEnv::addRawKeys()` before `loadPrivate()`
  — `DB_PASSWORD=false`/`=123456` reach the handlers as the literal string,
  not a cast `bool`/`int`.
- **`messaging()` accessor, real (not just container-reachable).** New
  12th method on `DomainKernelInterface`; the packer's
  `Bootstrap\Handler\BuildMessagingFromEnv` bootstraps it from
  canonical ENV keys (no connection opened while building) (see dedicated section below) — see the
  G11-accessor rule in `[[domainkernel-ist-die-infrastrukturflaeche]]`
  (wissensbasis): a named accessor exists exactly when the kernel itself
  bootstraps the service from canonical ENV keys.
