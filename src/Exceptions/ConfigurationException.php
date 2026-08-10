<?php

declare(strict_types=1);

namespace Authkit\Authkit\Exceptions;

use RuntimeException;

/**
 * A package feature was used without the config it cannot work without.
 * Thrown on first use, naming the missing key(s) — never a silent
 * infinite-401 loop or a raw WorkOS error surfacing three calls deep.
 */
final class ConfigurationException extends RuntimeException {}
