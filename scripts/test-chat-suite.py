"""Run the complete local chat suite; never uses production data or GCP resources."""
from pathlib import Path
from datetime import datetime,timezone,timedelta
import argparse,hashlib,json,shutil,subprocess,sys,time

ROOT=Path(__file__).resolve().parents[1];CHAT=ROOT/'cloud/chat'
p=argparse.ArgumentParser();p.add_argument('--php',type=Path,default=Path(r'C:\xampp\php\php.exe'));args=p.parse_args()
php=str(args.php.resolve());go=shutil.which('go')
if not go or not args.php.is_file():raise SystemExit('Install Go and provide --php PATH first.')
stamp=datetime.now(timezone(timedelta(hours=7)))
logs=ROOT/'.test-data'/('chat-suite-'+stamp.strftime('%Y%m%d-%H%M%S'));logs.mkdir(parents=True,exist_ok=True)
steps=[
 ('PHP helper', [php,'server/tests/chat.php'],ROOT),
 ('Frontend', ['cmd.exe','/d','/c','npm','test','--','--run'],ROOT),
 ('Compile Go tests', [go,'test','-c','-o','bin/chat-tests.exe'],CHAT),
 ('Go gateway', [str(CHAT/'bin/chat-tests.exe'),'-test.count=1','-test.timeout=30s'],CHAT),
 ('PHP HTTP integration', [php,'server/tests/integration.php'],ROOT),
 ('MariaDB integration', [sys.executable,'scripts/test-chat-mariadb.py','--php',php],ROOT),
 ('Build gateway', [go,'build','-o','bin/gateway.exe','.'],CHAT),
 ('Real Qwen with PHP retrieval', [sys.executable,'run-local-evaluation.py','--php',php,'--extended'],CHAT),
 ('Hosting build', ['cmd.exe','/d','/c','npm','run','build:hosting'],ROOT),
]
results=[]
for i,(label,command,cwd) in enumerate(steps):
    print(f'{i+1}/{len(steps)}: {label}',flush=True);start=time.monotonic()
    log=logs/f'{i+1:02d}.log'
    with log.open('wb') as output:result=subprocess.run(command,cwd=cwd,stdout=output,stderr=subprocess.STDOUT,timeout=360)
    passed=result.returncode==0
    if label=='Real Qwen with PHP retrieval' and passed:
        basic=json.loads((CHAT/'evaluation.json').read_text(encoding='utf-8'))
        extended=json.loads((CHAT/'evaluation-extended.json').read_text(encoding='utf-8'))
        passed=basic['correct']==basic['total']==20 and extended['correct']==extended['total'] and extended['all_pass'] and basic['retrieval']=='php-sql'
    results.append({'test':label,'passed':passed,'exit_code':result.returncode,'duration_ms':round((time.monotonic()-start)*1000),'log':str(log)})
    print('PASS' if passed else 'FAIL; inspect '+str(log),flush=True)
    if not passed:break
files=['cloud/chat/main.go','cloud/chat/grounding.go','cloud/chat/main_test.go','cloud/chat/grounding_test.go','server/src/chat.php','server/tests/chat.php','server/tests/chat-retrieval-fixture.php','cloud/chat/evaluate.py','cloud/chat/evaluate-extended.py']
report={
 'checked_at':stamp.isoformat(),'scope':'local synthetic data only','all_local_tests_pass':len(results)==len(steps) and all(r['passed'] for r in results),
 'steps':results,'not_run':['Linux Docker image and RAM','live GCP IAM','live Cloud Run latency','registry storage and billing/free-tier/egress/spend cap','physical iPhone'],
 'source_sha256':{name:hashlib.sha256((ROOT/name).read_bytes()).hexdigest() for name in files},
}
(ROOT/'release').mkdir(exist_ok=True)
(ROOT/'release/chat-test-results.json').write_text(json.dumps(report,indent=2)+'\n',encoding='utf-8')
print('All local tests passed.' if report['all_local_tests_pass'] else 'Local test suite incomplete or failed.',flush=True)
raise SystemExit(0 if report['all_local_tests_pass'] else 1)
