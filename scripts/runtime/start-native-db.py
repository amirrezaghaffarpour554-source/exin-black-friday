from pathlib import Path
import os,subprocess
base=Path(__file__).resolve().parents[2]/'work/v101'
root=base/'native-db-root'
data=base/'native-db';data.mkdir(exist_ok=True);data.chmod(0o700)
server=root/'usr/sbin/mariadbd';share=root/'usr/share/mariadb'
env=os.environ.copy();env['LD_LIBRARY_PATH']=str(root/'usr/lib/x86_64-linux-gnu')
args=[str(server),'--no-defaults','--basedir='+str(root/'usr'),'--datadir='+str(data),'--lc-messages-dir='+str(share),'--character-sets-dir='+str(share/'charsets'),'--skip-networking','--skip-name-resolve','--innodb-buffer-pool-size=16M','--innodb-log-file-size=4M','--innodb-data-file-path=ibdata1:5M:autoextend:max:32M','--innodb-undo-tablespaces=0','--innodb-use-native-aio=OFF','--innodb-autoextend-increment=1','--innodb-temp-data-file-path=ibtmp1:1M:autoextend:max:12M','--aria-pagecache-buffer-size=4M','--aria-log-file-size=1M','--key-buffer-size=1M','--max-connections=25','--skip-log-bin','--innodb-flush-log-at-trx-commit=2','--performance-schema=OFF']
if not (data/'mysql').exists():
 sql='create database if not exists mysql;\nuse mysql;\nSET @auth_root_socket=NULL;\n'
 for name in ['mariadb_system_tables.sql','mariadb_performance_tables.sql','mariadb_system_tables_data.sql','fill_help_tables.sql','maria_add_gis_sp_bootstrap.sql','mariadb_sys_schema.sql']:
  sql+=(share/name).read_text()+'\n'
 with (data/'bootstrap.log').open('wb') as log:
  result=subprocess.run(args+['--bootstrap'],input=sql.encode(),stdout=log,stderr=subprocess.STDOUT,env=env)
 if result.returncode:raise RuntimeError((data/'bootstrap.log').read_text()[-8000:])
print('MariaDB system tables initialized with official installation SQL')
pid_file=base/'native-mariadb.pid'
running=False
if pid_file.exists():
 try:
  pid=int(pid_file.read_text());cmd=Path('/proc')/str(pid)/'cmdline';running='native-db-root/usr/sbin/mariadbd' in cmd.read_bytes().decode()
 except (OSError,ValueError):pass
 if not running:pid_file.unlink()
if not running:
 socket=base/'native-mariadb.sock'
 if socket.exists():socket.unlink()
 log=(data/'server.log').open('ab')
 proc=subprocess.Popen(args+['--socket='+str(base/'native-mariadb.sock'),'--pid-file='+str(base/'native-mariadb.pid')],stdout=log,stderr=subprocess.STDOUT,env=env,start_new_session=True)
 print('Started local MariaDB process',proc.pid)
