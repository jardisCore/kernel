<?php

declare(strict_types=1);

namespace JardisCore\Domain;

use Exception;
use JardisPort\ClassVersion\ClassVersionInterface;
use JardisPort\Domain\BoundedContextInterface;
use JardisPort\Domain\ContextResultInterface;
use JardisPort\Domain\DomainKernelInterface;
use Psr\Container\ContainerInterface;

/**
 * Base Bounded Context.
 *
 * Class resolution via PSR-11 Container with optional ClassVersion support.
 * ClassVersion is discovered automatically from the Container — if registered,
 * versioned class resolution is active. If not, classes resolve by exact name.
 */
class BoundedContext implements BoundedContextInterface
{
    private DomainKernelInterface $domainKernel;
    private mixed $payload;
    private string $version;
    private ?ContextResultInterface $result = null;

    public function __construct(DomainKernelInterface $domainKernel, mixed $payload = null, string $version = '')
    {
        $this->domainKernel = $domainKernel;
        $this->payload = $payload;
        $this->version = $version;
    }

    /**
     * @template T
     * @param class-string<T> $className
     * @throws Exception
     * @return T|null
     */
    public function handle(string $className, mixed ...$parameters): mixed
    {
        try {
            $container = $this->resource()->container();
            if ($container === null) {
                throw new Exception('Container not available');
            }

            // ClassVersion: resolve class name (if registered in container)
            $resolved = $this->resolveClassName($container, $className);

            // If ClassVersion returns a ready object (Proxy)
            if (is_object($resolved)) {
                return $resolved;
            }

            return $container->get($resolved);
        } catch (Exception $e) {
            $logger = $this->resource()->logger();
            if ($logger !== null) {
                $logger->error($e->getMessage(), ['exception' => $e]);
            }
            throw $e;
        }
    }

    protected function resource(): DomainKernelInterface
    {
        return $this->domainKernel;
    }

    protected function payload(): mixed
    {
        return $this->payload;
    }

    protected function version(): string
    {
        return $this->version;
    }

    protected function result(): ContextResultInterface
    {
        if ($this->result === null) {
            $context = basename(str_replace('\\', '/', get_class($this)));
            $this->result = new ContextResult($context);
        }

        return $this->result;
    }

    /**
     * Resolves a class name through ClassVersion if available in the container.
     *
     * @param class-string $className
     * @return class-string|object Resolved class name or proxy object
     */
    private function resolveClassName(ContainerInterface $container, string $className): string|object
    {
        if (!$container->has(ClassVersionInterface::class)) {
            return $className;
        }

        /** @var ClassVersionInterface $classVersion */
        $classVersion = $container->get(ClassVersionInterface::class);
        $resolved = $classVersion($className, $this->version);

        if ($resolved === null) {
            return $className;
        }

        return $resolved;
    }
}
