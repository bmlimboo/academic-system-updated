"""Isolated Exam Cell HTTP flow; no real emails, removes its own uploaded fixtures."""
import os, pathlib, tempfile, subprocess, sqlite3, urllib.request, urllib.parse, urllib.error, http.cookiejar, re, time, datetime, json
root=pathlib.Path(__file__).resolve().parents[1]
php=os.environ.get('PHP_BIN','php')
n=0
def ck(v,m):
 global n
 assert v,m
 n+=1
with tempfile.TemporaryDirectory() as td:
 env=dict(os.environ,APP_DSN='sqlite:'+td+'/test.sqlite',APP_DEMO='1',ADMIN_EMAIL='admin@example.test',ADMIN_PASSWORD='CnrPreview2026!')
 for s in ['setup.php','demo.php','migrate.php']:subprocess.run([php,str(root/'bin'/s)],env=env,check=True,stdout=subprocess.DEVNULL)
 db=sqlite3.connect(td+'/test.sqlite');db.row_factory=sqlite3.Row
 teacher=db.execute("SELECT * FROM users WHERE role='faculty' AND active=1 LIMIT 1").fetchone()
 stamp=datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=6))).replace(tzinfo=None)
 db.execute('INSERT INTO exam_windows(academic_year,semester,exam_type,opens_at,closes_at,published,created_at) VALUES(?,?,?,?,?,1,?)',('2026–2027','I','Semester-end examination',str(stamp-datetime.timedelta(days=1)),str(stamp+datetime.timedelta(days=5)),str(stamp)));db.commit()
 srv=subprocess.Popen([php,'-S','127.0.0.1:8098','-t',str(root/'public')],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 base='http://127.0.0.1:8098/'
 def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 def get(c,path):
  try:return c.open(base+path).read()
  except urllib.error.HTTPError as ex:return ex.read()
 def csrf(b):return re.search(rb'name="csrf" value="([^"]+)"',b).group(1).decode()
 def post(c,path,d,files=None):
  if files is None:req=urllib.request.Request(base+path,urllib.parse.urlencode(d).encode())
  else:
   boundary='cnr-test-boundary-xyz';body=b''
   for k,v in d.items():body+=f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode()
   for k,(name,data) in files.items():body+=f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"; filename="{name}"\r\nContent-Type: application/pdf\r\n\r\n'.encode()+data+b'\r\n'
   body+=f'--{boundary}--\r\n'.encode();req=urllib.request.Request(base+path,body,{'Content-Type':'multipart/form-data; boundary='+boundary})
  return c.open(req).read()
 try:
  pub=client()
  for _ in range(40):
   try:b=get(pub,'?page=exam_submit&exam_id=1');break
   except urllib.error.URLError:time.sleep(.1)
  ck(b'Question Paper Submission' in b,'public form')
  ck(b'Re-assessment Exam' not in b,'unannounced examination types hidden')
  db.execute('UPDATE exam_windows SET published=0');db.commit()
  empty=get(pub,'?page=exam_submit');ck(b'has not been announced' in empty and b'<select' not in empty,'no dropdown before announcement')
  db.execute('UPDATE exam_windows SET published=1,opens_at=?',(str(stamp+datetime.timedelta(days=1)),));db.commit()
  future=get(pub,'?page=exam_submit');ck(b'<select' not in future,'scheduled period hidden before opening')
  db.execute('UPDATE exam_windows SET opens_at=?',(str(stamp-datetime.timedelta(days=1)),));db.commit()
  ck(f'value="{teacher["id"]}"'.encode() in b,'staff ID option')
  data=dict(action='exam_submit',window_id=1,staff_id=teacher['id'],email=teacher['email'],department=teacher['department'],programme='BSc. Agriculture',module_code='RES601',module_title='Research methods',confirmed=1,csrf=csrf(b))
  pdf=b'%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n'
  b=post(pub,'?page=exam_submit&exam_id=1',data,{'paper':('paper.pdf',pdf)})
  ck(b'Question paper received.' in b,'PDF upload succeeds');pid=db.execute('SELECT id FROM exam_papers').fetchone()[0]
  b=post(pub,'?page=exam_submit&exam_id=1',data,{'paper':('paper.pdf',pdf)})
  ck(b'already been submitted' in b,'duplicate upload denied');ck(db.execute('SELECT COUNT(*) FROM exam_papers').fetchone()[0]==1,'one saved paper')
  ck(not get(pub,f'?page=exam_download&id={pid}').startswith(b'%PDF'),'public download denied')
  data['module_code']='CON601';b=post(pub,'?page=exam_submit&exam_id=1',data,{'paper':('paper.pdf',b'not a pdf')});ck(b'Only PDF files' in b,'invalid PDF rejected');ck(b'value="CON601"' in b,'text retained on error')
  manager=client();b=get(manager,'?page=login');post(manager,'?page=login',dict(action='login',email='admin@example.test',password='CnrPreview2026!',csrf=csrf(b)))
  b=get(manager,'?page=exam_manage&exam_id=1');ck(b'Teaching staff submission status' in b and b'RES601' in b,'dashboard includes paper')
  ck(get(manager,f'?page=exam_download&id={pid}').startswith(b'%PDF'),'authorized download succeeds')
  other=client();login=get(other,'?page=login');post(other,'?page=login',dict(action='login',email='daa@example.test',password='CnrPreview2026!',csrf=csrf(login)));ck(not get(other,f'?page=exam_download&id={pid}').startswith(b'%PDF'),'DAA cannot download question papers')
  b=post(manager,'?page=exam_manage&exam_id=1',dict(action='exam_delete_paper',paper_id=pid,reason='Incorrect test paper',csrf=csrf(b)));ck(b'Paper removed' in b,'manager delete')
  b=post(pub,'?page=exam_submit&exam_id=1',dict(data,module_code='RES601'),{'paper':('paper.pdf',pdf)});ck(b'Question paper received.' in b,'replacement succeeds')
  db.execute('UPDATE exam_windows SET closes_at=?,late_enabled=1 WHERE id=1',(str(stamp-datetime.timedelta(minutes=1)),));db.commit()
  data.update(late=1,module_code='CON601');b=post(pub,'?page=exam_submit&exam_id=1',data,{'paper':('paper.pdf',pdf)});ck(b'Choose a PDF' in b,'late needs approval document')
  b=post(pub,'?page=exam_submit&exam_id=1',data,{'paper':('paper.pdf',pdf),'approval':('approval.pdf',pdf)});ck(b'Question paper received.' in b,'late PDF plus approval accepted')
  b=get(manager,'?page=exam_manage&exam_id=1');ck(b'Download approval' in b,'approval download visible')
  if os.environ.get('EXAM_SCREENSHOTS'):
   subprocess.run(['/Users/budhimanlimboo/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node',str(root.parent/'tmp/exam-review/capture.cjs')],check=True,cwd=root.parent)
  b=get(manager,'?page=exam_manage&exam_id=1');b=post(manager,'?page=exam_manage&exam_id=1',dict(action='exam_delete_window',window_id=1,reason='Remove test period',csrf=csrf(b)));ck(b'Submission period deleted' in b,'delete period HTTP')
  b=get(pub,'?page=exam_submit&exam_id=1');ck(b'<select' not in b and b'has not been announced' in b,'deleted period removed from public view')
  b=get(manager,'?page=exam_manage&show_deleted=1&exam_id=1');ck(b'Deleted period' in b and b'Download paper' in b,'deleted period archive preserved')
  b=get(manager,'?page=admin');post(manager,'?page=admin',dict(action='user',name='Record Officer',email='record@example.test',password='TestOnlyPassword123!',role='exam',department='Exam Cell',csrf=csrf(b)));ck(db.execute("SELECT department FROM users WHERE email='record@example.test'").fetchone()[0]=='Exam Cell','create account office department')
  print(f'{n} Exam Cell HTTP checks passed.')
 finally:
  srv.terminate();srv.wait()
  for row in db.execute('SELECT paper_path,approval_path FROM exam_papers'):
   for key in row:
    if key:(root/'storage'/'uploads'/key).unlink(missing_ok=True)
  db.close()
