import json,time,urllib.request
from pathlib import Path
expected=json.loads(Path('feed.json').read_text())['checked_at']
# Public metadata only; no hosting credentials or private endpoints.
for attempt in range(9):
 try:
  url='https://mosspeaker.ru/platform-v1/api/news.php?v='+str(int(time.time()))
  req=urllib.request.Request(url,headers={'Cache-Control':'no-cache','User-Agent':'MosspeakerNewsVerification/1.0'})
  with urllib.request.urlopen(req,timeout=12) as r: live=json.load(r)
  good=live.get('checked_at','')>=expected and all(s.get('status')=='updated' for s in live.get('sources',[])) and len(live.get('sources',[]))==2
  print(json.dumps({'attempt':attempt+1,'expected':expected,'source_checked_at':live.get('checked_at'),'cache_checked_at':live.get('cache_checked_at'),'item_count':len(live.get('items',[])),'ok':good},ensure_ascii=False),flush=True)
  if good: break
 except Exception as e: print('Live verification unavailable: '+type(e).__name__,flush=True)
 if attempt<8:time.sleep(25)
else:raise SystemExit('Live site did not confirm the new feed within the verification window')
