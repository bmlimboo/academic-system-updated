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
 srv=subprocess.Popen([php,'-S','127.0.0.1:8107','-t',str(root/'public')],env=env,stdout=log,stderr=log)
 base='http://127.0.0.1:8107/'
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
  admin=login('admin');exam=login('exam');daa=login('daa');tutor=login('tutor')
  ck(b'access required' in get(tutor,'?page=rt_home'),'tutor cannot access results')
  ck(b'Results &amp; Transcripts' in get(exam,'?page=exam_portal'),'portal link')
  b=get(exam,'?page=rt_settings');settings=dict(action='rt_settings',dean='Dr. Example Dean',president='Example President',heading='SAMPLE - Academic Transcript',dz_university='༄༅། འབྲུག་རྒྱལ་འཛིན་གཙུག་ལག་སློབ་སྡེ།',dz_college='༄༅། རང་བྱུང་ཐོན་ཁུངས་མཐོ་རིམ་སློབ་གྲྭ།',csrf=csrf(b));post(exam,'?page=rt_settings',settings)
  b=get(exam,'?page=rt_home');post(exam,'?page=rt_home',dict(action='rt_create',title='Sample 2021–2025',programme='B.Sc. in Animal Science',duration=4,study_period='July 2021 – June 2025',issued_on='2025-06-30',csrf=csrf(b)))
  cid=db.execute('select id from result_cohorts').fetchone()[0];path=f'?page=rt_home&cohort_id={cid}'
  data=(root.parent/'tmp/results-review/sample.csv').read_bytes()
  b=get(exam,path);b=post(exam,path,dict(action='rt_preview',cohort_id=cid,csrf=csrf(b)),('marks.csv',data,'marks'));ck(b'Upload preview' in b,'CSV preview')
  ck(db.execute('select count(*) from result_marks').fetchone()[0]==0,'preview does not save')
  post(exam,path,dict(action='rt_commit',cohort_id=cid,confirmed=1,csrf=csrf(b)))
  ck(db.execute('select count(*) from result_marks').fetchone()[0]==37,'all 37 marks saved')
  b=get(exam,path);ck(b'73.99%' in b,'sample percentage matches Excel calculation')
  ck(b'01210041' in b,'leading zeros retained')
  printpath=f'?page=rt_print&cohort_id={cid}'
  html=get(exam,printpath);ck(html.count(b'class="result-page ')==2,'two pages per student')
  ck(b'RES402' in html and b'Example President' in html,'final semester and signatories on print')
  ck(html.count(b'class="semester-total"')==8 and html.count(b'class="semester-weight"')==8,'each semester has total and weighting')
  ck(re.findall(rb'<td colspan="3">([0-9.]+)% of the total',html)==[b'5',b'5',b'10',b'10',b'15',b'15',b'20',b'20'],'original semester weights')
  ck(b'6,120.00' in html and b'4,528.26' in html and b'73.99%' in html,'semester scale totals preserve percentage')
  ck(b'Result Information' not in html and b'page-ref' not in html and b'Calculations use' not in html,'extra printed information removed')
  ck(b'Access denied' in get(tutor,printpath),'tutor cannot print results')
  b=get(exam,path);post(exam,path,dict(action='rt_preview',cohort_id=cid,csrf=csrf(b)),('marks.csv',data,'marks'));post(exam,path,dict(action='rt_commit',cohort_id=cid,confirmed=1,csrf=csrf(b)));ck(db.execute('select count(*) from result_marks').fetchone()[0]==37,'reupload idempotent')
  bad=data.replace(b'67.7',b'167.7');b=get(exam,path);b=post(exam,path,dict(action='rt_preview',cohort_id=cid,csrf=csrf(b)),('marks.csv',bad,'marks'));ck(b'Mark exceeds maximum' in b,'out of range mark rejected')
  conflict=data.replace(b'67.7',b'66.7');b=post(exam,path,dict(action='rt_preview',cohort_id=cid,csrf=csrf(b)),('marks.csv',conflict,'marks'));ck(b'Conflicting saved mark' in b,'conflict requires correction')
  rev=db.execute('select revision from result_cohorts').fetchone()[0];b=get(daa,path);post(daa,path,dict(action='rt_finalize',cohort_id=cid,revision=rev,reason='Checked fixture',confirmed=1,csrf=csrf(b)));ck(db.execute('select status from result_cohorts').fetchone()[0]=='Finalized','DAA finalizes complete batch')
  b=get(exam,path);b=post(exam,path,dict(action='rt_preview',cohort_id=cid,csrf=csrf(b)),('marks.csv',data,'marks'));ck(b'Reopen the batch' in b,'finalized marks locked')
  # Save authenticated render for visual checks and PDF generation with local assets.
  html=get(exam,printpath).decode().replace('assets/results/','file://'+str(root/'public/assets/results')+'/').replace('?page=rt_logo&amp;key='+locals().get('logo','UNUSED'),'file://'+str(root/'public/assets/results/cnr.png'))
  (root.parent/'tmp/results-review/sample.html').write_text(html)
  # Settings and uploaded logos cannot silently alter an existing batch snapshot.
  b=get(exam,'?page=rt_settings');settings['csrf']=csrf(b);settings['dean']='Replacement Dean'
  post(exam,'?page=rt_settings',settings,('logo.png',(root/'public/assets/results/cnr.png').read_bytes(),'cnr_logo'))
  saved_settings=json.loads(db.execute('select payload from result_settings').fetchone()[0]);logo=saved_settings['cnr_logo']
  ck(logo.endswith('.png') and '/' not in logo,'uploaded logo stored with safe name')
  ck(get(exam,'?page=rt_logo&key='+logo).startswith(b'\x89PNG'),'authorized private logo route')
  ck(b'Access denied' in get(tutor,'?page=rt_logo&key='+logo),'private logo role protected')
  ck(b'Dr. Example Dean' in get(exam,printpath),'existing signatory snapshot unchanged')
  rev=db.execute('select revision from result_cohorts where id=?',(cid,)).fetchone()[0]
  b=get(exam,path);post(exam,path,dict(action='rt_reopen',cohort_id=cid,revision=rev,reason='Correct test mark',csrf=csrf(b)))
  b=get(exam,path);b=post(exam,path,dict(action='rt_preview',cohort_id=cid,correction=1,reason='Correct transcription',csrf=csrf(b)),('marks.csv',conflict,'marks'))
  ck(b'Upload preview' in b,'correction preview')
  post(exam,path,dict(action='rt_commit',cohort_id=cid,confirmed=1,csrf=csrf(b)))
  ck(float(db.execute("select mark from result_marks where cohort_id=? and module_code='VSC101'",(cid,)).fetchone()[0])==66.7,'correction applied')
  ck('Correct transcription' in db.execute("select details from result_audit where action='Marks imported' order by id desc").fetchone()[0],'correction audit saved')
  # An intervening metadata edit invalidates the staged import.
  b=get(exam,path);post(exam,path,dict(action='rt_preview',cohort_id=cid,csrf=csrf(b)),('marks.csv',conflict,'marks'))
  rev=db.execute('select revision from result_cohorts where id=?',(cid,)).fetchone()[0]
  b=get(exam,path);post(exam,path,dict(action='rt_metadata',cohort_id=cid,revision=rev,title='Updated batch',programme='B.Sc. in Animal Science',study_period='July 2021 – June 2025',issued_on='2025-06-30',reason='Metadata check',csrf=csrf(b)))
  b=get(exam,path);b=post(exam,path,dict(action='rt_commit',cohort_id=cid,confirmed=1,csrf=csrf(b)));ck(b'Batch changed' in b,'stale staged import rejected')
  # Read an actual XLSX upload through the same PHP reader used in production.
  fixture=pathlib.Path(td)/'marks.xlsx'
  code='require '+json.dumps(str(root/'src/bootstrap.php'))+r'; $b=new \PhpOffice\PhpSpreadsheet\Spreadsheet(); $s=$b->getActiveSheet(); $rows=json_decode(file_get_contents('+json.dumps(str(root.parent/'tmp/results-review/sample.json'))+r'),true); array_unshift($rows,rtHeaders()); foreach($rows as $i=>$row)foreach($row as $j=>$v)$s->setCellValueExplicit([$j+1,$i+1],(string)$v,\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING); (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($b))->save('+json.dumps(str(fixture))+');'
  subprocess.run([php,'-r',code],env=env,check=True)
  b=get(exam,path);b=post(exam,path,dict(action='rt_preview',cohort_id=cid,correction=1,reason='Restore sample',csrf=csrf(b)),('marks.xlsx',fixture.read_bytes(),'marks'))
  ck(b'Upload preview' in b and b'01210041' in b,'XLSX upload and leading zeros')
  # Keep user-provided sample unchanged; restore the original approved final marks.
  post(exam,path,dict(action='rt_commit',cohort_id=cid,confirmed=1,csrf=csrf(b)))
  # Build two-year and three-year sample batches and verify weighting, completeness.
  for duration,expected in [(2,'75.16%'),(3,'73.23%')]:
   b=get(admin,'?page=rt_home');post(admin,'?page=rt_home',dict(action='rt_create',title=f'{duration} year sample',programme='Diploma in Agriculture' if duration==2 else 'BSc. Sustainable Development',duration=duration,study_period='July 2021 – June 2024',issued_on='2025-06-30',csrf=csrf(b)))
   newcid=db.execute('select max(id) from result_cohorts').fetchone()[0];newpath=f'?page=rt_home&cohort_id={newcid}'
   import csv
   rr=list(csv.reader(io.StringIO(data.decode()))); subset=[rr[0]]+[x for x in rr[1:] if int(x[2])<=duration*2];out=io.StringIO();csv.writer(out).writerows(subset)
   b=get(admin,newpath);post(admin,newpath,dict(action='rt_preview',cohort_id=newcid,csrf=csrf(b)),('marks.csv',out.getvalue().encode(),'marks'));post(admin,newpath,dict(action='rt_commit',cohort_id=newcid,confirmed=1,csrf=csrf(b)))
   ck(db.execute('select count(*) from result_marks where cohort_id=?',(newcid,)).fetchone()[0]==len(subset)-1,f'{duration} year import')
   html=get(admin,f'?page=rt_print&cohort_id={newcid}').decode().replace('assets/results/','file://'+str(root/'public/assets/results')+'/').replace('?page=rt_logo&amp;key='+locals().get('logo','UNUSED'),'file://'+str(root/'public/assets/results/cnr.png'));(root.parent/f'tmp/results-review/sample-{duration}.html').write_text(html)
  # 100-student batch render: logos are URLs, not repeated multi-megabyte base64 data.
  template=db.execute('select * from result_marks where cohort_id=?',(newcid,)).fetchall()
  for number in range(2,101):
   for row in template:db.execute('insert into result_marks(cohort_id,student_number,student_name,semester,module_code,module_title,passing_year,maximum,mark,credits) values(?,?,?,?,?,?,?,?,?,?)',(newcid,f'TEST{number:04d}',f'Fictional Student {number}',row['semester'],row['module_code'],row['module_title'],row['passing_year'],row['maximum'],row['mark'],row['credits']))
  db.commit();bulk=get(admin,f'?page=rt_print&cohort_id={newcid}')
  ck(bulk.count(b'class="result-page ')==200,'100 students produce 200 alternating front/back pages')
  ck(len(bulk)<3000000,'bulk HTML avoids repeating image binaries')
  (root.parent/'tmp/results-review/bulk.html').write_text(bulk.decode().replace('assets/results/','file://'+str(root/'public/assets/results')+'/').replace('?page=rt_logo&amp;key='+locals().get('logo','UNUSED'),'file://'+str(root/'public/assets/results/cnr.png')))
  listing=get(admin,'?page=rt_home');ck(b'<th>Created</th><th>Actions</th>' in listing and b'manage=edit' in listing and b'manage=delete' in listing,'actions follow Created')
  edit=get(admin,newpath+'&manage=edit');ck(b'id="rt-edit" open' in edit,'edit opens batch details')
  before=db.execute('select count(*) from result_marks').fetchone()[0]
  b=get(admin,newpath+'&manage=delete');rev=db.execute('select revision from result_cohorts where id=?',(newcid,)).fetchone()[0]
  ck(db.execute('select count(*) from result_marks').fetchone()[0]==before,'delete link does not mutate data')
  payload=dict(action='rt_delete',cohort_id=newcid,revision=rev,reason='Remove test batch',confirmed=1,csrf=csrf(b))
  post(admin,newpath,dict(payload,confirmed=''));ck(db.execute('select count(*) from result_cohorts where id=?',(newcid,)).fetchone()[0]==1,'confirmation required')
  post(admin,newpath,dict(payload,revision=rev-1));ck(db.execute('select count(*) from result_cohorts where id=?',(newcid,)).fetchone()[0]==1,'stale deletion blocked')
  post(admin,newpath,dict(payload,reason=''));ck(db.execute('select count(*) from result_cohorts where id=?',(newcid,)).fetchone()[0]==1,'reason required')
  tb=get(tutor,'?page=exam_portal');post(tutor,newpath,dict(payload,csrf=csrf(tb)));ck(db.execute('select count(*) from result_cohorts where id=?',(newcid,)).fetchone()[0]==1,'tutor cannot delete')
  post(admin,newpath,payload);ck(db.execute('select count(*) from result_marks where cohort_id=?',(newcid,)).fetchone()[0]==0 and db.execute('select count(*) from result_cohorts where id=?',(newcid,)).fetchone()[0]==0,'admin deletes draft and marks')
  audit=json.loads(db.execute("select details from result_audit where cohort_id=? and action='Batch deleted'",(newcid,)).fetchone()[0]);ck(len(audit['marks'])==len(template)*100 and audit['reason']=='Remove test batch','deletion snapshot retained')
  ck(db.execute('select count(*) from result_marks where cohort_id=?',(cid,)).fetchone()[0]==37,'other batch unaffected')
  db.execute("update result_cohorts set status='Finalized' where id=?",(cid,));db.commit()
  b=get(daa,path+'&manage=edit');ck(b'Reopen for correction' in b,'finalized edit requires reopen')
  b=get(daa,path+'&manage=delete');rev=db.execute('select revision from result_cohorts where id=?',(cid,)).fetchone()[0]
  post(daa,path,dict(action='rt_delete',cohort_id=cid,revision=rev,reason='Delete finalized test',confirmed=1,csrf=csrf(b)))
  ck(db.execute('select count(*) from result_cohorts where id=?',(cid,)).fetchone()[0]==0,'DAA can delete finalized batch')
  log.flush();log.seek(0);logs=log.read();ck('Fatal error' not in logs and 'PHP Warning' not in logs,'no PHP warnings')
  print(f'{n} results HTTP checks passed.')
 finally:
  for file in (root/'storage/result-logos').glob('*'):
   if 'logo' in locals() and file.name==logo:file.unlink()
  srv.terminate();srv.wait();log.close()
