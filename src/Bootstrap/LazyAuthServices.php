<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Bootstrap;

use Closure;
use JardisSupport\Contract\Auth\AuthServicesInterface;
use JardisSupport\Contract\Auth\PasswordHasherInterface;
use JardisSupport\Contract\Auth\SessionManagerInterface;
use JardisSupport\Contract\Auth\TokenIssuerInterface;
use JardisSupport\Contract\Auth\TokenRedeemerInterface;

/**
 * Defers building the auth services until a service is first used (Lazy
 * Initialization).
 *
 * The auth token store lives on the domain's database connection. Resolving
 * that connection can open it (a pool health-checks its writer on first
 * access), so a request that never logs in must not pay for it: the packer
 * hands the kernel this proxy, and neither `$kernel->auth()` nor a null check
 * on it touches the database — only `sessions()` / `tokenIssuer()` /
 * `tokenRedeemer()` / `hasher()` do, once.
 */
final class LazyAuthServices implements AuthServicesInterface
{
    private ?AuthServicesInterface $services = null;

    /** @param Closure(): AuthServicesInterface $build */
    public function __construct(private readonly Closure $build)
    {
    }

    public function sessions(): SessionManagerInterface
    {
        return $this->services()->sessions();
    }

    public function tokenIssuer(): TokenIssuerInterface
    {
        return $this->services()->tokenIssuer();
    }

    public function tokenRedeemer(): TokenRedeemerInterface
    {
        return $this->services()->tokenRedeemer();
    }

    public function hasher(): PasswordHasherInterface
    {
        return $this->services()->hasher();
    }

    private function services(): AuthServicesInterface
    {
        return $this->services ??= ($this->build)();
    }
}
