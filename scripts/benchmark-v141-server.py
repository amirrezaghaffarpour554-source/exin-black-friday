#!/usr/bin/env python3
"""Read-only HTTP ABC/CBA benchmark on explicitly supplied isolated server clones."""
import argparse,urllib.request,urllib.error,http.cookiejar,time,uuid,json,statistics,math
p=argparse.ArgumentParser();p.add_argument('--baseline',required=True);p.add_argument('--candidate',required=True);p.add_argument('--disabled',required=True);p.add_argument('--path',action='append',required=True);p.add_argument('--pairs',type=int,default=30);p.add_argument('--warmup',type=int,default=3);p.add_argument('--output',required=True);p.add_argument('--server-metrics')
for variant in ['baseline','candidate','disabled']:p.add_argument('--'+variant+'-cookies')
a=p.parse_args()
if a.pairs<30:raise SystemExit('At least 30 paired cycles are required')
clients={}
for v in ['baseline','candidate','disabled']:
 jar=http.cookiejar.MozillaCookieJar()
 if getattr(a,v+'_cookies'):jar.load(getattr(a,v+'_cookies'),ignore_discard=True,ignore_expires=False)
 clients[v]=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
def request(v,path):
 ident='exin-'+uuid.uuid4().hex;url=getattr(a,v).rstrip('/')+'/'+path.lstrip('/');req=urllib.request.Request(url,headers={'X-EXIN-BENCH-ID':ident});start=time.perf_counter()
 try:
  with clients[v].open(req,timeout=120)as r:body=r.read();status=r.status;headers=dict(r.headers)
 except urllib.error.HTTPError as e:body=e.read();status=e.code;headers=dict(e.headers)
 return {'id':ident,'variant':v,'path':path,'ms':(time.perf_counter()-start)*1000,'status':status,'bytes':len(body),'cache_headers':{k:v for k,v in headers.items()if k.lower()in ['age','x-cache','x-rocket-cache','cf-cache-status']}}
rows=[]
for path in a.path:
 for v in clients:
  for _ in range(a.warmup):request(v,path)
 for cycle in range(a.pairs):
  for v in (['baseline','candidate','disabled'] if cycle%2==0 else ['disabled','candidate','baseline']):rows.append(request(v,path))
metrics={}
if a.server_metrics:
 with open(a.server_metrics)as f:
  for line in f:
   record=json.loads(line);metrics[record['id']]=record
for row in rows:row['server']=metrics.get(row['id'])
summary=[]
for path in a.path:
 for v in clients:
  group=[r for r in rows if r['path']==path and r['variant']==v];times=sorted(r['ms']for r in group);summary.append({'variant':v,'path':path,'samples':len(times),'median_ms':statistics.median(times),'p95_ms':times[math.ceil(.95*len(times))-1],'variance_ms2':statistics.variance(times),'non_200':sum(r['status']!=200 for r in group)})
with open(a.output,'w')as f:json.dump({'release_gate':'UNVERIFIED: inspect correctness, full-page cache and mandatory infrastructure separately','summary':summary,'samples':rows,'boundary':'GET only; no automatic plugin switches or cache flushes; missing server metrics remain null. ABC/CBA controls ordering, not server equivalence. Disable full-page caching on isolated clones explicitly; test warm/cold Redis in separate runs without global flush.'},f,indent=2)
print(json.dumps({'requests':len(rows),'output':a.output,'missing_server_metrics':sum(r['server']is None for r in rows)}))
