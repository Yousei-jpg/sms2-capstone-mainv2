<?php
/**
 * SMS 2 - Online Pre-Registration Shared UI
 *
 * Render helpers shared by the five Registrar screens. These wrap the existing
 * SMS 2 design system (Bootstrap 5, .mpl-* classes, smsIcon()) so Enrollment
 * looks like the rest of the system without new component CSS.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/enrollment.php';
require_once ROOT_PATH . '/includes/icons.php';

/** Base URL of the Enrollment pages folder. */
function enrPageUrl(string $file, array $query = []): string
{
    $url = BASE_URL . '/modules/enrollment/pages/' . $file;
    if ($query) {
        $url .= '?' . http_build_query($query);
    }
    return $url;
}

/** A status pill using the shared .mpl-status classes. */
function enrStatusBadge(string $status): string
{
    return '<span class="mpl-status ' . htmlspecialchars(enrStatusClass($status)) . '">'
        . htmlspecialchars($status) . '</span>';
}

/**
 * The dashboard summary cards.
 *
 * @param list<array{label:string, value:int, icon:string, tone:string, url:string}> $cards
 */
function enrRenderStatCards(array $cards): void
{
    if (!$cards) {
        return;
    }
    ?>
    <section class="mpl-stats" aria-label="Pre-registration summary">
        <?php foreach ($cards as $card): ?>
            <a class="mpl-stat text-decoration-none" href="<?= htmlspecialchars($card['url']) ?>">
                <div class="mpl-stat-icon <?= htmlspecialchars($card['tone']) ?>">
                    <?= smsIcon($card['icon'], ['aria-hidden' => 'true']) ?>
                </div>
                <div>
                    <span><?= htmlspecialchars($card['label']) ?></span>
                    <strong><?= (int) $card['value'] ?></strong>
                </div>
            </a>
        <?php endforeach; ?>
    </section>
    <?php
}

/**
 * Empty, no-results, error, and permission states.
 * $variant: empty | no-results | error | denied | setup
 */
function enrRenderState(string $variant, string $title, string $message, ?array $action = null): void
{
    $icon = match ($variant) {
        'no-results' => 'search',
        'error'      => 'times-circle',
        'denied'     => 'lock',
        'setup'      => 'database',
        default      => 'inbox',
    };
    $tone = match ($variant) {
        'error', 'denied' => 'danger',
        'setup'           => 'warning',
        default           => 'secondary',
    };
    ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5">
            <div class="mb-3 text-<?= htmlspecialchars($tone) ?>" style="font-size:2rem;line-height:1;">
                <?= smsIcon($icon, ['aria-hidden' => 'true']) ?>
            </div>
            <h5 class="fw-semibold mb-2"><?= htmlspecialchars($title) ?></h5>
            <p class="text-muted mb-3 mx-auto" style="max-width:34rem;"><?= htmlspecialchars($message) ?></p>
            <?php if ($action !== null): ?>
                <a class="enr-btn enr-btn-sm" href="<?= htmlspecialchars($action['url']) ?>">
                    <?= htmlspecialchars($action['label']) ?>
                </a>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/** A dismissible flash message. $type: success | danger | warning | info */
function enrRenderAlert(string $type, string $message, string $detail = '', string $nextStep = ''): void
{
    if ($message === '') {
        return;
    }
    $icon = match ($type) {
        'success' => 'check-circle',
        'danger'  => 'times-circle',
        'warning' => 'exclamation-triangle',
        default   => 'info-circle',
    };
    ?>
    <div class="alert alert-<?= htmlspecialchars($type) ?> alert-dismissible fade show" role="alert">
        <div class="d-flex gap-2">
            <span class="pt-1"><?= smsIcon($icon, ['aria-hidden' => 'true']) ?></span>
            <div>
                <strong><?= htmlspecialchars($message) ?></strong>
                <?php if ($detail !== ''): ?>
                    <div class="small mt-1"><?= htmlspecialchars($detail) ?></div>
                <?php endif; ?>
                <?php if ($nextStep !== ''): ?>
                    <div class="small mt-1 fw-semibold"><?= htmlspecialchars($nextStep) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php
}

/**
 * Server-side pagination. Preserves the current filters in every link.
 *
 * @param array<string, mixed> $query
 */
function enrRenderPagination(int $page, int $totalPages, int $totalRows, string $file, array $query = []): void
{
    if ($totalRows === 0) {
        return;
    }

    $window = 2;
    $start = max(1, $page - $window);
    $end = min($totalPages, $page + $window);
    ?>
    <div class="mpl-foot">
        <span class="meta">
            Page <?= (int) $page ?> of <?= (int) $totalPages ?> &middot;
            <?= (int) $totalRows ?> application<?= $totalRows === 1 ? '' : 's' ?> found
        </span>
        <?php if ($totalPages > 1): ?>
            <nav class="mpl-pager" aria-label="Application queue pagination">
                <?php if ($page > 1): ?>
                    <a href="<?= htmlspecialchars(enrPageUrl($file, array_merge($query, ['page' => $page - 1]))) ?>"
                       aria-label="Previous page">&laquo;</a>
                <?php endif; ?>

                <?php if ($start > 1): ?>
                    <a href="<?= htmlspecialchars(enrPageUrl($file, array_merge($query, ['page' => 1]))) ?>">1</a>
                    <?php if ($start > 2): ?><span>&hellip;</span><?php endif; ?>
                <?php endif; ?>

                <?php for ($i = $start; $i <= $end; $i++): ?>
                    <?php if ($i === $page): ?>
                        <span class="active" aria-current="page"><?= (int) $i ?></span>
                    <?php else: ?>
                        <a href="<?= htmlspecialchars(enrPageUrl($file, array_merge($query, ['page' => $i]))) ?>"><?= (int) $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($end < $totalPages): ?>
                    <?php if ($end < $totalPages - 1): ?><span>&hellip;</span><?php endif; ?>
                    <a href="<?= htmlspecialchars(enrPageUrl($file, array_merge($query, ['page' => $totalPages]))) ?>"><?= (int) $totalPages ?></a>
                <?php endif; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="<?= htmlspecialchars(enrPageUrl($file, array_merge($query, ['page' => $page + 1]))) ?>"
                       aria-label="Next page">&raquo;</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * The step strip across the top of every pre-registration screen, mirroring the
 * five-stage Registrar workflow. Steps the Registrar cannot reach yet are shown
 * as plain text rather than dead links.
 */
function enrRenderWorkflowSteps(int $currentStep, ?string $referenceNo = null): void
{
    $steps = [
        1 => ['label' => 'Dashboard',        'file' => 'online-pre-registration.php',   'needsRef' => false],
        2 => ['label' => 'Application Queue','file' => 'pre-registration-queue.php',    'needsRef' => false],
        3 => ['label' => 'Application Details','file' => 'pre-registration-details.php','needsRef' => true],
        4 => ['label' => 'Validate & Decide','file' => 'pre-registration-validate.php', 'needsRef' => true],
        5 => ['label' => 'Processed',        'file' => 'pre-registration-result.php',   'needsRef' => true],
    ];
    ?>
    <nav class="enr-steps" aria-label="Pre-registration workflow">
        <?php foreach ($steps as $number => $step): ?>
            <?php
            $isCurrent = ($number === $currentStep);
            $isDone = ($number < $currentStep);
            $reachable = !$step['needsRef'] || ($referenceNo !== null && $referenceNo !== '');
            $classes = 'enr-step' . ($isCurrent ? ' is-current' : ($isDone ? ' is-done' : ''));
            $href = $reachable
                ? enrPageUrl($step['file'], $step['needsRef'] ? ['ref' => $referenceNo] : [])
                : null;
            ?>
            <?php if ($href !== null && !$isCurrent): ?>
                <a class="<?= $classes ?>" href="<?= htmlspecialchars($href) ?>">
                    <span class="enr-step-no"><?= (int) $number ?></span>
                    <span class="enr-step-label"><?= htmlspecialchars($step['label']) ?></span>
                </a>
            <?php else: ?>
                <span class="<?= $classes ?><?= $reachable ? '' : ' is-locked' ?>"
                      <?= $isCurrent ? 'aria-current="step"' : '' ?>>
                    <span class="enr-step-no"><?= (int) $number ?></span>
                    <span class="enr-step-label"><?= htmlspecialchars($step['label']) ?></span>
                </span>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <?php
}

/**
 * A read-only field, styled to look like a disabled input so the Registrar can
 * see at a glance that applicant-submitted data is not theirs to retype.
 */
function enrReadOnlyField(string $label, ?string $value, string $emptyText = 'Not provided'): void
{
    $value = trim((string) $value);
    $isEmpty = ($value === '');
    ?>
    <div class="col-md-6">
        <label class="form-label small text-muted mb-1"><?= htmlspecialchars($label) ?></label>
        <div class="enr-readonly<?= $isEmpty ? ' is-empty' : '' ?>">
            <?= htmlspecialchars($isEmpty ? $emptyText : $value) ?>
        </div>
    </div>
    <?php
}

/** Up to two initials for the avatar chip. */
function enrInitials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $letters = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        if ($part !== '') {
            $letters .= strtoupper(substr($part, 0, 1));
        }
    }
    return $letters !== '' ? $letters : '?';
}

/** Format a datetime for display, or a dash when absent. */function enrFormatDate(?string $value, string $format = 'M j, Y'): string
{
    $value = trim((string) $value);
    if ($value === '' || str_starts_with($value, '0000')) {
        return '—';
    }
    $timestamp = strtotime($value);
    return $timestamp ? date($format, $timestamp) : '—';
}

/** Format a datetime including the time. */
function enrFormatDateTime(?string $value): string
{
    return enrFormatDate($value, 'M j, Y g:i A');
}

/** Emit the module stylesheet once per request. */
function enrPageStyles(): void
{
    static $emitted = false;
    if ($emitted) {
        return;
    }
    $emitted = true;
    echo '<link href="' . BASE_URL . '/modules/enrollment/assets/css/enrollment.css?v=2" rel="stylesheet">';
    enrPageScripts();
}

/**
 * Small progressive-enhancement layer for the Enrollment screens.
 *
 * Everything here is cosmetic feedback only. With JavaScript disabled the
 * screens still submit, validate, and decide exactly the same way, because
 * every rule is enforced server-side regardless.
 */
function enrPageScripts(): void
{
    ?>
    <script>
    (function () {
        'use strict';

        document.addEventListener('DOMContentLoaded', function () {

            // 1. The decision button takes the colour of the decision chosen:
            //    green to approve, amber to return, red to reject. The action's
            //    consequence is visible before it is clicked.
            var decisionRadios = document.querySelectorAll('input[name="decision"]');
            var decisionButton = document.querySelector('[data-enr-decision-submit]');

            if (decisionRadios.length && decisionButton) {
                var toneFor = {
                    approve: { cls: 'enr-btn-success', label: 'Approve Application' },
                    return:  { cls: 'enr-btn-warning', label: 'Return for Correction' },
                    reject:  { cls: 'enr-btn-danger',  label: 'Reject Application' }
                };

                var applyTone = function () {
                    var chosen = document.querySelector('input[name="decision"]:checked');
                    decisionButton.classList.remove('enr-btn-success', 'enr-btn-warning', 'enr-btn-danger');

                    if (!chosen || !toneFor[chosen.value]) {
                        return;
                    }

                    decisionButton.classList.add(toneFor[chosen.value].cls);

                    var text = decisionButton.querySelector('[data-enr-decision-label]');
                    if (text) {
                        text.textContent = toneFor[chosen.value].label;
                    }
                };

                decisionRadios.forEach(function (radio) {
                    radio.addEventListener('change', applyTone);
                });

                applyTone();
            }

            // 2. Guard against double submission. A decision writes two rows in
            //    one transaction, so a double click must not fire it twice.
            document.querySelectorAll('form').forEach(function (form) {
                form.addEventListener('submit', function () {
                    form.querySelectorAll('[data-enr-submit]').forEach(function (btn) {
                        btn.classList.add('is-busy');
                    });
                });
            });

            // 3. Whole table rows become clickable, so the Open button is a
            //    convenience rather than the only target. Clicks on a real link
            //    or control inside the row are left alone.
            document.querySelectorAll('[data-enr-row-link]').forEach(function (row) {
                row.style.cursor = 'pointer';
                row.addEventListener('click', function (event) {
                    if (event.target.closest('a, button, input, label, select')) {
                        return;
                    }
                    window.location.href = row.getAttribute('data-enr-row-link');
                });
            });
        });
    })();
    </script>
    <?php
}
