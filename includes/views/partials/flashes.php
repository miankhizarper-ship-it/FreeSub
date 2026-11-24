<?php
/**
 * Renders queued flash messages (consumed once per request).
 */
$messages = flash_messages();

if ($messages === []) {
    return;
}

$icons = [
    'success' => 'check-circle-2',
    'error' => 'circle-alert',
    'danger' => 'circle-alert',
    'warning' => 'triangle-alert',
    'info' => 'info',
];
?>
<div class="mb-3">
    <?php foreach ($messages as $message): ?>
        <?php
        $type = (string) ($message['type'] ?? 'info');
        $type = in_array($type, ['success', 'error', 'warning', 'info'], true) ? $type : 'info';
        $class = $type === 'error' ? 'danger' : $type;
        ?>
        <div class="alert alert-<?= e($class) ?> alert-dismissible fade show" role="<?= $type === 'error' || $type === 'warning' ? 'alert' : 'status' ?>">
            <i data-lucide="<?= e($icons[$type] ?? 'info') ?>" aria-hidden="true"></i>
            <div class="flex-grow-1"><?= e((string) ($message['message'] ?? '')) ?></div>
            <button type="button" class="btn-close ms-2" data-bs-dismiss="alert" aria-label="Dismiss"></button>
        </div>
    <?php endforeach; ?>
</div>
