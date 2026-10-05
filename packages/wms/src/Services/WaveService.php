<?php

namespace ESolution\InventoryWms\Services;

use ESolution\InventoryWms\Models\Task;
use ESolution\InventoryWms\Models\Wave;
use Illuminate\Support\Facades\DB;

final class WaveService
{
    /** @param list<int> $taskIds */
    public function create(string $code, int $warehouseId, array $taskIds): Wave
    {
        return DB::transaction(function () use ($code, $warehouseId, $taskIds): Wave {
            $tasks = Task::query()->whereKey($taskIds)->lockForUpdate()->get();
            if ($tasks->count() !== count(array_unique($taskIds))
                || $tasks->contains(fn(Task $task): bool => $task->type !== 'pick'
                    || $task->status !== 'open'
                    || (int) $task->warehouse_id !== $warehouseId)) {
                throw new \DomainException('A wave may only contain open pick tasks from one warehouse.');
            }

            $wave = Wave::query()->firstOrCreate(['code' => $code], ['warehouse_id' => $warehouseId]);
            if ((int) $wave->warehouse_id !== $warehouseId) {
                throw new \DomainException('Wave code belongs to a different warehouse.');
            }
            $wave->tasks()->syncWithoutDetaching($taskIds);

            return $wave->load('tasks');
        });
    }
}
