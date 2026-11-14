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
   name,data=file;body+=f'--{boundary}\r\nContent-Disposition: form-data; name="sheet"; filename="{name}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode()+data+b'\r\n'+f'--{boundary}--\r\n'.encode();req=urllib.request.Request(base+path,body,{'Content-Type':'multipart/form-data; boundary='+boundary})
  else:req=urllib.request.Request(base+path,urllib.parse.urlencode(d).encode())
  return c.open(req).read()
 def login(who):
  c=client();b=get(c,'?page=login');b=post(c,'?page=login',dict(action='login',email=who+'@example.test',password='AcademicTest123!',csrf=csrf(b)));ck(b'Sign out' in b,'login '+who);return c
 try:
  pub=client()
  for _ in range(50):
   try:b=get(pub,'?page=ac_home');break
   except urllib.error.URLError:time.sleep(.1)
  ck(b'Welcome back' in b,'anonymous login gate');t=login('tutor');other=login('other');pl=login('pl');foreign=login('foreignpl');daa=login('daa');exam=login('exam');admin=login('admin')
  b=get(t,'?page=ac_new&kind=assessment');p=dict(action='ac_create',kind='assessment',department=department,programme='BSc. Agriculture',academic_year='2026-2027',study_year='I',semester='I',section='A',module_code='CP101',module_name='Crop Protection',classroom='B1-2',contact_hours=60,has_exam='Yes',components=json.dumps([dict(name='Class test',type='CA',max=100,weight=60),dict(name='Exam',type='ESE',max=100,weight=40)]),csrf=csrf(b))
  b=post(t,'?page=ac_new&kind=assessment',p);ck(b'Crop Protection' in b and b'STEP 2' in b,'create draft HTTP');rid=db.execute('select max(id) from academic_records').fetchone()[0];path=f'?page=ac_record&id={rid}'
  ck(b'not available to your account' in get(other,path),'other tutor privacy');ck(b'not available to your account' in get(daa,path),'draft private from DAA')
  template=get(t,f'?page=ac_download&id={rid}&mode=template');ck(zipfile.is_zipfile(io.BytesIO(template)),'XLSX template valid');ck(b'Student ID' in get(t,f'?page=ac_download&id={rid}&format=csv'),'CSV template headers')
  bad=b'Student ID,Student Name,Class test,Exam\n001,Private Student,101,80\n';b=post(t,path,dict(action='ac_upload',id=rid,revision=1,csrf=csrf(b)),('marks.csv',bad));ck(b'Row 2' in b and db.execute('select entries from academic_records').fetchone()[0]=='[]','invalid upload preserves draft')
  good=b'Student ID,Student Name,Class test,Exam\n001,Private Student,60,20\n002,Second Student,80,80\n';b=post(t,path,dict(action='ac_upload',id=rid,revision=1,csrf=csrf(b)),('marks.csv',good));ck(b'Private Student' in b and b'Weighted marks' in b,'multipart upload preview');ck(b'Fail' in b and b'Pass' in b,'results shown')
  b=post(t,path,dict(action='ac_save_rows',id=rid,revision=2,csrf=csrf(b),sheet_rows=json.dumps([['Student ID','Student Name','Class test','Exam'],['001','Correction retained',101,10]])));ck(b'Correction retained' in b and b'Row 2' in b,'manual invalid input retained')
  b=post(t,path,dict(action='ac_submit',id=rid,revision=2,confirmed=1,csrf=csrf(b)));ck(b'Receipt #' in b and b'Private Student' not in b,'final hides data from tutor')
  ck(b'not available to your account' in get(t,path),'final tutor read blocked');ck(b'not available to your account' in get(foreign,path),'foreign PL read blocked');ck(b'Private Student' in get(pl,path),'own PL read');ck(b'Private Student' not in get(exam,path),'exam has no student marks access')
  ck(b'not available' in get(t,f'?page=ac_download&id={rid}&mode=draft'),'final export blocked tutor');report=get(daa,'?page=ac_reports'+scope);ck(b'Private Student' in report,'consolidated report');report_xlsx=get(daa,'?page=ac_download&mode=report'+scope);ck(zipfile.is_zipfile(io.BytesIO(report_xlsx)),'report XLSX valid');ck(zipfile.is_zipfile(io.BytesIO(get(exam,'?page=ac_download&mode=report'+scope))),'exam consolidated export allowed');ck(b'Private Student' in get(exam,'?page=ac_reports'+scope),'exam consolidated report visible')
  b=get(admin,path);b=post(admin,path,dict(action='ac_contact_hours',id=rid,revision=3,contact_hours=65,reason='Descriptor corrected',csrf=csrf(b)));ck(b'Descriptor corrected' in b,'admin correction audited')
  b=get(exam,'?page=ac_windows');ck(b'Expected modules' in b,'Exam Cell entry management');b=post(exam,'?page=ac_windows',dict(action='ac_cohort_save',programme='BSc. Agriculture',academic_year='2026-2027',study_year='I',semester='I',section='A',module_codes='CP101',csrf=csrf(b)));ck(db.execute('select count(*) from academic_cohorts').fetchone()[0]==1,'cohort save HTTP')
  stamp=datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=6)))
  b=post(exam,'?page=ac_windows',dict(action='ac_window_create',title='Test RA window',academic_year='2026-2027',semester='I',programme='BSc. Agriculture',opens_at=(stamp-datetime.timedelta(hours=1)).strftime('%Y-%m-%dT%H:%M'),closes_at=(stamp+datetime.timedelta(hours=1)).strftime('%Y-%m-%dT%H:%M'),csrf=csrf(b)))
  ck(b'Test RA window' in b,'RA date window HTTP')
  b=get(t,'?page=ac_new&kind=reassessment');ck(b'Original submitted module' in b,'RA inherits original form')
  b=post(t,'?page=ac_new&kind=reassessment',dict(action='ac_create',kind='reassessment',original_id=rid,window_id=1,csrf=csrf(b)));raid=db.execute("select id from academic_records where kind='reassessment'").fetchone()[0];rapath=f'?page=ac_record&id={raid}'
  b=post(t,rapath,dict(action='ac_upload',id=raid,revision=1,csrf=csrf(b)),('ra.csv',b'Student ID,Student Name,Re-exam raw mark\n001,Private Student,80\n'))
  ck(b'50' in b and b'68' in b,'RA calculated and capped totals HTTP')
  b=post(t,rapath,dict(action='ac_submit',id=raid,revision=2,confirmed=1,csrf=csrf(b)));b=get(daa,'?page=ac_reports'+scope);ck(b'RA #' in b,'latest RA consolidated')
  xlsx=get(daa,'?page=ac_download&mode=report'+scope);ck(zipfile.is_zipfile(io.BytesIO(xlsx)),'RA export valid XLSX')
  ck(b'Private Student' in get(admin,path),'admin can see submitted student data')
  b=get(admin,path);rev=db.execute('select revision from academic_records where id=?',(rid,)).fetchone()[0]
  correction=b'Student ID,Student Name,Class test,Exam\n001,Private Student,60,30\n002,Second Student,80,80\n'
  b=post(admin,path,dict(action='ac_admin_correct',id=rid,revision=rev,reason='Admin marks correction',csrf=csrf(b)),('corrected.csv',correction));ck(b'Administrator corrected submitted marks' in b,'admin audited marks correction')
  saved=db.execute('select entries from academic_records where id=?',(rid,)).fetchone()[0];b=get(pl,path);post(pl,path,dict(action='ac_admin_correct',id=rid,revision=rev+1,reason='Forbidden',csrf=csrf(b)),('corrected.csv',good));ck(db.execute('select entries from academic_records where id=?',(rid,)).fetchone()[0]==saved,'PL cannot use admin marks correction')
  # Browser verifies live JavaScript, responsive forms and actual downloads.
  if os.environ.get('ACADEMIC_BROWSER'):
   node=os.environ['NODE_BIN'];subprocess.run([node,str(root/'tests/academic_browser.cjs')],check=True)
  log.flush();log.seek(0);logs=log.read();ck('Fatal error' not in logs and 'PHP Warning' not in logs,'no PHP warnings or fatal errors')
  print(f'{n} Academic HTTP checks passed.')
 finally:srv.terminate();srv.wait();log.close()
