<?php
/**
 * SMS 2 - Document Upload Portal: shared rendering helpers
 *
 * Presentation only. These build on prereg-ui.php rather than duplicating it,
 * so both subsystems keep the same look and the same page URL helper.
 */

declare(strict_types=1);

require_once __DIR__ . '/prereg-ui.php';
require_once __DIR__ . '/docs-workflow.php';

/** Status pill for a document, coloured by outcome. */
function enrDocBadge(string $status): string
{
    return '<span class="mpl-status ' . htmlspecialchars(enrDocStatusClass($status)) . '">'
        . htmlspecialchars($status) . '</span>';
}

/** Rollup pill for an application's overall document state. */
function enrDocSummaryBadge(string $status): string
{
    $class = match ($status) {
        ENR_DOCS_COMPLETE         => 'completed',
        ENR_DOCS_FOR_VERIFICATION => 'pending',
        default                   => 'cancelled',
    };

    return '<span class="mpl-status ' . $class . '">' . htmlspecialchars($status) . '</span>';
}

/**
 * The five-step portal workflow strip, mirroring the one Pre-Registration uses
 * so the two subsystems feel like one module.
 */
function enrDocWorkflowSteps(int $currentStep, ?string $reference = null, int $documentId = 0): void
{
    $steps = [
        1 => ['label' => 'Portal Dashboard', 'file' => 'document-upload-portal.php', 'query' => []],
        2 => ['label' => 'Document Queue',   'file' => 'document-queue.php',         'query' => []],
        3 => ['label' => 'Applicant Documents', 'file' => 'applicant-documents.php', 'query' => $reference !== null ? ['ref' => $reference] : null],
        4 => ['label' => 'Verify Document',  'file' => 'document-verify.php',        'query' => $documentId > 0 ? ['id' => $documentId] : null],
        5 => ['label' => 'Result',           'file' => 'document-result.php',        'query' => $documentId > 0 ? ['id' => $documentId] : null],
    ];
    ?>
    <nav class="enr-steps" aria-label="Document verification workflow">
        <?php foreach ($steps as $number => $step): ?>
            <?php
            $isDone    = $number < $currentStep;
            $isCurrent = $number === $currentStep;
            // A step is only a link when we hold the context it needs. Step 4
            // without a document id would be a button that goes nowhere.
            $reachable = $step['query'] !== null && !$isCurrent && $number <= $currentStep;
            $class = 'enr-step' . ($isDone ? ' is-done' : ($isCurrent ? ' is-current' : ' is-locked'));
            ?>
            <?php if ($reachable): ?>
                <a class="<?= $class ?>" href="<?= htmlspecialchars(enrPageUrl($step['file'], $step['query'])) ?>">
                    <span class="enr-step-no"><?= $number ?></span>
                    <span class="enr-step-label"><?= htmlspecialchars($step['label']) ?></span>
                </a>
            <?php else: ?>
                <span class="<?= $class ?>">
                    <span class="enr-step-no"><?= $number ?></span>
                    <span class="enr-step-label"><?= htmlspecialchars($step['label']) ?></span>
                </span>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <?php
}

/**
 * Completeness bar. The number shown is VERIFIED required documents, not
 * uploaded ones: an unverified upload is submitted, not complete, and the bar
 * should not imply otherwise.
 */
function enrDocProgressBar(array $completeness): void
{
    $percent  = (int) $completeness['percent'];
    $verified = (int) $completeness['required_verified'];
    $total    = (int) $completeness['required_total'];

    $tone = match (true) {
        $percent >= 100 => 'success',
        $percent > 0    => 'warning',
        default         => 'secondary',
    };
    ?>
    <div class="enr-doc-progress">
        <div class="d-flex justify-content-between align-items-baseline mb-1">
            <span class="fw-semibold small">Document Completeness</span>
            <span class="small text-muted"><?= $verified ?> of <?= $total ?> required verified</span>
        </div>
        <div class="progress" style="height:.55rem;" role="progressbar"
             aria-valuenow="<?= $percent ?>" aria-valuemin="0" aria-valuemax="100">
            <div class="progress-bar bg-<?= $tone ?>" style="width:<?= $percent ?>%"></div>
        </div>
        <div class="small text-muted mt-1"><?= $percent ?>% verified</div>
    </div>
    <?php
}

/** One row of the document checklist on Screen 3. */
function enrDocChecklistRow(array $item, string $reference): void
{
    $status   = (string) $item['status'];
    $document = $item['document'] ?? null;
    $isMissing = $status === ENR_DOC_MISSING;
    ?>
    <div class="enr-doc-row enr-doc-<?= htmlspecialchars(strtolower(str_replace(' ', '-', $status))) ?>">
        <div class="enr-doc-row-icon">
            <?= smsIcon(enrDocStatusIcon($status), ['aria-hidden' => 'true']) ?>
        </div>

        <div class="enr-doc-row-main">
            <div class="fw-semibold">
                <?= htmlspecialchars((string) $item['document_name']) ?>
                <?php if (!$item['is_required']): ?>
                    <span class="badge bg-secondary-subtle text-secondary-emphasis ms-1">Optional</span>
                <?php endif; ?>
            </div>
            <?php if ($isMissing): ?>
                <div class="small text-muted">
                    <?= htmlspecialchars((string) ($item['description'] ?? 'Not submitted yet.')) ?>
                </div>
            <?php else: ?>
                <div class="small text-muted">
                    <?= htmlspecialchars((string) $document['original_name']) ?>
                    &middot; <?= htmlspecialchars(enrDocFormatSize((int) $document['file_size'])) ?>
                    &middot; uploaded <?= htmlspecialchars(enrFormatDateTime((string) $document['uploaded_at'])) ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="enr-doc-row-status"><?= enrDocBadge($status) ?></div>

        <div class="enr-doc-row-action">
            <?php if ($isMissing): ?>
                <span class="small text-muted">Nothing to view</span>
            <?php else: ?>
                <a class="enr-btn enr-btn-ghost enr-btn-sm"
                   href="<?= htmlspecialchars(BASE_URL . '/modules/enrollment/api/document-file.php?id=' . (int) $document['id']) ?>"
                   target="_blank" rel="noopener">
                    <?= smsIcon('eye', ['aria-hidden' => 'true']) ?> View
                </a>
                <a class="enr-btn enr-btn-soft enr-btn-sm"
                   href="<?= htmlspecialchars(enrPageUrl('document-verify.php', ['id' => (int) $document['id']])) ?>">
                    <?= smsIcon('checkbox', ['aria-hidden' => 'true']) ?> Verify
                </a>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
