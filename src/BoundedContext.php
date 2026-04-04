<?php

declare(strict_types=1);

namespace JardisCore\Kernel;

use JardisCore\Kernel\Response\ContextResponse;
use JardisSupport\Contract\ClassVersion\ClassVersionInterface;
use JardisSupport\Contract\Kernel\BoundedContextInterface;
use JardisSupport\Contract\Kernel\ContextResponseInterface;
use JardisSupport\Contract\Kernel\DomainKernelInterface;
use JardisSupport\Factory\Factory;
use Psr\Container\ContainerInterface;

/**
 * Base Bounded Context.
 *
 * Class resolution via Factory with optional ClassVersion support.
 * ClassVersion is discovered automatically from the Container — if registered,
 * versioned class resolution is active. If not, classes resolve by exact name.
 *
 * BoundedContext subclasses are instantiated with kernel, payload and version.
 * All other classes are instantiated via Container or Factory with parameters.
 */
class BoundedContext implements BoundedContextInterface
{
    private DomainKernelInterface $domainKernel;
    private mixed $payload;
    private string $version;
    private ?ContextResponseInterface $result = null;

    public function __construct(DomainKernelInterface $domainKernel, mixed $payload = null, string $version = '')
    {
        $this->domainKernel = $domainKernel;
        $this->payload = $payload;
        $this->version = $version;
    }

    /**
     * Resolves and instantiates a class.
     *
     * BoundedContext subclasses receive kernel, payload and version automatically.
     * Other classes are instantiated via Container or Factory with parameters.
     *
     * @template T
     * @param class-string<T> $className
     * @throws \Throwable
     * @return T|null
     */
    public function handle(string $className, mixed ...$parameters): mixed
    {
        try {
            $container = $this->resource()->container();
            $factory = $container instanceof Factory ? $container : new Factory($container);

            // ClassVersion: resolve class name
            $resolved = $this->resolveClassName($container, $className);

            // If ClassVersion returns a ready object (Proxy)
            if (is_object($resolved)) {
                /** @phpstan-ignore return.type */
                return $resolved;
            }

            // BoundedContext subclasses: instantiate with kernel + payload + version
            if (is_subclass_of($resolved, BoundedContextInterface::class)) {
                /** @phpstan-ignore return.type */
                return $factory->create($resolved, $this->domainKernel, $this->payload, $this->version, ...$parameters);
            }

            // Other classes with parameters: instantiate via Factory
            if (!empty($parameters)) {
                /** @phpstan-ignore return.type */
                return $factory->create($resolved, ...$parameters);
            }

            // No parameters: resolve via Container (instances, backend, reflection)
            return $container->get($resolved);
        } catch (\Throwable $e) {
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

    protected function result(): ContextResponseInterface
    {
        if ($this->result === null) {
            $context = basename(str_replace('\\', '/', get_class($this)));
            $this->result = new ContextResponse($context);
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
