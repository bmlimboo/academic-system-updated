"""Local-only HTTP and browser checks. Temporary SQLite database; no mail worker."""
import datetime, os, pathlib, tempfile, subprocess, sqlite3, urllib.request, urllib.parse, urllib.error, http.cookiejar, re, time, json, io, zipfile
root=pathlib.Path(__file__).resolve().parents[1]; php=os.environ.get('PHP_BIN','php'); n=0

def ck(v,m):
 global n
 assert v,m
 n+=1
with tempfile.TemporaryDirectory() as td:
 env=dict(os.environ,APP_DSN='sqlite:'+td+'/test.sqlite',APP_DEMO='1',ADMIN_EMAIL='admin@example.test',ADMIN_PASSWORD='AcademicTest123!')
 subprocess.run([php,str(root/'bin/setup.php')],env=env,check=True,stdout=subprocess.DEVNULL)
 db=sqlite3.connect(td+'/test.sqlite'); db.row_factory=sqlite3.Row
 pw=db.execute('select password from users limit 1').fetchone()[0]
 department='Department of Agriculture'
 for name,role,dept in [('Tutor','faculty',department),('Other','faculty',department),('DAA','daa',department),('PL','pl',department),('ForeignPL','pl','Department of Animal Science'),('Exam','exam','Exam Cell'),('Library','librarian',''),('President','president',''),('Learner','student',department),('Unregistered','student',department)]:
  db.execute('insert into users(name,email,password,role,department) values(?,?,?,?,?)',(name,name.lower()+'@example.test',pw,role,dept))
 db.commit()
 log=open(td+'/server.log','w+')
 srv=subprocess.Popen([php,'-S','127.0.0.1:8103','-t',str(root/'public')],env=env,stdout=log,stderr=log)
 base='http://127.0.0.1:8103/'
 scope='&'+urllib.parse.urlencode(dict(programme='BSc. Agriculture',academic_year='2026-2027',study_year='I',semester='I'))
 def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 def get(c,path):
  try:return c.open(base+path).read()
  except urllib.error.HTTPError as e:return e.read()
 def csrf(b):return re.search(rb'name="csrf" value="([^"]+)"',b).group(1).decode()
 def post(c,path,d,file=None):
  if file:
   boundary='academic-http-fixture';body=b''
   for k,v in d.items():body+=f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode()
   name,data=file[:2];field=file[2] if len(file)>2 else 'sheet';body+=f'--{boundary}\r\nContent-Disposition: form-data; name="{field}"; filename="{name}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode()+data+b'\r\n'+f'--{boundary}--\r\n'.encode();req=urllib.request.Request(base+path,body,{'Content-Type':'multipart/form-data; boundary='+boundary})
  else:req=urllib.request.Request(base+path,urllib.parse.urlencode(d).encode())
  return c.open(req).read()
 def login(who):
  c=client();b=get(c,'?page=login');b=post(c,'?page=login',dict(action='login',email=who+'@example.test',password='AcademicTest123!',csrf=csrf(b)));ck(b'Sign out' in b,'login '+who);return c
 try:
  pub=client()
  for _ in range(50):
   try:get(pub,'?page=login');break
   except urllib.error.URLError:time.sleep(.1)
  admin=login('admin');daa=login('daa');tutor=login('tutor');other=login('other');library=login('library');president=login('president')
  b=get(admin,'?page=research');ck(b'Register / update student account' in b,'admin student provisioning')
  b=post(admin,'?page=research',dict(action='research_student',name='Learner',email='learner@example.test',student_number='001',department=department,programme='BSc. Agriculture',csrf=csrf(b)))
  ck(db.execute('select count(*) from research_students').fetchone()[0]==1,'student registered')
  ck(db.execute("select count(*) from outbox where recipient='learner@example.test' and subject like '%password%'").fetchone()[0]==1,'student password setup queued')
  student=login('learner')
  c=client();b=get(c,'?page=login');b=post(c,'?page=login',dict(action='login',email='unregistered@example.test',password='AcademicTest123!',csrf=csrf(b)));ck(b'Email or password is incorrect' in b,'unregistered consultation student cannot login')
  b=get(daa,'?page=research');b=post(daa,'?page=research',dict(action='research_period',title='Research 2026',department=department,opens_on='2020-01-01',closes_on='2030-12-31',due_on='2031-01-31',csrf=csrf(b)))
  ck(db.execute('select count(*) from research_periods').fetchone()[0]==1,'DAA sets timeline')
  tutorid=db.execute("select id from users where email='tutor@example.test'").fetchone()[0]
  b=get(student,'?page=research');proposal=dict(action='research_submit',period_id=1,supervisor_id=tutorid,title='Soil study',objectives='Study soil',methodology='Sampling',team='Learner',outcomes='Dataset',funding='1000',csrf=csrf(b))
  b=post(student,'?page=research',proposal);ck(b'Soil study' in b,'student submits proposal')
  project=db.execute('select * from research_projects').fetchone();pid=project['id'];path=f'?page=research&id={pid}'
  ck(db.execute("select count(*) from outbox where subject like 'Research #%Proposal submitted'").fetchone()[0]==3,'student supervisor DAA notifications')
  ck(b'project not available' in get(other,path),'unassigned teacher cannot view project')
  b=get(student,path);b=post(student,path,dict(action='research_decision',id=pid,revision=1,status='Approved',details='Self approve',csrf=csrf(b)));ck(db.execute('select status from research_projects').fetchone()[0]=='Submitted','student cannot approve')
  b=get(tutor,path);b=post(tutor,path,dict(action='research_decision',id=pid,revision=1,status='Approved',details='Approved proposal',csrf=csrf(b)));ck(db.execute('select status from research_projects').fetchone()[0]=='Approved','selected supervisor approves')
  b=get(student,path);b=post(student,path,dict(action='research_update',id=pid,revision=2,kind='Expenditure',details='Field visit',amount=250,csrf=csrf(b)));ck(db.execute('select sum(amount) from research_updates').fetchone()[0]==250,'progress expenditure tracked')
  b=post(student,path,dict(action='research_update',id=pid,revision=2,kind='Progress',details='Stale',csrf=csrf(b)));ck(b'Project changed' in b,'stale update blocked')
  b=get(student,path);b=post(student,path,dict(action='research_update',id=pid,revision=3,kind='Dataset',details='Dataset description',link='javascript:alert(1)',csrf=csrf(b)));ck(b'HTTP or HTTPS' in b,'unsafe output links blocked')
  b=get(tutor,path);b=post(tutor,path,dict(action='research_update',id=pid,revision=3,kind='Comment',details='Review feedback',csrf=csrf(b)));ck(b'Review feedback' in b,'supervisor feedback saved')
  b=get(daa,'?page=research');ck(b'250.00' in b and b'1,000.00' in b,'management funding and expenditure report')
  b=get(admin,'?page=research');post(admin,'?page=research',dict(action='research_period_toggle',period_id=1,csrf=csrf(b)));ck(db.execute('select count(*) from research_projects').fetchone()[0]==1,'closing period preserves project')
  b=get(library,'?page=reports&module=library');ck(b'Programme Documents (DPD)' in b and 'Academic · Attendance'.encode() not in b,'library focused navigation')
  b=get(president,'');ck(b'Library Services' not in b and b'Tutor Attendance' not in b,'president focused navigation')
  # Seed fictional library request for testing response delivery.
  owner=db.execute("select id from users where email='tutor@example.test'").fetchone()[0]
  db.execute("insert into requests(module,applicant_id,department,status,payload,created_at,updated_at) values('library',?,?,'Submitted',?,'2026-01-01','2026-01-01')",(owner,department,json.dumps(dict(service='Other Library Service',details='Test',programme='BSc. Agriculture'))));rid=db.execute('select max(id) from requests').fetchone()[0];db.commit();libpath=f'?page=detail&id={rid}'
  b=get(library,libpath);ck(b'Save and send' in b and b'response_document' in b,'library attachment form')
  b=post(library,libpath,dict(action='library_reply',id=rid,status='Completed',note='Delivered',csrf=csrf(b)),('bad.exe',b'bad','response_document'));ck(db.execute('select status from requests where id=?',(rid,)).fetchone()[0]=='Submitted','invalid file leaves request unchanged')
  pdf=b'%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF'
  b=get(library,libpath);b=post(library,libpath,dict(action='library_reply',id=rid,status='Completed',note='Delivered',csrf=csrf(b)),('response.pdf',pdf,'response_document'))
  ck(db.execute('select status from requests where id=?',(rid,)).fetchone()[0]=='Completed','library reply saves status')
  att=db.execute('select a.*,o.recipient,f.path from mail_attachments a join outbox o on o.id=a.outbox_id join files f on f.id=a.file_id').fetchone();ck(att and att['recipient']=='tutor@example.test','attachment queued only to applicant')
  mime=subprocess.run([php,'-r',f'require "{root}/src/bootstrap.php"; $m=one("SELECT * FROM outbox WHERE id=?",[{att["outbox_id"]}]); $c=queuedMailContent($m); echo $c[1]["Content-Type"]."\\n".$c[0];'],env=env,capture_output=True,text=True,check=True).stdout
  ck('multipart/mixed' in mime and 'response.pdf' in mime and 'JVBERi0' in mime,'attachment MIME built without sending email')
  (root/'storage/uploads'/att['path']).unlink()
  ck(b'<th>Service</th>' in get(admin,''),'service column displayed')
  b=get(student,path);post(student,path,dict(action='research_update',id=pid,revision=4,kind='Dataset',details='Published dataset',link='https://example.test/dataset',csrf=csrf(b)))
  ck(db.execute("select count(*) from research_updates where kind='Dataset'").fetchone()[0]==1,'research output recorded')
  b=get(student,'?page=research');proposal['csrf']=csrf(b);post(student,'?page=research',proposal);ck(db.execute('select count(*) from research_projects').fetchone()[0]==1,'closed registration blocks new proposal')
  b=get(student,'?page=research');post(student,'?page=research',dict(action='research_period',title='Forged',department=department,opens_on='2020-01-01',closes_on='2030-01-01',due_on='2031-01-01',csrf=csrf(b)));ck(db.execute('select count(*) from research_periods').fetchone()[0]==1,'student cannot create timeline')
  b=get(daa,'?page=research');post(daa,'?page=research',dict(action='research_period',period_id=1,title='Research 2026 extended',department=department,opens_on='2020-01-01',closes_on='2030-12-31',due_on='2031-06-30',csrf=csrf(b)));ck(db.execute('select due_on from research_periods').fetchone()[0]=='2031-06-30','DAA edits timeline')
  b=get(other,path);post(other,path,dict(action='research_update',id=pid,revision=5,kind='Comment',details='Forbidden',csrf=csrf(b)));ck(db.execute("select count(*) from research_updates where details='Forbidden'").fetchone()[0]==0,'unassigned supervisor write denied')
  b=get(tutor,path);post(tutor,path,dict(action='research_decision',id=pid,revision=5,status='Completed',details='Final report accepted',csrf=csrf(b)));ck(db.execute('select status from research_projects').fetchone()[0]=='Completed','supervisor completes project')
  snapshots=root.parent/'tmp/research-review';snapshots.mkdir(parents=True,exist_ok=True)
  for name,c,page in [('coordinator',daa,'?page=research'),('student',student,path),('library',library,'?page=reports&module=library'),('home',admin,'')]:
   html=get(c,page).decode();html=re.sub(r'<script[^>]*>.*?</script>','',html,flags=re.S);html=re.sub(r'<link[^>]*rel="stylesheet"[^>]*>','',html);html=html.replace('</head>','<style>'+ (root/'public/assets/style.css').read_text()+'</style></head>');(snapshots/(name+'.html')).write_text(html)
  for c in [student,tutor]:
   b=get(c,path);post(c,path,dict(action='research_delete',id=pid,revision=6,reason='Test removal',confirmed=1,csrf=csrf(b)))
   ck(db.execute('select count(*) from research_projects').fetchone()[0]==1,'non-manager delete blocked')
  for changes in [dict(confirmed=0),dict(reason=''),dict(revision=1)]:
   d=dict(action='research_delete',id=pid,revision=6,reason='Dummy data',confirmed=1);d.update(changes);d['csrf']=csrf(get(daa,path));post(daa,path,d)
   ck(db.execute('select count(*) from research_projects').fetchone()[0]==1,'invalid delete blocked')
  b=get(daa,path);post(daa,path,dict(action='research_delete',id=pid,revision=6,reason='Dummy data',confirmed=1,csrf=csrf(b)))
  ck(db.execute('select count(*) from research_projects').fetchone()[0]==0,'DAA deletes project')
  ck(db.execute("select count(*) from research_updates where kind='Project deleted'").fetchone()[0]==1,'deletion audit retained')
  ck(db.execute("select count(*) from outbox where subject like 'Research #%' and status='queued'").fetchone()[0]==0,'pending research emails cancelled')
  ck(b'project not available' in get(student,path),'deleted project inaccessible')
  b=get(admin,'?page=research');post(admin,'?page=research',dict(action='research_period_toggle',period_id=1,csrf=csrf(b)))
  proposal['csrf']=csrf(get(student,'?page=research'));post(student,'?page=research',proposal)
  new=db.execute('select * from research_projects').fetchone();ck(new is not None and new['id']!=pid,'student can resubmit with new identity')
  b=get(admin,'?page=research');post(admin,'?page=research',dict(action='research_delete',id=new['id'],revision=1,reason='Admin test cleanup',confirmed=1,csrf=csrf(b)))
  ck(db.execute('select count(*) from research_projects').fetchone()[0]==0,'Admin deletes project')
  print(f'{n} Research / Library HTTP checks passed.')
 finally:
  srv.terminate();srv.wait();log.close()
