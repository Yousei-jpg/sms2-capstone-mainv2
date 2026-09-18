(function () {
    'use strict';

    const moduleLinks = [
        ['ti-stack-2', 'Section Assignment', 'section-assignment-tool.php'],
        ['ti-presentation', 'Teacher Mapping', 'teacher-schedule-mapping.php'],
        ['ti-building', 'Room Availability', 'room-availability-checker.php'],
        ['ti-shield-search', 'Conflict Checker', 'conflict-checker.php'],
        ['ti-clock-cog', 'Time Blocks', 'time-block-generator.php'],
        ['ti-calendar-plus', 'Special Classes', 'special-class-scheduler.php'],
        ['ti-calendar-stats', 'Exam Timetable', 'exam-timetable-generator.php'],
        ['ti-user-exclamation', 'Substitute Tracker', 'substitute-assignment-tracker.php'],
        ['ti-copy', 'Schedule Cloning', 'schedule-cloning-tool.php'],
        ['ti-calendar-share', 'Calendar Integration', 'calendar-integration.php'],
        ['ti-layout-dashboard', 'Scheduling Overview', 'index.php']
    ];

    const controllers = [
        { selector: '#tsmProgress', wrapper: '.tsm-progress-card' },
        { selector: '#spcSteps' },
        { selector: '#ccxSteps' },
        { selector: '#racSteps', wrapper: '.rac-step-card' },
        { selector: '#satSteps', wrapper: '.sat-step-wrap' },
        { selector: '#clnStepper' },
        { selector: '#tbFlow' },
        { selector: '.ex-flow' },
        { selector: '#ciSteps' },
        { selector: '#satStepper', wrapper: '.card', sectionAssignment: true }
    ];

    const pagePath = window.location.pathname;
    const baseUrl = pagePath.includes('/modules/') ? pagePath.split('/modules/')[0] : '';
    let controller = null;
    let sourceNav = null;
    let workspaceBar = null;
    let viewList = null;
    let currentLabel = null;
    let refreshQueued = false;

    function text(el, selectors) {
        for (const selector of selectors) {
            const match = el.querySelector(selector);
            const value = match && match.textContent.trim();
            if (value && !/^\d+$/.test(value) && !/^step\s*\d+$/i.test(value)) return value;
        }
        return el.textContent.replace(/^\s*(step\s*)?\d+\s*/i, '').trim();
    }

    function iconClass(el) {
        const icon = el.querySelector('i');
        if (!icon) return 'ti ti-layout-dashboard';
        const tabler = [...icon.classList].find(name => name.startsWith('ti-'));
        if (tabler) return 'ti ' + tabler;
        return 'ti ti-circle';
    }

    function sourceItems() {
        if (!sourceNav) return [];
        const buttons = [...sourceNav.querySelectorAll('button')];
        return buttons.length ? buttons : [...sourceNav.querySelectorAll(':scope > li')];
    }

    function sectionAssignmentAction(index) {
        return function () {
            const sat = window.SAT;
            if (!sat) return;
            if (index === 0) sat.goToStep('open');
            else if (index === 1) sat.startCreateNew();
            else if (index === 2 && sat.current) sat.submitSectionForm();
            else if (index === 3 && sat.current) sat.goToValidate();
            else if (index === 4 && sourceItems()[4]?.classList.contains('active')) sat.goToStep('saved');
            else if (index === 5 && (sourceItems()[4]?.classList.contains('active') || sourceItems()[4]?.classList.contains('done'))) sat.goToNextModuleStep();
            else sat.showAlert('Open or create a section first. Required validation is still enforced.', 'warning');
            closeMenus();
            queueRefresh();
        };
    }

    function itemState(source, index) {
        const host = source.matches('li') ? source : source.closest('li') || source;
        const active = source.classList.contains('active') || host.classList.contains('active');
        let locked = Boolean(source.disabled || source.getAttribute('aria-disabled') === 'true');
        if (controller.sectionAssignment) {
            const sat = window.SAT;
            const sourceState = sourceItems();
            if (index === 2 && !sat?.current) locked = true;
            if (index === 3 && !sat?.current?.subjects?.length) locked = true;
            if (index === 4 && !sourceState[4]?.classList.contains('active')) locked = true;
            if (index === 5 && !(sourceState[4]?.classList.contains('active') || sourceState[4]?.classList.contains('done'))) locked = true;
        }
        return { active, locked };
    }

    function refreshViews() {
        if (!viewList || !sourceNav) return;
        const items = sourceItems();
        viewList.innerHTML = '';
        let activeName = 'Overview';

        items.forEach((source, index) => {
            const name = text(source, ['strong', 'b', 'small']) || `View ${index + 1}`;
            const detail = text(source, ['small']);
            const state = itemState(source, index);
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'scheduling-workspace-item' + (state.active ? ' is-active' : '') + (state.locked ? ' is-locked' : '');
            button.disabled = state.locked;
            button.innerHTML = `<i class="${iconClass(source)}"></i><span><strong>${escapeHtml(name)}</strong>${detail && detail !== name ? `<small>${escapeHtml(detail)}</small>` : ''}</span><i class="ti ${state.locked ? 'ti-lock' : 'ti-chevron-right'}"></i>`;
            button.addEventListener('click', controller.sectionAssignment ? sectionAssignmentAction(index) : function () {
                source.click();
                closeMenus();
                queueRefresh();
            });
            viewList.appendChild(button);
            if (state.active) activeName = name;
        });

        if (currentLabel) currentLabel.textContent = activeName;
    }

    function escapeHtml(value) {
        const node = document.createElement('span');
        node.textContent = String(value || '');
        return node.innerHTML;
    }

    function moduleMenu() {
        const currentFile = pagePath.split('/').pop();
        return moduleLinks.map(([icon, label, file]) => {
            const overview = file === 'index.php';
            const active = currentFile === file && (overview ? !pagePath.includes('/pages/') : true);
            const href = overview ? `${baseUrl}/modules/scheduling/index.php` : `${baseUrl}/modules/scheduling/pages/${file}`;
            return `<a class="scheduling-workspace-item${active ? ' is-active' : ''}" href="${href}"><i class="ti ${icon}"></i><span><strong>${label}</strong><small>${overview ? 'Open module home' : 'Open subsystem'}</small></span><i class="ti ${active ? 'ti-check' : 'ti-chevron-right'}"></i></a>`;
        }).join('');
    }

    function closeMenus(except) {
        document.querySelectorAll('.scheduling-workspace-menu[open]').forEach(menu => {
            if (menu !== except) menu.removeAttribute('open');
        });
    }

    function cleanWizardLanguage() {
        document.querySelectorAll('.spc-pill, .ccx-pill, .rac-badge, .sat-badge, .cln-pill, .tb-pill, .ex-pill').forEach(badge => {
            if (/^\s*step\s+\d+\s*$/i.test(badge.textContent)) badge.classList.add('scheduling-step-badge');
        });
        document.querySelectorAll('button').forEach(button => {
            [...button.childNodes].filter(node => node.nodeType === Node.TEXT_NODE).forEach(node => {
                node.textContent = node.textContent
                    .replace(/\bNext:\s*/gi, 'Open ')
                    .replace(/\bContinue to\s+/gi, 'Open ')
                    .replace(/\bProceed to\s+/gi, 'Open ')
                    .replace(/\bBack to\s+/gi, 'Return to ')
                    .replace(/^\s*Back\s*$/gi, ' Return ');
            });
        });
    }

    function queueRefresh() {
        if (refreshQueued) return;
        refreshQueued = true;
        requestAnimationFrame(() => {
            refreshQueued = false;
            cleanWizardLanguage();
            refreshViews();
        });
    }

    function createWorkspaceBar() {
        const pageTitle = document.querySelector('h1')?.textContent.trim() || 'Scheduling Workspace';
        workspaceBar = document.createElement('div');
        workspaceBar.className = 'scheduling-workspace-bar no-print';
        workspaceBar.setAttribute('aria-label', 'Scheduling workspace navigation');
        workspaceBar.innerHTML = `
            <div class="scheduling-workspace-home">
                <i class="ti ti-layout-dashboard"></i>
                <span><strong>${escapeHtml(pageTitle)} Workspace</strong><small>Open any available view without a numbered wizard</small></span>
            </div>
            <div class="scheduling-workspace-actions">
                ${sourceNav ? `<details class="scheduling-workspace-menu"><summary><i class="ti ti-layout-grid"></i><span>Current view: <b data-current-view>Overview</b></span><i class="ti ti-chevron-down"></i></summary><div class="scheduling-workspace-popover"><div class="scheduling-workspace-popover-title">Workspace views</div><div data-view-list></div></div></details>` : ''}
                <details class="scheduling-workspace-menu"><summary><i class="ti ti-apps"></i><span>Scheduling Modules</span><i class="ti ti-chevron-down"></i></summary><div class="scheduling-workspace-popover"><div class="scheduling-workspace-popover-title">Open a subsystem</div>${moduleMenu()}</div></details>
            </div>`;

        const insertionTarget = sourceNav
            ? ((controller.wrapper ? sourceNav.closest(controller.wrapper) : null) || sourceNav)
            : document.querySelector('.sms-main > *');
        if (insertionTarget && insertionTarget.parentNode) insertionTarget.parentNode.insertBefore(workspaceBar, insertionTarget);
        else document.querySelector('.sms-main')?.prepend(workspaceBar);
        viewList = workspaceBar.querySelector('[data-view-list]');
        currentLabel = workspaceBar.querySelector('[data-current-view]');

        workspaceBar.querySelectorAll('details').forEach(menu => menu.addEventListener('toggle', () => {
            if (menu.open) closeMenus(menu);
        }));
    }

    function init() {
        controller = controllers.find(entry => document.querySelector(entry.selector)) || null;
        sourceNav = controller ? document.querySelector(controller.selector) : null;
        if (sourceNav) {
            const oldWrapper = controller.wrapper ? sourceNav.closest(controller.wrapper) : sourceNav;
            (oldWrapper || sourceNav).classList.add('scheduling-wizard-removed');
            sourceNav.setAttribute('aria-hidden', 'true');
        }
        createWorkspaceBar();
        cleanWizardLanguage();
        refreshViews();

        if (sourceNav) new MutationObserver(queueRefresh).observe(sourceNav, {
            subtree: true,
            childList: true,
            attributes: true,
            attributeFilter: ['class', 'disabled', 'aria-disabled']
        });
        document.addEventListener('click', event => {
            if (!event.target.closest('.scheduling-workspace-menu')) closeMenus();
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
