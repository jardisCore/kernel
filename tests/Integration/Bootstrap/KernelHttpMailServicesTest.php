<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Integration\Bootstrap;

use JardisAdapter\Http\Config\ClientConfig;
use JardisAdapter\Http\HttpClient;
use JardisAdapter\Http\Message\Psr17Factory;
use JardisAdapter\Mailer\Config\SmtpConfig;
use JardisAdapter\Mailer\Data\Envelope;
use JardisAdapter\Mailer\Mailer;
use JardisCore\Kernel\Bootstrap\BuildDomainKernelFromEnv;
use JardisCore\Kernel\DomainKernel;
use JardisCore\Kernel\Tests\Fixtures\Domain\NotifyOrderPlaced;
use JardisCore\Kernel\Tests\Support\EnvIsolation;
use JardisSupport\Contract\Http\HttpServiceInterface;
use JardisSupport\Contract\Kernel\DomainKernelInterface;
use JardisSupport\Contract\Mailer\MailServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionClass;

/**
 * Acceptance of "Kernel liefert Dienste" (Rückbau P0.3): a body that knows only
 * `DomainKernelInterface` sends an HTTP request with data and a mail through
 * `http()` / `mail()` — without any import from the adapter namespace — and the
 * ENV packer hands over the very instance behind `httpClient()` / `mailer()`.
 *
 * No network: the HTTP client gets an answering transport function, the mailer
 * an in-memory transport.
 */
final class KernelHttpMailServicesTest extends TestCase
{
    use EnvIsolation;

    /** @var list<RequestInterface> */
    private array $requests = [];

    /** @var list<Envelope> */
    private array $envelopes = [];

    protected function setUp(): void
    {
        $this->saveProcessEnv();
        $this->requests = [];
        $this->envelopes = [];
    }

    protected function tearDown(): void
    {
        $this->restoreProcessEnv();
    }

    private function kernelWithServices(): DomainKernel
    {
        $psr17 = new Psr17Factory();
        $http = new HttpClient(
            $psr17,
            $psr17,
            $psr17,
            $psr17,
            new ClientConfig(),
            function (RequestInterface $request) use ($psr17) {
                $this->requests[] = $request;

                return $psr17->createResponse(202);
            },
        );
        $mailer = new Mailer(
            new SmtpConfig(host: 'localhost', fromAddress: 'default@example.test'),
            function (Envelope $envelope): void {
                $this->envelopes[] = $envelope;
            },
        );

        return new DomainKernel(projectRoot: '/app', httpClient: $http, mailer: $mailer, http: $http, mail: $mailer);
    }

    public function testBodyKnowingOnlyTheKernelContractSendsHttpRequestAndMail(): void
    {
        $kernel = $this->kernelWithServices();
        $body = new NotifyOrderPlaced();

        $response = $body($kernel, 'A-1001');

        self::assertSame(202, $response->getStatusCode());

        self::assertCount(1, $this->requests);
        $request = $this->requests[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://hooks.example.test/orders', (string) $request->getUri());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('kernel-test', $request->getHeaderLine('X-Source'));
        self::assertSame(['order' => 'A-1001', 'status' => 'placed'], json_decode((string) $request->getBody(), true));

        self::assertCount(1, $this->envelopes);
        self::assertSame(['buyer@example.test'], $this->envelopes[0]->recipients);
        self::assertSame('shop@example.test', $this->envelopes[0]->sender);
        self::assertStringContainsString('Subject: Order A-1001', $this->envelopes[0]->rawMessage);
    }

    public function testBodyReferencesNoAdapterNamespace(): void
    {
        $file = (new ReflectionClass(NotifyOrderPlaced::class))->getFileName();
        self::assertIsString($file);
        $source = (string) file_get_contents($file);

        self::assertDoesNotMatchRegularExpression('/^\s*use\s+JardisAdapter\\\\/m', $source);
        self::assertStringNotContainsString('JardisAdapter\\', $source);

        // The signature carries the kernel contract and PSR-7 only.
        $parameters = (new ReflectionClass(NotifyOrderPlaced::class))->getMethod('__invoke')->getParameters();
        self::assertSame(DomainKernelInterface::class, (string) $parameters[0]->getType());
    }

    public function testPackerHandsOverTheSameInstancesAsHttpClientAndMailer(): void
    {
        $kernel = (new BuildDomainKernelFromEnv())(
            sys_get_temp_dir(),
            "HTTP_TIMEOUT=5\nMAIL_HOST=smtp.example.test\n",
        );

        self::assertInstanceOf(HttpServiceInterface::class, $kernel->http());
        self::assertSame($kernel->httpClient(), $kernel->http());
        self::assertInstanceOf(MailServiceInterface::class, $kernel->mail());
        self::assertSame($kernel->mailer(), $kernel->mail());
    }

    public function testPackerWithoutMailHostHasNoMailButStillHttp(): void
    {
        $kernel = (new BuildDomainKernelFromEnv())(sys_get_temp_dir(), "HTTP_TIMEOUT=5\n");

        self::assertNull($kernel->mailer());
        self::assertNull($kernel->mail());
        self::assertSame($kernel->httpClient(), $kernel->http());
    }
}
