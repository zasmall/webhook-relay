<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Event types are dot-separated lowercase segments, e.g. "invoice.paid".
 * Endpoints subscribe with patterns:
 *
 * - an exact type: "invoice.paid"
 * - everything: "*"
 * - a prefix: "invoice.*" matches any type under "invoice." at any depth,
 *   but not "invoice" itself
 */
final class EventTypePattern
{
    public const TYPE_REGEX = '/^[a-z0-9_]+(\.[a-z0-9_]+)*$/';

    public const WILDCARD = '*';

    public static function isValidType(string $type): bool
    {
        return preg_match(self::TYPE_REGEX, $type) === 1;
    }

    public static function isValid(string $pattern): bool
    {
        if ($pattern === self::WILDCARD) {
            return true;
        }

        if (str_ends_with($pattern, '.*')) {
            return self::isValidType(substr($pattern, 0, -2));
        }

        return self::isValidType($pattern);
    }

    public static function matches(string $pattern, string $type): bool
    {
        return in_array($pattern, self::candidatesFor($type), true);
    }

    /**
     * Every pattern that matches the given type. Fan-out compares this list
     * with each endpoint's patterns in SQL, so matching never loads every
     * endpoint into PHP.
     *
     * "a.b.c" yields ["*", "a.*", "a.b.*", "a.b.c"].
     *
     * @return list<string>
     */
    public static function candidatesFor(string $type): array
    {
        $candidates = [self::WILDCARD];
        $segments = explode('.', $type);

        for ($i = 1; $i < count($segments); $i++) {
            $candidates[] = implode('.', array_slice($segments, 0, $i)).'.*';
        }

        $candidates[] = $type;

        return $candidates;
    }
}
