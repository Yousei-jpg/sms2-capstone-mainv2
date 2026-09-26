(() => {
'use strict';
const root=document.getElementById('teacherMapping');if(!root)return;
const BASE=root.dataset.base,$=id=>document.getElementById('tm'+id);
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const DAYS=['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
const short=t=>String(t||'').slice(0,5),time=t=>{const [h,m]=short(t).split(':').map(Number);return Number.isFinite(h)?`${h%12||12}:${String(m).padStart(2,'0')} ${h<12?'AM':'PM'}`:'—';};
const badge=(v,t='gray')=>`<span class="tm-badge ${t}">${esc(v)}</span>`;
const dl=rows=>rows.map(([k,v])=>`<dt>${esc(k)}</dt><dd>${esc(v??'—')}</dd>`).join('');
const S={data:{sections:[],teachers:[],assigned_subjects:[],section_schedule:[],time_blocks:[]},section:null,subject:null,teacher:null,entry:null,block:null,day:'Monday',view:'subjects',busy:false,dirty:false,validation:null};
function notice(message,tone='danger'){$('Notice').textContent=message;$('Notice').className='alert alert-'+tone;}
function invalidate(){S.validation=null;S.dirty=true;$('Save').disabled=true;$('Notice').classList.add('d-none');}
async function api(path,p){const res=await fetch(BASE+path,p?{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(p)}:{credentials:'same-origin'});const data=await res.json();if(!res.ok||!data.ok)throw Error(data.error||'Unable to load scheduling data.');return data;}
async function work(fn){if(S.busy)return;S.busy=true;root.inert=true;root.setAttribute('aria-busy','true');try{await fn();}catch(e){notice(e.message);}finally{S.busy=false;root.inert=false;root.setAttribute('aria-busy','false');}}
function photo(t,small=false){const initials=(t.full_name||'Faculty').replace(/^(Prof\.?|Dr\.?|Mr\.?|Ms\.?)\s+/i,'').split(/\s+/).slice(0,2).map(n=>n[0]).join('').toUpperCase();let url='';try{const u=new URL(t.photo_url,location.origin);if(t.photo_url&&u.origin===location.origin&&u.pathname.startsWith(BASE+'/images/faculty/'))url=u.href;}catch{}return `<span class="tm-photo${small?' small':''}" role="img" aria-label="${esc(t.full_name)} profile"><span>${esc(initials)}</span>${url?`<img src="${esc(url)}" alt="" loading="lazy">`:''}</span>`;}
root.addEventListener('error',e=>{if(e.target.matches('.tm-photo img'))e.target.remove();},true);
function qualification(t){return(t.qualifications||[]).find(q=>Number(q.subject_id)===Number(S.subject?.id));}
function projected(t){return Number(t.current_load_units||0)+(Number(S.entry?.teacher_id)===Number(t.id)?0:Number(S.subject?.units||0));}
function eligible(t){return t.status==='Active'&&!!qualification(t)&&(!Number(t.max_load_units)||projected(t)<=Number(t.max_load_units));}
function profile(t){return `<div class="tm-profile">${photo(t)}<div><b>${esc(t.full_name)}</b><small>${esc(t.employee_no)}</small>${badge(t.status,t.status==='Active'?'green':'red')}</div></div><dl>${dl([['Department',t.department],['Specialization',qualification(t)?.specialization||qualification(t)?.subject_code||'Not verified'],['Current load',`${t.current_load_units} units`],['Projected load',`${projected(t)} units`],['Maximum load',Number(t.max_load_units)?`${t.max_load_units} units`:'Not configured']])}</dl>`;}
function entryFor(s){return S.data.section_schedule.find(e=>Number(e.subject_id)===Number(s.id))||null;}
function payload(action){return {action,section_id:Number(S.section.id),subject_id:Number(S.subject.id),teacher_id:Number(S.teacher.id),time_block_id:Number(S.block.id),day:S.day,entry_id:Number(S.entry?.id||0),revision:S.entry?.revision||'new'};}
function setOptions(id,rows,value,placeholder){$(id).innerHTML=(placeholder?`<option value="">${esc(placeholder)}</option>`:'')+rows.map(([id,label])=>`<option value="${esc(id)}">${esc(label)}</option>`).join('');$(id).value=String(value??'');}
const FILTERS=[['Year','academic_year'],['Semester','semester'],['Program','program'],['Level','year_level']];
function filters(target){let rows=S.data.sections;for(const [id,key] of FILTERS){const values=[...new Set(rows.map(r=>String(r[key])))];const value=target?String(target[key]):(values.includes($(id).value)?$(id).value:'');setOptions(id,values.map(v=>[v,v]),value,'All');if(value)rows=rows.filter(r=>String(r[key])===value);}setOptions('Section',rows.map(r=>[r.id,r.code]),target?.id,'Select section');return rows;}
function reset(){S.subject=null;S.teacher=null;S.entry=null;S.block=null;S.validation=null;S.dirty=false;}
async function load(id=0){const d=await api('/api/scheduling/teacher-schedule-mapping.php'+(id?'?section_id='+id:''));S.data=d;S.section=d.section||null;reset();filters(S.section);show('subjects');}
function show(view){if(S.busy&&view!=='subjects')return;if(view!=='subjects'&&!S.subject)return;if(['availability','review'].includes(view)&&!S.teacher)return;if(view==='review'&&!S.block)return;S.view=view;root.querySelectorAll('[data-panel]').forEach(el=>el.hidden=el.dataset.panel!==view);root.querySelectorAll('[data-view]').forEach(el=>{el.classList.toggle('active',el.dataset.view===view);el.disabled=el.dataset.view!=='subjects'&&!S.subject||['availability','review'].includes(el.dataset.view)&&!S.teacher||el.dataset.view==='review'&&!S.block;});if(view==='subjects')renderSubjects();if(view==='faculty')renderFaculty();if(view==='availability')renderAvailability();if(view==='review'){renderReview();validate();}}
function renderSubjects(){const rows=S.data.assigned_subjects||[],q=$('SubjectSearch').value.trim().toLowerCase();$('SectionLabel').textContent=S.section?'· '+S.section.code:'';$('Subjects').innerHTML=rows.filter(s=>(s.code+' '+s.name).toLowerCase().includes(q)).map(s=>{const e=entryFor(s),assigned=!!e?.teacher_id;return `<tr><td><b>${esc(s.code)}</b></td><td>${esc(s.name)}</td><td>${esc(s.units)}</td><td>${esc(s.subject_type)}</td><td>${badge(e?.status==='Published'?'Published':assigned?'Teacher Assigned':'Pending Teacher',assigned?'green':'amber')}</td><td class="no-print"><button class="btn btn-sm btn-${assigned?'outline-':''}primary" data-subject="${s.id}"><i class="ti ti-${assigned?'eye':'user-plus'}"></i> ${assigned?'View Mapping':'Map Teacher'}</button></td></tr>`;}).join('')||'<tr><td colspan="6" class="tm-empty">No assigned subjects.</td></tr>';
 const s=S.section;$('SectionDetails').innerHTML=s?dl([['Section',s.code],['Program',s.program],['Year level',s.year_level],['Academic year',s.academic_year],['Semester',s.semester],['Students',s.current_students],['Capacity',s.max_students]]):'<dd>No section selected.</dd>';
 const count=rows.filter(x=>entryFor(x)?.teacher_id).length;$('ProgressCount').textContent=`${count} / ${rows.length}`;$('Progress').value=rows.length?100*count/rows.length:0;$('ProgressDetails').innerHTML=badge(`${rows.length-count} pending`,rows.length>count?'amber':'green');
}
function selectSubject(id){if(S.dirty&&!confirm('Discard unsaved teacher mapping changes?'))return;reset();S.subject=S.data.assigned_subjects.find(s=>Number(s.id)===id);S.entry=entryFor(S.subject);if(S.entry){S.teacher=S.data.teachers.find(t=>Number(t.id)===Number(S.entry.teacher_id))||null;S.day=S.entry.day_of_week||'Monday';S.block=S.data.time_blocks.find(b=>short(b.start_time)===short(S.entry.start_time)&&short(b.end_time)===short(S.entry.end_time))||null;}show('faculty');}
function renderFaculty(){const s=S.subject;$('SubjectContext').innerHTML=`<div><b><i class="ti ti-book"></i> ${esc(s.code)} · ${esc(s.name)}</b><div>${esc(S.section.code)} · ${esc(s.units)} units · ${esc(s.subject_type)}</div></div>${S.entry?.status==='Published'?badge('Published · Read-only','blue'):''}`;
 const previous=$('Department').value;setOptions('Department',[...new Set(S.data.teachers.map(t=>t.department).filter(Boolean))].map(d=>[d,d]),previous,'All departments');
 const q=$('TeacherSearch').value.toLowerCase(),dep=$('Department').value;
 const teachers=S.data.teachers.filter(t=>(!dep||t.department===dep)&&(!$('Eligibility').value||$('Eligibility').value==='all'||eligible(t)||Number(t.id)===Number(S.teacher?.id))&&(`${t.full_name} ${t.employee_no} ${qualification(t)?.specialization||''}`).toLowerCase().includes(q));
 $('Teachers').innerHTML=teachers.map(t=>{const status=t.status!=='Active'?'Inactive':!qualification(t)?'Not qualified':!eligible(t)?'Overload':'Eligible';return `<tr><td><div class="tm-profile mb-0">${photo(t,true)}<div><b>${esc(t.full_name)}</b><small>${esc(t.employee_no)} · ${esc(t.department)}</small></div></div></td><td>${esc(qualification(t)?.specialization||qualification(t)?.subject_code||'Not verified')}</td><td>${esc(t.current_load_units)} / ${esc(Number(t.max_load_units)||'—')}</td><td>${badge(status,status==='Eligible'?'green':'red')}</td><td><button class="btn btn-sm btn-outline-primary" data-teacher="${t.id}"><i class="ti ti-eye"></i> View</button></td></tr>`;}).join('')||'<tr><td colspan="5" class="tm-empty">No eligible teachers match this subject.</td></tr>';
 renderTeacherDetails();
}
function renderTeacherDetails(){const t=S.teacher;$('TeacherDetails').innerHTML=t?`<h2><i class="ti ti-user"></i> Teacher Details</h2>${profile(t)}<button class="btn btn-primary w-100 mt-3" id="tmChooseTeacher" ${!eligible(t)||S.entry?.status==='Published'?'disabled':''}><i class="ti ti-calendar"></i> Select Teacher &amp; Time</button>`:'<h2><i class="ti ti-user"></i> Teacher Details</h2><div class="tm-empty">Select a faculty profile.</div>';if(t)$('ChooseTeacher').onclick=()=>{invalidate();show('availability');};}
function slot(day,b){const overlap=r=>r.day_of_week===day&&short(r.start_time)<short(b.end_time)&&short(r.end_time)>short(b.start_time);const sameTerm=r=>(!r.academic_year||r.academic_year===S.section.academic_year)&&(!r.semester||r.semester===S.section.semester);
 const windows=(S.data.teacher_availability||[]).filter(r=>Number(r.teacher_id)===Number(S.teacher.id)&&r.availability==='Unavailable'&&sameTerm(r)&&overlap(r));
 if(windows.length)return {state:'unavailable',label:'Unavailable',detail:'Faculty unavailable'};
 const hits=(S.data.availability_records||[]).filter(r=>!(r.schedule_type==='Regular'&&Number(r.id)===Number(S.entry?.id))&&sameTerm(r)&&overlap(r)&&(Number(r.teacher_id)===Number(S.teacher.id)||Number(r.section_id)===Number(S.section.id)));
 if(hits.length)return {state:'occupied',label:'Occupied',detail:hits[0].subject_code+' · '+hits[0].section_code};
 return {state:'available',label:'Available',detail:''};
}
function renderAvailability(){const t=S.teacher;$('TeacherContext').innerHTML=`<div class="tm-profile mb-0">${photo(t)}<div><b>${esc(t.full_name)}</b><small>${esc(qualification(t)?.specialization||'')} · ${esc(S.subject.code)} / ${esc(S.section.code)}</small></div></div><div>${badge(`${t.current_load_units} / ${Number(t.max_load_units)||'—'} units`,'blue')}</div>`;setOptions('Day',DAYS.map(d=>[d,d]),S.day);renderSlots();}
function renderSlots(){const blocks=S.data.time_blocks;
 $('Week').innerHTML='<thead><tr><th>Time</th>'+DAYS.map(d=>`<th>${d.slice(0,3)}</th>`).join('')+'</tr></thead><tbody>'+blocks.map(b=>`<tr><th>${time(b.start_time)}<br>${time(b.end_time)}</th>`+DAYS.map(day=>{const a=slot(day,b),selected=day===S.day&&Number(b.id)===Number(S.block?.id);return `<td><button class="tm-slot ${a.state}${selected?' selected':''}" data-slot="${b.id}" data-day="${day}" ${a.state!=='available'?'disabled':''}>${selected?'✓ Selected':a.label}<small>${esc(a.detail)}</small></button></td>`;}).join('')+'</tr>').join('')+'</tbody>';
 if(!blocks.length)$('Week').innerHTML='<tbody><tr><td class="tm-empty">No active Class time blocks.</td></tr></tbody>';
 $('Block').innerHTML='<option value="">Select time block</option>'+blocks.map(b=>{const a=slot(S.day,b);return `<option value="${b.id}" ${a.state!=='available'?'disabled':''}>${time(b.start_time)} – ${time(b.end_time)}${a.state!=='available'?' · '+a.label:''}</option>`;}).join('');$('Block').value=S.block?.id||'';
 $('SlotSummary').innerHTML=S.block?`<dl>${dl([['Day',S.day],['Time',`${time(S.block.start_time)} – ${time(S.block.end_time)}`],['Room','Pending assignment']])}</dl>`:'';$('Review').disabled=!S.block||slot(S.day,S.block).state!=='available';
}
function renderReview(){
 $('ReviewSubject').innerHTML=dl([['Section',S.section.code],['Subject',S.subject.code+' · '+S.subject.name],['Units',S.subject.units],['Academic year',S.section.academic_year],['Semester',S.section.semester]]);
 $('ReviewTeacher').innerHTML=profile(S.teacher);
 $('ReviewSchedule').innerHTML=dl([['Day',S.day],['Time',time(S.block.start_time)+' – '+time(S.block.end_time)],['Room',S.entry?.room_id?'Pending reassignment':'Pending assignment'],['Status','Teacher Assigned / Draft']]);
 $('Save').disabled=true;
}
async function validate(){
 if(!S.subject||!S.teacher||!S.block||S.busy)return;
 S.validation=null;$('Save').disabled=true;$('Validation').textContent='Checking current records…';
 await work(async()=>{
  const p=payload('validate'),result=await api('/api/scheduling/teacher-mapping-save.php',p);
  S.validation={...result,fingerprint:JSON.stringify(payload('validate'))};
  const issues=result.findings||[];
  $('Validation').innerHTML=(result.valid?'<div class="tm-check"><i class="ti ti-circle-check"></i><div><b>Teacher and time validated</b></div></div>'+result.checks.map(label=>`<div class="tm-check"><i class="ti ti-check"></i><div>${esc(label)}</div></div>`).join(''):'')+issues.map(f=>`<div class="tm-check ${f.severity==='Error'?'error':'warning'}"><i class="ti ti-${f.severity==='Error'?'circle-x':'alert-triangle'}"></i><div><b>${esc(f.message)}</b><small>${esc(f.recommended_action)}</small></div></div>`).join('');
  $('Save').disabled=!result.valid||S.entry?.status==='Published';
 });
 if(!S.validation)$('Validation').textContent='Validation failed. Correct the issue and recheck.';
}
async function save(){
 if(!S.validation?.valid||S.validation.fingerprint!==JSON.stringify(payload('validate')))return;
 if(!confirm('Save this teacher/time mapping as Draft and continue to room assignment?'+(S.entry?.room_id?' The current room will need reassignment.':'')))return;
 $('Save').disabled=true;
 await work(async()=>{
  const result=await api('/api/scheduling/teacher-mapping-save.php',payload('save'));
  if(!result.valid){S.validation=null;$('Validation').innerHTML=result.findings.map(f=>`<div class="tm-check error"><i class="ti ti-circle-x"></i><div>${esc(f.message)}<small>${esc(f.recommended_action)}</small></div></div>`).join('');notice('The assignment changed. Resolve the latest findings before saving.');return;}
  S.dirty=false;S.validation=null;notice('Teacher assigned. Opening room assignment…','success');
  const link=document.createElement('a');link.className='btn btn-primary ms-2';link.textContent='Continue to Room Availability';link.href=result.next_url;$('Notice').append(link);
  location.assign(result.next_url);
 });
 if(S.validation)$('Save').disabled=false;
}
function exportCsv(){const rows=[['Section','Subject','Teacher','Day','Start','End','Room','Status'],...(S.data.section_schedule||[]).map(e=>[S.section.code,e.subject_code,e.teacher_name,e.day_of_week,e.start_time,e.end_time,e.room_code||'Pending',e.status])];const quote=v=>'"'+String(v??'').replace(/^[\s]*[=+@-]/,"'$&").replace(/"/g,'""')+'"';const url=URL.createObjectURL(new Blob([rows.map(r=>r.map(quote).join(',')).join('\r\n')],{type:'text/csv;charset=utf-8'}));const a=document.createElement('a');a.href=url;a.download=(S.section?.code||'section')+'-teacher-mapping.csv';a.click();URL.revokeObjectURL(url);}
async function history(){await work(async()=>{const d=await api('/api/scheduling/teacher-schedule-mapping.php?action=history'+(S.section?'&section_code='+encodeURIComponent(S.section.code):''));$('HistoryRows').innerHTML=d.history.map(h=>`<article class="py-3 border-bottom"><b>${esc(h.action)}</b><p>${esc(h.detail)}</p><small>${esc(h.created_at)} · ${esc(h.user_name)}</small></article>`).join('')||'<p>No mapping history.</p>';});$('HistoryDialog').showModal();}
root.addEventListener('click',e=>{
 if(S.busy)return;
 const view=e.target.closest('[data-view],[data-edit]');if(view){show(view.dataset.view||view.dataset.edit);return;}
 const sub=e.target.closest('[data-subject]');if(sub){selectSubject(Number(sub.dataset.subject));return;}
 const teacher=e.target.closest('[data-teacher]');if(teacher){const next=S.data.teachers.find(t=>Number(t.id)===Number(teacher.dataset.teacher));if(S.entry?.status==='Published'&&Number(next.id)!==Number(S.entry.teacher_id))return notice('Published mappings are read-only.');if(Number(next.id)!==Number(S.teacher?.id)){S.teacher=next;S.block=null;invalidate();}renderTeacherDetails();return;}
 const slotButton=e.target.closest('[data-slot]');if(slotButton&&!slotButton.disabled){S.day=slotButton.dataset.day;S.block=S.data.time_blocks.find(b=>Number(b.id)===Number(slotButton.dataset.slot));$('Day').value=S.day;invalidate();renderSlots();}
});
FILTERS.forEach(([id],i)=>$(id).onchange=()=>{if(S.dirty&&!confirm('Discard unsaved mapping changes?')){filters(S.section);return;}FILTERS.slice(i+1).forEach(([next])=>$(next).value='');S.section=null;reset();S.data.assigned_subjects=[];S.data.section_schedule=[];filters();show('subjects');});
$('Section').onchange=()=>{if(S.dirty&&!confirm('Discard unsaved mapping changes?')){$('Section').value=S.section?.id||'';return;}work(()=>load(Number($('Section').value)));};
$('SubjectSearch').oninput=renderSubjects;
['TeacherSearch','Department','Eligibility'].forEach(id=>$(id).oninput=renderFaculty);
$('Day').onchange=()=>{S.day=$('Day').value;S.block=null;invalidate();renderSlots();};
$('Block').onchange=()=>{S.block=S.data.time_blocks.find(b=>Number(b.id)===Number($('Block').value))||null;invalidate();renderSlots();};
$('Review').onclick=()=>show('review');$('Recheck').onclick=validate;$('Save').onclick=save;
$('Refresh').onclick=()=>{if(S.dirty&&!confirm('Discard unsaved mapping changes and refresh?'))return;work(()=>load(Number(S.section?.id||0)));};
$('Export').onclick=exportCsv;$('History').onclick=history;$('CloseHistory').onclick=()=>$('HistoryDialog').close();$('Print').onclick=()=>window.print();
window.addEventListener('beforeunload',e=>{if(S.dirty){e.preventDefault();e.returnValue='';}});
work(async()=>{
 await load(Number(root.dataset.sectionId||0));
 if(!S.section){let id=0;if(Number(root.dataset.recordId)){const entry=S.data.availability_records.find(r=>r.schedule_type==='Regular'&&Number(r.id)===Number(root.dataset.recordId));id=entry?.section_id||0;}if(!id&&root.dataset.section)id=S.data.sections.find(s=>s.code===root.dataset.section)?.id||0;if(id)await load(Number(id));}
});
})();
