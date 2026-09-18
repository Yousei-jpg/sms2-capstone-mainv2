<?php
/**
 * SMS 2 - Section Assignment Tool
 * Module: Class Scheduling
 *
 * Direct-access workspace supporting:
 *   1. Open Section Assignment  (create new OR select existing)
 *   2. Section Information      (create)   / Select Existing Section (existing)
 *   3. Assign Subjects          (create)   / Section Details         (existing)
 *   4. Review & Validate        (create only)
 *   5. Save Section Assignment  (create only)
 *   6. Proceed to Next Module   (Teacher Schedule Mapping)
 *
 * Backend note: sections and subjects are loaded and saved through the scheduling API.
 */
require_once __DIR__ . '/../../../config/config.php';

$pageTitle    = 'Section Assignment Tool';
$activeModule = 'scheduling';
$activePage   = 'section-assignment-tool';
$breadcrumbs  = [
    ['label' => 'Class Scheduling', 'url' => BASE_URL . '/modules/scheduling/index.php'],
    ['label' => 'Section Assignment Tool', 'url' => null],
];

$hideModulePageBanner = true;

require_once __DIR__ . '/../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../includes/layout-start.php';
?>

<?php renderBreadcrumbs($breadcrumbs); ?>

<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div>
        <h1><i class="fas fa-layer-group text-sms-primary me-2"></i>Section Assignment Tool</h1>
        <p>Select or create the record that belongs to this page.</p>
    </div>
    <div class="sat-term-badge">
        <i class="fas fa-calendar-alt me-2"></i>
        <span id="satTermBadge">SY 2026-2027 &middot; 1st Semester</span>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="SAT.exportSections()"><i class="fas fa-file-export me-1"></i>Export</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="SAT.printSections()"><i class="fas fa-print me-1"></i>Print</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="SAT.showHistory()"><i class="fas fa-clock-rotate-left me-1"></i>History</button>
    </div>
</div>

<!-- ================= Stepper ================= -->
<div class="card mb-3">
    <div class="card-body py-3">
        <ol class="sat-stepper" id="satStepper">
            <li data-step="1"><span class="sat-step-dot"><i class="fas fa-folder-open"></i></span><div><strong>Open Section Assignment</strong><small>Select or create a record</small></div></li>
            <li data-step="2"><span class="sat-step-dot"><i class="fas fa-clipboard-list"></i></span><div><strong>Section Information</strong><small>Basic section details</small></div></li>
            <li data-step="3"><span class="sat-step-dot"><i class="fas fa-book"></i></span><div><strong>Assign Subjects</strong><small>Subjects the section will take</small></div></li>
            <li data-step="4"><span class="sat-step-dot"><i class="fas fa-shield-halved"></i></span><div><strong>Review &amp; Validate</strong><small>Check before saving</small></div></li>
            <li data-step="5"><span class="sat-step-dot"><i class="fas fa-floppy-disk"></i></span><div><strong>Save Section</strong><small>Confirm &amp; store</small></div></li>
            <li data-step="6"><span class="sat-step-dot"><i class="fas fa-user-group"></i></span><div><strong>Next Module</strong><small>Teacher scheduling mapping</small></div></li>
        </ol>
    </div>
</div>

<div id="satAlert" class="alert alert-success submodule-alert d-none" role="alert">
    <i class="fas fa-check-circle me-2"></i><span id="satAlertText"></span>
</div>

<div class="row g-3 mb-3" id="satOverviewCards">
    <div class="col-sm-6 col-xl-3">
        <div class="card sat-overview-card h-100">
            <div class="card-body"><span class="sat-overview-icon primary"><i class="fas fa-layer-group"></i></span><div><span class="sat-overview-label">All Sections</span><strong id="satOverviewTotal">0</strong><small><i class="fas fa-calendar-check me-1"></i>Current term records</small></div></div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card sat-overview-card h-100">
            <div class="card-body"><span class="sat-overview-icon success"><i class="fas fa-circle-check"></i></span><div><span class="sat-overview-label">Active Sections</span><strong id="satOverviewActive">0</strong><small><i class="fas fa-clipboard-check me-1"></i>Ready for subject review</small></div></div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card sat-overview-card h-100">
            <div class="card-body"><span class="sat-overview-icon warning"><i class="fas fa-pen-to-square"></i></span><div><span class="sat-overview-label">Drafts</span><strong id="satOverviewDrafts">0</strong><small><i class="fas fa-hourglass-half me-1"></i>Need completion</small></div></div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card sat-overview-card h-100">
            <div class="card-body"><span class="sat-overview-icon info"><i class="fas fa-book-open"></i></span><div><span class="sat-overview-label">Assigned Subjects</span><strong id="satOverviewSubjects">0</strong><small><i class="fas fa-graduation-cap me-1"></i>Across all sections</small></div></div>
        </div>
    </div>
</div>

<!-- ================= STEP 1: Open Section Assignment ================= -->
<section class="sat-step" id="sat-step-open">
    <div class="row g-3 mb-1">
        <div class="col-md-6">
            <div class="card hover-card sat-choice-card h-100" onclick="SAT.startSelectExisting()">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="sat-choice-icon primary"><i class="fas fa-folder-open"></i></div>
                    <div>
                        <h6 class="mb-1 fw-semibold"><i class="fas fa-list-check text-sms-primary me-1"></i>Select Existing Section</h6>
                        <p class="text-muted mb-0 small">Search and select a section that has already been created.</p>
                    </div>
                    <i class="fas fa-chevron-right ms-auto text-muted"></i>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card hover-card sat-choice-card h-100" onclick="SAT.startCreateNew()">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="sat-choice-icon success"><i class="fas fa-plus"></i></div>
                    <div>
                        <h6 class="mb-1 fw-semibold"><i class="fas fa-circle-plus text-success me-1"></i>Create New Section</h6>
                        <p class="text-muted mb-0 small">Create a new section and assign subjects.</p>
                    </div>
                    <i class="fas fa-chevron-right ms-auto text-muted"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="card mt-3">
        <div class="card-body">
            <h5 class="card-title fw-semibold mb-3"><i class="fas fa-clock-rotate-left text-sms-primary me-2"></i>Recently Created / Updated Sections</h5>
            <div class="table-responsive">
                <table class="table submodule-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Section Code</th>
                            <th>Section Name</th>
                            <th>Program</th>
                            <th>Year Level</th>
                            <th>Semester</th>
                            <th>Students</th>
                            <th>Status</th>
                            <th>Last Updated</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody id="satRecentTableBody"></tbody>
                </table>
            </div>
        </div>
    </div>
</section>

<!-- ================= STEP 2a: Select Existing Section ================= -->
<section class="sat-step d-none" id="sat-step-select">
    <div class="card">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                <div>
                    <h5 class="card-title fw-semibold mb-1"><i class="fas fa-magnifying-glass text-sms-primary me-2"></i>Select Existing Section</h5>
                    <p class="text-muted small mb-0">Search and select the section you want to open.</p>
                </div>
                <div class="d-flex gap-2"><button type="button" class="btn btn-outline-secondary btn-sm" onclick="SAT.exportSections()"><i class="fas fa-file-export me-1"></i>Export List</button><button type="button" class="btn btn-outline-secondary btn-sm" onclick="SAT.goToStep('open')"><i class="fas fa-arrow-left me-1"></i>Back</button></div>
            </div>

            <div class="input-group mb-3">
                <span class="input-group-text"><i class="fas fa-search"></i></span>
                <input type="text" class="form-control" id="satSearchInput" placeholder="Search by Section Code or Section Name...">
            </div>

            <div class="row g-3 align-items-end mb-3">
                <div class="col-6 col-md-3">
                    <label class="form-label fw-semibold small">Program</label>
                    <select class="form-select" id="satFilterProgram">
                        <option value="">All Programs</option>
                        <option value="BSIT">BSIT</option>
                        <option value="BSCS">BSCS</option>
                        <option value="BIT">BIT</option>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label fw-semibold small">Year Level</label>
                    <select class="form-select" id="satFilterYear">
                        <option value="">All Years</option>
                        <option>1st Year</option>
                        <option>2nd Year</option>
                        <option>3rd Year</option>
                        <option>4th Year</option>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label fw-semibold small">Semester</label>
                    <select class="form-select" id="satFilterSemester">
                        <option value="">All Semesters</option>
                        <option>1st Semester</option>
                        <option>2nd Semester</option>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label fw-semibold small">Academic Year</label>
                    <select class="form-select" id="satFilterAY">
                        <option value="">All A.Y.</option>
                        <option>2026-2027</option>
                        <option>2025-2026</option>
                    </select>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table submodule-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Section Code</th>
                            <th>Section Name</th>
                            <th>Program</th>
                            <th>Year Level</th>
                            <th>Semester</th>
                            <th>Students</th>
                            <th>Status</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody id="satSelectTableBody"></tbody>
                </table>
            </div>
            <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
                <small class="text-muted" id="satSelectCount"></small>
                <nav><ul class="pagination pagination-sm mb-0" id="satSelectPagination"></ul></nav>
            </div>
        </div>
    </div>
</section>

<!-- ================= STEP 2b: Section Details (view existing) ================= -->
<section class="sat-step d-none" id="sat-step-details">
    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="card-title fw-semibold mb-3"><i class="fas fa-circle-info text-sms-primary me-2"></i>Section Information</h5>
                    <div class="student-detail-list" id="satDetailsInfo"></div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card mb-3">
                <div class="card-body">
                    <h5 class="card-title fw-semibold mb-3 d-flex justify-content-between">
                        <span><i class="fas fa-book text-sms-primary me-2"></i>Assigned Subjects</span>
                        <span class="badge bg-primary-subtle text-primary" id="satDetailsSubjectCount"></span>
                    </h5>
                    <div class="table-responsive">
                        <table class="table submodule-table align-middle mb-0">
                            <thead><tr><th>#</th><th>Code</th><th>Subject Name</th><th>Units</th><th>Type</th></tr></thead>
                            <tbody id="satDetailsSubjects"></tbody>
                            <tfoot>
                                <tr><td colspan="3" class="text-end fw-semibold">Total Units</td><td class="fw-bold" colspan="2" id="satDetailsTotalUnits"></td></tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title fw-semibold mb-3"><i class="fas fa-users text-sms-primary me-2"></i>Students Summary</h5>
                    <div class="row text-center g-2">
                        <div class="col-4">
                            <div class="sat-mini-stat"><i class="fas fa-user-graduate text-sms-primary"></i><strong id="satDetailsMax"></strong><span>Maximum</span></div>
                        </div>
                        <div class="col-4">
                            <div class="sat-mini-stat"><i class="fas fa-user-check text-sms-primary"></i><strong id="satDetailsCurrent"></strong><span>Current</span></div>
                        </div>
                        <div class="col-4">
                            <div class="sat-mini-stat"><i class="fas fa-user-plus text-sms-primary"></i><strong id="satDetailsAvailable"></strong><span>Available</span></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="submodule-action-bar">
        <button type="button" class="btn btn-outline-secondary" onclick="SAT.goToStep('select')"><i class="fas fa-arrow-left me-2"></i>Back to List</button>
        <button type="button" class="btn btn-outline-secondary" onclick="SAT.printSection()"><i class="fas fa-print me-2"></i>Print</button>
        <button type="button" class="btn btn-outline-secondary" onclick="SAT.showHistory()"><i class="fas fa-clock-rotate-left me-2"></i>History</button>
        <button type="button" class="btn btn-outline-primary" id="satEditSectionBtn"><i class="fas fa-pen me-2"></i>Edit Section</button>
        <button type="button" class="btn btn-sms-primary ms-auto" id="satContinueTeacherBtn">Continue to Teacher Mapping<i class="fas fa-arrow-right ms-2"></i></button>
    </div>
</section>

<!-- ================= STEP 2c: Section Information (create/edit form) ================= -->
<section class="sat-step d-none" id="sat-step-form">
    <div class="card">
        <div class="card-body">
            <h5 class="card-title fw-semibold mb-3"><i class="fas fa-clipboard-list text-sms-primary me-2"></i><span id="satFormTitle">Section Information</span></h5>
            <form id="satSectionForm" novalidate>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Section Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="satFieldName" placeholder="e.g. BSIT 3C" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Section Code <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="satFieldCode" placeholder="e.g. BSIT-3C" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Program / Course <span class="text-danger">*</span></label>
                        <select class="form-select" id="satFieldProgram" required>
                            <option value="">Select program...</option>
                            <option value="BSIT">Bachelor of Science in Information Technology (BSIT)</option>
                            <option value="BSCS">Bachelor of Science in Computer Science (BSCS)</option>
                            <option value="BIT">Bachelor in Industrial Technology (BIT)</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Year Level <span class="text-danger">*</span></label>
                        <select class="form-select" id="satFieldYear" required>
                            <option value="">Select year level...</option>
                            <option>1st Year</option>
                            <option>2nd Year</option>
                            <option>3rd Year</option>
                            <option>4th Year</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Semester <span class="text-danger">*</span></label>
                        <select class="form-select" id="satFieldSemester" required>
                            <option>1st Semester</option>
                            <option>2nd Semester</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Academic Year <span class="text-danger">*</span></label>
                        <select class="form-select" id="satFieldAY" required>
                            <option>2026-2027</option>
                            <option>2025-2026</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Maximum Students <span class="text-danger">*</span></label>
                        <input type="number" min="1" max="80" class="form-control" id="satFieldMax" placeholder="e.g. 45" required>
                    </div>
                    <div class="col-12">
                        <div class="alert alert-primary bg-primary-subtle border-0 small mb-0">
                            <i class="fas fa-circle-info me-2"></i>Maximum students will be used to validate room capacity and generate schedule.
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <div class="submodule-action-bar">
        <button type="button" class="btn btn-outline-secondary" onclick="SAT.cancelForm()"><i class="fas fa-xmark me-2"></i>Cancel</button>
        <button type="button" class="btn btn-outline-primary" onclick="SAT.saveDraftFromForm()"><i class="fas fa-file-pen me-2"></i>Save Draft</button>
        <button type="button" class="btn btn-sms-primary ms-auto" onclick="SAT.submitSectionForm()">Next: Assign Subjects<i class="fas fa-arrow-right ms-2"></i></button>
    </div>
</section>

<!-- ================= STEP 3: Assign Subjects to Section ================= -->
<section class="sat-step d-none" id="sat-step-subjects">
    <div class="card">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                <h5 class="card-title fw-semibold mb-0"><i class="fas fa-book text-sms-primary me-2"></i>Assign Subjects to Section</h5>
                <span class="badge bg-primary-subtle text-primary" id="satSubjectsSectionBadge"></span>
            </div>
            <p class="text-muted small mb-3" id="satSubjectsMeta"></p>

            <div class="input-group input-group-sm mb-3">
                <span class="input-group-text"><i class="fas fa-search"></i></span>
                <input type="search" class="form-control" id="satSubjectSearch" placeholder="Search available subjects by code or name">
            </div>

            <div class="table-responsive">
                <table class="table submodule-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width:36px">#</th>
                            <th>Subject Code</th>
                            <th>Subject Name</th>
                            <th>Units</th>
                            <th>Type</th>
                            <th class="text-center" style="width:70px">Select</th>
                        </tr>
                    </thead>
                    <tbody id="satSubjectsTableBody"></tbody>
                </table>
            </div>
            <div class="d-flex justify-content-end gap-4 mt-3 pt-3 border-top">
                <div><span class="text-muted small">Total Subjects</span> <strong id="satTotalSubjects">0</strong></div>
                <div><span class="text-muted small">Total Units</span> <strong id="satTotalUnits">0</strong></div>
            </div>
        </div>
    </div>
    <div class="submodule-action-bar">
        <button type="button" class="btn btn-outline-secondary" onclick="SAT.goToStep('form')"><i class="fas fa-arrow-left me-2"></i>Back</button>
        <button type="button" class="btn btn-outline-primary" onclick="SAT.saveDraft()"><i class="fas fa-file-pen me-2"></i>Save Draft</button>
        <button type="button" class="btn btn-sms-primary ms-auto" onclick="SAT.goToValidate()">Validate Section<i class="fas fa-shield-halved ms-2"></i></button>
    </div>
</section>

<!-- ================= STEP 4: Review & Validate ================= -->
<section class="sat-step d-none" id="sat-step-validate">
    <div class="card">
        <div class="card-body text-center py-4">
            <div class="mb-2" id="satValidateIcon"><i class="fas fa-circle-check fa-3x text-success"></i></div>
            <h4 class="fw-semibold mb-1" id="satValidateTitle">Validation Passed!</h4>
            <p class="text-muted mb-4" id="satValidateSub">All information is valid and ready to save.</p>
            <div class="sat-validate-list text-start mx-auto" id="satValidateList" style="max-width:560px;"></div>
        </div>
    </div>
    <div class="submodule-action-bar">
        <button type="button" class="btn btn-outline-secondary" onclick="SAT.goToStep('subjects')"><i class="fas fa-arrow-left me-2"></i>Back to Edit</button>
        <button type="button" class="btn btn-sms-primary ms-auto" id="satSaveBtn" onclick="SAT.saveSection()">Save Section Assignment<i class="fas fa-floppy-disk ms-2"></i></button>
    </div>
</section>

<!-- ================= STEP 5: Save Section Assignment (success) ================= -->
<section class="sat-step d-none" id="sat-step-saved">
    <div class="card">
        <div class="card-body text-center py-5">
            <div class="mb-3"><i class="fas fa-file-circle-check fa-4x text-success opacity-75"></i></div>
            <h4 class="fw-semibold mb-1">Section assignment has been saved successfully!</h4>
            <p class="text-muted mb-4">You can proceed to teacher scheduling mapping for this section.</p>
            <div class="student-record-grid mx-auto text-start" style="max-width:640px;" id="satSavedSummary"></div>
        </div>
    </div>
    <div class="submodule-action-bar">
        <button type="button" class="btn btn-outline-secondary" onclick="SAT.goToStep('open')"><i class="fas fa-list me-2"></i>Back to List</button>
        <button type="button" class="btn btn-sms-primary ms-auto" onclick="SAT.goToNextModuleStep()">Proceed to Teacher Mapping<i class="fas fa-arrow-right ms-2"></i></button>
    </div>
</section>

<!-- ================= STEP 6: What's Next ================= -->
<section class="sat-step d-none" id="sat-step-next">
    <div class="card">
        <div class="card-body text-center py-4">
            <div class="mb-3"><i class="fas fa-user-group fa-3x text-sms-primary opacity-75"></i></div>
            <h4 class="fw-semibold mb-1">The section is ready for teacher assignment.</h4>
            <p class="text-muted mb-4 mx-auto" style="max-width:520px;">Proceed to Teacher Scheduling Mapping to assign qualified teachers for each subject.</p>
            <div class="student-record-grid mx-auto text-start mb-2" style="max-width:640px;" id="satNextSummary"></div>
        </div>
    </div>
    <div class="submodule-action-bar">
        <a href="<?= BASE_URL ?>/dashboard/index.php" class="btn btn-outline-secondary"><i class="fas fa-house me-2"></i>Back to Dashboard</a>
        <a href="#" class="btn btn-sms-primary ms-auto" id="satGoToTeacherMappingBtn">Go to Teacher Mapping<i class="fas fa-arrow-right ms-2"></i></a>
    </div>
</section>

<div class="modal fade" id="satHistoryModal" tabindex="-1" aria-labelledby="satHistoryTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title" id="satHistoryTitle"><i class="fas fa-clock-rotate-left text-sms-primary me-2"></i>Section Assignment History</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body"><p class="text-muted small">Front-end activity for the current browser session.</p><div class="table-responsive"><table class="table submodule-table align-middle mb-0"><thead><tr><th>Date &amp; Time</th><th>Section</th><th>Action</th><th>Details</th></tr></thead><tbody id="satHistoryTableBody"></tbody></table></div></div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button></div>
        </div>
    </div>
</div>

<style>
.sat-term-badge{display:inline-flex;align-items:center;padding:.55rem .95rem;border-radius:999px;border:1px solid var(--sms-glass-border);background:var(--sms-surface);color:var(--sms-primary);font-weight:700;font-size:.82rem;backdrop-filter:var(--sms-glass-blur);-webkit-backdrop-filter:var(--sms-glass-blur);}
.sat-stepper{list-style:none;display:flex;flex-wrap:wrap;gap:.5rem;margin:0;padding:0;counter-reset:sat;}
.sat-stepper li{flex:1 1 150px;display:flex;align-items:center;gap:.6rem;padding:.5rem .6rem;border-radius:10px;opacity:.55;transition:var(--sms-transition);}
.sat-stepper li strong{display:block;font-size:.8rem;color:var(--sms-heading);line-height:1.2;}
.sat-stepper li small{display:block;font-size:.68rem;color:var(--sms-text-muted);}
.sat-step-dot{width:32px;height:32px;flex-shrink:0;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;background:var(--sms-surface-muted);color:var(--sms-text-muted);border:1px solid var(--sms-border);font-size:.85rem;}
.sat-stepper li.active{opacity:1;background:var(--sms-primary-xlight);}
.sat-stepper li.active .sat-step-dot{background:var(--sms-primary);color:#fff;border-color:transparent;}
.sat-stepper li.done{opacity:1;}
.sat-stepper li.done .sat-step-dot{background:var(--sms-success);color:#fff;border-color:transparent;}
.sat-choice-card{cursor:pointer;}
.sat-choice-icon{width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0;}
.sat-choice-icon.primary{background:var(--sms-primary-xlight);color:var(--sms-primary);}
.sat-choice-icon.success{background:rgba(22,163,74,.15);color:var(--sms-success);}
.sat-row-icon{display:inline-flex;align-items:center;justify-content:center;width:23px;height:23px;margin-right:.35rem;border-radius:7px;background:var(--sms-primary-xlight);color:var(--sms-primary);font-size:.7rem;vertical-align:middle;}
.sat-mini-stat{padding:.75rem .5rem;border:1px solid var(--sms-border);border-radius:10px;background:var(--sms-surface-muted);}
.sat-mini-stat i{display:block;margin-bottom:.35rem;}
.sat-mini-stat strong{display:block;font-size:1.15rem;color:var(--sms-heading);}
.sat-mini-stat span{font-size:.72rem;color:var(--sms-text-muted);}
.sat-validate-list{display:grid;gap:.55rem;}
.sat-validate-list div{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:.6rem .85rem;border:1px solid var(--sms-border);border-radius:10px;background:var(--sms-surface-muted);font-size:.88rem;}
.sat-validate-list .ok{color:var(--sms-success);font-weight:700;font-size:.8rem;}
.sat-validate-list .fail{color:var(--sms-danger);font-weight:700;font-size:.8rem;}
.sat-subject-row.selected{background:var(--sms-primary-xlight);}
.sat-overview-card{border:1px solid var(--sms-border);transition:transform .2s ease,box-shadow .2s ease;}.sat-overview-card:hover{transform:translateY(-2px);box-shadow:0 .5rem 1.2rem rgba(16,71,132,.1);}.sat-overview-card .card-body{display:flex;align-items:center;gap:.85rem;padding:1rem;}.sat-overview-icon{width:42px;height:42px;display:inline-flex;align-items:center;justify-content:center;flex:0 0 42px;border-radius:12px;font-size:1rem;}.sat-overview-icon.primary{color:var(--sms-primary);background:var(--sms-primary-xlight);}.sat-overview-icon.success{color:var(--sms-success);background:rgba(22,163,74,.13);}.sat-overview-icon.warning{color:#b7791f;background:rgba(245,158,11,.14);}.sat-overview-icon.info{color:#0e7490;background:rgba(6,182,212,.12);}.sat-overview-label,.sat-overview-card small{display:block;color:var(--sms-text-muted);font-size:.74rem;}.sat-overview-card strong{display:block;color:var(--sms-heading);font-size:1.35rem;line-height:1.2;}.sat-flow-list{display:grid;gap:.75rem;}.sat-flow-list>div{display:flex;gap:.75rem;align-items:flex-start;}.sat-flow-list>div>span{width:27px;height:27px;display:inline-flex;align-items:center;justify-content:center;flex:0 0 27px;border-radius:50%;background:var(--sms-primary-xlight);color:var(--sms-primary);font-weight:700;font-size:.78rem;}.sat-flow-list strong,.sat-flow-list small{display:block;}.sat-flow-list strong{color:var(--sms-heading);font-size:.86rem;}.sat-flow-list small{color:var(--sms-text-muted);font-size:.76rem;}.sat-check-list{padding:0;margin:0;list-style:none;display:grid;gap:.55rem;}.sat-check-list li{display:flex;gap:.55rem;align-items:flex-start;font-size:.8rem;color:var(--sms-text);}.sat-check-list i{color:var(--sms-success);margin-top:.15rem;}
@media (max-width:767.98px){ .sat-stepper li{flex:1 1 100%;} }
</style>

<script>
(function () {
"use strict";

/* ---------------------------------------------------------------------
 * SAT.db — real database data layer.
 * Data is loaded from the PHP API below instead of browser-only mock data.
 * ------------------------------------------------------------------- */
const SMS_BASE = '<?= BASE_URL ?>';
const SAT_API = SMS_BASE + '/api/scheduling/section-assignment.php';

let subjectCatalog = [];
let sections = [];
let activityHistory = [];
let satReference = { programs: [], yearLevels: [], semesters: [], academicYears: [], currentTerm: null };

function satOrdinalYear(value) {
    const n = Number(value || 0);
    if (n === 1) return '1st Year';
    if (n === 2) return '2nd Year';
    if (n === 3) return '3rd Year';
    return `${n}th Year`;
}

function satDateLabel(value) {
    if (!value) return '—';
    const date = new Date(String(value).replace(' ', 'T'));
    return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString([], { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
}

function satSectionFromApi(row) {
    return {
        id: Number(row.id), code: row.code, name: row.name, program: row.program,
        year: satOrdinalYear(row.year_level), sem: row.semester, ay: row.academic_year,
        maxStudents: Number(row.max_students || 0), currentStudents: Number(row.current_students || 0),
        status: row.status || 'Active', created: satDateLabel(row.created_at), updated: satDateLabel(row.updated_at),
        advisor: row.advisor_name || '', subjects: Array.isArray(row.subjects) ? row.subjects : []
    };
}

function satSetOptions(id, values, placeholder = null, selected = '') {
    const select = document.getElementById(id);
    if (!select) return;
    const first = placeholder !== null ? `<option value="">${SAT.escape(placeholder)}</option>` : '';
    select.innerHTML = first + values.map(item => {
        const value = typeof item === 'object' ? item.value : item;
        const label = typeof item === 'object' ? item.label : item;
        return `<option value="${SAT.escape(value)}"${String(value) === String(selected) ? ' selected' : ''}>${SAT.escape(label)}</option>`;
    }).join('');
}

async function loadSATData() {
    const response = await fetch(SAT_API, { headers: { Accept: 'application/json' } });
    const body = await response.json().catch(() => null);
    if (!response.ok || !body?.ok) throw new Error(body?.error || 'Section Assignment data could not be loaded.');

    subjectCatalog = (body.subjects || []).map(subject => ({
        id: Number(subject.id), code: subject.code, name: subject.name,
        units: Number(subject.units || 0), type: subject.subject_type || 'Subject',
        program: subject.program || 'ALL', yearLevel: subject.year_level === null ? null : Number(subject.year_level),
        semester: subject.semester || null
    }));
    sections = (body.sections || []).map(satSectionFromApi);
    satReference = {
        programs: body.programs || [], yearLevels: body.year_levels || [],
        semesters: body.semesters || [], academicYears: body.academic_years || [],
        currentTerm: body.current_term || null
    };

    const current = satReference.currentTerm || {};
    document.getElementById('satTermBadge').textContent = `SY ${current.academic_year || '—'} · ${current.semester || '—'}`;
    const programs = satReference.programs.map(program => ({ value: program.code, label: `${program.name} (${program.code})` }));
    const years = satReference.yearLevels.map(level => satOrdinalYear(level));
    satSetOptions('satFieldProgram', programs, 'Select program...');
    satSetOptions('satFieldYear', years, 'Select year level...');
    satSetOptions('satFieldSemester', satReference.semesters, null, current.semester || '');
    satSetOptions('satFieldAY', satReference.academicYears, null, current.academic_year || '');
    satSetOptions('satFilterProgram', programs, 'All Programs');
    satSetOptions('satFilterYear', years, 'All Year Levels');
    satSetOptions('satFilterSemester', satReference.semesters, 'All Semesters');
    satSetOptions('satFilterAY', satReference.academicYears, 'All Academic Years');
}

/* The foundation uses Tabler Icons. Convert the legacy Font Awesome markup
 * in this legacy page so every visual icon renders in the existing UI. */
function renderSectionIcons(root = document) {
    const iconNames = {
        'layer-group': 'stack-2', 'calendar-alt': 'calendar', 'floppy-disk': 'device-floppy',
        'shield-halved': 'shield', 'folder-open': 'folder-open', 'clipboard-list': 'clipboard-list',
        'user-group': 'users-group', 'file-circle-check': 'file-check', 'clock-rotate-left': 'history',
        'circle-info': 'info-circle', 'magnifying-glass': 'search', 'triangle-exclamation': 'alert-triangle',
        'circle-plus': 'circle-plus', 'file-pen': 'file-pencil', 'pen-to-square': 'edit',
        'graduation-cap': 'school', 'user-graduate': 'school', 'house': 'home',
        'xmark': 'x', 'check-circle': 'circle-check', 'circle-check': 'circle-check',
        'circle-xmark': 'circle-x', 'circle-info': 'info-circle', 'print': 'printer',
        'list-check': 'list-check', 'clipboard-check': 'clipboard-check', 'hourglass-half': 'hourglass'
    };
    const icons = root.matches?.('i.fas, i.far') ? [root] : root.querySelectorAll?.('i.fas, i.far') || [];
    icons.forEach(icon => {
        const faClass = [...icon.classList].find(name => name.startsWith('fa-'));
        if (!faClass) return;
        const name = faClass.slice(3);
        icon.classList.remove('fas', 'far', faClass);
        icon.classList.add('ti', 'ti-' + (iconNames[name] || name));
    });
}
/* ---------------------------------------------------------------------
 * SAT — wizard controller
 * ------------------------------------------------------------------- */
const SAT = {
    mode: null,          // 'create' | 'edit'
    editingCode: null,
    current: null,        // working section object while building
    selectPage: 1,
    pageSize: 5,
    subjectQuery: '',

    escape(str) {
        return String(str ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    },

    /* ---------- navigation ---------- */
    goToStep(name) {
        document.querySelectorAll('.sat-step').forEach(el => el.classList.add('d-none'));
        document.getElementById('sat-step-' + name).classList.remove('d-none');
        window.scrollTo({ top: document.querySelector('.page-header').offsetTop - 20, behavior: 'smooth' });
        this.updateStepper(name);
    },

    stepMap: {
        open: 1, select: 2, details: 3, form: 2, subjects: 3, validate: 4, saved: 5, next: 6,
    },

    updateStepper(name) {
        const active = this.stepMap[name] || 1;
        document.querySelectorAll('#satStepper li').forEach(li => {
            const n = parseInt(li.dataset.step, 10);
            li.classList.remove('active', 'done');
            if (n < active) li.classList.add('done');
            else if (n === active) li.classList.add('active');
        });
    },

    showAlert(msg) {
        const box = document.getElementById('satAlert');
        document.getElementById('satAlertText').textContent = msg;
        box.classList.remove('d-none');
        clearTimeout(this._alertTimer);
        this._alertTimer = setTimeout(() => box.classList.add('d-none'), 4000);
    },

    recordHistory(action, details, section = this.current?.code || '—') {
        activityHistory.unshift({ at: 'Just now', section, action, details });
    },

    showHistory() {
        const body = document.getElementById('satHistoryTableBody');
        body.innerHTML = activityHistory.map(item => `<tr><td>${this.escape(item.at)}</td><td class="fw-semibold">${this.escape(item.section)}</td><td><span class="badge bg-primary-subtle text-primary">${this.escape(item.action)}</span></td><td>${this.escape(item.details)}</td></tr>`).join('') || '<tr><td colspan="4" class="text-center text-muted py-3">No activity recorded yet.</td></tr>';
        const modal = document.getElementById('satHistoryModal');
        if (window.bootstrap?.Modal) window.bootstrap.Modal.getOrCreateInstance(modal).show();
    },

    exportSections() {
        const records = this.filteredSections?.().length ? this.filteredSections() : sections;
        const header = ['Section Code', 'Section Name', 'Program', 'Year Level', 'Semester', 'Academic Year', 'Capacity', 'Students', 'Status', 'Subjects'];
        const escapeCsv = value => `"${String(value ?? '').replaceAll('"', '""')}"`;
        const rows = records.map(section => [section.code, section.name, section.program, section.year, section.sem, section.ay, section.maxStudents, section.currentStudents, section.status, (section.subjects || []).join(', ')]);
        const csv = [header, ...rows].map(row => row.map(escapeCsv).join(',')).join('\r\n');
        const download = document.createElement('a');
        download.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8;' }));
        download.download = 'section-assignments.csv';
        download.click();
        URL.revokeObjectURL(download.href);
        this.showAlert('Section assignments exported as a CSV file.');
    },

    printSections() {
        window.print();
    },

    printSection() {
        if (!this.current) return this.showAlert('Open a section before printing its details.');
        window.print();
    },

    saveDraftFromForm() {
        const read = id => document.getElementById(id).value.trim();
        const draftCode = read('satFieldCode').toUpperCase() || `DRAFT-${Date.now().toString().slice(-6)}`;
        this.current = {
            ...(this.current || {}),
            code: draftCode,
            name: read('satFieldName') || 'Untitled Section',
            program: document.getElementById('satFieldProgram').value || 'BSIT',
            year: document.getElementById('satFieldYear').value || '1st Year',
            sem: document.getElementById('satFieldSemester').value || '1st Semester',
            ay: document.getElementById('satFieldAY').value || '2026-2027',
            maxStudents: Number(document.getElementById('satFieldMax').value) || 0,
            currentStudents: this.current?.currentStudents || 0,
            subjects: this.current?.subjects || []
        };
        this.saveDraft();
    },

    saveDraft() {
        if (!this.current) return this.showAlert('Enter section information before saving a draft.');
        const c = this.current;
        c.id = c.id || Math.max(0, ...sections.map(section => section.id || 0)) + 1;
        c.status = 'Draft';
        c.created = c.created || 'Just now';
        c.updated = 'Just now';
        const existingIndex = sections.findIndex(section => section.id === c.id || section.code === this.editingCode);
        if (existingIndex >= 0) sections.splice(existingIndex, 1, { ...c, subjects: [...c.subjects] });
        else sections.unshift({ ...c, subjects: [...c.subjects] });
        this.current = sections.find(section => section.id === c.id);
        this.recordHistory('Draft saved', 'Section information saved for completion later.');
        this.renderRecent();
        this.showAlert('Draft saved in this front-end preview.');
        this.goToStep('open');
    },

    /* ---------- STEP 1 ---------- */
    renderRecent() {
        this.renderOverview();
        const body = document.getElementById('satRecentTableBody');
        const rows = sections.slice().sort((a, b) => (a.updated < b.updated ? 1 : -1)).slice(0, 5);
        body.innerHTML = rows.map(s => `
            <tr>
                <td class="fw-semibold"><span class="sat-row-icon"><i class="fas fa-users"></i></span>${this.escape(s.code)}</td>
                <td>${this.escape(s.name)}</td>
                <td>${this.escape(s.program)}</td>
                <td>${this.escape(s.year)}</td>
                <td>${this.escape(s.sem)}</td>
                <td>${s.currentStudents} / ${s.maxStudents}</td>
                <td><span class="badge ${s.status === 'Draft' ? 'text-bg-warning' : 'text-bg-success'}"><i class="fas ${s.status === 'Draft' ? 'fa-pen' : 'fa-check'} me-1"></i>${this.escape(s.status)}</span></td>
                <td>${this.escape(s.updated)}</td>
                <td class="text-end"><button type="button" class="btn btn-outline-primary btn-sm" onclick="SAT.openDetails('${s.code}')"><i class="fas fa-eye me-1"></i>Open</button></td>
            </tr>
        `).join('') || `<tr><td colspan="9" class="text-center text-muted py-3">No sections yet — create one to get started.</td></tr>`;
    },

    renderOverview() {
        const totalSubjects = sections.reduce((total, section) => total + (section.subjects || []).length, 0);
        document.getElementById('satOverviewTotal').textContent = sections.length;
        document.getElementById('satOverviewActive').textContent = sections.filter(section => section.status === 'Active').length;
        document.getElementById('satOverviewDrafts').textContent = sections.filter(section => section.status === 'Draft').length;
        document.getElementById('satOverviewSubjects').textContent = totalSubjects;
    },

    startSelectExisting() {
        this.selectPage = 1;
        this.goToStep('select');
        this.renderSelectTable();
    },

    startCreateNew() {
        this.mode = 'create';
        this.editingCode = null;
        this.subjectQuery = '';
        this.current = { code: '', name: '', program: '', year: '', sem: satReference.currentTerm?.semester || satReference.semesters[0] || '', ay: satReference.currentTerm?.academic_year || satReference.academicYears[0] || '', maxStudents: '', subjects: [] };
        document.getElementById('satFormTitle').textContent = 'Section Information';
        this.fillForm();
        this.goToStep('form');
    },

    /* ---------- STEP 2a: select existing ---------- */
    filteredSections() {
        const q = (document.getElementById('satSearchInput')?.value || '').trim().toLowerCase();
        const program = document.getElementById('satFilterProgram')?.value || '';
        const year = document.getElementById('satFilterYear')?.value || '';
        const sem = document.getElementById('satFilterSemester')?.value || '';
        const ay = document.getElementById('satFilterAY')?.value || '';
        return sections.filter(s => {
            if (program && s.program !== program) return false;
            if (year && s.year !== year) return false;
            if (sem && s.sem !== sem) return false;
            if (ay && s.ay !== ay) return false;
            if (q && !(s.code.toLowerCase().includes(q) || s.name.toLowerCase().includes(q))) return false;
            return true;
        });
    },

    renderSelectTable() {
        const all = this.filteredSections();
        const totalPages = Math.max(1, Math.ceil(all.length / this.pageSize));
        this.selectPage = Math.min(this.selectPage, totalPages);
        const start = (this.selectPage - 1) * this.pageSize;
        const rows = all.slice(start, start + this.pageSize);

        document.getElementById('satSelectTableBody').innerHTML = rows.map(s => `
            <tr>
                <td class="fw-semibold"><span class="sat-row-icon"><i class="fas fa-users"></i></span>${this.escape(s.code)}</td>
                <td>${this.escape(s.name)}</td>
                <td>${this.escape(s.program)}</td>
                <td>${this.escape(s.year)}</td>
                <td>${this.escape(s.sem)}</td>
                <td>${s.currentStudents} / ${s.maxStudents}</td>
                <td><span class="badge ${s.status === 'Draft' ? 'text-bg-warning' : 'text-bg-success'}"><i class="fas ${s.status === 'Draft' ? 'fa-pen' : 'fa-check'} me-1"></i>${this.escape(s.status)}</span></td>
                <td class="text-end"><button type="button" class="btn btn-outline-primary btn-sm" onclick="SAT.openDetails('${s.code}')"><i class="fas fa-eye me-1"></i>Open</button></td>
            </tr>
        `).join('') || `<tr><td colspan="8" class="text-center text-muted py-3">No sections match your filters.</td></tr>`;

        document.getElementById('satSelectCount').textContent = all.length
            ? `Showing ${start + 1} to ${Math.min(start + this.pageSize, all.length)} of ${all.length} entries`
            : 'No entries found';

        const pager = document.getElementById('satSelectPagination');
        let html = `<li class="page-item ${this.selectPage === 1 ? 'disabled' : ''}"><a class="page-link" href="#" onclick="event.preventDefault();SAT.changeSelectPage(${this.selectPage - 1})">&lsaquo;</a></li>`;
        for (let p = 1; p <= totalPages; p++) {
            html += `<li class="page-item ${p === this.selectPage ? 'active' : ''}"><a class="page-link" href="#" onclick="event.preventDefault();SAT.changeSelectPage(${p})">${p}</a></li>`;
        }
        html += `<li class="page-item ${this.selectPage === totalPages ? 'disabled' : ''}"><a class="page-link" href="#" onclick="event.preventDefault();SAT.changeSelectPage(${this.selectPage + 1})">&rsaquo;</a></li>`;
        pager.innerHTML = html;
    },

    changeSelectPage(p) {
        if (p < 1) return;
        this.selectPage = p;
        this.renderSelectTable();
    },

    /* ---------- STEP 2b: section details (view) ---------- */
    openDetails(code) {
        const s = sections.find(x => x.code === code);
        if (!s) return;
        this.current = s;
        const subjects = s.subjects.map(code => subjectCatalog.find(sub => sub.code === code)).filter(Boolean);
        const totalUnits = subjects.reduce((sum, x) => sum + x.units, 0);

        document.getElementById('satDetailsInfo').innerHTML = [
            ['Section Code', s.code], ['Section Name', s.name], ['Program / Course', s.program],
            ['Year Level', s.year], ['Semester', s.sem], ['Academic Year', s.ay],
            ['Maximum Students', s.maxStudents], ['Current Students', s.currentStudents],
            ['Status', s.status],
            ['Created At', s.created], ['Last Updated', s.updated],
        ].map(([label, val]) => `<div><span>${this.escape(label)}</span><strong>${this.escape(val)}</strong></div>`).join('');

        document.getElementById('satDetailsSubjectCount').textContent = subjects.length + ' subjects';
        document.getElementById('satDetailsSubjects').innerHTML = subjects.map((sub, i) => `
            <tr><td>${i + 1}</td><td>${this.escape(sub.code)}</td><td>${this.escape(sub.name)}</td><td>${sub.units}</td><td>${this.escape(sub.type)}</td></tr>
        `).join('') || `<tr><td colspan="5" class="text-center text-muted py-3">No subjects assigned yet.</td></tr>`;
        document.getElementById('satDetailsTotalUnits').textContent = totalUnits;

        document.getElementById('satDetailsMax').textContent = s.maxStudents;
        document.getElementById('satDetailsCurrent').textContent = s.currentStudents;
        document.getElementById('satDetailsAvailable').textContent = Math.max(0, s.maxStudents - s.currentStudents);

        document.getElementById('satEditSectionBtn').onclick = () => this.editFromDetails(s.code);
        document.getElementById('satContinueTeacherBtn').onclick = () => this.goToNextModuleStep();

        this.goToStep('details');
    },

    editFromDetails(code) {
        const s = sections.find(x => x.code === code);
        if (!s) return;
        this.mode = 'edit';
        this.editingCode = s.code;
        this.subjectQuery = '';
        this.current = { ...s, subjects: s.subjects.slice() };
        document.getElementById('satFormTitle').textContent = 'Edit Section — ' + s.code;
        this.fillForm();
        this.goToStep('form');
    },

    /* ---------- STEP 2c: form ---------- */
    fillForm() {
        const c = this.current;
        document.getElementById('satFieldName').value = c.name || '';
        document.getElementById('satFieldCode').value = c.code || '';
        document.getElementById('satFieldProgram').value = c.program || '';
        document.getElementById('satFieldYear').value = c.year || '';
        document.getElementById('satFieldSemester').value = c.sem || '1st Semester';
        document.getElementById('satFieldAY').value = c.ay || '2026-2027';
        document.getElementById('satFieldMax').value = c.maxStudents || '';
    },

    cancelForm() {
        this.goToStep(this.editingCode ? 'details' : 'open');
        if (!this.editingCode) this.renderRecent();
    },

    submitSectionForm() {
        const name = document.getElementById('satFieldName').value.trim();
        const code = document.getElementById('satFieldCode').value.trim().toUpperCase();
        const program = document.getElementById('satFieldProgram').value;
        const year = document.getElementById('satFieldYear').value;
        const sem = document.getElementById('satFieldSemester').value;
        const ay = document.getElementById('satFieldAY').value;
        const max = parseInt(document.getElementById('satFieldMax').value, 10);

        const missing = !name || !code || !program || !year || !sem || !ay || !max;
        document.querySelectorAll('#satSectionForm [required]').forEach(el => el.classList.toggle('is-invalid', !el.value));
        if (missing) {
            this.showAlert('Please complete all required fields before continuing.');
            return;
        }

        this.current = {
            ...this.current, name, code, program, year, sem, ay,
            maxStudents: max,
            currentStudents: this.current.currentStudents || 0,
            subjects: this.current.subjects || [],
        };
        this.renderSubjectStep();
        this.goToStep('subjects');
    },

    /* ---------- STEP 3: assign subjects ---------- */
    renderSubjectStep() {
        const c = this.current;
        document.getElementById('satSubjectSearch').value = this.subjectQuery;
        document.getElementById('satSubjectsSectionBadge').textContent = `${c.code} (${c.name})`;
        document.getElementById('satSubjectsMeta').textContent = `Program: ${c.program} · Year Level: ${c.year} · Semester: ${c.sem} · Academic Year: ${c.ay}`;
        const sectionYear = parseInt(c.year, 10);
        const query = this.subjectQuery.trim().toLowerCase();
        const available = subjectCatalog.filter(s => {
            const programOk = (s.program === c.program || s.program === 'ALL');
            const yearOk    = (s.yearLevel === null || s.yearLevel === sectionYear);
            const semOk     = (s.semester === null || s.semester === c.sem);
            const matchesQuery = !query || s.code.toLowerCase().includes(query) || s.name.toLowerCase().includes(query);
            return programOk && yearOk && semOk && matchesQuery;
        });
        document.getElementById('satSubjectsTableBody').innerHTML = available.map((s, i) => {
            const checked = c.subjects.includes(s.code) ? 'checked' : '';
            return `
            <tr class="sat-subject-row ${checked ? 'selected' : ''}" id="satSubjRow_${s.code}">
                <td>${i + 1}</td>
                <td>${this.escape(s.code)}</td>
                <td>${this.escape(s.name)}</td>
                <td>${s.units}</td>
                <td>${this.escape(s.type)}</td>
                <td class="text-center">
                    <input type="checkbox" class="form-check-input" ${checked} onchange="SAT.toggleSubject('${s.code}', this.checked)">
                </td>
            </tr>`;
        }).join('') || `<tr><td colspan="6" class="text-center text-muted py-3">No subjects available for this program yet.</td></tr>`;

        this.updateSubjectTotals();
    },

    toggleSubject(code, checked) {
        const c = this.current;
        const idx = c.subjects.indexOf(code);
        if (checked && idx === -1) c.subjects.push(code);
        if (!checked && idx !== -1) c.subjects.splice(idx, 1);
        document.getElementById('satSubjRow_' + code).classList.toggle('selected', checked);
        this.updateSubjectTotals();
    },

    updateSubjectTotals() {
        const subs = this.current.subjects.map(code => subjectCatalog.find(s => s.code === code)).filter(Boolean);
        document.getElementById('satTotalSubjects').textContent = subs.length;
        document.getElementById('satTotalUnits').textContent = subs.reduce((sum, s) => sum + s.units, 0);
    },

    /* ---------- STEP 4: validate ---------- */
    goToValidate() {
        const c = this.current;
        const dupe = sections.some(s => s.code === c.code && s.code !== this.editingCode);
        const validateYear = parseInt(c.year, 10);
        const invalidSubjects = c.subjects.filter(code => {
            const s = subjectCatalog.find(x => x.code === code);
            if (!s) return true;
            const programOk = (s.program === c.program || s.program === 'ALL');
            const yearOk    = (s.yearLevel === null || s.yearLevel === validateYear);
            const semOk     = (s.semester === null || s.semester === c.sem);
            return !(programOk && yearOk && semOk);
        });
        const checks = [
            { label: 'Section Information', ok: !!(c.name && c.code) },
            { label: 'Program, Year Level, Semester', ok: !!(c.program && c.year && c.sem) },
            { label: 'Academic Year', ok: !!c.ay },
            { label: 'Maximum Students', ok: !!c.maxStudents },
            { label: 'Assigned Subjects', ok: c.subjects.length > 0 },
            { label: 'Duplicate Section Code', ok: !dupe, okText: 'No duplicates found', failText: 'Section code already exists' },
            { label: 'Subject Availability',
              ok: invalidSubjects.length === 0,
              okText: c.subjects.length + ' subject(s) verified',
              failText: 'Not valid for this section: ' + invalidSubjects.join(', ') },
        ];
        const allOk = checks.every(x => x.ok);

        document.getElementById('satValidateList').innerHTML = checks.map(x => `
            <div>
                <span>${this.escape(x.label)}</span>
                <span class="${x.ok ? 'ok' : 'fail'}"><i class="fas ${x.ok ? 'fa-circle-check' : 'fa-circle-xmark'} me-1"></i>${this.escape(x.ok ? (x.okText || 'Complete') : (x.failText || 'Incomplete'))}</span>
            </div>
        `).join('') + `
            <div><span>Total Units</span><strong>${c.subjects.reduce((sum, code) => { const s = subjectCatalog.find(x => x.code === code); return sum + (s ? s.units : 0); }, 0)} units</strong></div>
        `;

        document.getElementById('satValidateIcon').innerHTML = allOk
            ? '<i class="fas fa-circle-check fa-3x text-success"></i>'
            : '<i class="fas fa-triangle-exclamation fa-3x text-danger"></i>';
        document.getElementById('satValidateTitle').textContent = allOk ? 'Validation Passed!' : 'Validation Failed';
        document.getElementById('satValidateTitle').className = 'fw-semibold mb-1 ' + (allOk ? 'text-success' : 'text-danger');
        document.getElementById('satValidateSub').textContent = allOk
            ? 'All information is valid and ready to save.'
            : 'Please resolve the issues above before saving.';
        document.getElementById('satSaveBtn').disabled = !allOk;

        this.goToStep('validate');
    },

    /* ---------- STEP 5: save ---------- */
    async saveSection() {
        const c = this.current;
        if (!c) return this.showAlert('Complete the section information before saving.');
        const button = document.getElementById('satSaveBtn');
        const original = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<i class="ti ti-loader-2 ti-spin me-2"></i>Saving to database…';
        try {
            const payload = {
                id: c.id || null, code: c.code, name: c.name, program: c.program,
                year_level: parseInt(c.year, 10), semester: c.sem, academic_year: c.ay,
                max_students: Number(c.maxStudents || 0), advisor_name: c.advisor || '',
                subjects: [...c.subjects]
            };
            const response = await fetch(SAT_API, {
                method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify(payload)
            });
            const result = await response.json().catch(() => null);
            if (!response.ok || !result?.ok) throw new Error(result?.error || 'The section could not be saved.');

            await loadSATData();
            this.current = sections.find(section => Number(section.id) === Number(result.section));
            if (!this.current) throw new Error('The section was saved but could not be reloaded. Refresh the page and try again.');
            this.editingCode = this.current.code;
            this.mode = 'edit';
            this.recordHistory('Saved', 'Section assignment saved to the shared scheduling database.');

            const savedSubjects = this.current.subjects.map(code => subjectCatalog.find(subject => subject.code === code)).filter(Boolean);
            const savedUnits = savedSubjects.reduce((sum, subject) => sum + Number(subject.units || 0), 0);
            document.getElementById('satSavedSummary').innerHTML = [
                ['Section Code', this.current.code], ['Section Name', this.current.name],
                ['Program', this.current.program], ['Year Level', this.current.year],
                ['Semester', this.current.sem], ['Academic Year', this.current.ay],
                ['Total Subjects', savedSubjects.length], ['Total Units', savedUnits],
                ['Status', this.current.status],
            ].map(([label, val]) => `<div><span>${this.escape(label)}</span><strong>${this.escape(val)}</strong></div>`).join('');
            this.renderRecent();
            this.showAlert(result.message || 'Section assignment saved successfully.');
            this.goToStep('saved');
        } catch (error) {
            this.showAlert('Save failed: ' + error.message);
        } finally {
            button.disabled = false;
            button.innerHTML = original;
        }
    },

    /* ---------- STEP 6: next module ---------- */
    goToNextModuleStep() {
        const c = this.current;
        const subs = (c.subjects || []).map(code => subjectCatalog.find(s => s.code === code)).filter(Boolean);
        const totalUnits = subs.reduce((sum, s) => sum + s.units, 0);
        document.getElementById('satNextSummary').innerHTML = [
            ['Section', `${c.code} (${c.name})`], ['Program', c.program], ['Year Level', c.year],
            ['Semester', c.sem], ['Academic Year', c.ay], ['Total Subjects', subs.length],
            ['Total Units', totalUnits], ['Status', c.status],
        ].map(([label, val]) => `<div><span>${this.escape(label)}</span><strong>${this.escape(val)}</strong></div>`).join('');

        document.getElementById('satGoToTeacherMappingBtn').href =
            '<?= BASE_URL ?>/modules/scheduling/pages/teacher-schedule-mapping.php?section=' + encodeURIComponent(c.code);

        this.goToStep('next');
    },
};

window.SAT = SAT;

/* wire filter/search live updates */
document.addEventListener('DOMContentLoaded', async () => {
    renderSectionIcons();
    new MutationObserver(records => {
        records.forEach(record => record.addedNodes.forEach(node => {
            if (node.nodeType === Node.ELEMENT_NODE) renderSectionIcons(node);
        }));
    }).observe(document.body, { childList: true, subtree: true });

    ['satFilterProgram', 'satFilterYear', 'satFilterSemester', 'satFilterAY'].forEach(id => {
        document.getElementById(id).addEventListener('change', () => { SAT.selectPage = 1; SAT.renderSelectTable(); });
    });
    document.getElementById('satSearchInput').addEventListener('input', () => { SAT.selectPage = 1; SAT.renderSelectTable(); });
    document.getElementById('satSubjectSearch').addEventListener('input', event => {
        SAT.subjectQuery = event.target.value;
        SAT.renderSubjectStep();
    });

    try {
        await loadSATData();
        SAT.renderRecent();
        SAT.goToStep('open');
    } catch (error) {
        console.error(error);
        SAT.renderRecent();
        SAT.goToStep('open');
        SAT.showAlert('Unable to load scheduling data: ' + error.message);
    }
});
})();
</script>

<?php require_once __DIR__ . '/../../../includes/layout-end.php'; ?>
