#!/usr/bin/env python3
"""Exercise the actual PHP HTTP endpoints in an isolated copy, never production."""
import base64, concurrent.futures, hashlib, html, http.cookiejar, json, os, pathlib, secrets, shutil, socket, subprocess, tempfile, time, urllib.error, urllib.request

ROOT=pathlib.Path(__file__).resolve().parents[1]
temp=pathlib.Path(tempfile.mkdtemp(prefix='sp49-cms-test-'))
web=temp/'web'; shutil.copytree(ROOT/'dist',web)
private=temp/'private'
sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close()
origin=f'http://127.0.0.1:{port}'
key=secrets.token_hex(32)
env=os.environ.copy();env.update(SP49_TEST_DATA=str(private),SP49_TEST_KEY_HASH=hashlib.sha256(key.encode()).hexdigest())
log=open(temp/'php.log','w+')
process=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(web)],env=env,stdout=log,stderr=log)
PASS=secrets.token_urlsafe(24)
checks=[]

class Client:
    def __init__(self):
        self.jar=http.cookiejar.CookieJar();self.opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar));self.csrf=''
    def request(self,action,data=None,code=200,csrf=True,raw=None,headers=None):
        h={'Origin':origin};h.update(headers or {})
        if data is not None or raw is not None:
            if csrf:h['X-CSRF-Token']=self.csrf
            if raw is None:raw=json.dumps(data).encode();h['Content-Type']='application/json'
        req=urllib.request.Request(origin+'/admin/api.php?action='+action,data=raw,headers=h)
        try:r=self.opener.open(req,timeout=20)
        except urllib.error.HTTPError as e:r=e
        body=r.read();assert r.status==code,(action,r.status,body[:700])
        value=json.loads(body) if 'application/json' in r.headers.get('Content-Type','') else body
        if isinstance(value,dict) and value.get('csrf'):self.csrf=value['csrf']
        return value
    def upload(self,name,body,mime,rev,code=200):
        boundary='----sp49test'+secrets.token_hex(8)
        data=(f'--{boundary}\r\nContent-Disposition: form-data; name="revision"\r\n\r\n{rev}\r\n--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="{name}"\r\nContent-Type: {mime}\r\n\r\n').encode()+body+f'\r\n--{boundary}--\r\n'.encode()
        return self.request('upload',raw=data,headers={'Content-Type':'multipart/form-data; boundary='+boundary},code=code)

def ok(name):checks.append(name)
def cdata(c):return c.request('data')['content']
def post_form(body,expected):
    req=urllib.request.Request(origin+'/admin/inquiry.php',data=json.dumps(body).encode(),headers={'Content-Type':'application/json','Origin':origin})
    try:r=urllib.request.urlopen(req,timeout=15)
    except urllib.error.HTTPError as e:r=e
    val=json.loads(r.read());assert r.status==expected,(r.status,val);return val

try:
    for attempt in range(100):
        try:urllib.request.urlopen(origin+'/admin/',timeout=1);break
        except Exception:time.sleep(.05)
    owner=Client();state=owner.request('status');assert not state['installed'] and state['setupReady'];ok('uninstalled state and CSRF token')
    owner.request('data',code=401);owner.request('setup',{'key':key},csrf=False,code=403);ok('authentication and CSRF boundaries')
    payload={'key':'bad','name':'Local Owner','email':'owner@example.invalid','password':PASS,'passwordRepeat':PASS}
    owner.request('setup',payload,code=403);payload['key']=key
    setup=owner.request('setup',payload);assert setup['user']['role']=='owner';owner.request('setup',payload,code=403);ok('one-time owner setup and replay rejection')
    cookies=list(owner.jar);assert cookies and cookies[0]._rest.get('HttpOnly') is None and cookies[0]._rest.get('SameSite')=='Strict';ok('HttpOnly and SameSite session cookies')
    c=cdata(owner);assert len(c['products'])==21 and len(c['categories'])==9 and len(c['brands'])==26 and len(c['pages'])==11;ok('initial content migration')
    prod={'id':'test-filter','name':'Фильтр <strong>проверка</strong>','article':'TST-001','brand':'MCP','cat':'filters','types':['bulldozer'],'stock':'in_stock','quantity':7,'price':1250.5,'image':'assets/filter.svg','desc':'Описание тестового товара','specs':'Размер: 100 мм\nМатериал: сталь','published':True,'example':False,'seoTitle':'Тестовый фильтр — каталог','seoDescription':'Проверка SEO товара'}
    old=c['revision'];c=owner.request('entitySave',{'revision':old,'collection':'products','item':prod})['content']
    owner.request('entitySave',{'revision':old,'collection':'products','item':prod},code=409);ok('product CRUD and optimistic concurrency')
    bad=dict(prod,id='../../bad');owner.request('entitySave',{'revision':c['revision'],'collection':'products','item':bad},code=400)
    bad=dict(prod,image='javascript:alert(1)');owner.request('entitySave',{'revision':c['revision'],'collection':'products','item':bad},code=400);ok('path and unsafe URL validation')
    page=dict(c['pages']['about.html']);page['html']=page['html'].replace('</main>','<script>alert("unsafe")</script><img src="x" onerror="alert(1)"></main>');page['title']='Новый SEO-заголовок'
    c=owner.request('pageSave',{'revision':c['revision'],'page':page})['content'];assert 'onerror=' not in c['pages']['about.html']['html'] and 'alert("unsafe")' not in c['pages']['about.html']['html'];ok('HTML script and event-handler sanitization')
    post_form({'name':'Test','email':'customer@example.invalid','message':'Тестовое обращение','consent':True},503);ok('public inquiry disabled until configured')
    s=dict(c['settings']);s.update(siteUrl=origin,email='company@example.invalid',inquiriesEnabled=True,mailNotifications=False,slogan='Тестовый слоган',phone='+7 000 000-00-00')
    c=owner.request('settingsSave',{'revision':c['revision'],'settings':s})['content'];ok('settings, contacts and draft separation')
    before=(web/'index.html').read_bytes();assert 'Тестовый слоган' not in before.decode();r=owner.request('publish',{'revision':c['revision']});c=r['content'];assert c['publishedRevision']==c['revision'];ok('explicit publication')
    home=html.unescape((web/'index.html').read_text());about=html.unescape((web/'about.html').read_text());part=html.unescape((web/'part-test-filter.html').read_text());bundle=(web/'assets/cms-data.js').read_text()
    assert 'Тестовый слоган' in home and 'Тестовый слоган' in about and 'Новый SEO-заголовок' in about
    assert 'canonical' in home and origin in home and 'index, follow' in home
    assert 'assets/app.js?v=' in home and 'assets/cms-data.js?v=cms-' in home
    assert 'Тестовый фильтр — каталог' in part and '1250' not in part and '1 250,5' in part and 'data-product-id="test-filter"' in part
    assert 'Фильтр &lt;strong&gt;' in (web/'part-test-filter.html').read_text().replace('&#1060;&#1080;&#1083;&#1100;&#1090;&#1088;','Фильтр') and PASS not in bundle and '"hash"' not in bundle and 'test-filter' in (web/'sitemap.xml').read_text();ok('published HTML, product SEO, escaped labels and sitemap')
    assert 'Disallow: /admin/' in (web/'robots.txt').read_text();ok('admin excluded from indexing')
    accepted=post_form({'name':'Test Customer','email':'customer@example.invalid','message':'Тестовое обращение','consent':True},200);assert accepted['id'];messages=owner.request('inquiries')['inquiries'];assert messages[0]['name']=='Test Customer';ok('private inquiry storage')
    post_form({'name':'Test','email':'bad','message':'Text','consent':True},400);post_form({'name':'Test','email':'customer@example.invalid','message':'Text','consent':False},400);ok('public contact validation and consent')
    png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6bXwAAAAASUVORK5CYII=')
    r=owner.upload('sample.png',png,'image/png',c['revision']);c=r['content'];asset=r['uploaded']['path'];assert (web/asset).is_file()
    assert ((web/'assets/uploads').stat().st_mode & 0o777)==0o755
    owner.upload('evil.php.png',b'<?php system($_GET["x"]); ?>','image/png',c['revision'],400)
    owner.upload('bad.svg',b'<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>','image/svg+xml',c['revision'],400);ok('media uploads and executable upload rejection')
    c=owner.request('mediaSave',{'revision':c['revision'],'path':asset,'name':'Test','alt':'Описание','archived':True})['content'];assert (web/asset).is_file();ok('recoverable media archive')
    csv='article;name;cat;price;quantity;published\nTST-001;Обновлённый фильтр;filters;2000;8;1\n'
    preview=owner.request('importProducts',{'revision':c['revision'],'csv':csv,'dryRun':True})['plan'];assert preview['update']==1 and not preview['errors']
    c=owner.request('importProducts',{'revision':c['revision'],'csv':csv,'dryRun':False})['content'];assert len([p for p in c['products'] if p.get('article')=='TST-001'])==1;ok('CSV preview and article-based update')
    published=(web/'index.html').read_bytes();history=owner.request('history')['history'];assert history
    c=owner.request('restore',{'revision':c['revision'],'id':history[0]['id']})['content'];assert (web/'index.html').read_bytes()==published;ok('restore to draft without changing live site')
    backup=owner.request('export');assert backup['format']=='sp49-cms-v1' and 'users' not in backup['content'] and PASS not in json.dumps(backup);ok('backup excludes credentials and customer inquiries')
    invalid=json.loads(json.dumps(backup));invalid['content']['pages']['index.html']['file']='../../owned.html';owner.request('restoreFile',{'revision':c['revision'],'backup':invalid},code=400);ok('backup import traversal rejection')
    owner.request('userSave',{'user':{'name':'Local Editor','email':'editor@example.invalid','role':'editor','active':True,'password':PASS}})
    editor=Client();editor.request('status');editor.request('login',{'email':'editor@example.invalid','password':PASS});editor.request('users',code=403);editor.request('settingsSave',{'revision':c['revision'],'settings':s},code=403);editor.request('inquiries',code=403);ok('editor cannot manage access, settings or personal inquiries')
    ec=cdata(editor);prod['name']='Редактор изменил товар';prod['price']=1800;c=editor.request('entitySave',{'revision':ec['revision'],'collection':'products','item':prod})['content'];ok('editor can edit website content')
    editor.request('logout',{});editor.request('data',code=401);ok('logout invalidates access')
    owner.request('password',{'currentPassword':PASS,'password':PASS+'2','passwordRepeat':PASS+'2'});owner.request('logout',{});owner.request('login',{'email':'owner@example.invalid','password':PASS},code=401);owner.request('login',{'email':'owner@example.invalid','password':PASS+'2'});ok('password change and old credential rejection')
    attacker=Client();attacker.request('status')
    for i in range(6):attacker.request('login',{'email':'nobody@example.invalid','password':'wrong'},code=401)
    attacker.request('login',{'email':'nobody@example.invalid','password':'wrong'},code=429);ok('login rate limiting')
    digest=json.loads((private/'auth.json').read_text())['users'][0]['hash']
    for path in ['/admin/lib.php','/.specparts49-cms/auth.json','/admin/install-key.php']:
        try:
            with urllib.request.urlopen(origin+path) as r: body=r.read().decode('utf-8','replace')
            # PHP's development server can return its parent index for a missing path.
            assert digest not in body and '<?php' not in body and '"users"' not in body,(path,body[:200])
        except urllib.error.HTTPError as e:assert e.code==404,(path,e.code)
    assert private.exists() and not str(private).startswith(str(web));ok('runtime data outside public directory')
    c=cdata(owner);c=owner.request('entityArchive',{'revision':c['revision'],'collection':'products','id':'test-filter','archived':True})['content'];owner.request('publish',{'revision':c['revision']});assert 'noindex' in (web/'part-test-filter.html').read_text() and 'part-test-filter' not in (web/'sitemap.xml').read_text();ok('archived product removes public price and search entry')
    owner.request('pageSave',{'revision':cdata(owner)['revision'],'page':dict(c['pages']['index.html'],archived=True)},code=400);ok('homepage availability guard')
    log.flush();log.seek(0);logs=log.read();assert 'PHP Fatal' not in logs and 'PHP Warning' not in logs,logs[-3000:]
    result={'passed':len(checks),'checks':checks,'warnings':False};print(json.dumps(result,ensure_ascii=False,indent=2))
    (ROOT/'tests/cms-last-result.json').write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n')
finally:
    process.terminate();process.wait(timeout=5);log.close();shutil.rmtree(temp)
