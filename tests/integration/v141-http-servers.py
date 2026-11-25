from pathlib import Path
import os,subprocess,json
root=Path.cwd();work=root/'work/v141';wp=os.environ['EXIN_NATIVE_WP'];servers=[]
for name in ['src/Application/FloorGuard.php','assets/admin.js','assets/admin.css']:
 assert (root/'work/v141/base'/name).is_file(), 'Extract exact optimized base source AND assets before HTTP comparison: '+name
for variant,port in [('candidate',8903),('base',8904),('excluded',8905)]:
 env=os.environ.copy();env['WORDPRESS_TABLE_PREFIX']='exin_v141_';env['EXIN_RELEASE_VARIANT']=variant
 log=(work/(variant+'-server.log')).open('ab');p=subprocess.Popen([str(root/'scripts/php-v101.sh'),'-S','127.0.0.1:'+str(port),'-t',wp,str(root/'tests/integration/v141-http-router.php')],cwd=root,env=env,stdout=log,stderr=log,start_new_session=True);servers.append({'variant':variant,'port':port,'pid':p.pid})
(work/'http-servers.json').write_text(json.dumps(servers));print(json.dumps(servers))
