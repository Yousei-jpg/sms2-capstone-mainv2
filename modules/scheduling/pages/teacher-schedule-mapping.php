<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../config/config.php';
$pageTitle = 'Teacher Schedule Mapping';
$activeModule = 'scheduling';
$activePage = 'teacher-schedule-mapping';
$hideModulePageBanner = true;
$breadcrumbs = [
    ['label' => 'Class Scheduling', 'url' => BASE_URL . '/modules/scheduling/index.php'],
    ['label' => 'Teacher Schedule Mapping', 'url' => null],
];
$initialSection = isset($_GET['section'])
    ? preg_replace('/[^A-Za-z0-9_-]/', '', (string)$_GET['section'])
    : '';
require_once __DIR__ . '/../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../includes/layout-start.php';
?>
<?php renderBreadcrumbs($breadcrumbs); ?>

<div class="tsm-shell" id="tsmApp" aria-busy="true">
  <header class="tsm-heading">
    <div>
      <div class="tsm-eyebrow">Class Scheduling</div>
      <h1>Teacher Schedule Mapping</h1>
      <p>Assign a qualified teacher, valid time block, and available room to a section subject.</p>
    </div>
    <div class="tsm-tools" aria-label="Schedule tools">
      <button type="button" class="btn btn-light" id="tsmExport"><i class="ti ti-download"></i> Export</button>
      <button type="button" class="btn btn-light" id="tsmPrint"><i class="ti ti-printer"></i> Print</button>
      <button type="button" class="btn btn-light" id="tsmHistory"><i class="ti ti-history"></i> History</button>
    </div>
  </header>

  <div class="tsm-progress-card">
    <ol class="tsm-progress" id="tsmProgress" aria-label="Teacher schedule mapping progress">
      <li data-step="1"><button type="button"><span>1</span><b><i class="ti ti-stack-2"></i>Select Section/Subject</b><small>Choose assignment</small></button></li>
      <li data-step="2"><button type="button"><span>2</span><b><i class="ti ti-presentation"></i>Select Teacher</b><small>Check qualification</small></button></li>
      <li data-step="3"><button type="button"><span>3</span><b><i class="ti ti-calendar-check"></i>Check Availability</b><small>Review weekly load</small></button></li>
      <li data-step="4"><button type="button"><span>4</span><b><i class="ti ti-calendar-plus"></i>Assign Schedule</b><small>Time and room</small></button></li>
      <li data-step="5"><button type="button"><span>5</span><b><i class="ti ti-shield-check"></i>Review &amp; Validate</b><small>Resolve conflicts</small></button></li>
      <li data-step="6"><button type="button"><span>6</span><b><i class="ti ti-device-floppy"></i>Save</b><small>Draft confirmation</small></button></li>
    </ol>
  </div>

  <div class="alert d-none" id="tsmNotice" role="alert"></div>

  <main>
    <section class="tsm-page" data-page="1">
      <div class="tsm-page-title"><span>1</span><div><h2><i class="ti ti-stack-2"></i>Select Section and Subject</h2><p>Subjects are read-only records retrieved from the Section Assignment Tool.</p></div></div>
      <div class="card tsm-card mb-3"><div class="card-body"><div class="row g-3">
        <div class="col-6 col-xl"><label for="filterYear" class="form-label">Academic Year</label><select id="filterYear" class="form-select"></select></div>
        <div class="col-6 col-xl"><label for="filterSemester" class="form-label">Semester</label><select id="filterSemester" class="form-select"></select></div>
        <div class="col-6 col-xl"><label for="filterProgram" class="form-label">Program</label><select id="filterProgram" class="form-select"></select></div>
        <div class="col-6 col-xl"><label for="filterLevel" class="form-label">Year Level</label><select id="filterLevel" class="form-select"></select></div>
        <div class="col-12 col-xl"><label for="filterSection" class="form-label">Section</label><select id="filterSection" class="form-select"></select></div>
      </div></div></div>
      <div class="card tsm-card">
        <div class="card-header tsm-card-header">
          <div><h3>Subjects for <span id="subjectSectionLabel">—</span></h3><p id="sectionMeta">Select a section to load its assigned subjects.</p></div>
          <div class="input-group tsm-search"><span class="input-group-text"><i class="ti ti-search"></i></span><input id="subjectSearch" class="form-control" type="search" placeholder="Search subject"></div>
        </div>
        <div class="table-responsive"><table class="table tsm-table mb-0"><thead><tr><th>#</th><th>Subject Code</th><th>Subject Title</th><th>Units</th><th>Type</th><th>Status</th><th class="text-end">Action</th></tr></thead><tbody id="subjectRows"></tbody></table></div>
      </div>
      <div class="tsm-page-actions justify-content-between">
        <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>/modules/scheduling/pages/schedule-cloning-tool.php"><i class="ti ti-copy me-2"></i>Open Cloning Tool</a>
        <span class="text-muted small">Choose Select or Edit to continue.</span>
      </div>
    </section>

    <section class="tsm-page d-none" data-page="2">
      <div class="tsm-page-title"><span>2</span><div><h2><i class="ti ti-presentation"></i>Select Teacher</h2><p>Choose an active, qualified faculty member whose projected load remains within the configured limit.</p></div></div>
      <div class="tsm-selection-card" id="selectedSubjectCard"></div>
      <div class="card tsm-card">
        <div class="card-header tsm-card-header align-items-end">
          <div><h3>Faculty Candidates</h3><p id="qualificationNote"></p></div>
          <div class="tsm-filters">
            <input id="teacherSearch" class="form-control" type="search" placeholder="Search teacher or specialization">
            <select id="teacherDepartment" class="form-select" aria-label="Department"><option value="">All departments</option></select>
            <select id="teacherStatus" class="form-select" aria-label="Status"><option value="">All statuses</option><option value="Active">Active</option><option value="Inactive">Inactive</option></select>
          </div>
        </div>
        <div class="table-responsive"><table class="table tsm-table mb-0"><thead><tr><th>Faculty ID</th><th>Teacher Name</th><th>Department</th><th>Specialization</th><th>Current / Projected Load</th><th>Status</th><th class="text-end">Action</th></tr></thead><tbody id="teacherRows"></tbody></table></div>
      </div>
      <div class="tsm-page-actions"><button class="btn btn-outline-secondary" type="button" data-back="1"><i class="ti ti-arrow-left me-2"></i>Back</button></div>
    </section>

    <section class="tsm-page d-none" data-page="3">
      <div class="tsm-page-title"><span>3</span><div><h2><i class="ti ti-calendar-check"></i>Check Teacher Availability</h2><p>Review occupied classes and choose an open period from configured class time blocks.</p></div></div>
      <div class="tsm-selection-card" id="selectedTeacherCard"></div>
      <div class="row g-3">
        <div class="col-xl-8"><div class="card tsm-card h-100">
          <div class="card-header tsm-card-header"><div><h3>Teacher's Weekly Schedule</h3><p>Existing Draft, Validated, and Published entries for this academic term.</p></div><div class="tsm-legend"><span><i class="available"></i>Available</span><span><i class="occupied"></i>Occupied</span></div></div>
          <div class="table-responsive"><table class="table tsm-week mb-0" id="weeklySchedule"></table></div>
        </div></div>
        <div class="col-xl-4"><div class="card tsm-card h-100"><div class="card-body">
          <label for="availabilityDay" class="form-label">Day</label><select id="availabilityDay" class="form-select mb-3"></select>
          <h3 class="h6 fw-bold">Available Time Slots</h3><div id="availableSlots" class="tsm-slot-list"></div>
        </div></div></div>
      </div>
      <div class="tsm-page-actions"><button class="btn btn-outline-secondary" type="button" data-back="2"><i class="ti ti-arrow-left me-2"></i>Back</button><button class="btn btn-primary ms-auto" type="button" id="availabilityNext">Continue to Schedule <i class="ti ti-arrow-right ms-2"></i></button></div>
    </section>

    <section class="tsm-page d-none" data-page="4">
      <div class="tsm-page-title"><span>4</span><div><h2><i class="ti ti-calendar-plus"></i>Assign Schedule</h2><p>Select a valid class time block and a room that is free and large enough for the section.</p></div></div>
      <div class="row g-3">
        <div class="col-xl-4"><div class="card tsm-card h-100"><div class="card-header tsm-card-header"><div><h3>Assignment Details</h3><p>Read-only source records</p></div></div><div class="card-body" id="assignmentDetails"></div></div></div>
        <div class="col-xl-4"><div class="card tsm-card h-100"><div class="card-header tsm-card-header"><div><h3>Schedule Information</h3><p>Time Block Generator values only</p></div></div><div class="card-body">
          <label for="scheduleDay" class="form-label">Day</label><select id="scheduleDay" class="form-select mb-3"></select>
          <label for="scheduleBlock" class="form-label">Time Slot</label><select id="scheduleBlock" class="form-select mb-3"></select>
          <label for="scheduleRoom" class="form-label">Room</label><select id="scheduleRoom" class="form-select mb-3"></select>
          <button class="btn btn-outline-primary w-100" type="button" id="checkRoom"><i class="ti ti-refresh me-2"></i>Recheck Room Availability</button>
          <div class="tsm-live-status mt-3" id="liveStatus"></div>
        </div></div></div>
        <div class="col-xl-4"><div class="card tsm-card h-100"><div class="card-header tsm-card-header"><div><h3>Room Details</h3><p>Live availability and facilities</p></div></div><div class="card-body" id="roomDetails"></div></div></div>
      </div>
      <div class="tsm-page-actions"><button class="btn btn-outline-secondary" type="button" data-back="3"><i class="ti ti-arrow-left me-2"></i>Back</button><button class="btn btn-primary ms-auto" type="button" id="reviewNext">Next: Review &amp; Validate <i class="ti ti-arrow-right ms-2"></i></button></div>
    </section>

    <section class="tsm-page d-none" data-page="5">
      <div class="tsm-page-title"><span>5</span><div><h2><i class="ti ti-shield-check"></i>Review and Validate</h2><p>Critical conflicts must be resolved before this mapping can be saved as Draft.</p></div></div>
      <div class="row g-3">
        <div class="col-xl-5"><div class="card tsm-card h-100"><div class="card-header tsm-card-header"><div><h3>Assignment Summary</h3><p>Recheck every detail before saving</p></div></div><div class="card-body" id="reviewSummary"></div></div></div>
        <div class="col-xl-7"><div class="card tsm-card h-100"><div class="card-header tsm-card-header"><div><h3>Validation Results</h3><p>Teacher, load, time, section, and room rules</p></div><button class="btn btn-outline-primary btn-sm" type="button" id="runValidation"><i class="ti ti-refresh me-2"></i>Run Again</button></div><div class="card-body"><div id="validationResults" class="tsm-validation"></div><div id="validationBanner" class="mt-3"></div></div></div></div>
      </div>
      <div class="tsm-page-actions"><button class="btn btn-outline-secondary" type="button" data-back="4"><i class="ti ti-arrow-left me-2"></i>Back</button><button class="btn btn-primary ms-auto" type="button" id="saveSchedule" disabled><i class="ti ti-device-floppy me-2"></i>Save Schedule as Draft</button></div>
    </section>

    <section class="tsm-page d-none" data-page="6">
      <div class="tsm-confirmation">
        <div class="tsm-success-icon"><i class="ti ti-check"></i></div>
        <h2><i class="ti ti-circle-check-filled"></i>Schedule Saved Successfully!</h2>
        <p>The teacher mapping has been stored as a <strong>Draft</strong>. It is not published yet.</p>
        <div class="tsm-confirm-card" id="confirmationDetails"></div>
        <div class="d-flex flex-wrap justify-content-center gap-2 mt-4">
          <button class="btn btn-outline-primary" type="button" id="viewSchedule"><i class="ti ti-calendar me-2"></i>View in Schedule</button>
          <button class="btn btn-primary" type="button" id="assignAnother"><i class="ti ti-plus me-2"></i>Assign Another Subject</button>
        </div>
        <div class="tsm-next-note"><i class="ti ti-shield-check"></i><span>The saved Draft is now available to the Conflict Checker and must pass the publication workflow.</span></div>
      </div>
    </section>
  </main>
</div>

<div class="tsm-overlay d-none" id="historyOverlay" role="dialog" aria-modal="true" aria-labelledby="historyTitle">
  <div class="tsm-dialog">
    <div class="tsm-dialog-head"><div><h2 id="historyTitle">Mapping History</h2><p id="historyScope">Important save activity</p></div><button class="btn btn-light" type="button" id="closeHistory" aria-label="Close"><i class="ti ti-x"></i></button></div>
    <div id="historyBody" class="tsm-history"></div>
  </div>
</div>

<style>
.tsm-shell{--navy:#0b2349;--blue:#0d6efd;--soft:#f4f8ff;--line:#dce6f4;--green:#159455;color:#26344f;padding-bottom:1rem}.tsm-heading{background:linear-gradient(120deg,#071e43,#0f467b);border-radius:16px;padding:1.35rem 1.5rem;color:#fff;display:flex;align-items:center;justify-content:space-between;gap:1rem;margin-bottom:1rem;box-shadow:0 12px 30px rgba(7,30,67,.14)}.tsm-heading h1{font-size:clamp(1.55rem,2.4vw,2.15rem);font-weight:750;margin:.1rem 0 .25rem}.tsm-heading p{margin:0;color:#d9e9ff}.tsm-eyebrow{text-transform:uppercase;letter-spacing:.12em;font-size:.72rem;color:#89c7ff;font-weight:700}.tsm-tools{display:flex;flex-wrap:wrap;gap:.5rem}.tsm-tools .btn{white-space:nowrap}.tsm-progress-card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:.9rem 1rem;margin-bottom:1rem;box-shadow:0 4px 16px rgba(25,60,105,.06);overflow-x:auto}.tsm-progress{list-style:none;display:flex;min-width:970px;margin:0;padding:0}.tsm-progress li{position:relative;flex:1}.tsm-progress li:not(:last-child):after{content:"";position:absolute;height:2px;background:#cfdaea;left:58%;right:-42%;top:17px}.tsm-progress button{border:0;background:transparent;width:100%;display:grid;grid-template-columns:36px 1fr;text-align:left;color:#75839a;padding:0 .35rem;position:relative;z-index:1}.tsm-progress button>span{grid-row:1/3;width:34px;height:34px;border-radius:50%;display:grid;place-items:center;background:#e6ecf5;color:#6e809b;font-weight:800;border:3px solid #fff;box-shadow:0 0 0 1px #d8e1ef}.tsm-progress b{font-size:.75rem;line-height:1.2;padding:.12rem .25rem 0}.tsm-progress small{font-size:.66rem;padding:.05rem .25rem}.tsm-progress li.active button{color:var(--navy)}.tsm-progress li.active button>span{background:var(--blue);color:#fff;box-shadow:0 0 0 2px #a9c8ff}.tsm-progress li.done button>span{background:var(--green);color:#fff}.tsm-progress li.done:not(:last-child):after{background:var(--green)}.tsm-page{animation:tsmIn .22s ease}@keyframes tsmIn{from{opacity:.3;transform:translateY(5px)}to{opacity:1;transform:none}}.tsm-page-title{display:flex;align-items:center;gap:.8rem;margin:1.1rem 0}.tsm-page-title>span{background:var(--navy);color:#fff;width:42px;height:42px;border-radius:50%;display:grid;place-items:center;font-weight:800;font-size:1.1rem;box-shadow:0 5px 14px rgba(11,35,73,.2)}.tsm-page-title h2{font-size:1.25rem;color:var(--navy);font-weight:750;margin:0}.tsm-page-title p,.tsm-card-header p{color:#708097;font-size:.79rem;margin:.2rem 0 0}.tsm-card{border:1px solid var(--line);border-radius:13px;box-shadow:0 4px 16px rgba(25,60,105,.055);overflow:hidden}.tsm-card-header{background:#fff;border-bottom:1px solid var(--line);padding:1rem 1.15rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}.tsm-card-header h3{font-size:1rem;color:var(--navy);font-weight:750;margin:0}.form-label{color:#334662;font-size:.76rem;font-weight:700}.form-select,.form-control,.input-group-text{border-color:#d6e1f0;min-height:41px}.form-select:focus,.form-control:focus{border-color:#72a8ff;box-shadow:0 0 0 .18rem rgba(13,110,253,.12)}.tsm-search{max-width:290px}.tsm-filters{display:flex;gap:.5rem;flex-wrap:wrap}.tsm-filters>*{width:auto;min-width:160px}.tsm-table{font-size:.79rem}.tsm-table thead th{background:#f3f7fc;color:#50627c;font-size:.68rem;text-transform:uppercase;letter-spacing:.035em;border-bottom:1px solid var(--line);white-space:nowrap;padding:.72rem .8rem}.tsm-table td{padding:.72rem .8rem;border-color:#edf1f7;vertical-align:middle}.tsm-table tbody tr:hover{background:#f8fbff}.tsm-badge{display:inline-flex;align-items:center;gap:.35rem;padding:.28rem .55rem;border-radius:999px;font-size:.69rem;font-weight:700}.tsm-badge.green{background:#def7e9;color:#08763c}.tsm-badge.red{background:#fee8e8;color:#b22431}.tsm-badge.amber{background:#fff1d6;color:#9a6500}.tsm-badge.blue{background:#e5efff;color:#1857ad}.tsm-badge.gray{background:#edf1f6;color:#65738a}.tsm-selection-card{border:1px solid #cfe0f7;background:linear-gradient(100deg,#f4f8ff,#fff);border-radius:13px;padding:1rem 1.15rem;margin-bottom:1rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}.tsm-selection-main{display:flex;align-items:center;gap:.8rem}.tsm-avatar{width:48px;height:48px;border-radius:12px;background:#d9e9ff;color:#0c5abf;display:grid;place-items:center;font-size:1.25rem}.tsm-selection-main h3{font-size:1rem;color:var(--navy);font-weight:750;margin:0}.tsm-selection-main p{margin:.2rem 0 0;color:#63738b;font-size:.78rem}.tsm-selection-stats{display:flex;align-items:center;gap:.65rem;flex-wrap:wrap}.tsm-stat{border-left:1px solid #d7e2f1;padding-left:.75rem;min-width:88px}.tsm-stat small{display:block;color:#8290a3;font-size:.65rem;text-transform:uppercase}.tsm-stat b{display:block;color:#263b5e;font-size:.8rem;margin-top:.1rem}.tsm-week th,.tsm-week td{font-size:.68rem;text-align:center;padding:.45rem;border-color:#e5edf7;min-width:84px;height:51px}.tsm-week thead th{background:#f3f7fc;color:#52647d}.tsm-week .time{background:#f8fafc;color:#718097;white-space:nowrap;font-weight:700}.tsm-week .busy{background:#d9eaff;border-radius:7px;color:#0d55ad;font-weight:700;line-height:1.2;padding:.35rem}.tsm-week .free{color:#9aa8ba}.tsm-legend{display:flex;gap:.8rem;font-size:.7rem;color:#6e7d91}.tsm-legend i{display:inline-block;width:9px;height:9px;border-radius:3px;margin-right:.25rem}.tsm-legend .available{background:#dff5e8}.tsm-legend .occupied{background:#afd2ff}.tsm-slot-list{display:grid;gap:.5rem;max-height:430px;overflow:auto}.tsm-slot{border:1px solid #dce6f3;background:#fff;border-radius:9px;padding:.65rem .75rem;display:flex;align-items:center;justify-content:space-between;text-align:left;font-size:.76rem;transition:.15s}.tsm-slot:hover:not(:disabled),.tsm-slot.selected{border-color:#0d6efd;background:#eef5ff}.tsm-slot:disabled{background:#f5f6f8;color:#9ca7b6}.tsm-slot b{display:block}.tsm-slot small{display:block;margin-top:.1rem}.tsm-page-actions{display:flex;align-items:center;gap:.6rem;margin-top:1rem}.tsm-data-list{display:grid;grid-template-columns:110px 1fr;gap:.65rem .8rem;font-size:.79rem}.tsm-data-list dt{color:#7b899d;font-weight:600}.tsm-data-list dd{margin:0;color:#203654;font-weight:650}.tsm-live-status{font-size:.75rem;border-radius:9px;padding:.65rem .75rem;background:#f3f6fa;color:#62718a}.tsm-live-status.ok{background:#e5f8ed;color:#097943}.tsm-live-status.bad{background:#ffebec;color:#b32431}.tsm-room-visual{height:92px;border-radius:10px;background:linear-gradient(135deg,#d9eaff,#eef6ff);display:grid;place-items:center;color:#2d6ab8;font-size:2rem;margin-bottom:1rem}.tsm-validation{display:grid;gap:.5rem}.tsm-check{display:grid;grid-template-columns:28px 1fr auto;gap:.6rem;align-items:start;border:1px solid #e1e9f4;border-radius:9px;padding:.65rem .75rem}.tsm-check>i{width:24px;height:24px;border-radius:50%;display:grid;place-items:center;font-size:.68rem}.tsm-check.pass>i{background:#dff7e8;color:#087a3d}.tsm-check.fail>i{background:#fee5e7;color:#b72331}.tsm-check.wait>i{background:#edf1f6;color:#738096}.tsm-check b{display:block;color:#233956;font-size:.78rem}.tsm-check small{display:block;color:#718096;margin-top:.12rem}.tsm-check .btn{white-space:nowrap}.tsm-validation-banner{border-radius:10px;padding:.85rem 1rem;display:flex;align-items:center;gap:.65rem;font-size:.8rem;font-weight:700}.tsm-validation-banner.ok{background:#e2f8eb;color:#08763e}.tsm-validation-banner.bad{background:#ffeaeb;color:#ae2430}.tsm-confirmation{max-width:680px;margin:3rem auto 1rem;text-align:center;background:#fff;border:1px solid var(--line);border-radius:18px;padding:2rem;box-shadow:0 16px 42px rgba(13,53,100,.12)}.tsm-success-icon{width:76px;height:76px;background:#169655;color:#fff;border-radius:50%;display:grid;place-items:center;margin:-3.8rem auto 1rem;font-size:2rem;border:7px solid #edf9f2}.tsm-confirmation h2{color:var(--navy);font-size:1.4rem;font-weight:800}.tsm-confirmation>p{color:#6f7f94;font-size:.84rem}.tsm-confirm-card{background:#f3f7fd;border:1px solid #d8e5f5;border-radius:11px;padding:1rem;margin-top:1.25rem;text-align:left}.tsm-next-note{display:flex;gap:.6rem;align-items:center;text-align:left;margin-top:1.4rem;padding:.75rem;border-radius:9px;background:#fff7df;color:#80600d;font-size:.75rem}.tsm-overlay{position:fixed;inset:0;background:rgba(6,20,43,.58);display:grid;place-items:center;padding:1rem;z-index:1085}.tsm-dialog{background:#fff;width:min(720px,100%);max-height:80vh;border-radius:14px;overflow:hidden;box-shadow:0 25px 70px rgba(0,0,0,.25)}.tsm-dialog-head{padding:1rem 1.15rem;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;gap:1rem}.tsm-dialog-head h2{font-size:1.1rem;color:var(--navy);margin:0}.tsm-dialog-head p{font-size:.75rem;color:#78879b;margin:.2rem 0 0}.tsm-history{padding:1rem;overflow:auto;max-height:60vh}.tsm-history-item{border-left:3px solid #4f8fe8;padding:.2rem 0 .8rem .8rem;margin-bottom:.65rem}.tsm-history-item b{font-size:.78rem;color:#263d5e}.tsm-history-item p{font-size:.75rem;color:#60718a;margin:.15rem 0}.tsm-history-item small{font-size:.66rem;color:#8a97a9}.tsm-empty{text-align:center;color:#7a899e;padding:2rem!important}.tsm-empty i{display:block;font-size:1.6rem;color:#b3c0d0;margin-bottom:.5rem}.tsm-loading{display:inline-flex;align-items:center;gap:.45rem}.tsm-loading i{animation:tsmSpin .8s linear infinite}@keyframes tsmSpin{to{transform:rotate(360deg)}}@media(max-width:767.98px){.tsm-heading{align-items:flex-start;flex-direction:column}.tsm-tools{width:100%}.tsm-tools .btn{flex:1}.tsm-filters{width:100%}.tsm-filters>*{width:100%}.tsm-page-actions{flex-wrap:wrap}.tsm-page-actions .ms-auto{margin-left:0!important;width:100%}.tsm-check{grid-template-columns:28px 1fr}.tsm-check .btn{grid-column:2}.tsm-data-list{grid-template-columns:92px 1fr}}@media print{body *{visibility:hidden}.tsm-shell,.tsm-shell *{visibility:visible}.tsm-shell{position:absolute;inset:0}.tsm-heading,.tsm-progress-card,.tsm-tools,.tsm-page-title,.tsm-page-actions,.btn,.tsm-overlay{display:none!important}.tsm-page{display:none!important}.tsm-page[data-page="5"]{display:block!important}.main-content{margin:0!important}.tsm-card{box-shadow:none}}
.tsm-progress b i{color:#4f78b5;margin-right:.32rem;width:.85rem;text-align:center}.tsm-progress li.active b i{color:var(--blue)}.tsm-progress li.done b i{color:var(--green)}.tsm-page-title h2 i{color:var(--blue);margin-right:.5rem;font-size:1rem;width:1.1rem;text-align:center}.tsm-confirmation h2>.ti{color:var(--green);margin-right:.45rem;vertical-align:-.1em}
</style>

<script>
(() => {
'use strict';
const BASE = <?= json_encode(BASE_URL, JSON_UNESCAPED_SLASHES) ?>;
const INITIAL_SECTION = <?= json_encode($initialSection) ?>;
const DAYS = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
const $ = id => document.getElementById(id);
const esc = value => String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
const shortTime = value => String(value || '').slice(0,5);
const timeLabel = block => `${shortTime(block.start_time)} - ${shortTime(block.end_time)}`;
const fmtTime = value => { const s=shortTime(value); if(!s)return '—'; const [h,m]=s.split(':').map(Number); return new Date(2000,0,1,h,m).toLocaleTimeString([], {hour:'numeric',minute:'2-digit'}); };
const badge = (text, tone='gray') => `<span class="tsm-badge ${tone}">${esc(text)}</span>`;

const TSM = {
  data:{sections:[],subjects:[],teachers:[],rooms:[],time_blocks:[],schedule_entries:[],assigned_subjects:[],section_schedule:[]},
  page:1, section:null, subject:null, teacher:null, day:'Monday', block:null, room:null,
  live:null, validation:null, saved:null, requestVersion:0,
  async api(path, options={}) {
    const response=await fetch(BASE+path,{credentials:'same-origin',...options});
    let body={}; try{body=await response.json();}catch(e){}
    if(!response.ok||body.ok===false)throw new Error(body.error||`Request failed (${response.status}).`);
    return body;
  },
  notice(message,tone='success'){const el=$('tsmNotice');el.className=`alert alert-${tone}`;el.textContent=message;el.classList.remove('d-none');clearTimeout(this.noticeTimer);this.noticeTimer=setTimeout(()=>el.classList.add('d-none'),4500);},
  invalidate(){this.validation=null;$('saveSchedule').disabled=true;},
  go(page){
    if(page>1&&!this.subject)return this.notice('Select a section subject first.','warning');
    if(page>2&&!this.teacher)return this.notice('Select a teacher first.','warning');
    if(page>3&&!this.block)return this.notice('Select an available time slot first.','warning');
    if(page>4&&!this.room)return this.notice('Select an available room first.','warning');
    if(page===6&&!this.saved)return this.notice('Save the validated assignment first.','warning');
    document.querySelectorAll('.tsm-page').forEach(el=>el.classList.toggle('d-none',Number(el.dataset.page)!==page));
    document.querySelectorAll('#tsmProgress li').forEach(li=>{const n=Number(li.dataset.step);li.classList.toggle('active',n===page);li.classList.toggle('done',n<page);});
    this.page=page;window.scrollTo({top:0,behavior:'smooth'});
    if(page===2)this.renderTeachers();if(page===3)this.renderAvailability();if(page===4)this.renderAssignment();if(page===5){this.renderReview();this.validate();}
  },
  async load(sectionCode=''){
    const version=++this.requestVersion;$('tsmApp').setAttribute('aria-busy','true');
    const body=await this.api('/api/scheduling/teacher-schedule-mapping.php'+(sectionCode?`?section_code=${encodeURIComponent(sectionCode)}`:''));
    if(version!==this.requestVersion)return;this.data={...this.data,...body};
    if(body.section){this.section=body.section;this.data.assigned_subjects=body.assigned_subjects||[];this.data.section_schedule=body.section_schedule||[];}
    $('tsmApp').setAttribute('aria-busy','false');
  },
  values(key,rows){return [...new Set(rows.map(x=>String(x[key]??'')).filter(Boolean))].sort((a,b)=>a.localeCompare(b,undefined,{numeric:true}));},
  fillSelect(id,values,selected){$(id).innerHTML=values.map(v=>`<option value="${esc(v)}"${v===selected?' selected':''}>${esc(v)}</option>`).join('')||'<option value="">No data</option>';},
  matchingSections(stage=5){
    let rows=this.data.sections||[];const filters=[['filterYear','academic_year'],['filterSemester','semester'],['filterProgram','program'],['filterLevel','year_level']];
    filters.slice(0,stage).forEach(([id,key])=>{const v=$(id)?.value;if(v)rows=rows.filter(s=>String(s[key])===v);});return rows;
  },
  async initFilters(preferred=''){
    const target=this.data.sections.find(s=>s.code===preferred)||this.data.sections[0];
    if(!target){['filterYear','filterSemester','filterProgram','filterLevel','filterSection'].forEach(id=>this.fillSelect(id,[],''));this.renderSubjects();return;}
    this.fillSelect('filterYear',this.values('academic_year',this.data.sections),target.academic_year);
    let rows=this.matchingSections(1);this.fillSelect('filterSemester',this.values('semester',rows),target.semester);
    rows=this.matchingSections(2);this.fillSelect('filterProgram',this.values('program',rows),target.program);
    rows=this.matchingSections(3);this.fillSelect('filterLevel',this.values('year_level',rows),target.year_level);
    rows=this.matchingSections(4);this.fillSelect('filterSection',rows.map(s=>s.code),target.code);
    await this.selectSection($('filterSection').value);
  },
  async filterChanged(level){
    let rows=this.matchingSections(level);const chain=[['filterSemester','semester'],['filterProgram','program'],['filterLevel','year_level']];
    for(let i=Math.max(0,level-1);i<chain.length;i++){const [id,key]=chain[i],vals=this.values(key,rows);this.fillSelect(id,vals,vals[0]||'');rows=this.matchingSections(i+2);}
    const codes=rows.map(s=>s.code);this.fillSelect('filterSection',codes,codes[0]||'');await this.selectSection($('filterSection').value);
  },
  resetSelection(){this.subject=null;this.teacher=null;this.block=null;this.room=null;this.live=null;this.validation=null;this.saved=null;},
  async selectSection(code){if(!code){this.section=null;this.resetSelection();this.renderSubjects();return;}await this.load(code);this.resetSelection();this.renderSubjects();},
  existingFor(subject){return(this.data.section_schedule||[]).find(x=>Number(x.subject_id)===Number(subject.id))||null;},
  renderSubjects(){
    const s=this.section;$('subjectSectionLabel').textContent=s?.code||'—';
    $('sectionMeta').textContent=s?`${s.program} · ${s.year_level} · ${s.semester} · ${s.current_students}/${s.max_students} students`:'Select a section to load its assigned subjects.';
    const q=$('subjectSearch').value.trim().toLowerCase(),rows=(this.data.assigned_subjects||[]).filter(x=>`${x.code} ${x.name}`.toLowerCase().includes(q));
    $('subjectRows').innerHTML=rows.length?rows.map((x,i)=>{const ex=this.existingFor(x);return `<tr><td>${i+1}</td><td><strong>${esc(x.code)}</strong></td><td>${esc(x.name)}</td><td>${esc(x.units)}</td><td>${esc(x.subject_type||'Lecture')}</td><td>${ex?badge(ex.status,ex.status==='Published'?'green':'blue'):badge('Unassigned','amber')}</td><td class="text-end"><button class="btn btn-${ex?'outline-':''}primary btn-sm" data-subject="${x.id}"><i class="ti ti-${ex?'edit':'user-plus'} me-1"></i>${ex?'Edit':'Select'}</button></td></tr>`}).join(''):`<tr><td colspan="7" class="tsm-empty"><i class="ti ti-books"></i>${s?'No assigned subjects match this search.':'No section selected.'}</td></tr>`;
    $('subjectRows').querySelectorAll('[data-subject]').forEach(btn=>btn.onclick=()=>this.chooseSubject(Number(btn.dataset.subject)));
  },
  chooseSubject(id){
    const subject=this.data.assigned_subjects.find(x=>Number(x.id)===id);if(!subject)return;this.subject=subject;
    const ex=this.existingFor(subject);this.teacher=ex?this.data.teachers.find(t=>Number(t.id)===Number(ex.teacher_id))||null:null;this.day=ex?.day_of_week||'Monday';
    this.block=ex?this.data.time_blocks.find(b=>shortTime(b.start_time)===shortTime(ex.start_time)&&shortTime(b.end_time)===shortTime(ex.end_time))||null:null;
    this.room=ex?this.data.rooms.find(r=>Number(r.id)===Number(ex.room_id))||null:null;this.live=null;this.invalidate();this.go(2);
  },
  subjectCard(){const x=this.subject,s=this.section;return `<div class="tsm-selection-main"><div class="tsm-avatar"><i class="ti ti-book"></i></div><div><h3>${esc(x.code)} · ${esc(x.name)}</h3><p>${esc(s.code)} · ${esc(s.program)} · ${esc(x.subject_type||'Lecture')}</p></div></div><div class="tsm-selection-stats"><div class="tsm-stat"><small>Units</small><b>${esc(x.units)}</b></div><div class="tsm-stat"><small>Enrollment</small><b>${esc(s.current_students)} students</b></div></div>`;},
  teacherQualification(t){const hit=(t.qualifications||[]).find(q=>Number(q.subject_id)===Number(this.subject.id));return{eligible:!this.data.qualification_configured||!!hit,label:hit?.specialization||hit?.subject_code||(this.data.qualification_configured?'Not qualified':'Not configured')};},
  projectedLoad(t){const base=Number(t.current_load_units||0),units=Number(this.subject.units||0),ex=this.existingFor(this.subject);return(ex&&Number(ex.teacher_id)===Number(t.id))?base:base+units;},
  renderTeachers(){
    $('selectedSubjectCard').innerHTML=this.subjectCard();$('qualificationNote').innerHTML=this.data.qualification_configured?'Qualification records are configured and enforced.':'<i class="ti ti-info-circle me-1"></i>Qualification register is not configured; no specialization is inferred.';
    const deps=this.values('department',this.data.teachers),prior=$('teacherDepartment').value;$('teacherDepartment').innerHTML='<option value="">All departments</option>'+deps.map(d=>`<option${d===prior?' selected':''}>${esc(d)}</option>`).join('');
    const q=$('teacherSearch').value.trim().toLowerCase(),dep=$('teacherDepartment').value,status=$('teacherStatus').value;
    const rows=this.data.teachers.filter(t=>{const spec=this.teacherQualification(t).label;return(!q||`${t.employee_no} ${t.full_name} ${t.department} ${spec}`.toLowerCase().includes(q))&&(!dep||t.department===dep)&&(!status||t.status===status);});
    $('teacherRows').innerHTML=rows.length?rows.map(t=>{const qual=this.teacherQualification(t),projected=this.projectedLoad(t),current=Number(t.current_load_units||0),max=Number(t.max_load_units||0),over=max>0&&projected>max,active=t.status==='Active',disabled=!active||!qual.eligible||over,selected=this.teacher&&Number(this.teacher.id)===Number(t.id);let reason=!active?'Inactive':!qual.eligible?'Not qualified':over?'Overload':'';return `<tr><td><strong>${esc(t.employee_no)}</strong></td><td>${esc(t.full_name)}</td><td>${esc(t.department||'—')}</td><td>${esc(qual.label)}</td><td><b>${current} / ${projected} / ${max||'—'}</b> units${over?'<br><small class="text-danger">Limit exceeded</small>':''}</td><td>${badge(t.status,active?'green':'red')}</td><td class="text-end"><button class="btn btn-${selected?'success':'primary'} btn-sm" data-teacher="${t.id}" ${disabled?'disabled':''}>${selected?'Selected':reason||'Select'}</button></td></tr>`}).join(''):'<tr><td colspan="7" class="tsm-empty"><i class="ti ti-user-off"></i>No teachers match these filters.</td></tr>';
    $('teacherRows').querySelectorAll('[data-teacher]:not(:disabled)').forEach(btn=>btn.onclick=()=>this.chooseTeacher(Number(btn.dataset.teacher)));
  },
  chooseTeacher(id){this.teacher=this.data.teachers.find(t=>Number(t.id)===id)||null;this.block=null;this.room=null;this.live=null;this.invalidate();this.go(3);},
  teacherCard(){const t=this.teacher,q=this.teacherQualification(t);return `<div class="tsm-selection-main"><div class="tsm-avatar"><i class="ti ti-user"></i></div><div><h3>${esc(t.full_name)}</h3><p>Faculty ID: ${esc(t.employee_no)} · ${esc(t.department||'No department')}</p></div></div><div class="tsm-selection-stats"><div class="tsm-stat"><small>Specialization</small><b>${esc(q.label)}</b></div><div class="tsm-stat"><small>Current / Max Load</small><b>${esc(t.current_load_units)}/${esc(t.max_load_units||'—')} units</b></div><div>${badge(t.status,t.status==='Active'?'green':'red')}</div><button class="btn btn-outline-primary btn-sm" type="button" onclick="TSM.go(2)"><i class="ti ti-edit me-1"></i>Replace Teacher</button></div>`;},
  overlaps(day,block,kind,id){const ex=this.existingFor(this.subject);return this.data.schedule_entries.filter(e=>e.day_of_week===day&&Number(e[kind])===Number(id)&&Number(e.id)!==Number(ex?.id||0)&&shortTime(e.start_time)<shortTime(block.end_time)&&shortTime(e.end_time)>shortTime(block.start_time));},
  renderAvailability(){
    $('selectedTeacherCard').innerHTML=this.teacherCard();this.fillSelect('availabilityDay',DAYS,this.day);
    let html='<thead><tr><th>Time</th>'+DAYS.map(d=>`<th>${d.slice(0,3)}</th>`).join('')+'</tr></thead><tbody>';
    html+=this.data.time_blocks.map(b=>`<tr><td class="time">${fmtTime(b.start_time)}<br>${fmtTime(b.end_time)}</td>`+DAYS.map(d=>{const hits=this.overlaps(d,b,'teacher_id',this.teacher.id);return `<td>${hits.length?`<div class="busy">${esc(hits[0].subject_code)}<br><small>${esc(hits[0].section_code)}</small></div>`:'<span class="free">Available</span>'}</td>`}).join('')+'</tr>').join('')+'</tbody>';$('weeklySchedule').innerHTML=html;this.renderSlotList();
  },
  renderSlotList(){
    this.day=$('availabilityDay').value||this.day;const blocks=this.data.time_blocks;
    $('availableSlots').innerHTML=blocks.length?blocks.map(b=>{const busy=this.overlaps(this.day,b,'teacher_id',this.teacher.id),selected=this.block&&Number(this.block.id)===Number(b.id);return `<button class="tsm-slot${selected?' selected':''}" type="button" data-block="${b.id}" ${busy.length?'disabled':''}><span><b>${fmtTime(b.start_time)} – ${fmtTime(b.end_time)}</b><small>${esc(b.label||b.code||'Class time block')}</small></span>${busy.length?badge('Occupied','red'):badge('Available','green')}</button>`}).join(''):'<div class="tsm-empty">No active Class time blocks are configured.</div>';
    $('availableSlots').querySelectorAll('[data-block]:not(:disabled)').forEach(btn=>btn.onclick=()=>{this.block=blocks.find(b=>Number(b.id)===Number(btn.dataset.block));this.room=null;this.live=null;this.invalidate();this.renderSlotList();});
  },
  assignmentList(){return `<dl class="tsm-data-list"><dt>Subject</dt><dd>${esc(this.subject.code)} · ${esc(this.subject.name)}</dd><dt>Section</dt><dd>${esc(this.section.code)}</dd><dt>Teacher</dt><dd>${esc(this.teacher.full_name)} (${esc(this.teacher.employee_no)})</dd><dt>Units</dt><dd>${esc(this.subject.units)}</dd><dt>Type</dt><dd>${esc(this.subject.subject_type||'Lecture')}</dd></dl>`;},
  renderAssignment(){
    $('assignmentDetails').innerHTML=this.assignmentList();this.fillSelect('scheduleDay',DAYS,this.day);
    $('scheduleBlock').innerHTML=this.data.time_blocks.map(b=>`<option value="${b.id}"${this.block&&Number(b.id)===Number(this.block.id)?' selected':''}>${esc(b.label||timeLabel(b))} (${fmtTime(b.start_time)} - ${fmtTime(b.end_time)})</option>`).join('');
    if(!this.block&&this.data.time_blocks[0])this.block=this.data.time_blocks[0];this.recheckSlot();
  },
  async recheckSlot(){
    this.day=$('scheduleDay').value||this.day;this.block=this.data.time_blocks.find(b=>Number(b.id)===Number($('scheduleBlock').value))||this.block;this.pendingRoomId=Number($('scheduleRoom').value||this.room?.id||0);this.room=null;this.invalidate();$('liveStatus').className='tsm-live-status';$('liveStatus').innerHTML='<span class="tsm-loading"><i class="ti ti-loader-2 ti-spin"></i>Checking teacher and room availability…</span>';
    if(!this.block)return;
    try{const p=new URLSearchParams({day:this.day,start:shortTime(this.block.start_time),end:shortTime(this.block.end_time),academic_year:this.section.academic_year||'',semester:this.section.semester||''}),ex=this.existingFor(this.subject);if(ex?.id)p.set('exclude_entry',ex.id);this.live=await this.api('/api/scheduling/slot-availability.php?'+p);this.renderRooms();}catch(e){this.live=null;$('liveStatus').className='tsm-live-status bad';$('liveStatus').textContent=e.message;this.renderRooms();}
  },
  roomAvailability(room){const slot=this.live?.rooms?.find(r=>Number(r.id)===Number(room.id)),enough=Number(room.capacity)>=Number(this.section.current_students);return{free:room.status==='Available'&&(!slot||slot.available),enough,slot};},
  renderRooms(){
    const current=Number(this.pendingRoomId||$('scheduleRoom').value||this.room?.id||0);this.pendingRoomId=0;
    $('scheduleRoom').innerHTML='<option value="">Select an available room</option>'+this.data.rooms.map(r=>{const a=this.roomAvailability(r),disabled=!a.free||!a.enough;return `<option value="${r.id}" ${disabled?'disabled':''}${Number(r.id)===current&&!disabled?' selected':''}>${esc(r.room_code)} · ${esc(r.room_type)} · ${esc(r.capacity)} seats${disabled?' — '+(!a.enough?'Insufficient capacity':r.status!=='Available'?r.status:'Occupied'):''}</option>`}).join('');
    if(current){const found=this.data.rooms.find(r=>Number(r.id)===current),a=found&&this.roomAvailability(found);if(found&&a.free&&a.enough)this.room=found;}
    const teacherSlot=this.live?.teachers?.find(t=>Number(t.id)===Number(this.teacher.id)),teacherFree=!teacherSlot||teacherSlot.available;
    $('liveStatus').className='tsm-live-status '+(teacherFree?'ok':'bad');$('liveStatus').innerHTML=teacherFree?`<i class="ti ti-circle-check me-1"></i>Teacher is available. ${this.live?.summary?.rooms_available??0} room(s) are currently free.`:`<i class="ti ti-circle-x me-1"></i>Teacher is occupied by ${esc(teacherSlot.busy_with||'another class')} (${esc(teacherSlot.busy_time||'selected time')}).`;this.renderRoomDetails();
  },
  renderRoomDetails(){const r=this.room;if(!r){$('roomDetails').innerHTML='<div class="tsm-empty"><i class="ti ti-door"></i>Select a room to view its details.</div>';return;}const a=this.roomAvailability(r);$('roomDetails').innerHTML=`<div class="tsm-room-visual"><i class="ti ti-building"></i></div><dl class="tsm-data-list"><dt>Room</dt><dd>${esc(r.room_code)}</dd><dt>Building</dt><dd>${esc(r.building||'Not configured')}</dd><dt>Room Type</dt><dd>${esc(r.room_type||'Not configured')}</dd><dt>Capacity</dt><dd>${esc(r.capacity)} seats</dd><dt>Facilities</dt><dd>Not configured</dd><dt>Slot Status</dt><dd>${badge(a.free?'Available':'Occupied',a.free?'green':'red')}</dd><dt>Capacity Check</dt><dd>${badge(a.enough?'Sufficient':'Insufficient',a.enough?'green':'red')}</dd></dl>`;},
  payload(){const ex=this.existingFor(this.subject);return{section_code:this.section.code,entries:[{id:ex?.id||undefined,existing_id:ex?.id||undefined,subject_code:this.subject.code,teacher:this.teacher.full_name,room:this.room.room_code,day:this.day,time:timeLabel(this.block),class_type:['Lecture','Laboratory','Online','Exam'].includes(this.subject.subject_type)?this.subject.subject_type:'Lecture'}]};},
  renderReview(){$('reviewSummary').innerHTML=this.assignmentList().replace('</dl>',`<dt>Day</dt><dd>${esc(this.day)}</dd><dt>Time</dt><dd>${fmtTime(this.block.start_time)} - ${fmtTime(this.block.end_time)}</dd><dt>Room</dt><dd>${esc(this.room.room_code)} (${esc(this.room.room_type)})</dd><dt>Save Status</dt><dd>${badge('Draft','blue')}</dd></dl>`);$('validationResults').innerHTML='<div class="tsm-check wait"><i class="ti ti-dots"></i><div><b>Validation pending</b><small>Running the complete conflict check…</small></div></div>';$('validationBanner').innerHTML='';},
  async validate(silent=false){
    this.invalidate();$('runValidation').disabled=true;if(!silent)$('validationResults').innerHTML='<div class="tsm-check wait"><i class="ti ti-loader-2 ti-spin"></i><div><b>Validating assignment</b><small>Checking the latest scheduling data…</small></div></div>';
    try{
      const body=await this.api('/api/scheduling/check-availability.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(this.payload())}),result=body.results?.[0],conflicts=result?.conflicts||[];
      const rules=[['Teacher','Teacher is active and available'],['Qualification','Teacher is qualified for the subject'],['Load','Teacher load is within the configured limit'],['Room','Room is available at the selected time'],['Capacity','Room capacity is sufficient'],['Section','Section has no conflicting class'],['Time','Time is an active class time block'],['Subject','Subject is assigned to the section']];
      const messageFor=key=>{if(key==='Qualification')return conflicts.find(c=>c.type==='Teacher'&&/qualified/i.test(c.message))?.message;if(key==='Load')return conflicts.find(c=>/load|units|limit/i.test(c.message))?.message;if(key==='Capacity')return conflicts.find(c=>/capacity|enrollment/i.test(c.message))?.message;if(key==='Time')return conflicts.find(c=>/time|start|end/i.test(c.message))?.message;if(key==='Subject')return conflicts.find(c=>/subject.*(inactive|exist|assigned)/i.test(c.message))?.message;return conflicts.find(c=>c.type===key)?.message;};
      $('validationResults').innerHTML=rules.map(([key,label])=>{const msg=messageFor(key),unconfigured=key==='Qualification'&&!this.data.qualification_configured,target=['Teacher','Qualification','Load'].includes(key)?2:4,tone=msg?'fail':unconfigured?'wait':'pass',icon=msg?'x':unconfigured?'minus':'check',detail=msg||(unconfigured?'Qualification register is not configured; no specialization was inferred.':'Passed');return `<div class="tsm-check ${tone}"><i class="ti ti-${icon}"></i><div><b>${esc(label)}</b><small>${esc(detail)}</small></div>${msg?`<button class="btn btn-outline-danger btn-sm" type="button" data-fix="${target}">Fix</button>`:''}</div>`;}).join('');
      $('validationResults').querySelectorAll('[data-fix]').forEach(b=>b.onclick=()=>this.go(Number(b.dataset.fix)));
      const valid=result?.result==='Available'&&Number(body.summary?.conflicts||0)===0&&Number(body.summary?.pending||0)===0;this.validation={valid,body,fingerprint:JSON.stringify(this.payload())};
      $('validationBanner').innerHTML=`<div class="tsm-validation-banner ${valid?'ok':'bad'}"><i class="ti ti-${valid?'circle-check':'alert-triangle'}"></i><span>${valid?'No conflicts found. This mapping can be saved as Draft.':conflicts.length?`${conflicts.length} critical issue(s) must be resolved before saving.`:'The assignment is incomplete and cannot be saved.'}</span></div>`;$('saveSchedule').disabled=!valid;return valid;
    }catch(e){$('validationResults').innerHTML=`<div class="tsm-check fail"><i class="ti ti-x"></i><div><b>Validation could not complete</b><small>${esc(e.message)}</small></div><button class="btn btn-outline-danger btn-sm" type="button" data-fix="4">Review</button></div>`;$('validationBanner').innerHTML='<div class="tsm-validation-banner bad"><i class="ti ti-alert-triangle"></i><span>Saving is blocked until validation succeeds.</span></div>';return false;}finally{$('runValidation').disabled=false;}
  },
  async save(){
    $('saveSchedule').disabled=true;$('saveSchedule').innerHTML='<i class="ti ti-loader-2 ti-spin me-2"></i>Rechecking…';
    try{
      const valid=await this.validate(true);if(!valid){this.notice('The latest validation found a conflict. Review the results before saving.','danger');return;}
      const body=await this.api('/api/scheduling/save-schedule.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(this.payload())});
      this.saved={...body,section:{...this.section},subject:{...this.subject},teacher:{...this.teacher},room:{...this.room},day:this.day,block:{...this.block}};
      $('confirmationDetails').innerHTML=`<dl class="tsm-data-list"><dt>Subject</dt><dd>${esc(this.subject.code)} · ${esc(this.subject.name)}</dd><dt>Section</dt><dd>${esc(this.section.code)}</dd><dt>Teacher</dt><dd>${esc(this.teacher.full_name)} (${esc(this.teacher.employee_no)})</dd><dt>Schedule</dt><dd>${esc(this.day)}, ${fmtTime(this.block.start_time)} - ${fmtTime(this.block.end_time)}</dd><dt>Room</dt><dd>${esc(this.room.room_code)}</dd><dt>Status</dt><dd>${badge('Draft','blue')}</dd></dl>`;this.go(6);
    }catch(e){this.notice(e.message,'danger');await this.validate();}finally{$('saveSchedule').innerHTML='<i class="ti ti-device-floppy me-2"></i>Save Schedule as Draft';}
  },
  async openHistory(){
    $('historyOverlay').classList.remove('d-none');$('historyBody').innerHTML='<div class="tsm-empty"><i class="ti ti-loader-2 ti-spin"></i>Loading history…</div>';$('historyScope').textContent=this.section?`Important changes for ${this.section.code}`:'Recent Teacher Mapping saves';
    try{const body=await this.api('/api/scheduling/teacher-schedule-mapping.php?action=history'+(this.section?`&section_code=${encodeURIComponent(this.section.code)}`:''));$('historyBody').innerHTML=body.history?.length?body.history.map(h=>`<article class="tsm-history-item"><b>${esc(h.action)} · ${esc(h.user_name||'System')}</b><p>${esc(h.detail)}</p><small>${esc(h.created_at)} · ${esc(h.role_key||'')}</small></article>`).join(''):'<div class="tsm-empty"><i class="ti ti-clock"></i>No matching history entries yet.</div>';}catch(e){$('historyBody').innerHTML=`<div class="alert alert-danger">${esc(e.message)}</div>`;}
  },
  exportCsv(){
    if(!this.section)return this.notice('Select a section before exporting.','warning');const rows=[['Subject','Section','Teacher','Day','Start','End','Room','Status']];
    for(const e of this.data.section_schedule||[])rows.push([`${e.subject_code} - ${e.subject_name}`,this.section.code,e.teacher_name||'',e.day_of_week||'',shortTime(e.start_time),shortTime(e.end_time),e.room_code||'',e.status]);
    if(this.subject&&this.teacher&&this.block&&this.room&&!rows.slice(1).some(r=>r[0].startsWith(this.subject.code+' - ')))rows.push([`${this.subject.code} - ${this.subject.name}`,this.section.code,this.teacher.full_name,this.day,shortTime(this.block.start_time),shortTime(this.block.end_time),this.room.room_code,'Unsaved']);
    const csv=rows.map(r=>r.map(v=>`"${String(v??'').replaceAll('"','""')}"`).join(',')).join('\r\n'),a=document.createElement('a');a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8'}));a.download=`${this.section.code}-teacher-schedule.csv`;a.click();URL.revokeObjectURL(a.href);
  },
  print(){if(!this.subject)return this.notice('Select an assignment before printing.','warning');if(this.page!==5&&this.room)this.go(5);setTimeout(()=>window.print(),150);},
  async returnToSchedule(){const code=this.saved?.section?.code||this.section?.code||'';await this.load(code);this.resetSelection();this.renderSubjects();this.go(1);},
  async another(){await this.returnToSchedule();}
};
window.TSM=TSM;
document.addEventListener('DOMContentLoaded',async()=>{
  try{await TSM.load();await TSM.initFilters(INITIAL_SECTION);TSM.go(1);}catch(e){TSM.notice(e.message,'danger');$('subjectRows').innerHTML='<tr><td colspan="7" class="tsm-empty">Scheduling data could not be loaded.</td></tr>';}
  $('filterYear').onchange=()=>TSM.filterChanged(1);$('filterSemester').onchange=()=>TSM.filterChanged(2);$('filterProgram').onchange=()=>TSM.filterChanged(3);$('filterLevel').onchange=()=>TSM.filterChanged(4);$('filterSection').onchange=()=>TSM.selectSection($('filterSection').value);
  $('subjectSearch').oninput=()=>TSM.renderSubjects();['teacherSearch','teacherDepartment','teacherStatus'].forEach(id=>$(id).oninput=()=>TSM.renderTeachers());
  $('availabilityDay').onchange=()=>{TSM.day=$('availabilityDay').value;TSM.block=null;TSM.room=null;TSM.invalidate();TSM.renderSlotList();};$('availabilityNext').onclick=()=>TSM.block?TSM.go(4):TSM.notice('Choose an available time slot to continue.','warning');
  $('scheduleDay').onchange=()=>TSM.recheckSlot();$('scheduleBlock').onchange=()=>TSM.recheckSlot();$('scheduleRoom').onchange=()=>{TSM.room=TSM.data.rooms.find(r=>Number(r.id)===Number($('scheduleRoom').value))||null;TSM.invalidate();TSM.renderRoomDetails();};$('checkRoom').onclick=()=>TSM.recheckSlot();
  $('reviewNext').onclick=()=>{if(!TSM.room)return TSM.notice('Select an available room to continue.','warning');const tf=TSM.live?.teachers?.find(t=>Number(t.id)===Number(TSM.teacher.id));if(tf&&!tf.available)return TSM.notice('The selected teacher is occupied at this time. Choose another slot.','danger');TSM.go(5);};
  $('runValidation').onclick=()=>TSM.validate();$('saveSchedule').onclick=()=>TSM.save();document.querySelectorAll('[data-back]').forEach(b=>b.onclick=()=>TSM.go(Number(b.dataset.back)));document.querySelectorAll('#tsmProgress button').forEach(b=>b.onclick=()=>TSM.go(Number(b.closest('li').dataset.step)));
  $('tsmExport').onclick=()=>TSM.exportCsv();$('tsmPrint').onclick=()=>TSM.print();$('tsmHistory').onclick=()=>TSM.openHistory();$('closeHistory').onclick=()=>$('historyOverlay').classList.add('d-none');$('historyOverlay').onclick=e=>{if(e.target===$('historyOverlay'))$('historyOverlay').classList.add('d-none');};$('viewSchedule').onclick=()=>TSM.returnToSchedule();$('assignAnother').onclick=()=>TSM.another();
});
})();
</script>
<?php require_once __DIR__ . '/../../../includes/layout-end.php'; ?>
