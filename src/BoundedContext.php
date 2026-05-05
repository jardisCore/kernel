<?php

declare(strict_types=1);

namespace JardisCore\Kernel;

use JardisCore\Kernel\Response\ContextResponse;
use JardisSupport\Contract\ClassVersion\ClassVersionInterface;
use JardisSupport\Contract\Kernel\BoundedContextInterface;
use JardisSupport\Contract\Kernel\ContextResponseInterface;
use JardisSupport\Contract\Kernel\DomainKernelInterface;
use JardisSupport\Factory\Factory;
use LogicException;
use Psr\Container\ContainerInterface;

/**
 * Base Bounded Context implementation.
 *
 * Resolves classes through Factory with optional ClassVersion support.
 * ClassVersion is discovered automatically from the Container — if registered,
 * versioned class resolution is active. If not, classes resolve by exact name.
 *
 * BoundedContext subclasses are instantiated with kernel, payload and version.
 * All other classes are instantiated via Container or Factory with parameters.
 *
 * Contract semantics for handle() and context() are defined on
 * BoundedContextInterface; this class only adds implementation details.
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
     * {@inheritDoc}
     */
    public function handle(string $className, mixed ...$parameters): mixed
    {
        return $this->resolve($className, $this->payload, $this->version, $parameters, requireBoundedContext: false);
    }

    /**
     * {@inheritDoc}
     *
     * Rejects non-BoundedContext targets via LogicException.
     */
    public function context(string $className, mixed $payload, string $version = ''): mixed
    {
        return $this->resolve($className, $payload, $version, [], requireBoundedContext: true);
    }

    /**
     * @param class-string $className
     * @param array<int|string, mixed> $parameters
     * @throws \Throwable
     */
    private function resolve(
        string $className,
        mixed $payload,
        string $version,
        array $parameters,
        bool $requireBoundedContext,
    ): mixed {
        try {
            $container = $this->resource()->container();
            $factory = $container instanceof Factory ? $container : new Factory($container);

            $resolved = $this->resolveClassName($container, $className, $version);

            // ClassVersion proxy short-circuit: $resolved is already an instance.
            if (is_object($resolved)) {
                return $resolved;
            }

            return match (true) {
                is_subclass_of($resolved, BoundedContextInterface::class)
                    => $factory->create($resolved, $this->domainKernel, $payload, $version, ...$parameters),
                $requireBoundedContext
                    => throw new LogicException(sprintf(
                        'context() requires a %s subclass; %s does not implement it.',
                        BoundedContextInterface::class,
                        $resolved,
                    )),
                !empty($parameters)
                    => $factory->create($resolved, ...$parameters),
                default
                    => $container->get($resolved),
            };
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
    private function resolveClassName(ContainerInterface $container, string $className, string $version): string|object
    {
        if (!$container->has(ClassVersionInterface::class)) {
            return $className;
        }

        /** @var ClassVersionInterface $classVersion */
        $classVersion = $container->get(ClassVersionInterface::class);
        $resolved = $classVersion($className, $version);

        if ($resolved === null) {
            return $className;
        }

        return $resolved;
    }
}
