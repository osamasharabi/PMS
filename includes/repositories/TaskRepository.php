<?php

namespace App\Repositories;

use App\Contracts\TaskRepositoryInterface;
use PDO;
use PDOException;

class TaskRepository extends BaseRepository implements TaskRepositoryInterface
{
    public function find(int $id): ?array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT t.*, p.name AS project_name, u.name AS assigned_to_name
                FROM tasks t
                LEFT JOIN projects p ON t.project_id = p.id
                LEFT JOIN users u ON t.assigned_to = u.id
                WHERE t.id = :id
                LIMIT 1
            ");
            $stmt->execute([':id' => $id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: null;
        } catch (PDOException $e) {
            error_log("Database error in TaskRepository::find: " . $e->getMessage());
            return null;
        }
    }

    public function getByProject(int $projectId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT t.*, u.name AS assigned_to_name
                FROM tasks t
                LEFT JOIN users u ON t.assigned_to = u.id
                WHERE t.project_id = :project_id
                ORDER BY t.created_at DESC
            ");
            $stmt->execute([':project_id' => $projectId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log("Database error in TaskRepository::getByProject: " . $e->getMessage());
            return [];
        }
    }

    public function getByUser(int $userId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT t.*, p.name AS project_name
                FROM tasks t
                LEFT JOIN projects p ON t.project_id = p.id
                WHERE t.assigned_to = :user_id
                ORDER BY t.due_date ASC
            ");
            $stmt->execute([':user_id' => $userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log("Database error in TaskRepository::getByUser: " . $e->getMessage());
            return [];
        }
    }

    public function create(array $data): int
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO tasks (title, description, project_id, assigned_to, priority, status, due_date, created_at, updated_at)
                VALUES (:title, :description, :project_id, :assigned_to, :priority, :status, :due_date, NOW(), NOW())
            ");
            $stmt->execute([
                ':title'       => $data['title'],
                ':description' => $data['description'] ?? null,
                ':project_id'  => $data['project_id'],
                ':assigned_to' => !empty($data['assigned_to']) ? $data['assigned_to'] : null,
                ':priority'    => $data['priority'] ?? 'medium',
                ':status'      => $data['status'] ?? 'todo',
                ':due_date'    => !empty($data['due_date']) ? $data['due_date'] : null,
            ]);
            return (int) $this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log("Database error in TaskRepository::create: " . $e->getMessage());
            return 0;
        }
    }

    public function update(int $id, array $data): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE tasks 
                SET title = :title,
                    description = :description,
                    project_id = :project_id,
                    assigned_to = :assigned_to,
                    priority = :priority,
                    status = :status,
                    due_date = :due_date,
                    updated_at = NOW()
                WHERE id = :id
            ");
            return $stmt->execute([
                ':id'          => $id,
                ':title'       => $data['title'],
                ':description' => $data['description'] ?? null,
                ':project_id'  => $data['project_id'],
                ':assigned_to' => !empty($data['assigned_to']) ? $data['assigned_to'] : null,
                ':priority'    => $data['priority'] ?? 'medium',
                ':status'      => $data['status'] ?? 'todo',
                ':due_date'    => !empty($data['due_date']) ? $data['due_date'] : null,
            ]);
        } catch (PDOException $e) {
            error_log("Database error in TaskRepository::update: " . $e->getMessage());
            return false;
        }
    }

    public function updateStatus(int $id, string $status): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE tasks 
                SET status = :status,
                    updated_at = NOW()
                WHERE id = :id
            ");
            return $stmt->execute([
                ':id'     => $id,
                ':status' => $status
            ]);
        } catch (PDOException $e) {
            error_log("Database error in TaskRepository::updateStatus: " . $e->getMessage());
            return false;
        }
    }

    public function addDependency(int $taskId, int $dependsOnId): bool
    {
        if ($taskId === $dependsOnId || $taskId <= 0 || $dependsOnId <= 0) {
            return false;
        }

        try {
            $checkStmt = $this->db->prepare("
                SELECT COUNT(*) FROM task_dependencies 
                WHERE task_id = :task_id AND depends_on_id = :depends_on_id
            ");
            $checkStmt->execute([
                ':task_id'       => $taskId,
                ':depends_on_id' => $dependsOnId
            ]);

            if ($checkStmt->fetchColumn() > 0) {
                return true;
            }

            $stmt = $this->db->prepare("
                INSERT INTO task_dependencies (task_id, depends_on_id) 
                VALUES (:task_id, :depends_on_id)
            ");
            return $stmt->execute([
                ':task_id'       => $taskId,
                ':depends_on_id' => $dependsOnId
            ]);
        } catch (PDOException $e) {
            error_log("Database error in TaskRepository::addDependency: " . $e->getMessage());
            return false;
        }
    }

    public function getDependencies(int $taskId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT t.id, t.title, t.status, t.priority, td.id AS dependency_id
                FROM task_dependencies td
                JOIN tasks t ON td.depends_on_id = t.id
                WHERE td.task_id = :task_id
            ");
            $stmt->execute([':task_id' => $taskId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log("Database error in TaskRepository::getDependencies: " . $e->getMessage());
            return [];
        }
    }

    public function hasUncompletedDependencies(int $taskId): bool
    {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) 
                FROM task_dependencies td
                JOIN tasks t ON td.depends_on_id = t.id
                WHERE td.task_id = :task_id AND t.status != 'completed'
            ");
            $stmt->execute([':task_id' => $taskId]);
            return ((int) $stmt->fetchColumn()) > 0;
        } catch (PDOException $e) {
            error_log("Database error in TaskRepository::hasUncompletedDependencies: " . $e->getMessage());
            return false;
        }
    }

    public function setDependencies(int $taskId, array $dependencies): void
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM task_dependencies WHERE task_id = :task_id");
            $stmt->execute([':task_id' => $taskId]);

            foreach ($dependencies as $depId) {
                $depId = (int)$depId;
                if ($depId > 0 && $depId !== $taskId) {
                    $this->addDependency($taskId, $depId);
                }
            }
        } catch (PDOException $e) {
            error_log("Database error in TaskRepository::setDependencies: " . $e->getMessage());
        }
    }
}