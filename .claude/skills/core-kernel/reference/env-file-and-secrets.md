## Eine `.env` im Root (v2.4.0) — what changed

- **One file, one place.** The packer reads `<projectRoot>/.env` plus DotEnv's
  cascade (`.env` → `.env.local` → `.env.{APP_ENV}`, plus any `load()`/
  `load?()` include). There is no `config/` layer any more, and the packer
  **never creates a directory**. A project root without a `.env` is "nothing
  configured": every service `null`, no error.
- **String input, exclusive against the file.**
  `__invoke(string $projectRoot, ?string $envContent = null)`. Pass an
  `.env`-formatted string (e.g. a secrets-manager payload) and the file is not
  read at all, not even per key. **`''` is a valid input** — the dateless
  container, where every value comes from the process environment. The project
  root still matters in string mode: the `support/secret.key` fallback resolves against it. `KEY_FILE=` values are read from disk only when the path is absolute (`jardissupport/dotenv` >= 1.6); a relative `_FILE` value stays a plain string — `COMPOSE_FILE`, `NGINX_INDEX_FILE` and the like are names, not secret mounts.
- **The process environment always wins** (jardissupport/dotenv >= 1.4.0,
  12-factor III) — in both modes, over file and string alike. Note for tests:
  the phpcli image exports `APP_ENV=dev`, which therefore beats a fixture's own
  `APP_ENV`; isolate it (save/restore `APP_ENV`, `APP_SECRET_KEY`,
  `JARDIS_DOTENV_VARS`) or the cascade picks a different overlay than intended.
- **Secret key chain (two steps, in this order).**
  1. `APP_SECRET_KEY` in the **process environment** → `EnvKeyProvider`.
  2. `<projectRoot>/support/secret.key` → `FileKeyProvider`.
  3. Neither → no secret resolution.
  ⚠️ **Henne-Ei:** the master key belongs in the process environment and
  **never in a `.env` file** — a key written into the file would have to be
  read by the very load it is meant to unlock, and it would sit in
  `$kernel->env()` in plaintext. `docs/.env.example` therefore carries
  `APP_SECRET_KEY` only as a comment. The key source is independent of the
  value source: a key file applies to string input too.
- **Unresolved `secret(...)` is loud (O8).** With no key at all, the packer
  does **not** pass the cipher on as a value: `AssertNoUnresolvedSecret` throws
  `JardisCore\Kernel\Exception\InvalidEnvConfigurationException` naming the ENV
  key — **never the value** — before any adapter is built. (Before v2.4.0 the
  cipher reached the handlers verbatim; that is the one behaviour change.)
- **S4' — what may be encrypted.** `secret(...)` is for keys only the kernel
  reads. A key that Docker Compose, `make` or CI also consumes must stay
  plaintext: those readers pass the marker through verbatim, they do not
  decrypt.
- **Template:** `docs/.env.example` — ONE file in eight blocks
  (`# === app ===`, `database`, `redis`, `cache`, `logger`, `http`, `mail`,
  `messaging`). The block syntax is a **contract** the tooling reads (Builder
  runtime catalogue, App-Template parity check), pinned by
  `tests/Unit/Docs/EnvExampleContractTest.php`.
- **Handler units** (all `Bootstrap\Handler\`): `LoadEnvForKernel` (owns the
  string-vs-file decision, wires raw keys + secret handler once),
  `NormalizeEnvKeys`, `ReadEnvValue`, `ReadProcessEnv`,
  `ResolveSecretKeyProvider`, `BuildSecretHandler`, `AssertNoUnresolvedSecret`.
  Their two v2.3 predecessors (the fixed-config-path loader and the
  key-file-only secret builder) are deleted — see CHANGELOG v2.4.0.
