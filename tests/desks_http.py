"""Isolated CAS integration tests; no mail worker or real emails."""
import os,pathlib,tempfile,subprocess,sqlite3,urllib.request,urllib.parse,urllib.error,http.cookiejar,re,time,json
root=pathlib.Path(__file__).resolve().parents[1];php=os.environ['PHP_BIN'];checks=0

def ck(v,m):
 global checks
 assert v,m
 checks+=1
with tempfile.TemporaryDirectory() as td:
 env=dict(os.environ,APP_DSN='sqlite:'+td+'/test.sqlite',APP_DEMO='1',ADMIN_EMAIL='admin@example.test',ADMIN_PASSWORD='AcademicTest123!')
 subprocess.run([php,str(root/'bin/setup.php')],env=env,check=True,stdout=subprocess.DEVNULL)
 db=sqlite3.connect(td+'/test.sqlite');db.row_factory=sqlite3.Row;pw=db.execute('select password from users limit 1').fetchone()[0]
 for name,role,dept in [('DAA','daa','Department of Agriculture'),('PL','pl','Department of Agriculture'),('ForeignPL','pl','Department of Animal Science'),('Faculty','faculty','Department of Agriculture'),('Other','faculty','Department of Animal Science'),('Student','student','Department of Agriculture'),('Otherstudent','student','Department of Animal Science'),('Exam','exam','Exam Cell'),('Alumni','alumnus','')]:db.execute('insert into users(name,email,password,role,department) values(?,?,?,?,?)',(name,name.lower()+'@example.test',pw,role,dept))
 db.commit();log=open(td+'/server.log','w+');srv=subprocess.Popen([php,'-S','127.0.0.1:8116','-t',str(root/'public')],env=env,stdout=log,stderr=log);base='http://127.0.0.1:8116/'
 def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 def get(c,path,data=None):
  try:b=c.open(base+path,urllib.parse.urlencode(data,doseq=True).encode() if data else None).read().decode()
  except urllib.error.HTTPError as e:b=e.read().decode()
  ck('Fatal error' not in b,'no fatal '+path+' '+b[:150])
  return b
 def upload(c,path,data,files):
  boundary='deskfixture';body=b''
  for k,v in data.items():body+=f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode()
  for field,name,content in files:body+=f'--{boundary}\r\nContent-Disposition: form-data; name="{field}"; filename="{name}"\r\nContent-Type: application/pdf\r\n\r\n'.encode()+content+b'\r\n'
  body+=f'--{boundary}--\r\n'.encode()
  try:b=c.open(urllib.request.Request(base+path,body,{'Content-Type':'multipart/form-data; boundary='+boundary})).read().decode()
  except urllib.error.HTTPError as e:b=e.read().decode()
  ck('Fatal error' not in b,'upload no fatal '+b[:200]);return b
 def tok(b):return re.search('name="csrf" value="([^"]+)"',b)[1]
 def login(name):
  c=client();b=get(c,'?page=login');b=get(c,'?page=login',dict(action='login',csrf=tok(b),email=name+'@example.test',password='AcademicTest123!'));ck('Sign out' in b or 'Back to CAS' in b,'login '+name)
  research_role={'daa':'daa','pl':'pl','foreignpl':'pl','faculty':'supervisor','other':'supervisor','student':'student','otherstudent':'student'}.get(name)
  if research_role:
   b=get(c,'desks/rd/index.php');ck('I am signing in as' in b,'CAS alone requires research login '+name)
   b=get(c,'desks/rd/login.php',dict(csrf=tok(b),role='daa' if research_role!='daa' else 'student',email=name+'@example.test',password='AcademicTest123!'));ck('not correct' in b,'wrong research role denied')
   b=get(c,'desks/rd/login.php',dict(csrf=tok(b),role=research_role,email=name+'@example.test',password='AcademicTest123!'));ck('Back to CAS' in b and 'I am signing in as' not in b,'research login '+name)
  return c
 try:
  pub=client()
  for _ in range(50):
   try:get(pub,'?page=login');break
   except urllib.error.URLError:time.sleep(.1)
  for name,paths in [('admin',['sd/index.php','sd/queue.php','sd/report.php','sd/stamps.php','cd/index.php','cd/settings.php','rd/index.php','rd/daa_users.php','rd/daa_timeline.php','rd/marks.php','rd/report_marks.php','rd/report_progress.php']),('daa',['sd/index.php','cd/index.php','cd/signature.php','rd/index.php']),('exam',['cd/index.php','cd/requests.php','cd/settings.php']),('student',['rd/index.php']),('faculty',['sd/index.php','sd/submit.php','rd/index.php']),('pl',['rd/index.php','rd/marks.php'])]:
   c=login(name)
   for path in paths:
    b=get(c,'desks/'+path);ck('Warning:' not in b,'no warnings '+path);ck('Fatal error' not in b,'page '+path)
  ck(db.execute("select count(*) from users where email like '%demo.local'").fetchone()[0]==0,'no demo accounts')
  ck(db.execute('select count(*) from rd_activities').fetchone()[0]==23,'23 activities')
  alumni=login('alumni');b=get(alumni,'desks/cd/request_new.php');ck('not open yet' in b,'certificate gate')
  b=get(alumni,'desks/rd/index.php');ck('I am signing in as' in b,'alumni cannot bypass research login')
  b=get(alumni,'desks/sd/index.php');ck('does not have access' in b,'alumni denied signing')
  b=get(pub,'?page=alumni_register');d=dict(action='alumni_code',csrf=tok(b),name='Personal Email',email='personal@example.test',password='PersonalTest123!',password_confirm='PersonalTest123!');b=get(pub,'?page=alumni_register',d);ck('Six-digit' in b,'code requested');ck(db.execute("select count(*) from users where email='personal@example.test'").fetchone()[0]==0,'no account before verification');code=re.search(r'\b\d{6}\b',db.execute("select body from outbox where recipient='personal@example.test'").fetchone()[0])[0]
  b=get(pub,'?page=alumni_register',dict(action='alumni_verify',csrf=tok(b),code='000000'));ck('Incorrect verification' in b,'invalid code');b=get(pub,'?page=alumni_register',dict(action='alumni_verify',csrf=tok(b),code=code));ck('CertDesk' in b,'verified login');ck(db.execute("select role from users where email='personal@example.test'").fetchone()[0]=='alumnus','verified CAS alumnus')
  # Real upload/decision flows with fictional PDFs; never run the worker.
  pdf=b'%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF'
  faculty=login('faculty');other=login('other');daa=login('daa');exam=login('exam')
  b=get(faculty,'desks/sd/submit.php');b=upload(faculty,'desks/sd/submit.php',dict(csrf=tok(b),stype='Faculty',dept='Department of Agriculture',title='Fictional signature test',doc_type='Internship',urgency='normal',remarks='Testing'),[('file','test.pdf',pdf)])
  ck(db.execute('select count(*) from sd_doc_requests').fetchone()[0]==1,'document submitted');ck('Fictional signature test' in b,'document view')
  b=get(other,'desks/sd/view.php?id=1');ck('Document not found' in b,'other staff cannot view document');b=get(other,'desks/sd/download.php?id=1');ck('not found' in b.lower(),'other staff cannot download')
  b=get(daa,'desks/sd/view.php?id=1');b=upload(daa,'desks/sd/view.php?id=1',dict(csrf=tok(b),action='sign',id=1,dispatch_no='TEST/1',reason='Signed'),[('signed','signed.pdf',pdf)]);ck(db.execute('select status from sd_doc_requests where id=1').fetchone()[0]=='signed','DAA signs');ck(db.execute('select count(*) from sd_dispatch_numbers').fetchone()[0]==1,'dispatch retained')
  ck(db.execute("select count(*) from outbox where recipient='faculty@example.test'").fetchone()[0]>0,'sign notification queued')
  db.execute("update desk_settings set setting_value='1' where setting_key='cert_open'");db.commit()
  b=get(alumni,'desks/cd/request_new.php');b=upload(alumni,'desks/cd/request_new.php',{'csrf':tok(b),'full_name':'Test Alumni','student_id':'00123','programme':'BSc. Agriculture','grad_year':'2024','phone':'123','purpose':'Test','types[]':'1','declare':'1'},[(k+'[]',k+'.pdf',pdf) for k in ['application','marksheet','degree']])
  ck(db.execute('select count(*) from cd_requests').fetchone()[0]==1,'certificate requested')
  b=get(exam,'desks/cd/request.php?id=1');b=upload(exam,'desks/cd/request.php?id=1',dict(csrf=tok(b),action='send_daa',id=1,amount='100',note='Test'),[('letter[]','letter.pdf',pdf)]);ck(db.execute('select status from cd_requests where id=1').fetchone()[0]=='with_daa','exam sends DAA')
  b=get(daa,'desks/cd/sign.php?id=1');b=upload(daa,'desks/cd/sign.php?id=1',dict(csrf=tok(b),id=1,remarks='Approved'),[('signed[]','signed.pdf',pdf)]);ck(db.execute('select status from cd_requests where id=1').fetchone()[0]=='awaiting_payment','DAA certificate approval');ck(db.execute('select count(*) from desk_mail_links').fetchone()[0]==1,'payment uses CAS queue')
  b=get(alumni,'desks/cd/request.php?id=1');b=upload(alumni,'desks/cd/request.php?id=1',dict(csrf=tok(b),action='upload_proof',id=1,deposit_ref='TEST'),[('proof[]','proof.pdf',pdf)]);ck(db.execute('select status from cd_requests where id=1').fetchone()[0]=='payment_submitted','payment proof')
  b=get(exam,'desks/cd/request.php?id=1');b=upload(exam,'desks/cd/request.php?id=1',dict(csrf=tok(b),action='upload_certs',id=1),[('cert_1[]','certificate.pdf',pdf)]);ck(db.execute("select count(*) from cd_request_files where kind='certificate'").fetchone()[0]==1,'certificate uploaded')
  fid=db.execute("select id from cd_request_files where kind='certificate'").fetchone()[0]
  b=get(exam,'desks/cd/email.php?id=1');b=get(exam,'desks/cd/email.php?id=1',{'csrf':tok(b),'id':1,'to':'alumni@example.test','subject':'Test release','body':'Test certificate','att[]':str(fid)});ck(db.execute('select status from cd_requests where id=1').fetchone()[0]=='released','released');ck(db.execute('select count(*) from mail_attachments').fetchone()[0]>0,'attachment queued')
  student=login('student');pl=login('pl');foreign=login('foreignpl')
  uid=lambda name:db.execute('select id from users where name=?',(name,)).fetchone()[0]
  db.execute('update rd_activities set enabled=1');db.commit()
  b=get(student,'desks/rd/act.php?id=2');b=get(student,'desks/rd/act.php?id=2',dict(csrf=tok(b),action='choose_sup',supervisor_id=uid('Faculty'),topic='Test research'));ck(db.execute('select count(*) from rd_supervision').fetchone()[0]==1,'choose existing CAS supervisor')
  b=get(faculty,'desks/rd/act.php?id=2');b=get(faculty,'desks/rd/act.php?id=2',dict(csrf=tok(b),action='sup_decide',student_id=uid('Student'),decision='accept',comment='Accepted'));ck(db.execute('select status from rd_supervision').fetchone()[0]=='accepted','supervisor accepts')
  b=get(pl,'desks/rd/act.php?id=16');b=get(pl,'desks/rd/act.php?id=16',dict(csrf=tok(b),action='allocate',student_id=uid('Student'),ev1=uid('Faculty'),ev2=uid('Other')));ck(db.execute('select count(*) from rd_allocations').fetchone()[0]==2,'existing faculty evaluators')
  b=get(foreign,'desks/rd/act.php?id=16');b=get(foreign,'desks/rd/act.php?id=16',dict(csrf=tok(b),action='allocate',student_id=uid('Student'),ev1=uid('Faculty'),ev2=uid('Other')));ck('outside your department' in b,'foreign PL mutation blocked')
  ck('Test research' not in get(foreign,'desks/rd/marks.php?dept=Department%20of%20Agriculture'),'foreign PL report scoped')
  b=get(faculty,'desks/rd/index.php');b=get(faculty,'desks/rd/index.php',dict(csrf=tok(b),desk_action='switch_role',desk_role='evaluator'));b=get(faculty,'desks/rd/form.php?form=6&student='+str(uid('Student'))+'&a=17');ck('Research Project Evaluation' in b,'assigned evaluator form')
  values={'csrf':tok(b),'form':6,'student':uid('Student'),'a':17,'do':'submit'};values.update({'m['+str(i)+']':v for i,v in enumerate([7,12,20,20,20,7,7,7])});b=get(faculty,'desks/rd/form.php?form=6&student='+str(uid('Student'))+'&a=17',values);ck(db.execute('select total from rd_marks').fetchone()[0]==100,'evaluation marks submitted')
  before=db.execute('select count(*) from users').fetchone()[0]
  subprocess.run([php,str(root/'bin/migrate-desks.php')],env=env,check=True,stdout=subprocess.DEVNULL)
  ck(db.execute('select count(*) from users').fetchone()[0]==before,'migration keeps users')
  ck(db.execute('select total from rd_marks').fetchone()[0]==100,'migration keeps marks')
  ck(db.execute('select count(*) from rd_activities').fetchone()[0]==23,'migration does not duplicate activities')
  if os.environ.get('DESK_BROWSER'):subprocess.run([os.environ['DESK_NODE'],str(root/'tests/desks_browser.cjs')],check=True)
  print(str(checks)+' integration checks passed.')
 finally:
  for table,col,key in [('sd_doc_requests','original_file','sd'),('sd_doc_requests','signed_file','sd'),('cd_request_files','stored','cd')]:
   for row in db.execute('select `'+col+'` from '+table):
    if row[0]:(root/'storage/desks'/key/row[0]).unlink(missing_ok=True)
  for row in db.execute('select path from files'):(root/'storage/uploads'/row[0]).unlink(missing_ok=True)
  srv.terminate();srv.wait();log.flush();log.seek(0);logs=log.read();(root.parent/'tmp/desks-test-log.txt').write_text(logs);log.close()
