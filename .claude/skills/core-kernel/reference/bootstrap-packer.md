#### Continuation BOOTSTRAP-PACKER — behaviour, fan-out and optional adapters

- **One invokable class**,
  `__invoke(string $projectRoot, ?string $envContent = null): DomainKernel` —
  no `static fromEnv()` (User-Entscheid, D4). `$kernel->projectRoot()` returns
  exactly the project root passed in.
- **ENV cascade** via `JardisSupport\DotEnv\DotEnv::loadPrivate()` (file mode)
  or `loadPrivateFromString()` (string mode) — the same `load()`/`load?()`
  cascade every other Jardis config file understands. Template:
  `docs/.env.example`. Credential-shaped keys
  (`Bootstrap\Data\CredentialEnvKeySuffixes`) are registered as DotEnv raw
  keys before loading, so `DB_PASSWORD=false`/`=123456` reach the handlers as
  literal strings, not cast `bool`/`int`.
- **Redis fan-out (D4):** one Redis connection, built once, feeds both the
  cache `redis` layer and the logger `redis` handler — no duplicated wiring,
  no Redis-specific knowledge in the orchestrator body. `messaging()`'s Redis
  transport builds its **own** separate connections (publisher + consumer) —
  never shares the cache/logger client, a blocking consumer connection must
  not be reused. The **writer PDO fans out the same way**: handed out once as a
  memoizing provider (`ProvideWriterPdo`), it feeds both the optional `db`
  cache layer and the `database` messaging transport — one stack, one
  database. Redis likewise travels as a provider (`BuildRedisFromEnv` returns `?Closure(): Redis`).
- **Every adapter is optional** (composer `suggest`, not required):
  `jardisadapter/{cache,dbconnection,eventdispatcher,filesystem,http,logger,mailer,messaging}` (and `jardissupport/auth` for `auth()`).
  Each Handler closure degrades to `null` via `class_exists()` guards when its
  adapter is missing or its ENV is unconfigured — nothing throws for a missing
  optional service. **Exceptions:** `DB_*` configured (`DB_DRIVER=sqlite` or
  `DB_HOST`) while `jardisadapter/dbconnection` is missing throws
  `InvalidEnvConfigurationException` while building; and a value that IS configured but invalid
  (bad bool, bad pool strategy, unreachable configured Redis host at its first use, invalid
  `MESSAGING_TRANSPORT`) throws `InvalidEnvConfigurationException` — "not
  configured" and "misconfigured" are deliberately different outcomes.
- **Container wiring is out of scope** (G6, "kein Problem, kein Pattern") —
  the packed kernel's `container()` is the bare `Factory` fallback. Need a
  custom PSR-11 container? Build a `DomainKernel` directly instead of going
  through the packer.
