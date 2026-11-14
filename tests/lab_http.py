"""Temporary local SQLite database. Queues inspected; never sends email."""
import os,pathlib,tempfile,subprocess,sqlite3,urllib.request,urllib.parse,urllib.error,http.cookiejar,re,time
root=pathlib.Path(__file__).resolve().parents[1];php=os.environ['PHP_BIN'];checks=0
def ck(v,m):
 global checks
 assert v,m
 checks+=1
with tempfile.TemporaryDirectory() as td:
 env=dict(os.environ,APP_DSN='sqlite:'+td+'/test.sqlite',APP_DEMO='1',ADMIN_EMAIL='admin@example.test',ADMIN_PASSWORD='AcademicTest123!')
 subprocess.run([php,str(root/'bin/setup.php')],env=env,check=True,stdout=subprocess.DEVNULL)
 db=sqlite3.connect(td+'/test.sqlite');db.row_factory=sqlite3.Row
 pw=db.execute('select password from users limit 1').fetchone()[0]
 for role in ['lab','faculty','daa']:
  db.execute('insert into users(name,email,password,role) values(?,?,?,?)',(role,role+'@example.test',pw,role))
 db.commit();log=open(td+'/server.log','w+');srv=subprocess.Popen([php,'-S','127.0.0.1:8114','-t',str(root/'public')],env=env,stdout=log,stderr=log)
 base='http://127.0.0.1:8114/'
 def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 def request(c,path,data=None):
  try:return c.open(base+path,urllib.parse.urlencode(data).encode() if data else None).read().decode()
  except urllib.error.HTTPError as e:return e.read().decode()
 def token(b,name='csrf'):return re.search('name="'+name+'" value="([^"]+)"',b)[1]
 def login(role,c=None):
  c=c or client();b=request(c,'?page=login');request(c,'?page=login',dict(action='login',csrf=token(b),email=role+'@example.test',password='AcademicTest123!'));return c
 try:
  pub=client()
  for _ in range(50):
   try:b=request(pub,'?page=lab_booking');break
   except urllib.error.URLError:time.sleep(.1)
  out=root.parent/'tmp/lab-review';out.mkdir(parents=True,exist_ok=True);(out/'form.html').write_text(b.replace('href="assets/', 'href="'+(root/'public/assets').as_uri()+'/').replace('src="assets/', 'src="'+(root/'public/assets').as_uri()+'/'));ck('Reserve a laboratory' in b,'public form');ck('Other laboratory' in b,'other room');ck('MSc. Conservation Biology' in b,'programmes')
  data=dict(action='lab_submit',csrf=token(b),submission_key=token(b,'submission_key'),tutor='Test Adjunct',email='adjunct@example.test',topic='Test practical',criteria='Academic Practical',module_name='Biology',module_code='BIO101',programme='BSc. Agriculture',study_year='I',laboratory='Nutrient Laboratory',practical_date='2030-11-10',start_time='09:00',end_time='10:00',materials='Microscope')
  b=request(pub,'?page=lab_booking',dict(data,email='invalid'));ck('valid email' in b,'invalid email');ck('Test practical' in b,'values retained')
  b=request(pub,'?page=lab_booking',data);ck('Booking #1 received' in b,'submit');ck(db.execute('select count(*) from lab_bookings').fetchone()[0]==1,'one booking');ck(db.execute('select count(*) from outbox').fetchone()[0]==2,'receipt and technician queued')
  m=db.execute("select body from outbox where recipient='sciencelab.cnr@rub.edu.bt'").fetchone()[0];ck('?page=lab_manage&id=1' in m and 'Click here' in m,'review link')
  data.update(csrf=token(b),submission_key=token(b,'submission_key'))
  b=request(pub,'?page=lab_booking',dict(data,start_time='09:30',end_time='10:30'));ck('Booking #1' in b and 'Please choose another' in b,'pending overlap rejected');ck('adjunct@example.test' not in b.split('Booking #1')[1].split('</div>')[0],'conflict hides email')
  b=request(pub,'?page=lab_booking',dict(data,start_time='10:00',end_time='11:00'));ck('Booking #2 received' in b,'adjacent allowed')
  b=request(pub,'?page=lab_manage&id=1');ck('Welcome back' in b,'private approvals login');lab=login('lab',pub);b=request(lab,'?page=lab_manage&id=1');ck('Materials required' in b,'technician sees detail')
  decision=dict(action='lab_decide',csrf=token(b),id=1,decision='Approved',handled_by='Test Technician',decision_note='Bring coats')
  b=request(lab,'?page=lab_manage&id=1',decision);ck(db.execute('select status from lab_bookings where id=1').fetchone()[0]=='Approved','approved');ck('approved and booked' in db.execute('select body from outbox order by id desc').fetchone()[0],'approval email')
  before=db.execute('select count(*) from outbox').fetchone()[0];b=request(lab,'?page=lab_manage&id=1',decision);ck('already been decided' in b,'stale decision');ck(db.execute('select count(*) from outbox').fetchone()[0]==before,'no duplicate notification')
  b=request(lab,'?page=lab_manage&id=2');decision.update(csrf=token(b),id=2,decision='Rejected',decision_note='');b=request(lab,'?page=lab_manage&id=2',decision);ck('reason for rejection' in b,'rejection reason required');decision['decision_note']='Equipment unavailable';request(lab,'?page=lab_manage&id=2',decision);ck(db.execute('select status from lab_bookings where id=2').fetchone()[0]=='Rejected','rejected');ck('Equipment unavailable' in db.execute('select body from outbox order by id desc').fetchone()[0],'rejection mail')
  fresh=client();b=request(fresh,'?page=lab_booking');data.update(csrf=token(b),submission_key=token(b,'submission_key'),start_time='10:00',end_time='11:00');b=request(fresh,'?page=lab_booking',data);ck('Booking #3 received' in b,'rejected slot released')
  daa=login('daa');b=request(daa,'?page=lab_manage');ck('access required' in b,'DAA cannot approve');b=request(daa,'?page=lab_manage',dict(decision,csrf=token(request(daa,'?page=lab_booking')),id=3));ck('access required' in b,'server authorization')
  admin=login('admin');b=request(admin,'?page=lab_manage&id=3');ck('Save decision' in b,'admin can approve');b=request(admin,'?page=admin');ck('Laboratory technician (lab)' in b,'account role UI')
  b=request(lab,'?page=lab_manage&lab_status=Rejected');ck('View #2' in b and 'View #1' not in b,'status filter')
  b=request(fresh,'?page=lab_booking');data.update(csrf=token(b),submission_key=token(b,'submission_key'),laboratory='Other',laboratory_other='Nutrient Laboratory',start_time='09:15',end_time='09:45');b=request(fresh,'?page=lab_booking',data);ck('Please choose another' in b,'other room cannot bypass canonical conflict')
  b=request(fresh,'?page=lab_booking',dict(data,csrf='bad'));ck('session expired' in b,'CSRF enforced')
  b=request(lab,'?page=lab_manage&id=3');ck('Delete booking' not in b,'technician has no delete button')
  deletion=dict(action='lab_delete',id=3,expected_status='Pending',confirm_delete='1',reason='Dummy booking')
  b=request(lab,'?page=lab_manage&id=3',dict(deletion,csrf=token(b)));ck('Admin access required' in b,'technician deletion blocked')
  b=request(admin,'?page=lab_manage&id=3');ck('Delete booking' in b,'admin delete button')
  b=request(admin,'?page=lab_manage&id=3',dict(deletion,csrf=token(b),confirm_delete=''));ck('Confirm deletion' in b,'deletion confirmation required')
  b=request(admin,'?page=lab_manage&id=3',dict(deletion,csrf=token(b)));ck('Booking deleted' in b,'admin deletes')
  ck(db.execute('select status from lab_bookings where id=3').fetchone()[0]=='Deleted','soft deleted')
  ck(db.execute('select count(*) from lab_booking_deletions where booking_id=3').fetchone()[0]==1,'audit retained')
  ck('no longer reserves' in db.execute('select body from outbox order by id desc').fetchone()[0],'cancellation email')
  ck('View #3' not in request(admin,'?page=lab_manage'),'deleted removed from list')
  ck('Materials required' not in request(admin,'?page=lab_manage&id=3'),'deleted detail hidden')
  ck(db.execute("select count(*) from outbox where status='sent'").fetchone()[0]==0,'no emails sent')
  log.flush();log.seek(0);ck('Fatal error' not in log.read(),'no server fatal')
  print(str(checks)+' lab booking HTTP checks passed.')
 finally:srv.terminate();srv.wait();log.close()
