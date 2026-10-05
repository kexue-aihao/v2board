<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Facades\DB;

/** Explicitly audit scoped builder writes, without recording SQL bindings. */
class SecurityAuditMutation
{
    public static function update($query, array $values, string $key = 'id'): int
    {
        if (SecurityAuditService::active() && $query instanceof EloquentBuilder && $query->getModel()->usesTimestamps()) {
            $column = $query->getModel()->getUpdatedAtColumn();
            if ($column && !array_key_exists($column, $values)) $values[$column] = $query->getModel()->freshTimestampString();
        }
        return self::mutate($query, $values, false, $key);
    }

    public static function delete($query, string $key = 'id'): int
    {
        return self::mutate($query, [], true, $key);
    }

    private static function mutate($query, array $values, bool $delete, string $key): int
    {
        if (!SecurityAuditService::active()) return $delete ? $query->delete() : $query->update($values);
        return $query->getConnection()->transaction(function () use ($query, $values, $delete, $key) {
            $builder = $query instanceof EloquentBuilder ? (clone $query)->toBase() : clone $query;
            $table = $builder->from;
            $rows = (clone $builder)->lockForUpdate()->get();
            if ($rows->isEmpty()) return 0;
            // Mutate exactly the locked set. A newly matching concurrent row must
            // not be affected without a corresponding before snapshot.
            $ids = $rows->pluck($key)->unique()->values()->all();
            $affected = 0;
            foreach (array_chunk($ids, 500) as $chunk) {
                $scope = (clone $builder)->whereIn($key, $chunk);
                $affected += $delete ? $scope->delete() : $scope->update($values);
            }
            $afterIds = isset($values[$key]) ? [$values[$key]] : $ids;
            $after = [];
            if (!$delete && $key === 'node_id') {
                foreach ($rows as $row) {
                    $newId = $values[$key] ?? $row->{$key};
                    $saved = $query->getConnection()->table($table)->where('node_type', $row->node_type)->where($key, $newId)->first();
                    if ($saved) $after[$row->node_type . ':' . $newId] = (array)$saved;
                }
            } elseif (!$delete) foreach (array_chunk($afterIds, 500) as $chunk) {
                foreach ($query->getConnection()->table($table)->whereIn($key, $chunk)->get() as $row) $after[(string)$row->{$key}] = (array)$row;
            }
            foreach ($rows as $row) {
                $newId = $values[$key] ?? $row->{$key};
                $identity = $key === 'node_id' ? $row->node_type . ':' . $newId : (string)$newId;
                SecurityAuditService::change($table, $newId, $delete ? 'deleted' : 'updated', (array)$row, $delete ? null : ($after[$identity] ?? null));
            }
            return $affected;
        });
    }

    public static function insert($query, array $rows): bool
    {
        if (!$rows) return true;
        if (!isset($rows[0]) || !is_array($rows[0])) $rows = [$rows];
        if (!SecurityAuditService::active()) return $query->insert($rows);
        return $query->getConnection()->transaction(function () use ($query, $rows) {
            $builder = $query instanceof EloquentBuilder ? $query->toBase() : $query;
            foreach ($rows as $row) {
                // insertGetId assigns each row's own ID, never a max-ID range.
                $id = $builder->insertGetId($row);
                $saved = $builder->getConnection()->table($builder->from)->where('id', $row['id'] ?? $id)->first();
                SecurityAuditService::change($builder->from, $row['id'] ?? $id, 'created', null, (array)$saved);
            }
            return true;
        });
    }

    public static function insertGetId($query, array $values): int
    {
        $id = $query->insertGetId($values);
        if (SecurityAuditService::active()) SecurityAuditService::change($query->from, $id, 'created', null, (array)$query->getConnection()->table($query->from)->where('id', $id)->first());
        return (int)$id;
    }

    public static function upsertOne($query, array $keys, array $values): bool
    {
        if (!SecurityAuditService::active()) return $query->updateOrInsert($keys, $values);
        return $query->getConnection()->transaction(function () use ($query, $keys, $values) {
            $before = (clone $query)->where($keys)->lockForUpdate()->first();
            $result = $query->updateOrInsert($keys, $values);
            $after = (clone $query)->where($keys)->first();
            $identity = $after->id ?? $before->id ?? implode(':', array_values($keys));
            SecurityAuditService::change($query->from, $identity, $before ? 'updated' : 'created', $before ? (array)$before : null, $after ? (array)$after : null);
            return $result;
        });
    }
}
