<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Portal\PortalAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Portal gate: the user must be linked to a guardian or student profile, and a {student} in the URL must be one of theirs.
 * Another family's child answers 404 (existence is not revealed). Usage: EnsurePortalAccess::class.':finance' for money data.
 */
final class EnsurePortalAccess
{
    public function __construct(private readonly PortalAccess $access) {}

    public function handle(Request $request, Closure $next, string ...$flags): Response
    {
        $students = $this->access->students($request->user());

        if ($students === []) {
            abort(403, 'This account is not linked to a student or a guardian profile.');
        }

        $student = $request->route('student');

        if ($student !== null) {
            if (! ctype_digit((string) $student) || ! array_key_exists((int) $student, $students)) {
                abort(404);
            }

            if (in_array('finance', $flags, true) && ! $students[(int) $student]) {
                abort(403, 'Financial information is available to the financially responsible guardian only.');
            }
        }

        $request->attributes->set('portal_students', $students);

        return $next($request);
    }
}
