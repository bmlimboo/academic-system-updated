import urllib.request, urllib.parse, http.cookiejar, re
base='http://127.0.0.1:8086/'
count=0
for role in ['admin','daa','hod','pl','exam','librarian']:
    opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    body=opener.open(base+'?page=login').read().decode()
    csrf=re.search(r'name="csrf" value="([^"]+)"',body).group(1)
    result=opener.open(base,urllib.parse.urlencode({'csrf':csrf,'action':'login','email':role+'@example.test','password':'CnrPreview2026!'}).encode()).read().decode()
    assert 'Sign out' in result,role
    routes=['?module='+m for m in ['substitution','consultation','exam','library']]
    routes+=['?page=timetable','?page=reports','?page=account','?page=new&module=consultation']
    if role!='student': routes+=['?page=new&module='+m for m in ['substitution','exam','library']]
    if role in ['admin','daa','pl','librarian']:routes+=['?page=documents']
    if role=='admin':routes+=['?page=admin','?page=import','?page=edit_user&id=2']
    for route in routes:
        response=opener.open(base+route)
        text=response.read().decode()
        assert response.status==200 and '<h1>' in text,(role,route)
        assert not any(x in text for x in ['Fatal error','Warning:','Parse error']),(role,route)
        count+=1
print(f'{count} authenticated HTTP route checks passed across 6 official roles.')
