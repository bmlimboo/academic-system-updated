"""Isolated HTTP regression checks; creates and removes its own SQLite fixture."""
import os, subprocess, tempfile, pathlib, urllib.request, urllib.parse, http.cookiejar, re, time
root=pathlib.Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory() as tmp:
 env=dict(os.environ,APP_DSN='sqlite:'+tmp+'/test.sqlite',APP_DEMO='1',ADMIN_EMAIL='admin@example.test',ADMIN_PASSWORD='CnrPreview2026!')
 php=os.environ.get('PHP_BIN','php')
 for script in ['setup.php','demo.php']:subprocess.run([php,str(root/'bin'/script)],env=env,check=True,stdout=subprocess.DEVNULL)
 server=subprocess.Popen([php,'-S','127.0.0.1:8097','-t',str(root/'public')],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 base='http://127.0.0.1:8097/'
 def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 def get(c,path=''):
  try:return c.open(base+path).read().decode()
  except urllib.error.HTTPError as e:return e.read().decode()
 def token(text):return re.search(r'name="csrf" value="([^"]+)"',text).group(1)
 def post(c,path,data):return c.open(base+path,urllib.parse.urlencode(data).encode()).read().decode()
 n=0
 def check(ok,msg):
  global n
  assert ok,msg
  n+=1
 try:
  for _ in range(40):
   try:body=get(client());break
   except urllib.error.URLError:time.sleep(.1)
  c=client();body=get(c)
  check('Latest Requisitions' in body and 'name="search"' not in body and '<select' not in body,'public homepage has no filters')
  check(body.count('＋</a>')==4,'four direct forms')
  for module in ['substitution','consultation','exam','library']:
   body=get(c,'?page=new&module='+module)
   check('name="action" value="submit"' in body,'public '+module+' form')
   check('name="applicant_email"' in body,'applicant email '+module)
  for page in ['admin','teaching','documents','export','download&id=1','detail&id=1','reports']:
   check('name="action" value="login"' in get(c,'?page='+page),'anonymous protected '+page)
  body=get(c,'?page=new&module=substitution')
  check('name="purpose"' not in body and 'name="purpose_other"' in body,'single purpose and conditional other')
  # Four public requests, with independent visitor sessions to respect submission throttling.
  import sqlite3
  db=sqlite3.connect(tmp+'/test.sqlite')
  faculty=db.execute("select id from users where role='faculty'").fetchone()[0]
  common={'action':'submit','applicant_id':faculty,'applicant_email':'faculty@example.test'}
  requests=[dict(module='exam',service='Student Name List',purpose='Review',programme='BSc. Agriculture',year='1',semester='I',academic_year='2026',collection_at='2030-10-07T14:00',delivery='Through email'),dict(module='library',service='Other Library Service',programme='BSc. Agriculture',details='Resource help'),dict(module='consultation',applicant_name='Public Student',applicant_email='newstudent@example.test',programme='BSc. Agriculture',purpose='Research',booking_date='2030-10-07',slot_id='1'),dict(module='substitution',substitution_required='No',travel_start='2030-10-07',travel_end='2030-10-08',return_date='2030-10-08',purpose_category='Other',purpose_other='External academic review',place='Thimphu',funding_agency='College',transportation='Other',transport_details='Hired bus')]
  for fields in requests:
   visitor=client();path='?page=new&module='+fields['module'];form=get(visitor,path)
   result=post(visitor,path,dict(common,**fields,csrf=token(form)))
   check('Notifications have been queued' in result,'public submit '+fields['module'])
   check('name="action" value="login"' in get(visitor,'?page=admin'),'submission does not authenticate')
  for missing in ['purpose_other','transport_details']:
   visitor=client();fields=dict(common,**requests[-1]);fields.pop(missing);fields['csrf']=token(get(visitor,'?page=new&module=substitution'))
   before=db.execute('select count(*) from requests').fetchone()[0]
   post(visitor,'?page=new&module=substitution',fields)
   check(before==db.execute('select count(*) from requests').fetchone()[0],'server rejects missing '+missing)
  for role in ['faculty','student','admin','daa','hod','exam','librarian']:
   visitor=client();body=get(visitor,'?page=login');result=post(visitor,'?page=login',dict(action='login',csrf=token(body),email=role+'@example.test',password='CnrPreview2026!'))
   check(('Sign out' in result)==(role not in ['faculty','student']),'login policy '+role)
  admin=client();post(admin,'?page=login',dict(action='login',csrf=token(get(admin,'?page=login')),email='admin@example.test',password='CnrPreview2026!'))
  result=post(admin,'?page=teaching',dict(action='teaching_save',csrf=token(get(admin,'?page=teaching')),name='New Tutor',email='newtutor@example.test',department='Department of Agriculture'))
  check('New Tutor' in result,'admin adds teaching staff')
  for route in ['att_dashboard','att_timetable','att_upload','att_rooms','att_devices','att_settings']:
   screen=get(admin,'?page='+route)
   check('Tutor Attendance' in screen and not any(error in screen for error in ['Fatal error','Warning:','Parse error']), 'admin attendance route '+route)
  # Library controls must be rendered as real choice fields.
  screen=get(client(),'?page=new&module=library')
  check('name="updates[]"' in screen and 'Newly acquired books' in screen,'CAS checkboxes rendered')
  check('name="isbn"' in screen and 'name="publisher"' in screen,'separate bibliography fields')
  screen=get(client(),'?page=new&module=consultation')
  check('name="booking_mode"' in screen and 'name="manual_start"' in screen,'manual consultation UI')
  # Full HTTP action path for teaching permission, room, preview and import.
  result=post(admin,'?page=att_devices',dict(action='att_permission',csrf=token(get(admin,'?page=att_devices')),staff_id=faculty,enabled='1',initial_password='AttendancePass123!'))
  check('Saved successfully' in result,'HTTP attendance permission')
  result=post(admin,'?page=att_rooms',dict(action='att_room',csrf=token(get(admin,'?page=att_rooms')),room_code='CR-HTTP',room_name='HTTP Room',building='Main',floor='1',active='1'))
  check('CR-HTTP' in result,'HTTP classroom creation')
  csv='Academic Year,Semester,Department,Programme,Year,Section,Subject Code,Subject Name,Tutor Email,Day,Start Time,End Time,Classroom\n2030,Autumn,Department of Agriculture,BSc. Agriculture,2,A,AGHTTP,HTTP Test,faculty@example.test,Monday,09:00,10:00,CR-HTTP\n'
  boundary='CNRBOUNDARYTEST'
  parts=[]
  for key,value in dict(action='att_upload',csrf=token(get(admin,'?page=att_upload')),valid_from='2030-01-01',valid_until='2030-06-01').items():parts.append('--'+boundary+'\r\nContent-Disposition: form-data; name="'+key+'"\r\n\r\n'+value+'\r\n')
  parts.append('--'+boundary+'\r\nContent-Disposition: form-data; name="sheet"; filename="timetable.csv"\r\nContent-Type: text/csv\r\n\r\n'+csv+'\r\n--'+boundary+'--\r\n')
  req=urllib.request.Request(base+'?page=att_upload',data=''.join(parts).encode(),headers={'Content-Type':'multipart/form-data; boundary='+boundary})
  result=admin.open(req).read().decode();check('Import 1 Valid Records' in result,'HTTP timetable preview')
  result=post(admin,'?page=att_upload',dict(action='att_import',csrf=token(result)))
  check('1 valid timetable rows imported' in result,'HTTP timetable import')
  check('AGHTTP' in get(admin,'?page=att_timetable'),'import visible in timetable')
  # Download generated XLSX (must be a real ZIP workbook).
  check(admin.open(base+'?page=att_template').read().startswith(b'PK'),'Excel template download')


  tid=db.execute("select id from users where email='newtutor@example.test'").fetchone()[0]
  post(admin,'?page=teaching',dict(action='teaching_save',csrf=token(result),id=tid,name='Edited Tutor',email='newtutor@example.test',department='Department of Agriculture'))
  check(db.execute('select name from users where id=?',(tid,)).fetchone()[0]=='Edited Tutor','admin edits staff')
  post(admin,'?page=teaching',dict(action='teaching_delete',csrf=token(get(admin,'?page=teaching')),id=tid))
  check(db.execute('select active from users where id=?',(tid,)).fetchone()[0]==0,'delete preserves history')
  check('Edited Tutor' not in get(client(),'?page=new&module=substitution'),'deleted staff unavailable')
  # Pagination beyond 15 rows; public summaries do not contain raw payloads.
  for i in range(20):db.execute("insert into requests(module,applicant_id,department,status,payload,created_at,updated_at) values('exam',?,'','Submitted','{\"purpose\":\"PRIVATE_SENTINEL\"}','2030-01-01','2030-01-01')",(faculty,))
  db.commit();body=get(client());tbody=body.split('<tbody>')[1].split('</tbody>')[0]
  check(tbody.count('<tr>')==15,'exactly 15 latest records')
  check('Next</a>' in body and 'Previous</a>' in get(client(),'?p=2'),'pagination')
  check('PRIVATE_SENTINEL' not in body,'public summary excludes private fields')
  check('name="search"' not in get(client(),'?module=exam'),'module report no search')
  print(str(n)+' public access, submission, staff and pagination checks passed.')
 finally:
  server.terminate();server.wait()
