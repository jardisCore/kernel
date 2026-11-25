---
name: core-kernel
description: jardiscore/kernel v2 - the immutable DomainKernel plus the optional ENV packer BuildDomainKernelFromEnv (one project-root .env + cascade). Use when building a DomainKernel, configuring .env/APP_SECRET_KEY/AUTH_TOKEN_KEY, or touching eventListenerRegistry, messaging(), auth(), http(), mail().
user-invocable: false
zone: post-active
persona: C
prerequisites: [foundation-architecture, foundation-patterns]
next: [generated-code-extend, core-app, support-response]
---

This skill is an index; the detail lives in `reference/`, one leaf per topic. Read exactly the leaf whose question matches your task, not all of them.
The architecture sketch, the `DomainKernel` constructor and sharing, the packer's call form and the rules that apply to every change stay in this index.

## Triggers

jardiscore/kernel v2 - the immutable DomainKernel (14 nullable/typed accessors + container(), incl. eventListenerRegistry, messaging, auth, http, mail) plus the optional Bootstrap-Packer BuildDomainKernelFromEnv (projectRoot-based: ONE `<projectRoot>/.env` plus its cascade, or an `.env`-formatted string; process environment always wins; secret key from APP_SECRET_KEY or `<projectRoot>/support/secret.key`; auth() from AUTH_TOKEN_KEY). DomainApp/BoundedContext/ServiceRegistry/Response pipeline were removed (Kernel-Entkopplung 2026-07) - the generated {Domain}Context now carries handle()/context()/resource()/payload()/version()/result(). TRIGGER: DomainKernel, DomainKernelInterface, projectRoot, BuildDomainKernelFromEnv, Bootstrap-Packer, eventListenerRegistry, messaging(), auth(), http(), mail(), HttpServiceInterface, MailServiceInterface, AUTH_TOKEN_KEY, .env im Root, envContent string mode, APP_SECRET_KEY, secret.key, InvalidEnvConfigurationException. Formerly (now generated, not in this package): DomainApp, BoundedContext, ServiceRegistry, SharedRegistry, ContextResponse, DomainResponse.

# KERNEL_COMPONENT_SKILL
> `jardiscore/kernel` v2 | NS: `JardisCore\Kernel` | PHP 8.2+

## Navigation

Navigation: one leaf per topic under `reference/`. Pick the row whose question fits the task.

| Question the leaf answers | Leaf |
| --- | --- |
| What changed since v1 — Kernel-Entkopplung (2026-07) and Env-Konfiguration (2026-08, R1-R4): deleted classes, `projectRoot()`, bool normalization, invalid config, credential keys, `messaging()` accessor? | reference/changes-since-v1.md |
| Which classes does the package contain, which are deleted, what does the generated side own, and what are the composer dependencies? | reference/classes-and-dependencies.md |
| How does the one `.env` in the root work — string input, process environment, secret key chain (`APP_SECRET_KEY`, `secret.key`), unresolved `secret(...)`, the `.env.example` template, handler units? | reference/env-file-and-secrets.md |
| What exactly does `BuildDomainKernelFromEnv` do — invokable form, ENV cascade, Redis/PDO fan-out, optional adapters, container wiring? | reference/bootstrap-packer.md |
| How is `messaging()` configured — canonical ENV keys per transport (kafka, rabbitmq, redis, database), eager vs. lazy? | reference/messaging.md |
| What does "everything is lazy" mean for the database, SQLite, Redis, the logger and messaging — and which exception appears at which first access? | reference/lazy-bootstrap-rule.md |
| How do domain code and fakes use `http()` and `mail()` (kernel 2.9.0), and how does the packer fill them? | reference/http-and-mail.md |
| How is `auth()` built from `AUTH_TOKEN_KEY` (kernel 2.6.0) — key format, token store, laziness? | reference/auth-from-env.md |

## ARCHITECTURE

```
Bootstrap\BuildDomainKernelFromEnv (optional ENV packer, one invokable class)
    → packs
DomainKernel implements DomainKernelInterface (immutable,
                                                constructor injection, 15 methods)
    ↓ passed to
new {Domain}($kernel)               ← generated Domain facade (final, JardisCore-free)
    ↓ new {BC}($kernel)              ← generated BC facade, extends {Domain}Context
{Domain}Context                     ← GENERATED per domain (generated-code-extend skill)
    handle()/context() (Kernel-Naht, protected) · resource()/payload()/version()/result()
    ↓ result()
ContextResponseInterface → jardissupport/response (TransformResponse, ProjectOutcome) → DomainResponseInterface
```

Everything below the `DomainKernel` line is generated per domain by the
Jardis Builder, not provided by this package.

## DOMAINKERNEL

```php
use JardisCore\Kernel\DomainKernel;

$kernel = new DomainKernel(
    projectRoot: '/path/to/project',     // required — the git-clone target; its .env is what the packer reads
    container: $factory,                 // ?ContainerInterface
    cache: $cache,                       // ?CacheInterface
    logger: $logger,                     // ?LoggerInterface
    eventDispatcher: $dispatcher,        // ?EventDispatcherInterface
    eventListenerRegistry: $registry,    // ?EventListenerRegistryInterface — Kernel-Entkopplung
    httpClient: $client,                 // ?ClientInterface
    connection: $pool,                   // ConnectionPoolInterface|PDO|null
    mailer: $mailer,                     // ?MailerInterface
    filesystem: $filesystemService,      // ?FilesystemServiceInterface
    env: ['db_host' => 'localhost'],     // array — private ENV, file-only (no $_ENV fallback)
    messaging: $messagingService,        // ?MessagingServiceInterface
    auth: $authServices,                 // ?AuthServicesInterface
    http: $httpService,                  // ?HttpServiceInterface — kernel 2.9.0 (contracts >= 2.19)
    mail: $mailService,                  // ?MailServiceInterface — last constructor param
);

$ecommerce = new Ecommerce($kernel);     // {Domain} facade, generated by Jardis — no extends
```

| Method | Return | Note |
|--------|--------|------|
| `projectRoot()` | `string` | renamed from `domainRoot()` (env-konfiguration R2/R3) |
| `env(string $key)` | `mixed` | case-insensitive, stored lowercase (`array_change_key_case`); file-only — no `$_ENV` fallback; explicitly empty (`KEY=`) reads as `null` |
| `container()` | `Factory` | always wraps the injected container (not just `ContainerInterface`) |
| `cache()` | `?CacheInterface` | |
| `logger()` | `?LoggerInterface` | |
| `eventDispatcher()` | `?EventDispatcherInterface` | |
| `eventListenerRegistry()` | `?EventListenerRegistryInterface` | **added with Kernel-Entkopplung (D3)** — paired with `eventDispatcher()`, same underlying `ListenerProvider` instance. A generated `{Agg}EventRouter` self-registers on it from the Domain facade's constructor; without a registry, event routing simply stays inactive — no error |
| `httpClient()` | `?ClientInterface` | |
| `dbConnection()` | `ConnectionPoolInterface\|PDO\|null` | |
| `mailer()` | `?MailerInterface` | |
| `filesystem()` | `?FilesystemServiceInterface` | |
| `messaging()` | `?MessagingServiceInterface` | **added with env-konfiguration R4** — publish/consume across kafka/rabbitmq/redis, see dedicated section below |
| `auth()` | `?AuthServicesInterface` | **added with kernel 2.6.0 (contracts >= 2.11)** — `sessions()` / `tokenIssuer()` / `tokenRedeemer()` / `hasher()` from `jardissupport/auth`; packer builds it from `AUTH_TOKEN_KEY`, see "Auth from ENV" below |
| `http()` | `?HttpServiceInterface` | **added with kernel 2.9.0 (contracts >= 2.19)** — ready-made `get`/`delete`/`head`/`post`/`put`/`patch` (JSON bodies) for domain code, no adapter import; see "http() and mail()" below |
| `mail()` | `?MailServiceInterface` | **added with kernel 2.9.0 (contracts >= 2.19)** — one ready-made `sendMail($to, $subject, $text, $html, $options)`; see "http() and mail()" below |

`DomainKernel` builds nothing and reads no ENV itself — a pure, immutable
consumer. All 14 services come via constructor; there is no lazy bootstrap,
no `kernel()` hook (that was `DomainApp`'s job — gone).

### Multi-domain sharing is still explicit

```php
$kernel = (new BuildDomainKernelFromEnv())(__DIR__);   // project root; reads <projectRoot>/.env

$ecommerce = new Ecommerce($kernel);   // same DomainKernel instance
$billing   = new Billing($kernel);     // same DomainKernel instance -> same connection, cache, ...
```

A domain that needs its own services builds its own `DomainKernel` instead of
sharing one — there is no static registry to fall back to anymore.

## BOOTSTRAP-PACKER — `BuildDomainKernelFromEnv`

```php
use JardisCore\Kernel\Bootstrap\BuildDomainKernelFromEnv;

$packer = new BuildDomainKernelFromEnv();
$kernel = $packer(__DIR__);              // project root; reads <projectRoot>/.env (+ cascade)
$kernel = $packer(__DIR__, $payload);    // .env-formatted string instead of the file
$kernel = $packer(__DIR__, '');          // dateless: everything from the process environment

$ecommerce = new Ecommerce($kernel);
```

## RULES
- `DomainKernel` is purely immutable — builds nothing, only consumes. All 14
  services are constructor-injected; there is no post-construction mutation.
- No static shared state anywhere in this package (the former
  `ServiceRegistry` is gone) — sharing across domains means passing the same
  `DomainKernel` instance; isolation means building separate instances.
- `DomainKernel` (+ the contract interfaces it implements) stays adapter-free
  — only `jardissupport/contracts` + PSR interfaces. Adapter imports
  (`jardisadapter/*`) are legitimate **only** inside the `Bootstrap\`
  sub-namespace (Application wiring, not Domain code) — see the
  Constitutional Note in the package README.
- `eventListenerRegistry()` and `eventDispatcher()` always come from the
  **same** underlying provider when built via the packer (D3) — do not wire
  them from two different providers by hand unless you specifically want
  disjoint listener sets.
- **Bool-shaped ENV values go through `NormalizeEnvBool`, never a raw `===
  'true'` string compare** — the packer already applies it to every boolean
  Handler key; an unparsable value throws instead of degrading.
- **A configured-but-invalid value throws `InvalidEnvConfigurationException`;
  only an absent one degrades to `null`.** Do not add a new Handler that
  silently swallows a validation failure into the PDO/degraded-`null` path.
- Never reach for `DomainApp`, `BoundedContext`, or `ServiceRegistry` in new
  code — they no longer exist in this package. A generated `{Domain}Context`
  already provides the equivalent surface; consult `generated-code-extend`.
