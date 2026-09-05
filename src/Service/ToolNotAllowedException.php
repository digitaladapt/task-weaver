<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

/**
 * Thrown when a tool call is denied — unknown tool, tag mismatch, or the
 * tool's host server is disabled. Maps to the 403 error contract.
 */
final class ToolNotAllowedException extends RuntimeException
{
}
