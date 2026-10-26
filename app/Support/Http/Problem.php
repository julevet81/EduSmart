<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/** RFC 7807 "problem details" responses, so every API error has the same shape. */
final class Problem
{
    /**
     * @param  array<string, mixed>  $extra
     * @param  array<string, string>  $headers
     */
    public static function make(int $status, ?string $detail = null, array $extra = [], array $headers = []): JsonResponse
    {
        $body = [
            'type' => 'about:blank',
            'title' => Response::$statusTexts[$status] ?? 'Error',
            'status' => $status,
            'detail' => $detail !== '' ? $detail : null,
            ...$extra,
        ];

        return response()->json(
            array_filter($body, static fn (mixed $v): bool => $v !== null),
            $status,
            $headers + ['Content-Type' => 'application/problem+json'],
        );
    }

    public static function validation(ValidationException $e): JsonResponse
    {
        return self::make(422, 'The given data was invalid.', ['errors' => $e->errors()]);
    }
}
