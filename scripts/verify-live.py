import json,time,urllib.request
from datetime import datetime
from pathlib import Path
expected=json.loads(Path('feed.json').read_text())['checked_at']
# Wait for the next native SpaceWeb cron, without requesting the site beforehand.
deadline=(int(time.time())//300+1)*300+25
print("Waiting for the next SpaceWeb cron boundary",flush=True)
time.sleep(max(0,deadline-time.time()))
# Public metadata only; no hosting credentials or private endpoints.
for attempt in range(9):
 try:
  url='https://mosspeaker.ru/platform-v1/api/news.php?v='+str(int(time.time()))
  req=urllib.request.Request(url,headers={'Cache-Control':'no-cache','User-Agent':'MosspeakerNewsVerification/1.0'})
  with urllib.request.urlopen(req,timeout=12) as r: live=json.load(r)
  stamp=datetime.fromisoformat(live.get('cache_checked_at','').replace('Z','+00:00'))
  cron_pulse=stamp.minute%5==0 and stamp.second<15 and 5<=time.time()-stamp.timestamp()<300
  good=cron_pulse and live.get('checked_at','')>=expected and all(s.get('status')=='updated' for s in live.get('sources',[])) and len(live.get('sources',[]))==2
  print(json.dumps({'attempt':attempt+1,'expected':expected,'source_checked_at':live.get('checked_at'),'cache_checked_at':live.get('cache_checked_at'),'item_count':len(live.get('items',[])),'cron_pulse':cron_pulse,'ok':good},ensure_ascii=False),flush=True)
  if good: break
 except Exception as e: print('Live verification unavailable: '+type(e).__name__,flush=True)
 if attempt<8:time.sleep(25)
else:raise SystemExit('Live site did not confirm the new feed within the verification window')
