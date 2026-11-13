<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Fixtures\Domain;

use JardisSupport\Contract\Kernel\DomainKernelInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Stand-in for a domain body (node/closure body): it knows ONLY the kernel
 * contract — HTTP and mail are reached through `http()` / `mail()`, with
 * ready-made calls. It must stay free of any adapter-package reference; the
 * test that uses it reads this very file to prove that.
 */
final class NotifyOrderPlaced
{
    public function __invoke(DomainKernelInterface $kernel, string $orderNumber): ResponseInterface
    {
        $response = $kernel->http()?->post(
            'https://hooks.example.test/orders',
            ['order' => $orderNumber, 'status' => 'placed'],
            ['X-Source' => 'kernel-test'],
        );

        $kernel->mail()?->sendMail(
            'buyer@example.test',
            'Order ' . $orderNumber,
            'Your order was placed.',
            '<p>Your order was placed.</p>',
            ['from' => 'shop@example.test'],
        );

        if ($response === null) {
            throw new \LogicException('http() is not configured');
        }

        return $response;
    }
}
