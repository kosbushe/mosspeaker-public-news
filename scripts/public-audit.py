import json,re,time,hashlib,urllib.request,urllib.parse,concurrent.futures
from pathlib import Path
from html.parser import HTMLParser
OUT=Path('audit-output');OUT.mkdir(exist_ok=True)
class Parse(HTMLParser):
 def __init__(self):super().__init__();self.tags=[];self.h1=0;self.title=False;self.title_text='';self.links=[];self.assets=[];self.canonical=[];self.meta={};self.ids=[];self.alt_missing=0
 def handle_starttag(self,t,attrs):
  a=dict(attrs);self.tags.append((t,a));self.h1+=t=='h1'
  if t=='title':self.title=True
  if 'id' in a:self.ids.append(a['id'])
  if t=='a':self.links.append(a.get('href',''))
  if t=='img' and 'alt' not in a:self.alt_missing+=1
  if t=='link' and a.get('rel')=='canonical':self.canonical.append(a.get('href'))
  if t=='meta' and a.get('name'):self.meta[a['name']]=a.get('content')
  if t in ('img','script','source','video') and a.get('src'):self.assets.append(a['src'])
  if t=='link' and a.get('rel')=='stylesheet':self.assets.append(a['href'])
 def handle_endtag(self,t):
  if t=='title':self.title=False
 def handle_data(self,d):
  if self.title:self.title_text+=d

def read(url,method='GET'):
 start=time.monotonic();rec={'url':url}
 try:
  req=urllib.request.Request(url,method=method,headers={'User-Agent':'MosspeakerReadOnlyAudit/1.0','Cache-Control':'no-cache'})
  with urllib.request.urlopen(req,timeout=18) as r:
   body=r.read(3000000) if method=='GET' else b''
   hs={k.lower():v for k,v in r.headers.items() if k.lower() not in ('set-cookie','cf-ray')}
   cookies=[]
   for c in r.headers.get_all('Set-Cookie',[]):
    cookies.append({'name':c.split('=',1)[0],'secure':bool(re.search(r';\s*secure(?:;|$)',c,re.I)),'httponly':bool(re.search(r';\s*httponly(?:;|$)',c,re.I)),'samesite':re.search(r';\s*samesite=([^;]+)',c,re.I).group(1) if re.search(r';\s*samesite=([^;]+)',c,re.I) else None})
   rec.update(status=r.status,final_url=r.url,headers=hs,cookies=cookies,bytes_read=len(body),elapsed_ms=round((time.monotonic()-start)*1000),sha256=hashlib.sha256(body).hexdigest() if body else None)
   if 'text/html' in hs.get('content-type',''):
    text=body.decode('utf-8','replace');p=Parse();p.feed(text)
    rec['html']={'h1_count':p.h1,'title':p.title_text,'description':p.meta.get('description'),'robots':p.meta.get('robots'),'canonical':p.canonical,'missing_alt':p.alt_missing,'duplicate_ids':list({x for x in p.ids if p.ids.count(x)>1}),'links':p.links,'assets':p.assets}
    if url=='https://mosspeaker.ru/':(OUT/'home.html').write_text(text)
   return rec,body
 except Exception as e:
  rec.update(error=type(e).__name__,detail=str(e)[:160],elapsed_ms=round((time.monotonic()-start)*1000));return rec,b''
urls=['https://mosspeaker.ru/','http://mosspeaker.ru/','https://www.mosspeaker.ru/','https://mosspeaker.ru/index.html','https://mosspeaker.ru/robots.txt','https://mosspeaker.ru/sitemap.xml','https://mosspeaker.ru/podgotovka-spikerov.html','https://mosspeaker.ru/meeting','https://mosspeaker.ru/rating','https://mosspeaker.ru/privacy','https://mosspeaker.ru/consent','https://mosspeaker.ru/offer','https://mosspeaker.ru/test/','https://vote.mosspeaker.ru/','https://mosspeaker.ru/platform-v1/api/news.php?v='+str(int(time.time())),'https://mosspeaker.ru/platform-v1/knowledge.json']
results=[];bodies={}
with concurrent.futures.ThreadPoolExecutor(max_workers=4) as ex:
 for rec,body in ex.map(read,urls):results.append(rec);bodies[rec['url']]=body
# Recovery snapshot of three public, non-authenticated resources only.
for url,relative in [('https://mosspeaker.ru/test/','test/index.html'),('https://mosspeaker.ru/robots.txt','robots.txt'),('https://mosspeaker.ru/sitemap.xml','sitemap.xml')]:
 if bodies.get(url):
  target=OUT/'public-snapshot'/relative;target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(bodies[url])
knowledge=json.loads(bodies.get('https://mosspeaker.ru/platform-v1/knowledge.json')or'[]')
links=[urllib.parse.urljoin('https://mosspeaker.ru/',x['url']) for x in knowledge]
with concurrent.futures.ThreadPoolExecutor(max_workers=4) as ex:
 results.extend(rec for rec,_ in ex.map(read,links))
assets={urllib.parse.urljoin(r.get('final_url',r['url']),x) for r in results for x in r.get('html',{}).get('assets',[]) if urllib.parse.urlparse(urllib.parse.urljoin(r.get('final_url',r['url']),x)).netloc in ('mosspeaker.ru','vote.mosspeaker.ru')}
assets.add('https://mosspeaker.ru/assets/hero-loop.mp4')
with concurrent.futures.ThreadPoolExecutor(max_workers=4) as ex:
 asset_results=[r for r,_ in ex.map(lambda u:read(u,'HEAD'),sorted(assets))]
home=(OUT/'home.html').read_text();p=Parse();p.feed(home);ids=set(p.ids)
missing_anchors=[x for x in p.links if x.startswith('#') and x[1:] not in ids and x!='#']
news=next((v for k,v in bodies.items() if '/api/news.php' in k),b'{}');(OUT/'news.json').write_bytes(news)
report={'checked_at_utc':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime()),'method':'Read-only public HTTP, no forms, logins, votes, brute force or load tests','pages':results,'assets':asset_results,'missing_home_anchors':missing_anchors,'knowledge_count':len(knowledge)}
(OUT/'audit.json').write_text(json.dumps(report,ensure_ascii=False,indent=2))
print(json.dumps({'pages':len(results),'assets':len(asset_results),'knowledge_count':len(knowledge),'failed':[r['url'] for r in results+asset_results if r.get('status')!=200],'missing_anchors':missing_anchors},ensure_ascii=False))
