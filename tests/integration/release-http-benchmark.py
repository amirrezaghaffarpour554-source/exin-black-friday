"""Local real HTTP release benchmark; requires dedicated exin_release_ fixture/routers."""
from pathlib import Path
import urllib.request,urllib.parse,urllib.error,http.cookiejar,json,time,math,re,statistics,subprocess,hashlib
root=Path.cwd();out=root/'work/release-audit';results=[];checks=[];run_id=str(time.time_ns())
def client():
 jar=http.cookiejar.CookieJar();return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
def call(opener,origin,path,ident,body=None,headers=None):
 hs={'X-EXIN-AUDIT-ID':ident,**(headers or {})};data=None
 if body is not None:
  if isinstance(body,dict):data=json.dumps(body).encode();hs['Content-Type']='application/json'
  else:data=body
 request=urllib.request.Request(origin+path,data=data,headers=hs);start=time.perf_counter()
 try:
  with opener.open(request,timeout=90)as response:raw=response.read();status=response.status;response_headers=dict(response.headers);final=response.url
 except urllib.error.HTTPError as e:raw=e.read();status=e.code;response_headers=dict(e.headers);final=e.url
 return {'status':status,'body':raw,'headers':response_headers,'client_ms':(time.perf_counter()-start)*1000,'final':final}
def check(value,label):
 checks.append({'check':label,'passed':bool(value)})
 if not value:raise RuntimeError(label)
for variant,port in [('candidate',8893),('base',8894)]:
 subprocess.run(['scripts/test-v101-native.sh','wp','--exec=putenv("WORDPRESS_TABLE_PREFIX=exin_release_");','eval',"update_option('exin_bf_last_tick',gmdate('Y-m-d H:i:s'),false);"],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 origin='http://127.0.0.1:'+str(port);guest=client();admin=client();empty=client()
 call(admin,origin,'/wp-login.php','login-prime-'+variant)
 login=urllib.parse.urlencode({'log':'release_test','pwd':'exin-release-local-only','wp-submit':'Log In','redirect_to':origin+'/wp-admin/','testcookie':'1'}).encode()
 response=call(admin,origin,'/wp-login.php','login-'+variant,login,{'Content-Type':'application/x-www-form-urlencoded'})
 check(response['status']==200 and '/wp-admin/'in response['final'],variant+' authenticated admin login')
 response=call(admin,origin,'/wp-admin/admin.php?page=exin-black-friday','admin-prime-'+variant);html=response['body'].decode(errors='replace')
 match=re.search(r'var exinBFAdmin\s*=\s*(\{[^;]+\})',html);check(match is not None,variant+' real campaign admin config');nonce=json.loads(match[1])['nonce']
 cartpath='/?rest_route=/wc/store/v1/cart';response=call(guest,origin,cartpath,'cart-prime-'+variant);check(response['status']==200,variant+' cart controller')
 nonce_header=next((v for k,v in response['headers'].items()if k.lower()=='nonce'),None);check(nonce_header is not None,variant+' Store API nonce')
 response=call(guest,origin,'/?rest_route=/wc/store/v1/cart/add-item','cart-add-'+variant,{'id':13,'quantity':1},{'Nonce':nonce_header});cart=json.loads(response['body']);check(response['status']==201 or response['status']==200,variant+' real cart add');check(cart['items'][0]['prices']['price']=='6000',variant+' current manual floor price in HTTP cart')
 endpoints=[('homepage-empty',empty,'/',None,None),('shop-empty',empty,'/shop/',None,None),('festival-empty',empty,'/release-festival/',None,None),('product-empty',empty,'/product/release-product-1/',None,None),('homepage',guest,'/',None,None),('shop',guest,'/shop/',None,None),('festival',guest,'/release-festival/',None,None),('product',guest,'/product/release-product-1/',None,None),('authenticated-home',admin,'/',None,None),('dashboard',admin,'/wp-admin/',None,None),('product-admin',admin,'/wp-admin/edit.php?post_type=product',None,None),('order-admin',admin,'/wp-admin/admin.php?page=wc-orders',None,None),('campaign-admin',admin,'/wp-admin/admin.php?page=exin-black-friday',None,None),('admin-rest-products',admin,'/?rest_route=/exin-bf/v1/admin/state&campaign_id=1&view=products&page=1',None,{'X-WP-Nonce':nonce}),('wc-ajax-cart',guest,'/?wc-ajax=get_refreshed_fragments',b'',{'Content-Type':'application/x-www-form-urlencoded'}),('cart-api',guest,cartpath,None,None),('cart-page',guest,'/cart/',None,None),('checkout-page',guest,'/checkout/',None,None)]
 for context,opener,path,body,headers in endpoints:
  for sample in range(6):
   ident=f'http-{run_id}-{variant}-{context}-{sample}';response=call(opener,origin,path,ident,body,headers)
   check(response['status']==200,f'{variant} {context} HTTP200 sample{sample}')
   if context=='admin-rest-products':
    parsed=json.loads(response['body']);check(parsed['count']==10000 and len(parsed['items'])==25,f'{variant} server pagination over actual10000 sample{sample}')
   if context=='cart-api':check(json.loads(response['body'])['items'][0]['prices']['price']=='6000',f'{variant} fresh cart floor sample{sample}')
   results.append({'id':ident,'variant':variant,'context':context,'sample':sample,'warm':sample>0,'client_ms':response['client_ms'],'status':response['status'],'response_bytes':len(response['body'])})
(out/'http-client-samples.json').write_text(json.dumps({'checks':checks,'samples':results},indent=2)+'\n')
raw=[json.loads(line)for line in(out/'http-requests.jsonl').read_text().splitlines()];byid={row['id']:row for row in raw};merged=[dict(row,**{k:v for k,v in byid[row['id']].items()if k not in row})for row in results];summary=[]
assert not any(row.get('last_php_message') for row in merged), 'Review PHP warnings/errors before accepting HTTP measurements'
for variant in ['base','candidate']:
 for context in sorted({r['context']for r in merged}):
  rows=[r for r in merged if r['variant']==variant and r['context']==context and r['warm']];cold=[r for r in merged if r['variant']==variant and r['context']==context and not r['warm']][0]
  metric={'variant':variant,'context':context,'warm_samples':len(rows),'first_client_ms':cold['client_ms']}
  for key in ['client_ms','server_ms','cpu_ms','queries','plugin_queries','duplicates','writes','locks','peak_bytes']:
   values=sorted(r[key]for r in rows);metric[key+'_median']=statistics.median(values);metric[key+'_p95']=values[math.ceil(.95*len(values))-1]
  summary.append(metric)
report={'measurement_boundary':'Server SQL/CPU/time/peak metrics recorded on WordPress shutdown at PHP_INT_MAX, after ordinary WordPress/WooCommerce/Action Scheduler shutdown callbacks. Client latency spans the complete HTTP response. Later raw PHP shutdown callbacks, if any, are outside this server hook boundary.','candidate_source_sha256':{p:hashlib.sha256((root/p).read_bytes()).hexdigest()for p in ['src/Application/Scheduler.php','src/Application/FloorGuard.php','src/Plugin.php']},'fixture':{'actual_woocommerce_products':10000,'selected_campaign_products':10000,'positive_floors':9000,'missing_floors':1000},'passed_checks':sum(r['passed']for r in checks),'failed_checks':sum(not r['passed']for r in checks),'samples':merged,'summary':summary,'boundary':'Real HTTP over PHP built-in single-worker, WP7.1.4/Woo11.3/MariaDB/HPOS. TwentyTwentyOne theme, isolated exin_release_ DB prefix, SAVEQUERIES instrumentation; 1 discarded warmup and 5 measured repeated requests per route/variant. This does not flush the database/OS caches; PHP/WordPress request-local caches start fresh on every request, and no persistent object cache or PHP opcache is enabled. Both empty and populated anonymous-cart contexts are measured; financial validation remains fresh. Dedicated fixture heartbeat is refreshed before each variant to measure healthy requests. No Production or traffic-capacity benchmark. No Redis server/client/extension/drop-in installed; Redis-enabled/failure tests unavailable.'}
(out/'http-10000-results.json').write_text(json.dumps(report,indent=2)+'\n');print(json.dumps({'checks':report['passed_checks'],'failed':report['failed_checks'],'http_requests':len(merged),'actual_products':10000,'contexts':len(summary)//2,'report':str(out/'http-10000-results.json')}))
