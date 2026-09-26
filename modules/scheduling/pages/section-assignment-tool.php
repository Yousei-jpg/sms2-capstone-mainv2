<?php
require_once __DIR__.'/../../../config/config.php';
$pageTitle='Section Assignment Tool'; $activeModule='scheduling'; $activePage='section-assignment-tool'; $hideModulePageBanner=true;
$breadcrumbs=[['label'=>'Class Scheduling','url'=>BASE_URL.'/modules/scheduling/index.php'],['label'=>'Section Assignment Tool','url'=>null]];
require_once __DIR__.'/../../../includes/breadcrumbs.php';
require_once __DIR__.'/../../../includes/layout-start.php';
renderBreadcrumbs($breadcrumbs);
?>
<div id="sectionWorkspace">
<header class="sa-heading"><div><small>CLASS SCHEDULING · SECTION REQUIREMENTS</small><h1><i class="ti ti-stack-2"></i> Section Assignment Tool</h1><p>Select a section, confirm its subjects, and prepare it for teacher scheduling.</p></div><div class="sa-actions no-print"><button class="btn btn-light" id="refresh"><i class="ti ti-refresh"></i> Refresh</button><button class="btn btn-light" id="history"><i class="ti ti-history"></i> History</button><button class="btn btn-light" id="export"><i class="ti ti-download"></i> Export</button><button class="btn btn-light" id="print"><i class="ti ti-printer"></i> Print</button></div></header>
<div id="notice" class="alert d-none" role="status" aria-live="polite"></div>
<div class="row g-3 mb-3" id="stats"></div>
<div class="card mb-3"><div class="card-body">
<h2><i class="ti ti-filter"></i> Select Section</h2>
<div class="row g-3 no-print">
<?php foreach (['academic_year'=>'Academic Year','semester'=>'Semester','program'=>'Program','year_level'=>'Year Level','section'=>'Section'] as $key=>$label): ?>
<div class="col-sm-6 col-xl"><label class="form-label" for="<?= $key ?>"><?= $label ?></label><select id="<?= $key ?>" class="form-select" disabled><option value="">Select...</option></select></div>
<?php endforeach; ?>
</div></div></div>
<div class="row g-3">
<aside class="col-xl-4">
<div class="card mb-3"><div class="card-body"><h2><i class="ti ti-layout-dashboard"></i> Section Dashboard</h2><div class="d-flex gap-2 no-print mb-3"><input id="search" class="form-control" placeholder="Search section..." aria-label="Search sections"><select id="status" class="form-select" aria-label="Filter scheduling status"><option value="">All statuses</option><option>For Scheduling</option><option>Ready for Scheduling</option><option>In Progress</option></select></div><div id="sectionList" class="sa-section-list"></div></div></div>
<div class="card"><div class="card-body"><h2><i class="ti ti-file-description"></i> Official Section Information</h2><p class="text-muted small">Read-only information from existing section records.</p><dl id="details" class="sa-details"><dd>Select a section to view its details.</dd></dl></div></div>
</aside>
<main class="col-xl-8">
<div class="card mb-3"><div class="card-body">
<div class="d-flex flex-wrap justify-content-between gap-2"><div><h2><i class="ti ti-books"></i> Confirm Curriculum Subjects</h2><p class="text-muted small" id="subjectHint">Select a section to load eligible subjects.</p></div><button class="btn btn-outline-primary no-print" id="allSubjects" disabled><i class="ti ti-list-check"></i> Select All Eligible</button></div>
<div class="table-responsive"><table class="table align-middle"><thead><tr><th>Include</th><th>Subject</th><th>Units</th><th>Type</th></tr></thead><tbody id="subjects"><tr><td colspan="4">No section selected.</td></tr></tbody></table></div><div id="totals" class="text-end fw-semibold"></div>
</div></div>
<div class="card mb-3"><div class="card-body"><h2><i class="ti ti-clipboard-list"></i> Scheduling Requirements</h2><p id="requirementSection" class="text-muted small">No section selected.</p><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Subject</th><th>Teacher</th><th>Time</th><th>Room</th></tr></thead><tbody id="requirements"><tr><td colspan="4">Confirm subjects to prepare requirements.</td></tr></tbody></table></div><p class="small text-muted mb-0"><i class="ti ti-info-circle"></i> Teacher, time, and room assignments continue in Teacher Schedule Mapping.</p></div></div>
<div class="card"><div class="card-body"><h2><i class="ti ti-shield-check"></i> Validate &amp; Save</h2><div id="validation" aria-live="polite" class="mb-3 text-muted">Confirm the selected subjects, then validate the requirements.</div><div class="sa-actions no-print"><button class="btn btn-outline-primary" id="validate" disabled><i class="ti ti-shield-check"></i> Validate Requirements</button><button class="btn btn-primary" id="save" disabled><i class="ti ti-device-floppy"></i> Save Assignment</button><button class="btn btn-success" id="continue" disabled><i class="ti ti-arrow-right"></i> Teacher Schedule Mapping</button></div></div></div>
</main></div>
<dialog id="historyDialog"><div class="d-flex justify-content-between align-items-center"><h2><i class="ti ti-history"></i> Assignment History</h2><button id="closeHistory" class="btn btn-outline-secondary">Close</button></div><div id="historyRows"></div></dialog>
</div>
<style>
.sa-heading{background:linear-gradient(110deg,#0a2851,#125d9d);color:white;padding:1.5rem;border-radius:14px;display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;margin-bottom:1rem}.sa-heading small{letter-spacing:.1em;font-size:.7rem;color:#c8e1ff}.sa-heading h1{font-size:1.65rem;color:white;margin:.45rem 0}.sa-heading p{margin:0;color:#e0ecff}.sa-actions{display:flex;gap:.6rem;flex-wrap:wrap}#sectionWorkspace h2{font-size:1rem;font-weight:700;margin-bottom:1rem}#sectionWorkspace h2 i{color:#1768b8;margin-right:.35rem}.sa-details{display:grid;grid-template-columns:110px 1fr;gap:.6rem;font-size:.85rem}.sa-details dt{color:#65778b}.sa-details dd{margin:0;overflow-wrap:anywhere}.sa-section-list{max-height:380px;overflow:auto;display:grid;gap:.5rem}.sa-section{padding:.8rem;border:1px solid #dae4ee;border-radius:8px;background:transparent;text-align:left;width:100%;color:inherit}.sa-section.selected{border-color:#1671dc;background:#eff6ff;color:#123d70}.sa-section small{display:block;margin:.3rem 0}.sa-stat{padding:1rem;border:1px solid #dce7f1;border-radius:12px;background:var(--sms-surface,#fff)}.sa-stat strong{font-size:1.6rem;display:block}.sa-pill{font-size:.73rem;background:#edf3fa;color:#345c81;border-radius:20px;padding:.25rem .55rem;display:inline-block}.sa-check{padding:.45rem 0;color:#18864d}.sa-check i{margin-right:.5rem}#historyDialog{border:0;border-radius:14px;padding:1.5rem;width:min(800px,90vw);max-height:80vh}#historyDialog::backdrop{background:#10213b88}@media print{.no-print,aside,#stats,#historyDialog{display:none!important}.sa-heading{background:white;color:black}.sa-heading h1,.sa-heading p{color:black}main{width:100%!important}body .sidebar,body .navbar{display:none!important}}
</style>
<script>
(() => {
'use strict';
const BASE=<?= json_encode(BASE_URL) ?>, API=BASE+'/api/scheduling/section-assignment.php';
const $=id=>document.getElementById(id), esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const fields=['academic_year','semester','program','year_level'];
let data={sections:[],subjects:[],history:[]}, current=null, selected=new Set(), valid=false, dirty=false, busy=false, revision=0;
function message(text,type='info'){ $('notice').className='alert alert-'+type; $('notice').textContent=text; }
function buttons(){ $('validate').disabled=busy||!current||!selected.size; $('save').disabled=busy||!valid; $('allSubjects').disabled=busy||!current; $('continue').disabled=busy||!current||dirty||!current.subjects.length; $('subjects').querySelectorAll('input').forEach(el=>el.disabled=busy); [...fields,'section','refresh'].forEach(k=>$(k).disabled=busy); $('print').disabled=busy||!current; }
function invalidate(){revision++;valid=false;dirty=true;$('notice').classList.add('d-none');$('validation').textContent='Selection changed. Validate the requirements again.';buttons();}
function payload(action){return {action,id:current.id,revision:current.revision,academic_year:current.academic_year,semester:current.semester,program:current.program,year_level:current.year_level,subjects:[...selected]};}
async function request(p){const res=await fetch(API,p?{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(p)}:{});const d=await res.json();if(!res.ok||!d.ok)throw Error(d.error||'Request failed.');return d;}
function eligible(){return current?data.subjects.filter(s=>(s.program==='ALL'||s.program===current.program)&&(s.year_level==null||Number(s.year_level)===Number(current.year_level))&&(!s.semester||s.semester===current.semester)):[];}
function filtered(){return data.sections.filter(s=>fields.every(k=>!$(k).value||String(s[k])===$(k).value));}
function visibleSections(){
 const q=$('search').value.trim().toLowerCase(), status=$('status').value;
 return filtered().filter(s=>(!status||s.scheduling_status===status)&&((s.code+' '+s.name+' '+s.program).toLowerCase().includes(q)));
}
function fill(id,values,value){$(id).innerHTML='<option value="">Select...</option>'+values.map(v=>'<option value="'+esc(v)+'">'+esc(v)+'</option>').join('');$(id).value=String(value??'');$(id).disabled=false;}
function filters(target=null){
 let rows=data.sections;
 for(const k of fields){const values=[...new Set(rows.map(s=>String(s[k])))];const value=target?String(target[k]):(values.includes($(k).value)?$(k).value:'');fill(k,values,value);if(value)rows=rows.filter(s=>String(s[k])===value);}
 $('section').innerHTML='<option value="">Select a section...</option>'+rows.map(s=>'<option value="'+s.id+'">'+esc(s.code)+'</option>').join('');$('section').disabled=false;$('section').value=target?String(target.id):'';list();
}
function list(){
 const rows=visibleSections();
 $('sectionList').innerHTML=rows.map(s=>'<button type="button" class="sa-section '+(s.id===current?.id?'selected':'')+'" data-id="'+s.id+'"><b>'+esc(s.code)+'</b><small>'+esc(s.program+' · Year '+s.year_level+' · '+s.academic_year+' · '+s.semester)+'</small><span class="sa-pill">'+esc(s.scheduling_status)+'</span></button>').join('')||'<p class="text-muted">No sections match these filters.</p>';
 $('sectionList').querySelectorAll('[data-id]').forEach(b=>b.onclick=()=>choose(Number(b.dataset.id)));
}
function render(){
 $('details').innerHTML=current?[['Code',current.code],['Name',current.name],['Program',current.program],['Year Level',current.year_level],['Academic Year',current.academic_year],['Semester',current.semester],['Capacity',current.max_students],['Students',current.current_students],['Scheduling',current.scheduling_status]].map(([k,v])=>'<dt>'+esc(k)+'</dt><dd>'+esc(v)+'</dd>').join(''):'<dd>Select a section to view its details.</dd>';
 const allowed=eligible(), allowedCodes=new Set(allowed.map(s=>s.code));
 const unavailable=current?current.subjects.filter(c=>!allowedCodes.has(c)):[];
 const rows=[...allowed,...unavailable.map(code=>({code,name:'No longer eligible — remove or correct source curriculum',units:0,subject_type:'Unavailable'}))];
 $('subjects').innerHTML=rows.map(s=>'<tr><td><input type="checkbox" class="form-check-input" aria-label="Include '+esc(s.code)+'" data-code="'+esc(s.code)+'" '+(selected.has(s.code)?'checked':'')+'></td><td><b>'+esc(s.code)+'</b><br><small>'+esc(s.name)+'</small></td><td>'+esc(s.units)+'</td><td>'+esc(s.subject_type)+'</td></tr>').join('')||'<tr><td colspan="4">No eligible curriculum subjects for this selection.</td></tr>';
 $('subjects').querySelectorAll('[data-code]').forEach(el=>el.onchange=()=>{el.checked?selected.add(el.dataset.code):selected.delete(el.dataset.code);invalidate();requirements();});
 $('subjectHint').textContent=current?'Curriculum for '+current.program+' · Year '+current.year_level+' · '+current.semester:'Select a section to load subjects.';
 requirements();list();buttons();
}
function requirements(){
 $('requirementSection').textContent=current?current.code+' · '+current.program+' · Year '+current.year_level+' · '+current.academic_year+' · '+current.semester+(dirty?' · Unsaved selection':' · Saved assignments'):'No section selected.';
 const sum=[...selected].reduce((n,c)=>n+Number(data.subjects.find(s=>s.code===c)?.units||0),0);
 $('totals').textContent=selected.size+' subjects · '+sum+' units';
 $('requirements').innerHTML=[...selected].map(code=>{
 const sub=data.subjects.find(s=>s.code===code), entries=(current?.requirements||[]).filter(e=>Number(e.subject_id)===Number(sub?.id));
 const tag=predicate=>{const count=entries.filter(predicate).length;return '<span class="sa-pill">'+(!count?'Pending':count===entries.length?'Assigned':'Partially assigned')+'</span>';};
 return '<tr><td>'+esc(code)+'</td><td>'+tag(e=>e.teacher_id)+'</td><td>'+tag(e=>e.day_of_week&&e.start_time&&e.end_time)+'</td><td>'+tag(e=>e.room_id)+'</td></tr>';
 }).join('')||'<tr><td colspan="4">Confirm subjects to prepare requirements.</td></tr>';
}
function choose(id){
 if(busy)return;
 if(dirty&&!confirm('Discard unsaved subject selections?')){ $('section').value=current?.id||'';return;}
 current=data.sections.find(s=>Number(s.id)===id)||null;selected=new Set(current?.subjects||[]);valid=false;dirty=false;revision++;
 if(current)filters(current);
 $('notice').classList.add('d-none');
 $('validation').textContent='Confirm subjects, then validate the requirements.';render();
}
async function load(){
 const id=current?.id;data=await request();
 $('stats').innerHTML=[['ti-users','Total Sections',data.sections.length],['ti-clock','For Scheduling',data.sections.filter(s=>s.scheduling_status==='For Scheduling').length],['ti-circle-check','Ready for Scheduling',data.sections.filter(s=>s.scheduling_status==='Ready for Scheduling').length],['ti-calendar','In Progress',data.sections.filter(s=>s.scheduling_status==='In Progress').length]].map(([icon,label,count])=>'<div class="col-sm-6 col-xl-3"><div class="sa-stat"><i class="ti '+icon+'"></i> '+label+'<strong>'+count+'</strong></div></div>').join('');
 current=data.sections.find(s=>s.id===id)||null;selected=new Set(current?.subjects||[]);dirty=false;valid=false;revision++;filters(current);render();
 $('validation').textContent='Confirm subjects, then validate the requirements.';
}
$('validate').onclick=async()=>{
 if(busy||!current||!selected.size)return;
 const version=revision;busy=true;buttons();
 $('notice').classList.add('d-none');$('validation').textContent='Checking requirements...';
 try {const d=await request(payload('validate'));if(version!==revision)return;valid=true;$('validation').innerHTML=d.checks.map(c=>'<div class="sa-check"><i class="ti ti-circle-check"></i>'+esc(c)+'</div>').join('');}
 catch(e){valid=false;$('validation').textContent=e.message;message(e.message,'danger');}finally{busy=false;buttons();}
};
$('save').onclick=async()=>{
 if(!valid||busy)return;const p=payload('save');busy=true;buttons();
 try{
   const d=await request(p);
   // A successful save must not be reported as failed if the subsequent refresh fails.
   current.subjects=[...p.subjects];current.revision=d.revision;current.scheduling_status=d.status;dirty=false;valid=false;
   try{await load();message(d.message,'success');}
   catch(refreshError){render();message(d.message+' The dashboard could not refresh. Use Refresh to reload it.','warning');}
   $('validation').innerHTML='<div class="sa-check"><i class="ti ti-circle-check"></i>Assignment saved. Continue to Teacher Schedule Mapping.</div>';
 }
 catch(e){valid=false;message(e.message,'danger');$('validation').textContent=e.message;}finally{busy=false;buttons();}
};
fields.forEach((k,i)=>$(k).onchange=()=>{if(busy){filters(current);return;}if(dirty&&!confirm('Discard unsaved subject selections?')){filters(current);return;}fields.slice(i+1).forEach(f=>$(f).value='');current=null;selected.clear();dirty=false;valid=false;revision++;filters();render();$('validation').textContent='Select a section and confirm its subjects.';});
$('section').onchange=()=>choose(Number($('section').value));
$('allSubjects').onclick=()=>{selected=new Set(eligible().map(s=>s.code));invalidate();render();};
$('search').oninput=list;$('status').onchange=list;
$('refresh').onclick=async()=>{if(busy)return;if(dirty&&!confirm('Discard unsaved subject selections and refresh?'))return;busy=true;buttons();try{await load();$('notice').classList.add('d-none');}catch(e){message(e.message,'danger');}finally{busy=false;buttons();}};
$('continue').onclick=()=>{if(!current||dirty||busy)return;location.href=BASE+'/modules/scheduling/pages/teacher-schedule-mapping.php?section='+encodeURIComponent(current.code)+'&section_id='+current.id;};
$('history').onclick=()=>{$('historyRows').innerHTML=data.history.map(h=>'<article class="border-bottom py-3"><b>'+esc(h.action)+'</b><p>'+esc(h.detail)+'</p><small>'+esc(h.created_at+' · '+(h.user_name||'Staff'))+'</small></article>').join('')||'<p>No saved assignment history.</p>';$('historyDialog').showModal();};$('closeHistory').onclick=()=>$('historyDialog').close();
$('print').onclick=()=>window.print();
$('export').onclick=()=>{const rows=[['Section','Academic Year','Semester','Program','Year','Scheduling Status','Subjects'],...visibleSections().map(s=>[s.code,s.academic_year,s.semester,s.program,s.year_level,s.scheduling_status,s.subjects.join(', ')])];const csv=rows.map(r=>r.map(v=>'"'+String(v).replace(/^[=+@-]/,"'$&").replace(/"/g,'""')+'"').join(',')).join('\r\n');const a=document.createElement('a');a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv'}));a.download='section-assignments.csv';a.click();URL.revokeObjectURL(a.href);};
window.addEventListener('beforeunload',e=>{if(dirty){e.preventDefault();e.returnValue='';}});
busy=true;buttons();load().catch(e=>message(e.message,'danger')).finally(()=>{busy=false;buttons();});
})();
</script>
<?php require_once __DIR__.'/../../../includes/layout-end.php'; ?>
