from pathlib import Path
import json,os,subprocess,time,traceback
root=Path(__file__).resolve().parents[2];base=root/'work/v101';jobs=base/'jobs';jobs.mkdir(exist_ok=True);jobs.chmod(0o700)
ram=Path('/dev/shm/exin-v101');ram.mkdir(parents=True,exist_ok=True);ram.chmod(0o700)
wp=ram/'wordpress'
(base/'native-worker.pid').write_text(str(os.getpid()))
(base/'native-worker-ready.json').write_text(json.dumps({'pid':os.getpid(),'ram_root':str(ram),'queue':str(jobs)})+'\n')
print('Native RAM worker ready; command queue '+str(jobs),flush=True)
while True:
 found=False
 for request in sorted(jobs.glob('*.request.json')):
  found=True;stem=request.name.removesuffix('.request.json');running=jobs/(stem+'.running.json');request.rename(running)
  start=time.monotonic();stdout=jobs/(stem+'.stdout');stderr=jobs/(stem+'.stderr')
  try:
   data=json.loads(running.read_text());op=data['operation'];args=data.get('args',[])
   assert isinstance(args,list) and all(isinstance(x,str) for x in args)
   args=[x.replace('/var/www/html',str(wp)) for x in args]
   env=os.environ.copy();env['EXIN_NATIVE_RAM']=str(ram);env['EXIN_NATIVE_WP']=str(wp)
   if op=='php':cmd=[str(root/'scripts/php-v101.sh'),*args]
   elif op=='wp':cmd=[str(root/'scripts/php-v101.sh'),str(root/'work/runtime/wp-cli.phar'),'--path='+str(wp),'--allow-root',*args]
   elif op=='setup':cmd=['bash',str(root/'scripts/runtime/native-setup.sh'),*args]
   else:raise ValueError('Unsupported native operation')
   with stdout.open('wb') as out,stderr.open('wb') as err:
    run=subprocess.run(cmd,cwd=root,env=env,stdout=out,stderr=err,timeout=600)
   result={'exit_code':run.returncode,'seconds':round(time.monotonic()-start,3)}
  except BaseException as error:
   stderr.write_text(traceback.format_exc());result={'exit_code':99,'seconds':round(time.monotonic()-start,3)}
  temp=jobs/(stem+'.result.tmp');temp.write_text(json.dumps(result)+'\n');temp.rename(jobs/(stem+'.result.json'));running.unlink()
 if not found:time.sleep(.15)
