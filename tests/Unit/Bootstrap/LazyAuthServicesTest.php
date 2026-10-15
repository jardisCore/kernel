<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Unit\Bootstrap;

use JardisCore\Kernel\Bootstrap\LazyAuthServices;
use JardisSupport\Contract\Auth\AuthServicesInterface;
use JardisSupport\Contract\Auth\PasswordHasherInterface;
use JardisSupport\Contract\Auth\SessionManagerInterface;
use JardisSupport\Contract\Auth\TokenIssuerInterface;
use JardisSupport\Contract\Auth\TokenRedeemerInterface;
use PHPUnit\Framework\TestCase;

final class LazyAuthServicesTest extends TestCase
{
    public function testNothingIsBuiltUntilAServiceIsUsed(): void
    {
        $builds = 0;
        $lazy = new LazyAuthServices(function () use (&$builds): AuthServicesInterface {
            $builds++;

            return $this->createMock(AuthServicesInterface::class);
        });

        self::assertSame(0, $builds);
        self::assertInstanceOf(AuthServicesInterface::class, $lazy);
    }

    public function testDelegatesAllFourServicesAndBuildsOnce(): void
    {
        $sessions = $this->createMock(SessionManagerInterface::class);
        $issuer = $this->createMock(TokenIssuerInterface::class);
        $redeemer = $this->createMock(TokenRedeemerInterface::class);
        $hasher = $this->createMock(PasswordHasherInterface::class);

        $inner = $this->createMock(AuthServicesInterface::class);
        $inner->method('sessions')->willReturn($sessions);
        $inner->method('tokenIssuer')->willReturn($issuer);
        $inner->method('tokenRedeemer')->willReturn($redeemer);
        $inner->method('hasher')->willReturn($hasher);

        $builds = 0;
        $lazy = new LazyAuthServices(function () use (&$builds, $inner): AuthServicesInterface {
            $builds++;

            return $inner;
        });

        self::assertSame($sessions, $lazy->sessions());
        self::assertSame($issuer, $lazy->tokenIssuer());
        self::assertSame($redeemer, $lazy->tokenRedeemer());
        self::assertSame($hasher, $lazy->hasher());
        self::assertSame(1, $builds);
    }
}
