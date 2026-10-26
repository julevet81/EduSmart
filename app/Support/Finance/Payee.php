<?php

declare(strict_types=1);

namespace App\Support\Finance;

use App\Enums\PayeeType;
use App\Models\StaffMember;
use App\Models\Teacher;
use Illuminate\Support\Collection;

/** Resolves the polymorphic "payee" (teacher or staff member) used by contracts, adjustments and payslips. */
final class Payee
{
    public static function table(PayeeType $type): string
    {
        return $type === PayeeType::Teacher ? 'teachers' : 'staff_members';
    }

    public static function exists(PayeeType $type, int $id): bool
    {
        return match ($type) {
            PayeeType::Teacher => Teacher::query()->whereKey($id)->exists(),
            PayeeType::Staff => StaffMember::query()->whereKey($id)->exists(),
        };
    }

    /**
     * @param  iterable<object{payee_type: PayeeType, payee_id: int}>  $rows
     * @return array<string, string> "type:id" => display name
     */
    public static function names(iterable $rows): array
    {
        $rows = Collection::make($rows);
        $names = [];

        $teachers = $rows->filter(fn ($r): bool => $r->payee_type === PayeeType::Teacher)->pluck('payee_id')->unique();
        $staff = $rows->filter(fn ($r): bool => $r->payee_type === PayeeType::Staff)->pluck('payee_id')->unique();

        foreach (Teacher::query()->withTrashed()->whereIn('id', $teachers)->get() as $t) {
            $names['teacher:'.$t->getKey()] = $t->first_name.' '.$t->last_name;
        }

        foreach (StaffMember::query()->whereIn('id', $staff)->get() as $s) {
            $names['staff:'.$s->getKey()] = $s->first_name.' '.$s->last_name;
        }

        return $names;
    }
}
