<?php

declare(strict_types=1);

namespace App\Support\Messaging;

/** The only variables a template may use. Unknown ones are rejected when the template is saved. */
final class Placeholders
{
    public const ALLOWED = [
        'recipient_name', 'guardian_name', 'student_name', 'group_name', 'institution', 'date',
        'absences', 'days', 'amount', 'due_date',
    ];

    /** @return list<string> placeholders used in the text that are not allowed */
    public static function unknown(string $text): array
    {
        preg_match_all('/\{([A-Za-z0-9_]+)\}/', $text, $m);

        return array_values(array_unique(array_diff($m[1], self::ALLOWED)));
    }

    /** @param  array<string, string|int>  $vars */
    public static function render(string $text, array $vars): string
    {
        return preg_replace_callback('/\{([A-Za-z0-9_]+)\}/', static fn (array $m): string => in_array($m[1], self::ALLOWED, true)
            ? (string) ($vars[$m[1]] ?? '') : $m[0], $text) ?? $text;
    }
}
