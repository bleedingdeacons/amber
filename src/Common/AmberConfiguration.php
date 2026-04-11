<?php

declare(strict_types=1);

namespace Amber\Common;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Field Constants for Amber
 */
final class AmberConfiguration
{
    public const SERVICE_EXPIRE_MONTHS_WARNING = 6;

    private function __construct()
    {
    }
}
