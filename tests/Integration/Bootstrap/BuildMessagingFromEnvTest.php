<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Integration\Bootstrap;

use JardisAdapter\Messaging\Connection\KafkaConnection;
use JardisAdapter\Messaging\Factory\ConnectionFactory;
use JardisAdapter\Messaging\Factory\ConsumerFactory;
use JardisAdapter\Messaging\MessageConsumer;
use JardisAdapter\Messaging\MessagePublisher;
use JardisCore\Kernel\Bootstrap\BuildDomainKernelFromEnv;
use JardisCore\Kernel\Exception\InvalidEnvConfigurationException;
use JardisSupport\Contract\Messaging\MessageHandlerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

/**
 * AK4.1–AK4.5 (PLAN.md §P4) — `DomainKernel::messaging()` through a REAL
 * DotEnv cascade against real Docker brokers (`support/docker-compose.yml`
 * services `kernel-test-redis`/`kernel-test-rabbitmq`/`kernel-test-kafka`,
 * `make start` before this suite, `make stop` after — development.md §5).
 *
 * Per-transport roundtrip strategy — all sequential, no forked/spawned
 * process anywhere (an earlier Redis Pub/Sub design needed one; switched to
 * Streams specifically to avoid it, see `BuildMessagingFromEnv`'s docblock):
 * - **Kafka**: `KafkaConsumer::consume()` polls with `max_empty_polls`
 *   (`auto.offset.reset=earliest` on a fresh consumer group picks up a
 *   message published just before) — publish, then poll once for real.
 * - **RabbitMQ**: `ConsumerFactory::rabbitMq()` binds the queue only inside
 *   `consume()` (`RabbitMqConsumer::setupQueue()`), so a plain
 *   publish-then-consume would lose the message (unbound topic exchange
 *   drops it). A first, message-less `consume()` call (`max_empty_polls: 1`)
 *   declares+binds the durable queue ahead of the publish; the second
 *   `consume()` call then reads what was published in between.
 * - **Redis**: Streams (`useStreams: true`, `BuildMessagingFromEnv`'s own
 *   choice — not Pub/Sub) persist every message regardless of subscriber
 *   timing, so a plain publish-then-consume with `start_id: '0'` reads
 *   everything from the beginning — no pre-declared consumer needed.
 *
 * AK4.2's "adapter not installed → null" sub-case is not covered by a
 * dedicated test — same documented gap as `BuildCacheFromEnvTest.php` /
 * `BuildConnectionPoolConfigFromEnv`'s `class_exists()` branch (both
 * `@codeCoverageIgnore`d, no test attempts them either): jardisadapter/messaging
 * is a `require-dev` dependency of this very test suite, so "not installed"
 * is structurally unreachable here, not a real path in this repo's QA. The
 * branch is marked `@codeCoverageIgnoreStart/End` in
 * `BuildMessagingFromEnv::__invoke()` for the same reason.
 */
final class BuildMessagingFromEnvTest extends TestCase
{
    private function fixturePath(string $name): string
    {
        return __DIR__ . '/../../Fixtures/ProjectRoot/' . $name;
    }

    // -- AK4.2: error rules ---------------------------------------------

    public function testMissingTransportReturnsNull(): void
    {
        $kernel = (new BuildDomainKernelFromEnv())($this->fixturePath('UnknownForeignKey'));

        self::assertNull($kernel->messaging());
    }

    public function testInvalidTransportThrows(): void
    {
        $this->expectException(InvalidEnvConfigurationException::class);

        (new BuildDomainKernelFromEnv())($this->fixturePath('MessagingInvalidTransport'));
    }

    /**
     * A configured transport with a missing required field is "invalid"
     * (G5 rule 2), not "unconfigured" — and must fail at boot (packing the
     * kernel), not lazily on the first `publish()`/`consume()` call. Guards
     * against the "dead try/catch" class of bug a Verifier found: connection
     * construction (and therefore `ConnectionConfig`'s own validation) used
     * to happen only inside a lazy closure, so an empty `KAFKA_BROKERS`
     * threw an unwrapped `InvalidArgumentException` from deep inside the
     * adapter on first `publish()` instead of a clear
     * `InvalidEnvConfigurationException` at boot.
     */
    public function testKafkaMissingBrokersThrowsAtBoot(): void
    {
        $this->expectException(InvalidEnvConfigurationException::class);
        $this->expectExceptionMessageMatches('/KAFKA_BROKERS/');

        (new BuildDomainKernelFromEnv())($this->fixturePath('MessagingKafkaMissingBrokers'));
    }

    public function testRabbitMqMissingHostThrowsAtBoot(): void
    {
        $this->expectException(InvalidEnvConfigurationException::class);
        $this->expectExceptionMessageMatches('/RABBITMQ_HOST/');

        (new BuildDomainKernelFromEnv())($this->fixturePath('MessagingRabbitMqMissingHost'));
    }

    public function testRedisMissingHostThrowsAtBoot(): void
    {
        $this->expectException(InvalidEnvConfigurationException::class);
        $this->expectExceptionMessageMatches('/REDIS_HOST/');

        (new BuildDomainKernelFromEnv())($this->fixturePath('MessagingRedisMissingHost'));
    }

    // -- AK4.3: KAFKA_BROKERS reaches the factory unchanged --------------

    public function testKafkaBrokersReachFactoryUnchanged(): void
    {
        $kernel = (new BuildDomainKernelFromEnv())($this->fixturePath('MessagingKafka'));
        $messaging = $kernel->messaging();
        self::assertNotNull($messaging);

        $publisher = $messaging->getPublisher();
        self::assertInstanceOf(MessagePublisher::class, $publisher);

        $publishers = (new ReflectionProperty(MessagePublisher::class, 'publishers'))->getValue($publisher);
        $connection = $publishers[0]->getConnection();
        self::assertInstanceOf(KafkaConnection::class, $connection);

        $config = (new ReflectionProperty(KafkaConnection::class, 'config'))->getValue($connection);
        // The fixture's KAFKA_BROKERS is a single host:port pair — the
        // canonical multi-broker form (host1:port1,host2:port2) is the same
        // string shape, unsplit; ConnectionConfig->host carries it verbatim
        // either way (G13 — no own broker-list parsing in the Handler).
        self::assertSame('kernel-test-kafka:9092', $config->host);
    }

    // -- AK4.1 + AK4.3: Kafka publish, raw-consumer read ------------------

    public function testKafkaPublishRoundtrip(): void
    {
        $topic = 'kernel-test.kafka.' . uniqid();
        $kernel = (new BuildDomainKernelFromEnv())($this->fixturePath('MessagingKafka'));
        $messaging = $kernel->messaging();
        self::assertNotNull($messaging);

        self::assertTrue($messaging->publish($topic, 'hello-kernel-kafka'));

        $received = null;
        $handler = $this->captureHandler($received);

        $connectionFactory = new ConnectionFactory();
        $consumerFactory = new ConsumerFactory();
        $connection = $connectionFactory->kafkaConsumer('kernel-test-kafka:9092', 'kernel-test-group-' . uniqid());
        $consumer = new MessageConsumer($consumerFactory->kafka($connection));

        $consumer->consume($topic, $handler, ['timeout' => 2000, 'max_empty_polls' => 10]);

        self::assertSame('hello-kernel-kafka', $received);
    }

    // -- AK4.4: Kafka consuming via the kernel accessor is a documented G14 limit --

    public function testKafkaConsumeViaKernelThrowsClearRuntimeException(): void
    {
        $kernel = (new BuildDomainKernelFromEnv())($this->fixturePath('MessagingKafka'));
        $messaging = $kernel->messaging();
        self::assertNotNull($messaging);

        // Publish still works — only the consumer side is unsupported (G14).
        self::assertTrue($messaging->publish('kernel-test.kafka.g14.' . uniqid(), 'still publishable'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/consumer group/i');

        $messaging->consume('any-topic', $this->captureHandler($unused));
    }

    // -- AK4.1: RabbitMQ publish + consume roundtrip ----------------------

    public function testRabbitMqPublishRoundtrip(): void
    {
        $topic = 'kernel-test.rabbitmq.' . uniqid();
        $kernel = (new BuildDomainKernelFromEnv())($this->fixturePath('MessagingRabbitMq'));
        $messaging = $kernel->messaging();
        self::assertNotNull($messaging);

        // Declares + binds the durable queue (topic == queue name, see class
        // docblock) BEFORE anything is published — otherwise the message,
        // published to a topic exchange with no bound queue yet, is dropped.
        $messaging->consume($topic, $this->captureHandler($unused1), [
            'max_empty_polls' => 1,
            'timeout' => 0.2,
        ]);

        self::assertTrue($messaging->publish($topic, 'hello-kernel-rabbitmq'));

        $received = null;
        $messaging->consume($topic, $this->captureHandler($received), [
            'max_empty_polls' => 10,
            'timeout' => 0.2,
        ]);

        self::assertSame('hello-kernel-rabbitmq', $received);
    }

    // -- AK4.1: Redis publish + consume roundtrip (Streams) --------------

    public function testRedisPublishRoundtrip(): void
    {
        $topic = 'kernel-test.redis.' . uniqid();
        $kernel = (new BuildDomainKernelFromEnv())($this->fixturePath('MessagingRedis'));
        $messaging = $kernel->messaging();
        self::assertNotNull($messaging);

        // Streams persist the message regardless of subscriber timing — no
        // pre-declared consumer needed (unlike the RabbitMQ topic-exchange
        // case above), publish first is fine.
        self::assertTrue($messaging->publish($topic, 'hello-kernel-redis'));

        $received = null;
        $messaging->consume($topic, $this->captureHandler($received), [
            'start_id' => '0',
            'block' => 2000,
        ]);

        self::assertSame('hello-kernel-redis', $received);
    }

    // -- AK4.5: cache AND messaging share the same Redis simultaneously --

    public function testCacheAndMessagingShareTheSameRedisSimultaneously(): void
    {
        $kernel = (new BuildDomainKernelFromEnv())($this->fixturePath('MessagingRedisWithCache'));

        $cache = $kernel->cache();
        self::assertNotNull($cache);
        self::assertTrue($cache->set('kernel-test-ak4.5', 'cached'));
        self::assertSame('cached', $cache->get('kernel-test-ak4.5'));
        $cache->delete('kernel-test-ak4.5');

        $messaging = $kernel->messaging();
        self::assertNotNull($messaging);
        $topic = 'kernel-test.combo.' . uniqid();
        self::assertTrue($messaging->publish($topic, 'combo-message'));

        $received = null;
        $messaging->consume($topic, $this->captureHandler($received), ['start_id' => '0', 'block' => 2000]);
        self::assertSame('combo-message', $received);
    }

    /**
     * @param mixed $capturedInto Bound by reference; receives the first message's payload.
     */
    private function captureHandler(mixed &$capturedInto): MessageHandlerInterface
    {
        return new class (
            $capturedInto
        ) implements MessageHandlerInterface {
            public function __construct(private mixed &$captured)
            {
            }

            public function handle(string|array $message, array $metadata): bool
            {
                $this->captured = $message;

                return false;
            }
        };
    }
}
