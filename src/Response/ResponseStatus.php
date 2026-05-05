<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Response;

/**
 * Status codes for domain responses.
 *
 * Inspired by HTTP status codes but domain-neutral.
 * Can be mapped 1:1 to HTTP codes by the API layer if needed.
 */
enum ResponseStatus: int
{
    case Success = 200;
    case Created = 201;
    case NoContent = 204;
    case ValidationError = 400;
    case Unauthorized = 401;
    case Forbidden = 403;
    case NotFound = 404;
    case Conflict = 409;
    case InternalError = 500;
}
