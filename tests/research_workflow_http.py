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
  admin=login('admin');daa=login('daa');tutor=login('tutor');other=login('other');pl=login('pl');foreign=login('foreignpl')
  uid=lambda name:db.execute('select id from users where name=?',(name,)).fetchone()[0]
  b=get(daa,'?page=research');post(daa,'?page=research',dict(action='research_period',title='Final year 2026–2027',department=department,opens_on='2020-01-01',closes_on='2030-12-31',due_on='2031-01-31',csrf=csrf(b)))
  b=get(daa,'?page=research');post(daa,'?page=research',dict(action='rw_timeline',period_id=1,template=1,year=2026,csrf=csrf(b)))
  ck(db.execute('select count(*) from research_milestones').fetchone()[0]==24,'24 milestones published')
  ck(db.execute('select due_date from research_milestones where sequence_no=24').fetchone()[0]=='2027-06-30','cross-year timeline')
  csv=get(daa,'?page=rw_timeline_csv&period=1');ck(b'Sequence,Activity' in csv and b'2027-06-30' in csv,'timeline CSV export')
  b=get(tutor,'?page=research');post(tutor,'?page=research',dict(action='rw_timeline',period_id=1,template=1,year=2025,csrf=csrf(b)));ck(db.execute('select due_date from research_milestones where sequence_no=24').fetchone()[0]=='2027-06-30','tutor cannot change timeline')
  access='?page=rw_access&register=1';b=get(pub,access)
  payload=dict(action='rw_code',register=1,name='Test Research Student',student_number='001234',email='001234.cnr@rub.edu.bt',department=department,programme='BSc. Agriculture',title='Soil and water conservation',scope='Study soil moisture and erosion in local fields.',supervisor_id=uid('Tutor'),period_id=1,final_year=1,csrf=csrf(b))
  out=post(pub,access,dict(payload,email='student@gmail.com'));ck(b'ending in .cnr@rub.edu.bt' in out,'student institutional email enforced')
  out=post(pub,access,dict(payload,programme='BSc. Forestry'));ck(b'programme in your department' in out,'programme department validated')
  b=post(pub,access,payload);ck(b'Six-digit code' in b,'code entry appears')
  ck(db.execute("select count(*) from users where email=?",(payload['email'],)).fetchone()[0]==0,'student not created before verification')
  code=re.search(r'code is (\d{6})',db.execute('select body from outbox where recipient=? order by id desc',(payload['email'],)).fetchone()[0]).group(1)
  out=post(pub,access,dict(action='rw_verify',code='000000',csrf=csrf(b)));ck(b'Incorrect verification' in out,'wrong code blocked')
  b=post(pub,access,dict(action='rw_verify',code=code,csrf=csrf(b)));ck(b'Soil and water conservation' in b,'verified registration creates project')
  r=db.execute('select * from research_projects').fetchone();pid=r['id'];path=f'?page=research&id={pid}'
  ck(db.execute('select count(*) from research_students').fetchone()[0]==1,'research student created')
  ck(db.execute("select count(*) from outbox where subject like '%Supervision request'").fetchone()[0]==1,'supervisor notified')
  ck(b'project not available' in get(other,path),'unassigned staff cannot open')
  ck(b'project not available' in get(foreign,path),'foreign PL blocked')
  ck(b'Soil and water conservation' in get(pl,path),'department PL sees project')
  def act(c,action,**kw):
   b=get(c,path);rev=db.execute('select revision from research_projects where id=?',(pid,)).fetchone()[0]
   return post(c,path,dict(action=action,id=pid,revision=rev,csrf=csrf(b),**kw))
  act(tutor,'research_decision',status='Changes requested',details='Please narrow the field study.')
  ck(db.execute('select status from research_projects').fetchone()[0]=='Changes requested','supervisor requests revision')
  act(pub,'research_submit',period_id=1,supervisor_id=uid('Tutor'),title='Soil and water conservation',objectives='Revised scope',methodology='Proposal follows',team='Individual',outcomes='Report',funding=0)
  act(tutor,'research_decision',status='Approved',details='Accepted for supervision.')
  ck(db.execute("select count(*) from outbox where recipient='pl@example.test' and subject like '%Approved'").fetchone()[0]==1,'PL acceptance notification')
  normal=db.execute('select id from research_milestones where sequence_no=4').fetchone()[0];evaluation=db.execute('select id from research_milestones where sequence_no=16').fetchone()[0]
  doc=io.BytesIO()
  with zipfile.ZipFile(doc,'w') as z:z.writestr('word/document.xml','<document>Test draft</document>')
  def upload(c,m,kind='Submission',name='draft.docx',data=None):
   b=get(c,path);rev=db.execute('select revision from research_projects where id=?',(pid,)).fetchone()[0]
   return post(c,path,dict(action='rw_work',id=pid,revision=rev,milestone_id=m,kind=kind,comments='Please review this version.',csrf=csrf(b)),(name,data if data is not None else doc.getvalue(),'document'))
  upload(pub,normal);ck(db.execute('select count(*) from research_work').fetchone()[0]==1,'student draft saved')
  w=db.execute('select * from research_work').fetchone();ck(get(pub,f"?page=rw_download&file={w['id']}")==doc.getvalue(),'student downloads own document')
  ck(b'project not available' in get(other,f"?page=rw_download&file={w['id']}"),'private download authorization')
  upload(tutor,normal,'Returned feedback');ck(db.execute('select count(*) from research_work').fetchone()[0]==2,'annotated feedback is new version')
  act(daa,'rw_work',milestone_id=normal,kind='Completed',comments='Reviewed and complete.')
  act(daa,'rw_work',milestone_id=normal,kind='Comment',comments='Keep this version for reference.')
  b=get(daa,path);ck(b'rw-done' in b,'comments preserve completed milestone')
  act(daa,'rw_assign',assignment='evaluator',teacher_id=uid('Other'),document_id=w['id'],comments='Review the research project.')
  b=get(other,path);ck(b'Evaluation workspace' in b and b'Submission of Research Proposal (Draft)' not in b,'evaluator sees only two evaluation milestones')
  ck(b'not shared' in get(other,f"?page=rw_download&file={w['id']}"),'evaluator cannot download unrelated drafts')
  upload(pub,evaluation);last=db.execute('select id from research_work order by id desc').fetchone()[0];ck(get(other,f'?page=rw_download&file={last}')==doc.getvalue(),'evaluator receives shared paper')
  upload(other,evaluation,'Returned feedback');ck(db.execute("select count(*) from research_work where actor_id=?",(uid('Other'),)).fetchone()[0]==1,'evaluator returns feedback')
  act(other,'rw_work',milestone_id=normal,kind='Comment',comments='Unauthorized');ck(db.execute("select count(*) from research_work where actor_id=?",(uid('Other'),)).fetchone()[0]==1,'evaluator cannot post outside assigned milestones')
  act(tutor,'rw_grade',secured=80,maximum=100,weighting=40,comments='Supervisor assessment')
  ck(db.execute('select secured from research_grades').fetchone()[0]==80,'grade saved')
  act(tutor,'rw_grade',secured=120,maximum=100,weighting=40,comments='Invalid');ck(db.execute('select secured from research_grades').fetchone()[0]==80,'invalid grade rejected')
  before=db.execute('select count(*) from research_work').fetchone()[0];upload(pub,normal,name='bad.php',data=b'<?php');ck(db.execute('select count(*) from research_work').fetchone()[0]==before,'executable upload blocked')
  b=get(daa,'?page=research');post(daa,'?page=research',dict(action='rw_timeline',period_id=1,csrf=csrf(b)),('timeline.csv',csv,'timeline'));ck(db.execute('select count(*) from research_work').fetchone()[0]==before,'timeline import retains history')
  act(pl,'rw_assign',assignment='supervisor',teacher_id=uid('Other'),comments='Replacement supervisor assigned.')
  ck(db.execute('select supervisor_id from research_projects').fetchone()[0]==uid('Other'),'PL reassigns supervisor')
  ck(b'project not available' in get(tutor,path),'old supervisor access removed')
  act(other,'research_decision',status='Rejected',details='Supervisor capacity reached.')
  ck(db.execute("select count(*) from outbox where recipient='pl@example.test' and subject like '%Rejected'").fetchone()[0]==1,'PL receives rejection')
  ck(db.execute("select count(*) from outbox where recipient='daa@example.test' and subject like '%Rejected'").fetchone()[0]==1,'DAA receives rejection')
  db.execute("insert into research_students(user_id,student_number,programme) values(?,?,'BSc. Agriculture')",(uid('Learner'),'009999'));db.commit()
  another=login('learner');ck(b'project not available' in get(another,path),'other student cannot view project')
  # Passwordless staff access and one-time verification.
  visitor=client();b=get(visitor,'?page=rw_access');b=post(visitor,'?page=rw_access',dict(action='rw_code',email='other@example.test',project=pid,csrf=csrf(b)))
  code=re.search(r'code is (\d{6})',db.execute("select body from outbox where recipient='other@example.test' and subject like '%verification code' order by id desc").fetchone()[0]).group(1)
  b=post(visitor,'?page=rw_access',dict(action='rw_verify',code=code,csrf=csrf(b)));ck(b'Sign out' in b and b'Soil and water' in b,'registered staff code login')
  b=post(visitor,'?page=rw_access',dict(action='rw_verify',code=code,csrf=csrf(b)));ck(b'Verification expired' in b,'code cannot be reused')
  # Save local render for visual inspection, no mail is delivered.
  dest=root.parent/'tmp/research-workflow-review';dest.mkdir(parents=True,exist_ok=True)
  for name,c,url in [('dashboard',daa,'?page=research'),('student',pub,path),('registration',client(),access)]:
   html=get(c,url).decode().replace('href="assets/','href="file://'+str(root/'public/assets')+'/').replace('src="assets/','src="file://'+str(root/'public/assets')+'/')
   (dest/(name+'.html')).write_text(html)
  log.flush();log.seek(0);logs=log.read();ck('Fatal error' not in logs and 'PHP Warning' not in logs,'no PHP warnings')
  print(f'{n} research workflow HTTP checks passed.')
 finally:
  for w in db.execute("select file_key from research_work where file_key<>''"):
   f=root/'storage/research-documents'/w[0]
   if f.exists():f.unlink()
  srv.terminate();srv.wait();log.close()
