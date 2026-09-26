<?php

namespace App\Contracts;

interface TaskRepositoryInterface
{
    public function find(int $id): ?array;
    public function getByProject(int $projectId): array;
    public function getByUser(int $userId): array;
    public function create(array $data): int;
    public function update(int $id, array $data): bool;
    public function updateStatus(int $id, string $status): bool;

    // دوال التبعيات
    public function addDependency(int $taskId, int $dependsOnId): bool;
    public function getDependencies(int $taskId): array;
    public function hasUncompletedDependencies(int $taskId): bool;
    public function setDependencies(int $taskId, array $dependencies): void;
}