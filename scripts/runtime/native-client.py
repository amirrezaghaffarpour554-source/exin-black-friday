from pathlib import Path
import json,os,sys,time,uuid
root=Path(__file__).resolve().parents[2];base=root/'work/v101';jobs=base/'jobs';jobs.mkdir(exist_ok=True)
args=sys.argv[1:]
if not args or args[0] not in ('php','wp','setup'):raise SystemExit('Usage: native client php|wp|setup <arguments>')
pidfile=base/'native-worker.pid'
if not pidfile.exists():raise SystemExit('The private native test worker is not running')
try:
 pid=int(pidfile.read_text());os.kill(pid,0)
 if 'native-worker.py' not in (Path('/proc')/str(pid)/'cmdline').read_bytes().decode():raise OSError('Stale native worker PID')
except OSError:raise SystemExit('The private native test worker has stopped')
stem=uuid.uuid4().hex;request=jobs/(stem+'.request.json');temp=jobs/(stem+'.request.tmp')
temp.write_text(json.dumps({'operation':args[0],'args':args[1:]})+'\n');temp.rename(request)
result=jobs/(stem+'.result.json');deadline=time.monotonic()+620
while not result.exists():
 if time.monotonic()>deadline:raise SystemExit('Native test job exceeded deadline')
 time.sleep(.15)
for suffix,target in [('.stdout',sys.stdout.buffer),('.stderr',sys.stderr.buffer)]:
 p=jobs/(stem+suffix)
 if p.exists():target.write(p.read_bytes());target.flush()
code=json.loads(result.read_text())['exit_code']
for p in jobs.glob(stem+'.*'):p.unlink()
raise SystemExit(code)
