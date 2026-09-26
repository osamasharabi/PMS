<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../includes/validation/TaskValidator.php';
use App\Validation\TaskValidator;

$validator = new TaskValidator();
$errors = [];
requireLogin();
$user = currentUser();

$taskRepo = new TaskRepository();
$projectRepo = new ProjectRepository();
$userRepo = new UserRepository();

$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
requirePermission($id ? 'tasks.edit' : 'tasks.create');
$task = [
    'project_id' => $_GET['project_id'] ?? '', 'phase_id' => '', 'parent_task_id' => '',
    'title' => '', 'description' => '', 'acceptance_criteria' => '',
    'priority' => 'medium', 'status' => 'todo', 'complexity' => 'moderate', 'progress' => 0,
    'estimated_hours' => '', 'actual_hours' => '', 'assigned_to' => '', 'reviewer_id' => '', 'start_date' => '', 'due_date' => '',
];

if ($id) {
    $found = $taskRepo->findRaw($id);
    if (!$found) { flash('error', 'Task not found.'); redirect('modules/tasks/index.php'); }
    $task = $found;
}

$projects = $projectRepo->all();
$users = $userRepo->activeList();
$phases = $task['project_id'] ? $projectRepo->phases((int)$task['project_id']) : [];
$candidateTasks = $task['project_id'] ? $taskRepo->candidateDependencies((int)$task['project_id'], $id ?? 0) : [];
$existingDependencies = $id ? $taskRepo->dependencyIds($id) : [];

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $data = [
        'project_id' => (int)($_POST['project_id'] ?? 0),
        'phase_id' => $_POST['phase_id'] ?: null,
        'title' => trim($_POST['title'] ?? ''),
        'description' => trim($_POST['description'] ?? ''),
        'acceptance_criteria' => trim($_POST['acceptance_criteria'] ?? ''),
        'priority' => $_POST['priority'] ?? 'medium',
        'status' => $_POST['status'] ?? 'todo',
        'complexity' => $_POST['complexity'] ?? 'moderate',
        'progress' => (int)($_POST['progress'] ?? 0),
        'estimated_hours' => $_POST['estimated_hours'] ?: null,
        'actual_hours' => $_POST['actual_hours'] ?: null,
        'assigned_to' => $_POST['assigned_to'] ?: null,
        'reviewer_id' => $_POST['reviewer_id'] ?: null,
        'start_date' => $_POST['start_date'] ?: null,
        'due_date' => $_POST['due_date'] ?: null,
    ];

   $isNewTask = empty($id);
if (!$validator->validate($data, $isNewTask)) {
    $errors = $validator->getErrors();
    $error = 'يرجى تصحيح الأخطاء الموضحة أدناه.';
} else {
        $previousAssignee = $id ? $task['assigned_to'] : null;

        if ($id) {
            $taskRepo->update($id, $data);
            logActivity($user['id'], 'task', $id, 'updated', 'Updated task "' . $data['title'] . '"');
            flash('success', 'Task updated.');
        } else {
            $data['created_by'] = $user['id'];
            $newId = $taskRepo->create($data);
            logActivity($user['id'], 'task', $newId, 'created', 'Created task "' . $data['title'] . '"');
            flash('success', 'Task created.');
        }

        $taskId = $id ?: $newId;
        $taskRepo->setDependencies($taskId, $_POST['depends_on'] ?? []);

        if ($data['assigned_to'] && (int)$data['assigned_to'] !== (int)$previousAssignee) {
            (new NotificationRepository())->create(
                (int) $data['assigned_to'],
                (int) $user['id'],
                'task_assigned',
                $user['full_name'] . ' assigned you to "' . $data['title'] . '"',
                'modules/tasks/view.php?id=' . $taskId
            );
        }

        redirect('modules/tasks/index.php');
    }
}

$pageTitle = $id ? 'Edit Task' : 'New Task';
$activeNav = 'tasks';
require __DIR__ . '/../../includes/header.php';
?>

<div class="card" style="max-width:680px;">
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
        <?= csrfField() ?>
       <div class="form-group">
            <label>Title</label>
            <input type="text" name="title" value="<?= e($_POST['title'] ?? $task['title']) ?>" class="<?= isset($errors['title']) ? 'is-invalid' : '' ?>" required>
            <?php if (isset($errors['title'])): ?>
                <div class="field-error text-danger small mt-1"><?= e($errors['title']) ?></div>
            <?php endif; ?>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Project</label>
                <select name="project_id" class="<?= isset($errors['project_id']) ? 'is-invalid' : '' ?>" required>
                    <option value="">Select Project</option>
                    <?php foreach ($projects as $p): ?>
                        <?php 
                            $selectedProj = $_POST['project_id'] ?? $task['project_id'];
                            $isSelected = ($selectedProj == $p['id']) ? 'selected' : '';
                        ?>
                        <option value="<?= $p['id'] ?>" <?= $isSelected ?>><?= e($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($errors['project_id'])): ?>
                    <div class="field-error text-danger small mt-1"><?= e($errors['project_id']) ?></div>
                <?php endif; ?>
            </div>
            <div class="form-group">
                <label>Phase</label>
                <select name="phase_id">
                    <option value="">— None —</option>
                    <?php foreach ($phases as $ph): ?>
                        <option value="<?= $ph['id'] ?>" <?= $task['phase_id'] == $ph['id'] ? 'selected' : '' ?>><?= e($ph['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-group">
            <label>Description</label>
            <textarea name="description"><?= e($task['description']) ?></textarea>
        </div>

        <div class="form-group">
            <label>Acceptance criteria</label>
            <textarea name="acceptance_criteria"><?= e($task['acceptance_criteria']) ?></textarea>
        </div>

        <div class="form-row">
            <div class="form-group">
            <label>Priority</label>
            <select name="priority" class="<?= isset($errors['priority']) ? 'is-invalid' : '' ?>">
                <?php foreach (['low', 'medium', 'high', 'urgent'] as $p): ?>
                    <?php 
                        $selectedPrio = $_POST['priority'] ?? $task['priority'];
                        $isSelected = ($selectedPrio === $p) ? 'selected' : '';
                    ?>
                    <option value="<?= $p ?>" <?= $isSelected ?>><?= ucfirst($p) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (isset($errors['priority'])): ?>
                <div class="field-error text-danger small mt-1"><?= e($errors['priority']) ?></div>
            <?php endif; ?>
        </div>
            <div class="form-group">
                <label>Status</label>
                <select name="status">
                    <?php foreach (['todo','in_progress','review','done','blocked'] as $s): ?>
                        <option value="<?= $s ?>" <?= $task['status'] === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Complexity</label>
                <select name="complexity">
                    <?php foreach (['simple','moderate','complex'] as $c): ?>
                        <option value="<?= $c ?>" <?= $task['complexity'] === $c ? 'selected' : '' ?>><?= ucfirst($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Assignee</label>
                <select name="assigned_to">
                    <option value="">— Unassigned —</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= $task['assigned_to'] == $u['id'] ? 'selected' : '' ?>><?= e($u['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Reviewer</label>
                <select name="reviewer_id">
                    <option value="">— None —</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= $task['reviewer_id'] == $u['id'] ? 'selected' : '' ?>><?= e($u['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Estimated hours</label>
                <input type="number" step="0.5" name="estimated_hours" value="<?= e((string)$task['estimated_hours']) ?>">
            </div>
            <div class="form-group">
                <label>Actual hours</label>
                <input type="number" step="0.5" name="actual_hours" value="<?= e((string)($task['actual_hours'] ?? '')) ?>">
            </div>
            <div class="form-group">
                <label>Progress (%)</label>
                <input type="number" name="progress" min="0" max="100" value="<?= (int)$task['progress'] ?>">
            </div>
        </div>

        <div class="form-group">
            <label>Depends on <span class="muted" style="font-weight:400;">(this task cannot be considered done until these are)</span></label>
            <?php if (!$task['project_id']): ?>
                <div class="muted" style="font-size:12.5px;">Select a project first, then save — you can add dependencies after.</div>
            <?php elseif (!$candidateTasks): ?>
                <div class="muted" style="font-size:12.5px;">No other tasks in this project yet.</div>
            <?php else: ?>
            <select name="depends_on[]" multiple size="5">
                <?php foreach ($candidateTasks as $ct): ?>
                    <option value="<?= $ct['id'] ?>" <?= in_array($ct['id'], $existingDependencies) ? 'selected' : '' ?>><?= e($ct['title']) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="muted" style="font-size:11px; margin-top:4px;">Hold Ctrl/Cmd to select multiple.</div>
            <?php endif; ?>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Start date</label>
                <input type="date" name="start_date" value="<?= e($task['start_date']) ?>">
            </div>
            <div class="form-group">
                <label>Due date</label>
                <input type="date" 
                    name="due_date" 
                    value="<?= e($_POST['due_date'] ?? $task['due_date']) ?>" 
                    class="<?= isset($errors['due_date']) ? 'is-invalid' : '' ?>">
                <?php if (isset($errors['due_date'])): ?>
                    <div class="field-error text-danger small mt-1"><?= e($errors['due_date']) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="flex gap-8">
            <button type="submit" class="btn btn-primary"><?= $id ? 'Save changes' : 'Create task' ?></button>
            <a href="<?= url('modules/tasks/index.php') ?>" class="btn">Cancel</a>
        </div>
    </form>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
