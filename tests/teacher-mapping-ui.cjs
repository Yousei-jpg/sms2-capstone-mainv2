// Lightweight DOM contract tests; not a substitute for visual browser testing.
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const elements=new Map();
function element(id){if(!elements.has(id))elements.set(id,{id,value:'',innerHTML:'',textContent:'',disabled:false,dataset:{},classList:{add(){},remove(){},toggle(){}},setAttribute(){},addEventListener(){},querySelectorAll(){return[];},append(){},showModal(){},close(){}});return elements.get(id);}
const root=element('teacherMapping');root.dataset={base:'/app',sectionId:'1',section:'',recordId:'0'};
const panels=['subjects','faculty','availability','review'].map(view=>({dataset:{panel:view},hidden:false}));
const tabs=panels.map(p=>({...element('tab-'+p.dataset.panel),dataset:{view:p.dataset.panel}}));
root.querySelectorAll=selector=>selector==='[data-panel]'?panels:selector==='[data-view]'?tabs:[];
root.addEventListener=()=>{};
const section={id:1,code:'TEST-A',program:'BSIT',year_level:2,academic_year:'2026-2027',semester:'1st Semester',current_students:30,max_students:40};
const subject={id:1,code:'T101',name:'Test',units:3,subject_type:'Major'};
const teacher={id:1,employee_no:'F1',full_name:'Test Faculty',status:'Active',current_load_units:0,max_load_units:6,department:'IT',qualifications:[{subject_id:1,subject_code:'T101',specialization:'Computing'}]};
const block={id:1,start_time:'09:00:00',end_time:'10:00:00'};
const data={ok:true,sections:[section],section,assigned_subjects:[subject],section_schedule:[],teachers:[teacher],time_blocks:[block],teacher_availability:[],availability_records:[]};
let requests=[],redirect=null,failValidation=false;
const context={document:{getElementById:element,createElement:()=>({click(){}})},window:{addEventListener(){},print(){}},location:{origin:'http://localhost',assign:url=>redirect=url},URL,Blob,confirm:()=>true,console,setTimeout,clearTimeout,
fetch:async(url,options)=>{const p=options?.body?JSON.parse(options.body):null;requests.push(p||url);if(p){if(failValidation)return {ok:false,json:async()=>({ok:false,error:'Unavailable'})};return{ok:true,json:async()=>({ok:true,valid:true,findings:[],checks:['Active teacher'],next_url:'/app/rooms?record_id=42',saved_id:42})};}return{ok:true,json:async()=>structuredClone(data)};}};
let source=fs.readFileSync('modules/scheduling/assets/js/teacher-mapping.js','utf8');
source=source.replace('work(async()=>{\n await load(Number(root.dataset.sectionId||0));','globalThis.harness={S,selectSubject,show,slot,photo,validate,save,renderSlots,eligible,invalidate};\nwork(async()=>{\n await load(Number(root.dataset.sectionId||0));');
vm.runInNewContext(source,context);
async function settled(){for(let i=0;i<15;i++)await Promise.resolve();}
(async()=>{
await settled();const h=context.harness;assert.equal(h.S.section.id,1);assert.equal(h.S.view,'subjects');assert.match(element('tmSubjects').innerHTML,/Pending Teacher/);
h.selectSubject(1);assert.equal(h.S.view,'faculty');assert.match(element('tmTeachers').innerHTML,/tm-photo/);assert.equal(h.eligible(teacher),true);assert.equal(h.eligible({...teacher,qualifications:[]}),false);
h.S.teacher=teacher;h.show('availability');assert.equal(h.slot('Monday',block).state,'available');
h.S.data.teacher_availability=[{teacher_id:1,day_of_week:'Monday',start_time:'09:00',end_time:'10:00',availability:'Unavailable'}];assert.equal(h.slot('Monday',block).state,'unavailable');h.S.data.teacher_availability=[];
h.S.data.availability_records=[{id:9,schedule_type:'Special',teacher_id:1,section_id:2,day_of_week:'Monday',start_time:'09:00',end_time:'10:00',subject_code:'S1'}];assert.equal(h.slot('Monday',block).state,'occupied');h.S.data.availability_records=[];
h.S.block=block;h.show('review');await settled();assert.equal(element('tmSave').disabled,false);assert.match(element('tmReviewSchedule').innerHTML,/Pending assignment/);assert.equal(requests.at(-1).action,'validate');
h.invalidate();assert.equal(element('tmSave').disabled,true);failValidation=true;await h.validate();assert.equal(element('tmSave').disabled,true);assert.match(element('tmNotice').textContent,/Unavailable/);
failValidation=false;await h.validate();await h.save();assert.equal(redirect,'/app/rooms?record_id=42');assert.equal(requests.at(-1).action,'save');assert.equal(h.S.dirty,false);
assert(!h.photo({...teacher,photo_url:'https://untrusted.test/face.jpg'}).includes('<img'));assert(h.photo({...teacher,photo_url:'/app/images/faculty/1.jpg'}).includes('<img'));
console.log('PASS teacher mapping UI: load, selection, profiles, eligibility, weekly availability, validation, failed validation, save and room handoff');
})().catch(e=>{console.error(e);process.exitCode=1;});
