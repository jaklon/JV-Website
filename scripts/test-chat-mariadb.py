"""Run real MariaDB migration/reservation race tests against a disposable instance.
No existing service or production database is used. Windows/XAMPP, stdlib only.
"""
from pathlib import Path
import os, secrets, subprocess, time, socket, argparse
ROOT=Path(__file__).resolve().parents[1]
p=argparse.ArgumentParser();p.add_argument('--php',default=r'C:\xampp\php\php.exe');p.add_argument('--mysql-bin',type=Path,default=Path(r'C:\xampp\mysql\bin'));args=p.parse_args()
target=ROOT/'.test-data'/('chat-mariadb-'+secrets.token_hex(5));target.mkdir(parents=True)
with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
subprocess.run([str(args.mysql_bin/'mysql_install_db.exe'),f'--datadir={target}',f'--port={port}'],check=True,capture_output=True)
log=(target/'server.log').open('w')
server=subprocess.Popen([str(args.mysql_bin/'mysqld.exe'),'--no-defaults',f'--datadir={target}',f'--port={port}','--bind-address=127.0.0.1','--innodb-buffer-pool-size=32M','--console'],stdout=log,stderr=log)
env=os.environ.copy();env['JV_CHAT_TEST_PORT']=str(port)
try:
    for _ in range(100):
        if server.poll() is not None:raise SystemExit('Disposable MariaDB failed; inspect '+str(target/'server.log'))
        try:
            with socket.create_connection(('127.0.0.1',port),timeout=.2):break
        except OSError:time.sleep(.2)
    else:raise SystemExit('MariaDB startup timeout')
    result=subprocess.run([args.php,str(ROOT/'server/tests/chat-mariadb.php')],env=env,cwd=ROOT)
    raise SystemExit(result.returncode)
finally:
    server.terminate();server.wait(timeout=15);log.close()
