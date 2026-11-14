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
 for name,role,dept in [('Tutor','faculty',department),('Other','faculty',department),('DAA','daa',department),('PL','pl',department),('ForeignPL','pl','Department of Animal Science'),('Exam','exam','Exam Cell')]:
  db.execute('insert into users(name,email,password,role,department) values(?,?,?,?,?)',(name,name.lower()+'@example.test',pw,role,dept))
 db.commit()
 log=open(td+'/server.log','w+')
 srv=subprocess.Popen([php,'-S','127.0.0.1:8099','-t',str(root/'public')],env=env,stdout=log,stderr=log)
 base='http://127.0.0.1:8099/'
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
   try:b=get(pub,'?page=sa_portal');break
   except urllib.error.URLError:time.sleep(.1)
  ck(b'No account password required' in b and b'not open' in b,'public portal before announcement')
  ck(b'name="code"' not in b,'code field hidden until email request')
  exam=login('exam');daa=login('daa');pl=login('pl');admin=login('admin')
  ck(b'restricted to' in get(pl,'?page=sa_manage'),'PL cannot open attendance')
  b=get(exam,'?page=sa_manage');stamp=datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=6)))
  window=dict(action='sa_window_create',title='Attendance test period',academic_year='2026-2027',semester='I',programme='',opens_at=(stamp-datetime.timedelta(hours=1)).strftime('%Y-%m-%dT%H:%M'),closes_at=(stamp+datetime.timedelta(hours=1)).strftime('%Y-%m-%dT%H:%M'),csrf=csrf(b))
  b=post(exam,'?page=sa_manage',window);ck(b'Attendance test period' in b,'exam creates attendance period')
  unknown=client();unknownpage=get(unknown,'?page=sa_portal');blocked=post(unknown,'?page=sa_new&window_id=1',dict(action='sa_draft_create',window_id=1,csrf=csrf(unknownpage)));ck(b'Verify your registered email first' in blocked,'email verification required before writes')
  b=get(pub,'?page=sa_portal');b=post(pub,'?page=sa_portal',dict(action='sa_request_code',email='tutor@example.test',csrf=csrf(b)));ck(b'code will arrive' in b,'code request generic response');ck(b'name="code"' in b and b'maxlength="6"' in b and b'tutor@example.test' in b,'code field appears with email retained')
  mail=db.execute("select body from outbox where recipient='tutor@example.test' order by id desc limit 1").fetchone()[0];code=re.search(r'code is: (\d{6})',mail).group(1)
  b=post(pub,'?page=sa_portal',dict(action='sa_verify_code',code='000000',csrf=csrf(b)));ck(b'incorrect or expired' in b,'bad code denied')
  b=post(pub,'?page=sa_portal',dict(action='sa_verify_code',code=code,csrf=csrf(b)));ck(b'Verified tutor' in b and b'Your attendance modules' in b,'password-free identity verified')
  b=get(pub,'?page=sa_new&window_id=1');p=dict(action='sa_draft_create',kind='attendance',window_id=1,department=department,programme='BSc. Agriculture',academic_year='2026-2027',study_year='I',semester='I',section='A',module_code='CP101',module_name='Attendance Test Module',classroom='B1-2',contact_hours=10,csrf=csrf(b))
  b=post(pub,'?page=sa_new&window_id=1',p);ck(b'Upload and preview attendance' in b,'create module with original form');rid=db.execute('select max(id) from academic_records').fetchone()[0];path=f'?page=sa_record&id={rid}&window_id=1';review=f'?page=sa_review&id={rid}'
  template=get(pub,f'?page=sa_download&id={rid}&mode=template');ck(zipfile.is_zipfile(io.BytesIO(template)),'matrix XLSX template');csvtemplate=get(pub,f'?page=sa_download&id={rid}&mode=template&format=csv');ck(b'Date,,,YYYY-MM-DD' in csvtemplate,'dates across top template')
  matrix=b'Date,,,2026-10-01,,,,,,,\nSl. No.,Student ID,Student Name,08:00-09:00,09:00-10:00,10:15-11:15,11:15-12:15,13:15-14:15,14:15-15:15,15:30-16:30,16:30-17:30\nClass Type,,,Regular,Regular,Regular,Regular,Regular,Regular,Regular,Regular\nClassroom,,,B1-2,B1-2,B1-2,B1-2,B1-2,B1-2,B1-2,B1-2\n1,001,Private Student,P,P,P,P,P,P,A,A\n2,002,Second Student,P,P,P,P,P,P,P,A\n'
  bad=matrix.replace(b'Private Student,P',b'Private Student,X');b=post(pub,path,dict(action='sa_draft_upload',id=rid,revision=1,window_id=1,csrf=csrf(b)),('attendance.csv',bad));ck(b'Row 5' in b and db.execute('select entries from academic_records').fetchone()[0]=='[]','bad cell preserves draft')
  b=post(pub,path,dict(action='sa_draft_upload',id=rid,revision=1,window_id=1,csrf=csrf(b)),('attendance.csv',matrix));ck(b'Private Student' in b and b'colspan="8"' in b,'matrix import and grouped date display')
  draftxlsx=get(pub,f'?page=sa_download&id={rid}&mode=register');b=post(pub,path,dict(action='sa_draft_upload',id=rid,revision=2,window_id=1,merge_mode='replace',csrf=csrf(b)),('attendance.xlsx',draftxlsx));ck(b'Private Student' in b and b'Attendance saved' in b,'XLSX matrix round trip')
  b=post(pub,path,dict(action='sa_draft_submit',id=rid,revision=3,window_id=1,confirmed=1,csrf=csrf(b)));ck(b'Add attendance to this module' in b and b'Private Student' not in b,'submitted privacy and add button')
  ck(b'Access denied' in get(client(),f'?page=sa_download&id={rid}&mode=register'),'anonymous export denied')
  ck(b'restricted to' in get(pub,f'?page=sa_download&id={rid}&mode=register'),'owner final export denied')
  ck(b'restricted to' in get(pl,review) and b'Private Student' not in get(pl,review),'PL cannot view attendance')
  ck(b'Access denied' in get(pl,f'?page=sa_download&id={rid}&mode=register'),'PL attendance download denied')
  ck(b'Private Student' in get(admin,review),'admin attendance review allowed')
  forged=post(pl,'?page=sa_reviews',dict(action='sa_correct',id=rid,revision=4,contact_hours=1,reason='Forbidden',csrf=csrf(get(pl,'?page=ac_home'))));ck(db.execute('select contact_hours from academic_records').fetchone()[0]==10,'PL cannot edit via forged post')
  b=get(exam,review);ck(b'Private Student' in b and b'Not allowed to sit' in b and b'verification required' in b,'exam sees eligibility bands')
  ck(b'Private Student' in get(daa,review),'DAA sees attendance')
  ck(b'Attendance Test Module' not in get(pl,'?page=ac_reports'),'PL legacy reports exclude attendance')
  pdf=b'%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF'
  b=post(exam,review,dict(action='sa_verify',id=rid,revision=4,student_id='002',decision='Approved',reason='Document checked for test',csrf=csrf(b)),('support.pdf',pdf,'support'))
  ck(b'Allowed' in b and b'supporting document verified' in b,'80 percent requires PDF and decision')
  blocked=post(exam,review,dict(action='sa_verify',id=rid,revision=4,student_id='001',decision='Approved',reason='Below threshold',csrf=csrf(b)),('support.pdf',pdf,'support'));ck(b'80% to below 90%' in blocked,'below 80 cannot override with document')
  doc=db.execute('select id from student_attendance_verifications').fetchone()[0]
  ck(get(exam,f'?page=sa_download&id={rid}&mode=support&document_id={doc}').startswith(b'%PDF'),'reviewer PDF download')
  ck(b'%PDF' not in get(pub,f'?page=sa_download&id={rid}&mode=support&document_id={doc}'),'tutor supporting PDF denied')
  b=get(pub,path);b=post(pub,path,dict(action='sa_append',id=rid,revision=4,window_id=1,confirmed=1,csrf=csrf(b)),('same.csv',matrix));ck(db.execute('select revision from academic_records').fetchone()[0]==4,'duplicate upload idempotent')
  changed=matrix.replace(b'Private Student,P',b'Private Student,A');b=post(pub,path,dict(action='sa_append',id=rid,revision=4,window_id=1,confirmed=1,csrf=csrf(b)),('changed.csv',changed));ck(b'Previously submitted attendance differs' in b and db.execute('select revision from academic_records').fetchone()[0]==4,'cannot alter submitted cells')
  addition=b'Date,,,2026-10-02\nSl. No.,Student ID,Student Name,08:00-09:00\nClass Type,,,Extra\nClassroom,,,B1-2\n1,001,Private Student,P\n2,002,Second Student,P\n'
  b=post(pub,path,dict(action='sa_append',id=rid,revision=4,window_id=1,confirmed=1,csrf=csrf(b)),('more.csv',addition));ck(db.execute('select revision from academic_records').fetchone()[0]==5,'same module append submitted');entries=json.loads(db.execute('select entries from academic_records').fetchone()[0]);ck(len(entries)==4,'old dates retained on append')
  b=get(exam,review);ck(b'verification required' in b and b'previous revision' in b,'append recalculates recorded attendance and invalidates old verification')
  b=post(exam,review,dict(action='sa_correct',id=rid,revision=5,contact_hours=11,reason='Test corrected DPD',csrf=csrf(b)));ck(b'Attendance corrected' in b and b'verification required' in b,'audited review correction')
  b=get(pub,path);b=post(pub,path,dict(action='sa_append',id=rid,revision=5,window_id=1,confirmed=1,csrf=csrf(b)),('more.csv',addition));ck(b'Record changed' in b,'stale append blocked')
  b=get(exam,'?page=sa_manage');post(exam,'?page=sa_manage',dict(action='sa_window_toggle',window_id=1,enabled=0,csrf=csrf(b)))
  b=get(pub,path);ck(b'submission is closed' in b and b'name="sheet"' not in b,'closed window hides upload')
  b=post(pub,path,dict(action='sa_append',id=rid,revision=6,window_id=1,confirmed=1,csrf=csrf(b)),('more.csv',addition));ck(b'submission is closed' in b and db.execute('select revision from academic_records').fetchone()[0]==6,'closed window server enforcement')
  signed=login('tutor');legacy=get(signed,'?page=ac_home');legacy=post(signed,'?page=ac_home',dict(p,action='ac_create',module_code='BYPASS',csrf=csrf(legacy)));ck(db.execute('select count(*) from academic_records').fetchone()[0]==1,'legacy attendance create cannot bypass closed window')
  other=client();ob=get(other,'?page=sa_portal');ob=post(other,'?page=sa_portal',dict(action='sa_request_code',email='other@example.test',csrf=csrf(ob)));othermail=db.execute("select body from outbox where recipient='other@example.test' order by id desc limit 1").fetchone()[0];othercode=re.search(r'code is: (\d{6})',othermail).group(1)
  for _ in range(5):ob=post(other,'?page=sa_portal',dict(action='sa_verify_code',code='000000',csrf=csrf(ob)))
  ob=post(other,'?page=sa_portal',dict(action='sa_verify_code',code=othercode,csrf=csrf(ob)));ck(b'incorrect or expired' in ob and b'Verified tutor' not in ob,'five guesses lock verification code')
  ob=post(other,'?page=sa_portal',dict(action='sa_request_code',email='other@example.test',csrf=csrf(ob)));othermail=db.execute("select body from outbox where recipient='other@example.test' order by id desc limit 1").fetchone()[0];othercode=re.search(r'code is: (\d{6})',othermail).group(1);post(other,'?page=sa_portal',dict(action='sa_verify_code',code=othercode,csrf=csrf(ob)))
  ck(b'does not belong' in get(other,path),'another verified tutor cannot access module')
  ck(b'Access denied' in get(other,f'?page=sa_download&id={rid}&mode=template'),'another verified tutor cannot export module')
  # Repeat migration keeps records intact.
  subprocess.run([php,str(root/'bin/migrate.php')],env=env,check=True,stdout=subprocess.DEVNULL);ck(db.execute('select count(*) from academic_records').fetchone()[0]==1,'repeat safe migration')
  # Administrator overrides role and submission-window restrictions, without email OTP.
  ab=get(admin,'?page=sa_portal');ck(b'Administrator access' in ab and b'All attendance modules' in ab,'admin no OTP and all modules')
  ab=get(admin,'?page=sa_new');ck(b'name="module_code"' in ab,'admin create form outside deadline')
  ab=post(admin,'?page=sa_new',dict(p,csrf=csrf(ab),window_id=0,module_code='ADMIN01'));ck(db.execute('select count(*) from academic_records').fetchone()[0]==2,'admin create outside deadline')
  ab=get(admin,review);ab=post(admin,review,dict(action='sa_correct',id=rid,revision=6,contact_hours=10,reason='Admin audited correction',csrf=csrf(ab)));ck(db.execute('select contact_hours from academic_records where id=?',(rid,)).fetchone()[0]==10,'admin correct submitted attendance')
  ck(get(admin,f'?page=sa_download&id={rid}&mode=support&document_id={doc}').startswith(b'%PDF'),'admin supporting document download')
  ab=get(admin,'?page=sa_manage');ab=post(admin,'?page=sa_manage',dict(window,csrf=csrf(ab),title='Admin opened period'));ck(b'Admin opened period' in ab,'admin creates submission period')
  def period_version():
   return re.search(rb'name="version" value="([^"]+)"',get(exam,'?page=sa_manage')).group(1).decode()
  count_before=db.execute('select count(*) from academic_records').fetchone()[0]
  version=period_version()
  edit=dict(window,action='sa_window_edit',window_id=2,version=version,title='Edited test period',reason='Correct deadline')
  edit['csrf']=csrf(get(exam,'?page=sa_manage'));post(exam,'?page=sa_manage',edit)
  ck(db.execute('select title from student_attendance_windows where id=2').fetchone()[0]=='Edited test period','Exam Cell edits period')
  edit['title']='Stale edit';edit['csrf']=csrf(get(exam,'?page=sa_manage'));b=post(exam,'?page=sa_manage',edit);ck(b'Period changed' in b,'stale period edit blocked')
  version=period_version()
  delete=dict(action='sa_window_delete',window_id=2,version=version,reason='Test cleanup',confirmed=1)
  delete['csrf']=csrf(get(signed,'?page=sa_portal'));post(signed,'?page=sa_portal',delete)
  ck(db.execute('select count(*) from student_attendance_windows where id=2').fetchone()[0]==1,'tutor cannot delete period')
  delete['csrf']=csrf(get(exam,'?page=sa_manage'));post(exam,'?page=sa_manage',dict(delete,confirmed=0))
  ck(db.execute('select count(*) from student_attendance_windows where id=2').fetchone()[0]==1,'period deletion requires confirmation')
  post(exam,'?page=sa_manage',delete)
  ck(db.execute('select count(*) from student_attendance_windows where id=2').fetchone()[0]==0,'Exam Cell deletes period')
  ck(db.execute('select count(*) from academic_records').fetchone()[0]==count_before,'period deletion preserves all attendance')
  if os.environ.get('ATTENDANCE_BROWSER'):
   cookies=[dict(name=c.name,value=c.value,domain='127.0.0.1',path='/') for h in pub.handlers if isinstance(h,urllib.request.HTTPCookieProcessor) for c in h.cookiejar];subprocess.run([os.environ['NODE_BIN'],str(root/'tests/student_attendance_browser.cjs')],env=dict(os.environ,SA_COOKIES=json.dumps(cookies)),check=True)
  log.flush();log.seek(0);logs=log.read();ck('Fatal error' not in logs and 'PHP Warning' not in logs,'no PHP warnings or fatal errors')
  print(f'{n} Student attendance HTTP checks passed.')
 finally:
  for row in db.execute('select document_path from student_attendance_verifications'):
   (root/'storage/attendance-support'/row[0]).unlink(missing_ok=True)
  srv.terminate();srv.wait();log.close()
