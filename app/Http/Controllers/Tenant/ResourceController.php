<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Thin CRUD base for tenant reference data. Permissions are enforced by the route middleware
 * (can:<resource>.view / .manage); tenant isolation by the model's global scope.
 * Subclasses declare the model, the resource and the validation rules, and may override the hooks.
 */
abstract class ResourceController extends Controller
{
    /** @return class-string<Model> */
    abstract protected function model(): string;

    /** @return class-string<JsonResource> */
    abstract protected function resource(): string;

    /** @return array<string, list<mixed>> rules for creation; they are made optional ("sometimes") on update */
    abstract protected function rules(Request $request, ?Model $model): array;

    /** @return list<string> */
    protected function with(): array
    {
        return [];
    }

    /** @return list<string> */
    protected function counts(): array
    {
        return [];
    }

    /** @param  Builder<Model>  $query */
    protected function filter(Builder $query, Request $request): void {}

    /** @param  array<string, mixed>  $data */
    protected function persist(array $data, ?Model $model): Model
    {
        if ($model === null) {
            return $this->model()::query()->create($data);
        }

        $model->update($data);

        return $model;
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'q' => ['sometimes', 'string', 'max:100'],
        ]);

        $query = $this->model()::query()->with($this->with())->withCount($this->counts());
        $this->filter($query, $request);

        return $this->resource()::collection(
            $query->orderBy('id')->cursorPaginate((int) $request->query('per_page', 25))
        );
    }

    public function show(string $id): JsonResource
    {
        $model = $this->find($id);
        $model->loadMissing($this->with());
        $model->loadCount($this->counts());

        return new ($this->resource())($model);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules($request, null));
        $model = DB::transaction(fn (): Model => $this->persist($data, null));

        return $this->respond($model)->setStatusCode(201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $model = $this->find($id);
        $rules = array_map(static fn (array $r): array => ['sometimes', ...$r], $this->rules($request, $model));
        $data = $request->validate($rules);
        $model = DB::transaction(fn (): Model => $this->persist($data, $model));

        return $this->respond($model);
    }

    public function destroy(string $id): Response
    {
        $model = $this->find($id);

        try {
            $model->delete();
        } catch (QueryException $e) {
            // Integrity violation (SQLSTATE class 23): the record is still referenced elsewhere.
            if (! str_starts_with((string) $e->getCode(), '23')) {
                throw $e;
            }

            abort(409, 'This record is in use and cannot be deleted.');
        }

        return response()->noContent();
    }

    protected function find(string $id): Model
    {
        return $this->model()::query()->findOrFail($id);
    }

    protected function respond(Model $model): JsonResponse
    {
        $model->refresh()->loadMissing($this->with())->loadCount($this->counts());

        return (new ($this->resource())($model))->response();
    }

    protected function like(string $term): string
    {
        return '%'.addcslashes($term, '%_\\').'%';
    }
}
