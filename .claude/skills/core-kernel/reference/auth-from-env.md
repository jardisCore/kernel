## Auth from ENV (v2.6.0)

`auth()` returns `?AuthServicesInterface` (contracts >= 2.11, implemented by
`jardissupport/auth` >= 1.6 `AuthServices`): sessions (signed access token +
opaque refresh token), token issuer/redeemer, password hasher.

- **Key:** `AUTH_TOKEN_KEY` = base64 of 32 raw bytes (same decode the generated
  `middleware.php` applies). **Without the key, or without `jardissupport/auth`
  installed, `auth()` is `null`** — nothing throws. A key that is set but not
  base64 / not 32 bytes, or set without a configured database, throws
  `InvalidEnvConfigurationException` naming `AUTH_TOKEN_KEY`.
- **Store:** `DbTokenStore` on the SAME connection as `dbConnection()` — a pool
  contributes its writer (via the packer SQLite is a pool too); a bare PDO —
  only in a hand-built `DomainKernel` — is wrapped via
  `jardisadapter/dbconnection` `ConnectionFactory::fromPdo(manageLifecycle: false)`
  (the bridge the messaging transport uses too). The token table DDL ships with
  the auth package (`resources/sql/<driver>.sql`), applied once per project.
- **Lazy:** the packer returns `LazyAuthServices`; holding or null-checking
  `$kernel->auth()` opens no connection. The first service call resolves the
  writer and builds the store.
- Rotating the key invalidates every issued access token. Put the key in the
  process environment or the project `.env` (no `secret(...)` needed, but fine).
