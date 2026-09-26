<?php
declare(strict_types=1);
require_once __DIR__.'/../../../config/config.php';
$pageTitle='Teacher Schedule Mapping';$activeModule='scheduling';$activePage='teacher-schedule-mapping';$hideModulePageBanner=true;
$breadcrumbs=[['label'=>'Class Scheduling','url'=>BASE_URL.'/modules/scheduling/index.php'],['label'=>$pageTitle,'url'=>null]];
require_once __DIR__.'/../../../includes/breadcrumbs.php';
require_once __DIR__.'/../../../includes/layout-start.php';
renderBreadcrumbs($breadcrumbs);
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/modules/scheduling/assets/css/teacher-mapping.css">
<div id="teacherMapping" class="tm" data-base="<?= htmlspecialchars(BASE_URL,ENT_QUOTES) ?>" data-section-id="<?= (int)($_GET['section_id']??0) ?>" data-record-id="<?= (int)($_GET['record_id']??0) ?>" data-section="<?= htmlspecialchars((string)($_GET['section']??$_GET['section_code']??''),ENT_QUOTES) ?>">
 <header class="tm-heading"><div class="d-flex align-items-center gap-3"><span class="tm-hero-icon"><i class="ti ti-users"></i></span><div><small>CLASS SCHEDULING</small><h1>Teacher Schedule Mapping</h1></div></div><div class="tm-actions no-print"><button id="tmRefresh" class="btn btn-light"><i class="ti ti-refresh"></i> Refresh</button><button id="tmHistory" class="btn btn-light"><i class="ti ti-history"></i> History</button><button id="tmExport" class="btn btn-light"><i class="ti ti-download"></i> Export</button><button id="tmPrint" class="btn btn-light"><i class="ti ti-printer"></i> Print</button></div></header>
 <div id="tmNotice" class="alert d-none" role="status" aria-live="polite"></div>
 <nav class="tm-tabs no-print" aria-label="Teacher mapping views">
  <button data-view="subjects"><i class="ti ti-books"></i> Section &amp; Subjects</button>
  <button data-view="faculty"><i class="ti ti-user-search"></i> Choose Teacher</button>
  <button data-view="availability"><i class="ti ti-calendar-time"></i> Availability &amp; Time</button>
  <button data-view="review"><i class="ti ti-shield-check"></i> Review &amp; Save</button>
 </nav>
 <section data-panel="subjects">
  <div class="tm-card tm-filters no-print">
   <?php foreach(['year'=>'Academic Year','semester'=>'Semester','program'=>'Program','level'=>'Year Level','section'=>'Section'] as $key=>$label): ?>
   <div><label for="tm<?= ucfirst($key) ?>"><?= $label ?></label><select class="form-select" id="tm<?= ucfirst($key) ?>"></select></div>
   <?php endforeach; ?>
  </div>
  <div class="tm-columns"><div class="tm-card"><div class="tm-card-head"><h2><i class="ti ti-books"></i> Subjects <span id="tmSectionLabel"></span></h2><input class="form-control tm-search no-print" id="tmSubjectSearch" type="search" placeholder="Search subject" aria-label="Search subjects"></div><div class="table-responsive"><table class="table tm-table"><thead><tr><th>Code</th><th>Subject</th><th>Units</th><th>Type</th><th>Status</th><th class="no-print">Action</th></tr></thead><tbody id="tmSubjects"></tbody></table></div></div>
  <aside><div class="tm-card"><h2><i class="ti ti-file-description"></i> Section Information</h2><dl id="tmSectionDetails"></dl></div><div class="tm-card"><h2><i class="ti ti-chart-donut"></i> Teacher Assignments</h2><div class="tm-progress-value" id="tmProgressCount">0 / 0</div><progress id="tmProgress" max="100" value="0" aria-label="Subjects with assigned teachers"></progress><div id="tmProgressDetails"></div></div></aside></div>
 </section>
 <section data-panel="faculty" hidden>
  <div class="tm-card tm-context" id="tmSubjectContext"></div>
  <div class="tm-columns"><div class="tm-card"><div class="tm-card-head"><h2><i class="ti ti-user-search"></i> Faculty</h2></div><div class="tm-actions mb-3 no-print"><input id="tmTeacherSearch" class="form-control tm-search" type="search" placeholder="Search faculty or specialization" aria-label="Search faculty"><select class="form-select tm-search" id="tmDepartment" aria-label="Department"></select><select class="form-select tm-search" id="tmEligibility" aria-label="Eligibility"><option value="eligible">Eligible teachers</option><option value="all">All teachers</option></select></div><div class="table-responsive"><table class="table tm-table"><thead><tr><th>Teacher</th><th>Specialization</th><th>Current / Max</th><th>Status</th><th>Action</th></tr></thead><tbody id="tmTeachers"></tbody></table></div></div><aside class="tm-card" id="tmTeacherDetails"></aside></div>
 </section>
 <section data-panel="availability" hidden>
  <div class="tm-card tm-context" id="tmTeacherContext"></div>
  <div class="tm-columns"><div class="tm-card"><div class="tm-card-head"><h2><i class="ti ti-calendar-time"></i> Weekly Availability</h2><div class="tm-legend"><span class="tm-badge green">Available</span><span class="tm-badge red">Occupied</span><span class="tm-badge gray">Unavailable</span></div></div><div class="table-responsive"><table class="table tm-week" id="tmWeek"></table></div></div><aside><div class="tm-card"><h2><i class="ti ti-clock"></i> Time Selection</h2><label for="tmDay">Day</label><select id="tmDay" class="form-select mb-3"></select><label for="tmBlock">Time Block</label><select id="tmBlock" class="form-select mb-3"></select><div id="tmSlotSummary"></div><button id="tmReview" class="btn btn-primary w-100 mt-3"><i class="ti ti-shield-check"></i> Review Assignment</button></div></aside></div>
 </section>
 <section data-panel="review" hidden>
  <div class="tm-columns"><div><div class="tm-card"><div class="tm-card-head"><h2><i class="ti ti-books"></i> Section &amp; Subject</h2><button data-edit="subjects" class="btn btn-outline-primary btn-sm no-print"><i class="ti ti-edit"></i> Edit</button></div><dl id="tmReviewSubject"></dl></div><div class="tm-card"><div class="tm-card-head"><h2><i class="ti ti-user"></i> Assigned Teacher</h2><button data-edit="faculty" class="btn btn-outline-primary btn-sm no-print"><i class="ti ti-edit"></i> Edit</button></div><div id="tmReviewTeacher"></div></div><div class="tm-card"><div class="tm-card-head"><h2><i class="ti ti-calendar"></i> Schedule</h2><button data-edit="availability" class="btn btn-outline-primary btn-sm no-print"><i class="ti ti-edit"></i> Edit</button></div><dl id="tmReviewSchedule"></dl></div></div>
  <aside class="tm-card"><div class="tm-card-head"><h2><i class="ti ti-shield-check"></i> Validation</h2><button id="tmRecheck" class="btn btn-outline-primary btn-sm no-print"><i class="ti ti-refresh"></i> Recheck</button></div><div id="tmValidation" aria-live="polite"></div><button class="btn btn-primary w-100 mt-3 no-print" id="tmSave" disabled><i class="ti ti-device-floppy"></i> Save &amp; Continue to Rooms</button></aside></div>
 </section>
 <dialog id="tmHistoryDialog"><div class="tm-card-head"><h2><i class="ti ti-history"></i> Mapping History</h2><button id="tmCloseHistory" class="btn btn-outline-secondary">Close</button></div><div id="tmHistoryRows"></div></dialog>
</div>
<script src="<?= BASE_URL ?>/modules/scheduling/assets/js/teacher-mapping.js"></script>
<?php require_once __DIR__.'/../../../includes/layout-end.php'; ?>
