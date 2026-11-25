## http() and mail() (v2.9.0)

Domain code (node/closure bodies, `{BC}/Service/`) reaches HTTP and mail through
the kernel contract only — **no `JardisAdapter\*` import**:

```php
$kernel->http()->post('https://hooks.example.test/orders', ['order' => 'A-1001'], ['X-Source' => 'shop']);
$kernel->mail()->sendMail('buyer@example.test', 'Order A-1001', 'Your order was placed.', null, ['from' => 'shop@example.test']);
```

- `http()` is `JardisSupport\Contract\Http\HttpServiceInterface`: `get`,
  `delete`, `head` (uri, headers) and `post`, `put`, `patch` (uri, data array
  encoded as JSON, headers); returns a PSR-7 response. 4xx/5xx is a normal
  response, only a failed transport throws `ClientExceptionInterface`.
- `mail()` is `JardisSupport\Contract\Mailer\MailServiceInterface`:
  `sendMail(string|list<string> $to, $subject, ?$text, ?$html, array $options)`;
  neither text nor HTML → `InvalidArgumentException`, transfer failure →
  `MailerExceptionInterface`.
- `httpClient()` (PSR-18, arbitrary requests) and `mailer()` (composed
  messages with attachments) stay as they are.
- **Packer:** no second build. The instance behind `httpClient()` / `mailer()` is
  passed as `http` / `mail` too when it implements the service contract
  (`jardisadapter/http` / `mailer` >= 1.1 — same object, `assertSame` holds),
  else `null`. composer `conflict` `<1.1` stops an old adapter from leaving the
  accessor silently `null`. No adapter or no `MAIL_HOST` → `null`.
- A hand-built `DomainKernel` takes the services via the new **last** named
  constructor params `http:` / `mail:`; `httpClient:` alone does not fill `http()`.
- Anyone implementing `DomainKernelInterface` (fakes) must add both methods.
