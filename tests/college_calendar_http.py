"""Isolated calendar authorization, dates, migration and browser checks; no mail worker."""
import os,pathlib,tempfile,subprocess,sqlite3,urllib.request,urllib.parse,urllib.error,http.cookiejar,re,time,json
root=pathlib.Path(__file__).resolve().parents[1];php=os.environ.get('PHP_BIN','php');n=0
def ck(v,m):
 global n
 assert v,m
 n+=1
with tempfile.TemporaryDirectory() as td:
 env=dict(os.environ,APP_DSN='sqlite:'+td+'/test.sqlite',APP_DEMO='1',ADMIN_EMAIL='admin@example.test',ADMIN_PASSWORD='CalendarTest123!')
 subprocess.run([php,str(root/'bin/setup.php')],env=env,check=True,stdout=subprocess.DEVNULL)
 subprocess.run([php,str(root/'bin/migrate-college-calendar.php'),'--import-2026'],env=env,check=True,stdout=subprocess.DEVNULL)
 db=sqlite3.connect(td+'/test.sqlite');db.row_factory=sqlite3.Row
 ck(db.execute('select count(*) from college_calendar_events').fetchone()[0]==122,'source events imported')
 subprocess.run([php,str(root/'bin/migrate-college-calendar.php'),'--import-2026'],env=env,check=True,stdout=subprocess.DEVNULL)
 ck(db.execute('select count(*) from college_calendar_events').fetchone()[0]==122,'repeat import idempotent')
 pw=db.execute('select password from users limit 1').fetchone()[0]
 for role in ['daa','exam','pl','faculty','hod']:
  db.execute('insert into users(name,email,password,role,department) values(?,?,?,?,?)',(role,role+'@example.test',pw,role,'Department of Agriculture'))
 db.commit();log=open(td+'/http.log','w+');srv=subprocess.Popen([php,'-S','127.0.0.1:8101','-t',str(root/'public')],env=env,stdout=log,stderr=log)
 base='http://127.0.0.1:8101/'
 def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 def get(c,p):return c.open(base+p).read()
 def csrf(b):return re.search(rb'name="csrf" value="([^"]+)"',b).group(1).decode()
 def post(c,p,d):return c.open(base+p,urllib.parse.urlencode(d).encode()).read()
 def login(role):
  c=client();b=get(c,'?page=login');b=post(c,'?page=login',dict(action='login',csrf=csrf(b),email=role+'@example.test',password='CalendarTest123!'));ck(b'Sign out' in b,'login '+role);return c
 try:
  pub=client()
  for _ in range(40):
   try:b=get(pub,'calendar.php?year=2026');break
   except urllib.error.URLError:time.sleep(.1)
  ck(b'College Calendar <span>2026' in b,'public year');ck(b'cc-sheet-heading' in b and b'Meetings</div>' in b,'annual column headings');ck(b'View all 122' not in b,'events not hidden in year disclosure');ck(b'cnr-logo' not in b and b'Centralized Academic' not in b,'no application banner');ck(b'CRC' in b,'imported events visible');ck(b'Add an event' not in b,'public no edit controls')
  ck(b'2028-02-29' in get(pub,'calendar.php?year=2028&month=2'),'leap day generated');ck(b'2027-02-29' not in get(pub,'calendar.php?year=2027&month=2'),'normal February');ck(b'Fireline preparation' in get(pub,'calendar.php?year=2026&month=10'),'crossmonth start');ck(b'Fireline preparation' in get(pub,'calendar.php?year=2026&month=11'),'crossmonth end')
  actors={r:login(r) for r in ['admin','daa','exam','pl','faculty','hod']};path='?page=calendar&manage=1&year=2026'
  for r in ['admin','daa','exam']:ck(b'Add an event' in get(actors[r],path),'manager '+r)
  for r in ['pl','faculty','hod']:ck(b'Add an event' not in get(actors[r],path),'reader '+r)
  data=dict(action='cc_save',year='2026',title='Private calendar fixture',category='meeting',start_date='2026-12-22',end_date='2026-12-23',start_time='',end_time='',location='Room One',description='Test event',source='Fixture',status='draft')
  admin=actors['admin'];token=csrf(get(admin,path));b=post(admin,path,dict(data,csrf=token));ck(b'Calendar updated' in b,'admin create');rid=db.execute('select max(id) from college_calendar_events').fetchone()[0]
  ck(b'Private calendar fixture' not in get(pub,'calendar.php?year=2026'),'draft hidden');ck(b'Private calendar fixture' in get(actors['exam'],path),'draft visible exam')
  for role in ['pl','faculty','hod']:
   c=actors[role];t=csrf(get(c,'?page=account'));b=post(c,path,dict(data,csrf=t));ck(b'Only Admin, Exam Cell and DAA' in b,'forged create denied '+role)
  b=post(admin,path,dict(data,csrf='wrong'));ck(b'Your session expired' in b,'CSRF denied')
  c=actors['daa'];token=csrf(get(c,path));b=post(c,path,dict(data,id=rid,revision=1,status='published',csrf=token));ck(b'Calendar updated' in b,'daa publishes other owner');ck(b'Private calendar fixture' in get(pub,'calendar.php?year=2026'),'published visible')
  b=post(c,path,dict(data,id=rid,revision=1,csrf=token));ck(b'Another editor changed' in b,'stale update blocked')
  b=post(c,path,dict(data,start_date='2026-02-30',csrf=token));ck(b'Enter a valid date' in b,'bad date');ck(b'value="Private calendar fixture"' in b,'failed values retained')
  b=post(c,path,dict(data,end_date='2026-12-01',csrf=token));ck(b'End date must' in b,'reversed dates')
  b=post(c,path,dict(data,end_date='2026-12-22',start_time='14:00',end_time='13:00',csrf=token));ck(b'End time must' in b,'invalid times')
  exam=actors['exam'];token=csrf(get(exam,path));b=post(exam,path,dict(action='cc_holidays',year=2027,csrf=token));ck(b'Fixed-date suggestions added' in b,'holiday suggestions');ck(db.execute("select count(*) from college_calendar_events where start_date like '2027%' and status='draft'").fetchone()[0]==5,'five draft suggestions');ck(b'National Day' not in get(pub,'calendar.php?year=2027'),'suggestions not public')
  post(exam,path,dict(action='cc_holidays',year=2027,csrf=token));ck(db.execute("select count(*) from college_calendar_events where start_date like '2027%'").fetchone()[0]==5,'suggestions idempotent')
  b=post(exam,path,dict(action='cc_delete',id=rid,revision=2,year=2026,csrf=token));ck(b'Calendar updated' in b,'exam deletes');ck(b'Private calendar fixture' not in get(pub,'calendar.php?year=2026'),'deleted hidden');ck(db.execute('select count(*) from college_calendar_audit where event_id=?',(rid,)).fetchone()[0]==3,'audit retained')
  b=post(admin,path,dict(data,title='<script>alert(1)</script>',status='published',csrf=csrf(get(admin,path))));b=get(pub,'calendar.php?year=2026');ck(b'&lt;script&gt;alert(1)&lt;/script&gt;' in b and b'<script>alert(1)</script>' not in b,'escaped titles')
  db.execute("update college_calendar_events set status='deleted' where title='<script>alert(1)</script>'");db.commit()
  if os.environ.get('CALENDAR_BROWSER'):
   subprocess.run([os.environ['NODE_BIN'],str(root/'tests/college_calendar_browser.cjs')],check=True,env=os.environ)
  print(n,'College calendar HTTP checks passed.')
 finally:
  srv.terminate();srv.wait();log.close()
