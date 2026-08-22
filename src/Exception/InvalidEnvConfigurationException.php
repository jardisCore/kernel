<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Exception;

use RuntimeException;

/**
 * A configured ENV value is present but invalid (unparsable, enum mismatch,
 * out-of-range) or the service it configures could not be reached.
 *
 * Both cases share one vehicle so a bootstrap caller can catch a single type
 * for "boot failed because the config is wrong" — distinct from the
 * null-degradation that applies when a key is simply absent or empty.
 */
final class InvalidEnvConfigurationException extends RuntimeException
{
}
