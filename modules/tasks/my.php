<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/repositories/BaseRepository.php';
require_once __DIR__ . '/../../includes/contracts/TaskRepositoryInterface.php';
require_once __DIR__ . '/../../includes/repositories/TaskRepository.php';

use App\Repositories\TaskRepository;

requireLogin();

$user = currentUser();
$taskRepo = new TaskRepository();

// معالجة تغيير الحالة السريع عبر POST ومؤمنة بـ CSRF Token
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'quick_status_toggle') {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        flash('danger', 'رمز الأمان CSRF غير صالح.');
        redirect('modules/tasks/my.php');
    }

    $taskId = (int)($_POST['task_id'] ?? 0);
    $newStatus = trim($_POST['status'] ?? '');
    $allowedStatuses = ['todo', 'in_progress', 'review', 'completed'];

    if ($taskId > 0 && in_array($newStatus, $allowedStatuses, true)) {
        // التأكد من أن المهمة مسندة للعضو الحالي حصراً
        $targetTask = $taskRepo->find($taskId);
        if ($targetTask && (int)$targetTask['assigned_to'] === (int)$user['id']) {
            if ($newStatus === 'completed' && $taskRepo->hasUncompletedDependencies($taskId)) {
                flash('danger', 'لا يمكن إكمال هذه المهمة لوجود تبعيات سابقة لم تُنجز بعد!');
            } else {
                $taskRepo->updateStatus($taskId, $newStatus);
                flash('success', 'تم تحديث حالة المهمة بنجاح.');
            }
        } else {
            flash('danger', 'غير مصرح لك بتعديل هذه المهمة.');
        }
    }
    redirect('modules/tasks/my.php');
}

// جلب المهام المسندة للمستخدم الحالي فقط مرتبة حسب تاريخ الاستحقاق الأقرب
$myTasks = $taskRepo->getByUser((int)$user['id']);

// تقسيم وتجميع المهام حسب الحالات
$board = [
    'todo'        => [],
    'in_progress' => [],
    'review'      => [],
    'completed'   => []
];

foreach ($myTasks as $t) {
    $st = $t['status'] ?? 'todo';
    if (isset($board[$st])) {
        $board[$st][] = $t;
    } else {
        $board['todo'][] = $t;
    }
}

$pageTitle = 'مهامي (My Tasks) - لوحة العمل السريعة';
$activeNav = 'my-tasks';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="h3 mb-0">لوحة مهامي السريعة</h2>
            <p class="text-muted">المهام المسندة إليك مرتبة حسب تاريخ الاستحقاق الأقرب.</p>
        </div>
        <div>
            <span class="badge bg-primary fs-6">إجمالي مهامك: <?= count($myTasks) ?></span>
        </div>
    </div>

    <!-- أعمدة الكانبان للمهام الشخصية -->
    <div class="row g-3">
        <?php
        $columns = [
            'todo'        => ['title' => 'قيد الانتظار (To Do)', 'color' => 'secondary'],
            'in_progress' => ['title' => 'جاري العمل (In Progress)', 'color' => 'primary'],
            'review'      => ['title' => 'قيد المراجعة (Review)', 'color' => 'info'],
            'completed'   => ['title' => 'مكتملة (Completed)', 'color' => 'success']
        ];

        foreach ($columns as $statusKey => $col):
        ?>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="card h-100 shadow-sm border-top border-4 border-<?= $col['color'] ?>">
                    <div class="card-header bg-light d-flex justify-content-between align-items-center">
                        <strong class="text-<?= $col['color'] ?>"><?= $col['title'] ?></strong>
                        <span class="badge bg-<?= $col['color'] ?> rounded-pill"><?= count($board[$statusKey]) ?></span>
                    </div>
                    <div class="card-body p-2 d-flex flex-column gap-2" style="min-height: 420px; background-color: #f8f9fa;">
                        <?php if (empty($board[$statusKey])): ?>
                            <div class="text-center text-muted my-auto py-4">لا توجد مهام</div>
                        <?php else: ?>
                            <?php foreach ($board[$statusKey] as $taskItem): ?>
                                <div class="card shadow-sm border-0">
                                    <div class="card-body p-3">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <span class="badge bg-light text-dark border"><?= htmlspecialchars($taskItem['project_name'] ?? 'مشروع عام') ?></span>
                                            <span class="badge bg-<?= $taskItem['priority'] === 'urgent' ? 'danger' : ($taskItem['priority'] === 'high' ? 'warning text-dark' : 'secondary') ?>">
                                                <?= htmlspecialchars($taskItem['priority'] ?? 'medium') ?>
                                            </span>
                                        </div>

                                        <h6 class="card-title mb-1">
                                            <a href="<?= url('modules/tasks/view.php?id=' . $taskItem['id']) ?>" class="text-decoration-none text-dark fw-bold">
                                                <?= htmlspecialchars($taskItem['title']) ?>
                                            </a>
                                        </h6>

                                        <div class="small text-muted mb-3">
                                            <i class="bi bi-calendar-event me-1"></i> الاستحقاق: <?= !empty($taskItem['due_date']) ? date('Y-m-d', strtotime($taskItem['due_date'])) : 'غير محدد' ?>
                                        </div>

                                        <!-- أزرار التبديل السريع للحالة بضغطة واحدة -->
                                        <form method="POST" action="<?= url('modules/tasks/my.php') ?>" class="d-flex gap-1 justify-content-end border-top pt-2">
                                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                            <input type="hidden" name="action" value="quick_status_toggle">
                                            <input type="hidden" name="task_id" value="<?= $taskItem['id'] ?>">

                                            <?php if ($statusKey !== 'todo'): ?>
                                                <button type="submit" name="status" value="todo" class="btn btn-outline-secondary btn-sm px-2 py-0" title="إرجاع إلى الانتظار">انتظار</button>
                                            <?php endif; ?>

                                            <?php if ($statusKey !== 'in_progress'): ?>
                                                <button type="submit" name="status" value="in_progress" class="btn btn-outline-primary btn-sm px-2 py-0" title="بدء العمل">تنفيذ</button>
                                            <?php endif; ?>

                                            <?php if ($statusKey !== 'review'): ?>
                                                <button type="submit" name="status" value="review" class="btn btn-outline-info btn-sm px-2 py-0" title="إرسال للمراجعة">مراجعة</button>
                                            <?php endif; ?>

                                            <?php if ($statusKey !== 'completed'): ?>
                                                <button type="submit" name="status" value="completed" class="btn btn-outline-success btn-sm px-2 py-0" title="إكمال المهمة">إكمال ✓</button>
                                            <?php endif; ?>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>