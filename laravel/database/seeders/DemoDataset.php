<?php

namespace Database\Seeders;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Internal provenance registry; ownership is never inferred from names. */
final class DemoDataset
{
    public const KEY = 'donqer-be-s2-04-demo-v1';

    public const DISCLAIMER = 'FICTICIO — DEMOSTRACIÓN. NO APTO PARA USO DIAGNÓSTICO REAL.';

    public static function run(Closure $operation): mixed
    {
        if (! app()->environment('local', 'testing')) {
            throw new LogicException('The demo dataset is restricted to local and testing environments.');
        }

        return DB::transaction(function () use ($operation) {
            DB::table('demo_datasets')->insertOrIgnore(['dataset_key' => self::KEY, 'created_at' => now()]);
            DB::table('demo_datasets')->where('dataset_key', self::KEY)->lockForUpdate()->firstOrFail();

            return $operation();
        });
    }

    /** @param class-string<Model> $model */
    public static function create(string $model, array $identity, array $attributes = [], ?Closure $factory = null): Model
    {
        return self::remember($model, hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR)),
            fn ($id) => $model::query()->where($identity)->whereKey($id)->first(),
            function () use ($model, $identity, $attributes, $factory) {
                if ($model::query()->where($identity)->exists()) {
                    throw new LogicException("Unregistered preexisting record conflicts with the demo dataset: {$model}.");
                }

                return $factory === null ? $model::query()->create(array_merge($attributes, $identity)) : $factory();
            });
    }

    public static function remember(string $kind, string $key, Closure $load, Closure $create): Model
    {
        $datasetId = DB::table('demo_datasets')->where('dataset_key', self::KEY)->value('id');
        if ($datasetId === null || DB::transactionLevel() === 0) {
            throw new LogicException('Demo writes require the dataset transaction.');
        }
        $record = DB::table('demo_dataset_records')->where('demo_dataset_id', $datasetId)
            ->where('entity_kind', $kind)->where('entity_key', $key)->first();
        if ($record !== null) {
            return $load($record->entity_id) ?? throw new LogicException('A registered demo identity is missing or changed; automatic repair is forbidden.');
        }
        $entity = $create();
        DB::table('demo_dataset_records')->insert([
            'demo_dataset_id' => $datasetId, 'entity_kind' => $kind, 'entity_key' => $key,
            'entity_id' => $entity->getKey(), 'created_at' => now(),
        ]);

        return $entity;
    }

    public static function owned(string $model, array $identity): Model
    {
        $key = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
        $record = DB::table('demo_dataset_records')->where('demo_dataset_id', DB::table('demo_datasets')->where('dataset_key', self::KEY)->value('id'))
            ->where('entity_kind', $model)->where('entity_key', $key)->first();

        return $record === null ? throw new LogicException('Required demo dependency has no registered provenance.')
            : ($model::query()->where($identity)->whereKey($record->entity_id)->first() ?? throw new LogicException('Registered demo dependency is missing or changed.'));
    }

    public static function assertOwned(Model $model): void
    {
        if (! DB::table('demo_dataset_records')->where('demo_dataset_id', DB::table('demo_datasets')->where('dataset_key', self::KEY)->value('id'))
            ->where('entity_kind', $model::class)->where('entity_id', $model->getKey())->exists()) {
            throw new LogicException('A demo dependency is not owned by this dataset.');
        }
    }
}
