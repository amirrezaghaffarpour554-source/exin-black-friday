from pathlib import Path
import json,os,subprocess,sys,zipfile
root=Path(__file__).resolve().parents[2];base=root/'work/v101';ram=Path(os.environ['EXIN_NATIVE_RAM']);wp=ram/'wordpress';op=sys.argv[1]
def alive(pidfile):
 try:os.kill(int(pidfile.read_text()),0);return True
 except (OSError,ValueError):return False
if op=='web':
 router=base/'native-router.php'
 router.write_text('''<?php
$root=getenv('EXIN_NATIVE_WP');
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if ($path && $path!=='/' && is_file($root.$path)) return false;
if ($path && is_dir($root.$path) && is_file(rtrim($root.$path,'/').'/index.php')) { require rtrim($root.$path,'/').'/index.php'; return; }
require $root.'/index.php';
''')
 pidfile=base/'native-web.pid'
 if not alive(pidfile):
  log=(base/'native-web.log').open('ab');p=subprocess.Popen([str(root/'scripts/php-v101.sh'),'-S','127.0.0.1:8891','-t',str(wp),str(router)],cwd=root,env=os.environ.copy(),stdout=log,stderr=log,start_new_session=True);pidfile.write_text(str(p.pid))
 print('NativePHP8.2HTTPserver ready on http://127.0.0.1:8891 (PHPbuilt-in; Nginx not claimed)')
elif op=='prepare-elementor':
 archive=base/'runtime/elementor-3.34.0.zip'
 if not archive.exists():
  partial=archive.with_suffix('.zip.part');subprocess.run(['curl','-fsSL','--max-time','120','https://github.com/elementor/elementor/releases/download/3.34.0/elementor-3.34.0.zip','-o',str(partial)],check=True)
  with zipfile.ZipFile(partial) as z:assert z.testzip() is None
  partial.rename(archive)
 if not (wp/'wp-content/plugins/elementor/elementor.php').exists():
  with zipfile.ZipFile(archive) as z:z.extractall(wp/'wp-content/plugins')
 print('Full official Elementor3.34.0 files ready; activation deliberately not performed during another DB lease')
elif op in ('prepare-legacy','prepare-package'):
 archive=root/'releases/exin-black-friday-v1.0.0.zip' if op=='prepare-legacy' else Path(sys.argv[2]).resolve()
 if archive.parent not in (root/'releases',root/'releases/v1.0.1',root/'work/dist') or not archive.name.startswith('exin-black-friday-v') or archive.suffix!='.zip':raise RuntimeError('Only a local EXIN release ZIP can be installed')
 target=ram/('legacy' if op=='prepare-legacy' else 'package')
 if op=='prepare-package' and target.exists():
  import shutil
  assert not target.is_symlink()
  shutil.rmtree(target)
 target.mkdir(exist_ok=True)
 with zipfile.ZipFile(archive) as z:
  assert z.testzip() is None
  for name in z.namelist():
   assert name.startswith('exin-black-friday/') and '..' not in Path(name).parts
  z.extractall(target)
 assert (target/'exin-black-friday/exin-black-friday.php').is_file()
 print('Verified release ZIP extracted into private RAM: '+archive.name)
elif op in ('use-legacy','use-source','use-package'):
 target=root if op=='use-source' else ram/('legacy' if op=='use-legacy' else 'package')/'exin-black-friday'
 assert (target/'exin-black-friday.php').is_file()
 link=wp/'wp-content/plugins/exin-black-friday'
 assert link.is_symlink(), 'Refusing to replace an unexpected plugin directory'
 link.unlink();link.symlink_to(target,target_is_directory=True)
 print('Synthetic runtime plugin source: '+str(target))
elif op=='package-proof':
 import hashlib
 archive=Path(sys.argv[2]).resolve()
 if archive.parent not in (root/'releases',root/'releases/v1.0.1',root/'work/dist'):raise RuntimeError('Expected a local release artifact')
 target=ram/'package'
 files={}
 with zipfile.ZipFile(archive) as z:
  assert z.testzip() is None
  for info in z.infolist():
   if info.is_dir():continue
   assert info.filename.startswith('exin-black-friday/') and '..' not in Path(info.filename).parts
   expected=hashlib.sha256(z.read(info)).hexdigest()
   actual=hashlib.sha256((target/info.filename).read_bytes()).hexdigest()
   assert expected==actual, 'Installed artifact differs: '+info.filename
   files[info.filename]=actual
 print(json.dumps({'archive':archive.name,'archive_sha256':hashlib.sha256(archive.read_bytes()).hexdigest(),'archive_bytes':archive.stat().st_size,'installed_files_verified':len(files),'installed_files_sha256':files,'install_tree':str(target/'exin-black-friday'),'result':'passed'}))
else:raise SystemExit('Unknown native service operation')
