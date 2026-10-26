<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Enums\ContractType;
use App\Enums\PayeeType;
use App\Http\Resources\SalaryContractResource;
use App\Models\SalaryContract;
use App\Support\Finance\Payee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SalaryContractController extends ResourceController
{
    protected function model(): string
    {
        return SalaryContract::class;
    }

    protected function resource(): string
    {
        return SalaryContractResource::class;
    }

    protected function rules(Request $request, ?Model $model): array
    {
        // Who is paid and how never changes: end the contract and create a new one.
        $immutable = $model === null ? [
            'payee_type' => ['required', Rule::enum(PayeeType::class)],
            'payee_id' => ['required', 'integer'],
            'type' => ['required', Rule::enum(ContractType::class)],
        ] : [];

        return $immutable + [
            'amount' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'is_active' => ['boolean'],
        ];
    }

    protected function persist(array $data, ?Model $model): Model
    {
        if ($model === null) {
            $type = PayeeType::from($data['payee_type']);

            if (! Payee::exists($type, (int) $data['payee_id'])) {
                abort(422, 'The payee does not exist.');
            }

            if ($type === PayeeType::Staff && $data['type'] !== ContractType::Monthly->value) {
                abort(422, 'Staff members can only have a monthly contract.');
            }
        }

        return parent::persist($data, $model);
    }

    protected function filter(Builder $query, Request $request): void
    {
        $request->validate(['payee_type' => ['sometimes', Rule::enum(PayeeType::class)], 'payee_id' => ['sometimes', 'integer']]);

        $query->when($request->query('payee_type'), fn (Builder $q, $v) => $q->where('payee_type', $v))
            ->when($request->query('payee_id'), fn (Builder $q, $v) => $q->where('payee_id', $v));
    }
}
