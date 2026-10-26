<?php

declare(strict_types=1);

namespace App\Exceptions;

use LogicException;

/** Thrown when tenant-owned data is touched without (or against) the current tenant context. */
final class TenantContextException extends LogicException {}
