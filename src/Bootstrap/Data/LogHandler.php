<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Bootstrap\Data;

/**
 * Available log handler types for the LOG_HANDLERS ENV configuration.
 *
 * Ported 1:1 from `jardiscore/foundation` (`JardisCore\Foundation\Data\LogHandler`,
 * Kernel-Entkopplung P2) into the Bootstrap-Packer sub-namespace.
 */
enum LogHandler: string
{
    case File = 'file';
    case Console = 'console';
    case ErrorLog = 'errorlog';
    case Syslog = 'syslog';
    case BrowserConsole = 'browserconsole';
    case Redis = 'redis';
    case Slack = 'slack';
    case Teams = 'teams';
    case Loki = 'loki';
    case Webhook = 'webhook';
    case Null = 'null';
}
