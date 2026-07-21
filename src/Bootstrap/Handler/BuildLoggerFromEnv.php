<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Bootstrap\Handler;

use Closure;
use JardisAdapter\Logger\LoggerBuilder;
use JardisCore\Kernel\Bootstrap\Data\LogHandler;
use Psr\Log\LoggerInterface;
use Redis;

/**
 * Builds a PSR-3 logger from ENV values.
 *
 * Requires jardisadapter/logger. Handler list defined by LOG_HANDLERS (comma-separated).
 * Each entry is handler:LEVEL, e.g. LOG_HANDLERS=file:ERROR,console:DEBUG
 * Level is optional — falls back to LOG_LEVEL.
 *
 * Ported 1:1 from `jardiscore/foundation` (`Handler\LoggerHandler`,
 * Kernel-Entkopplung P2). The Redis connection is part of the Bootstrap-Packer's
 * Redis fan-out (D4) — built once by `BuildRedisFromEnv` and shared with
 * `BuildCacheFromEnv`.
 */
final class BuildLoggerFromEnv
{
    /** @param Closure(string): mixed $env */
    public function __invoke(Closure $env, ?Redis $redis = null): ?LoggerInterface
    {
        if ($env('log_handlers') === null || !class_exists(LoggerBuilder::class)) {
            return null;
        }

        $context = (string) ($env('log_context') ?? 'app');
        $defaultLevel = (string) ($env('log_level') ?? 'INFO');
        $builder = new LoggerBuilder($context);

        $entries = array_map('trim', explode(',', (string) $env('log_handlers')));

        foreach ($entries as $entry) {
            [$name, $level] = $this->parseEntry($entry, $defaultLevel);

            $type = LogHandler::tryFrom($name);
            if ($type === null) {
                continue;
            }

            try {
                match ($type) {
                    LogHandler::File => $builder->addFile(
                        $level,
                        (string) ($env('log_file_path') ?? '/var/log/app.log'),
                    ),
                    LogHandler::Console => $builder->addConsole($level),
                    LogHandler::ErrorLog => $builder->addErrorLog($level),
                    LogHandler::Syslog => $builder->addSyslog($level),
                    LogHandler::BrowserConsole => $builder->addBrowserConsole($level),
                    LogHandler::Redis => $redis !== null ? $builder->addRedis($level, $redis) : null,
                    LogHandler::Slack => $this->addUrlHandler($env, 'log_slack_url', $builder, $type, $level),
                    LogHandler::Teams => $this->addUrlHandler($env, 'log_teams_url', $builder, $type, $level),
                    LogHandler::Loki => $this->addUrlHandler($env, 'log_loki_url', $builder, $type, $level),
                    LogHandler::Webhook => $this->addUrlHandler($env, 'log_webhook_url', $builder, $type, $level),
                    LogHandler::Null => $builder->addNull($level),
                };
            } catch (\Throwable) {
                // @codeCoverageIgnoreStart
                // Defensive safety net: none of the LoggerBuilder::add*() methods
                // validate eagerly in the installed adapter version, so this
                // branch cannot be forced without a broken/incompatible adapter build.
                continue;
                // @codeCoverageIgnoreEnd
            }
        }

        return $builder->getLogger();
    }

    /** @param Closure(string): mixed $env */
    private function addUrlHandler(
        Closure $env,
        string $envKey,
        LoggerBuilder $builder,
        LogHandler $type,
        string $level,
    ): void {
        $url = $env($envKey);
        if ($url === null || (string) $url === '') {
            return;
        }

        match ($type) {
            LogHandler::Slack => $builder->addSlack($level, (string) $url),
            LogHandler::Teams => $builder->addTeams($level, (string) $url),
            LogHandler::Loki => $builder->addLoki($level, (string) $url),
            LogHandler::Webhook => $builder->addWebhook($level, (string) $url),
            // @codeCoverageIgnoreStart
            // Unreachable by construction: addUrlHandler() is only ever called
            // for these four LogHandler cases (see the outer match above) —
            // kept as an exhaustive-match safety net, not a real call path.
            default => null,
            // @codeCoverageIgnoreEnd
        };
    }

    /** @return array{string, string} [name, level] */
    private function parseEntry(string $entry, string $defaultLevel): array
    {
        if (str_contains($entry, ':')) {
            [$name, $level] = explode(':', $entry, 2);
            return [trim($name), strtoupper(trim($level))];
        }

        return [trim($entry), $defaultLevel];
    }
}
