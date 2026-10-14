"""Start the real CPU-only gateway and run synthetic evaluation. Windows x64."""
from pathlib import Path
import json, os, subprocess, time, urllib.request, sys, argparse
ROOT=Path(__file__).resolve().parent
parser=argparse.ArgumentParser();parser.add_argument('--php',type=Path);parser.add_argument('--extended',action='store_true');args=parser.parse_args()
env=os.environ.copy()
env.update(LLAMA_BINARY=str(ROOT/'bin/llama-server.exe'),MODEL_PATH=str(ROOT/'model/qwen.gguf'),PORT='8099')
log=ROOT/'local-runtime.log'
with log.open('w',encoding='utf-8') as output:
    process=subprocess.Popen([str(ROOT/'bin/gateway.exe')],env=env,stdout=output,stderr=output,cwd=ROOT)
    try:
        start=time.monotonic()
        for _ in range(350):
            if process.poll() is not None:raise SystemExit('Runtime failed to start. Inspect local-runtime.log.')
            try:
                with urllib.request.urlopen('http://127.0.0.1:8099/healthz',timeout=1) as response:
                    if response.status==200:break
            except Exception:time.sleep(.2)
        else:raise SystemExit('Startup exceeded 70 seconds.')
        print('Local cold start seconds:',round(time.monotonic()-start,2),flush=True)
        command=[sys.executable,str(ROOT/'evaluate.py')]
        if args.php:command+=['--php',str(args.php)]
        result=subprocess.run(command,cwd=ROOT)
        if result.returncode==0 and args.extended:
            command=[sys.executable,str(ROOT/'evaluate-extended.py')]
            if args.php:command+=['--php',str(args.php)]
            result=subprocess.run(command,cwd=ROOT)
        raise SystemExit(result.returncode)
    finally:
        # Send CTRL termination to Go; its signal handler shuts down the child.
        # taskkill /T is used only for our recorded test PID to include its child on Windows.
        if process.poll() is None:
            subprocess.run(['taskkill','/PID',str(process.pid),'/T','/F'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        process.wait(timeout=10)
