"""Download official CPU tools/Qwen weights, then quantize locally (never in GCP).
Python stdlib only; Windows x64. Outputs are ignored and never in cPanel ZIPs.
"""
from pathlib import Path
from zipfile import ZipFile
import hashlib, json, subprocess, urllib.request, argparse

ROOT = Path(__file__).resolve().parent
parser = argparse.ArgumentParser()
parser.add_argument('--tag', default='b11491')
args = parser.parse_args()
def get_json(url):
    req = urllib.request.Request(url, headers={'User-Agent':'JV-Story-model-preparation'})
    with urllib.request.urlopen(req, timeout=60) as response:
        return json.load(response)
def download(url, path, expected=None):
    if path.is_file() and (not expected or hashlib.sha256(path.read_bytes()).hexdigest() == expected):
        return hashlib.sha256(path.read_bytes()).hexdigest()
    print('Downloading', path.name, flush=True)
    req = urllib.request.Request(url, headers={'User-Agent':'JV-Story-model-preparation'})
    partial = path.with_suffix(path.suffix + '.partial')
    with urllib.request.urlopen(req, timeout=120) as response, partial.open('wb') as target:
        while chunk := response.read(1024*1024):
            target.write(chunk)
    digest = hashlib.sha256(partial.read_bytes()).hexdigest()
    if expected and digest != expected:
        raise SystemExit('Checksum mismatch: ' + path.name)
    partial.replace(path)
    return digest

tools = ROOT / 'bin'; model = ROOT / 'model'
tools.mkdir(exist_ok=True); model.mkdir(exist_ok=True)
release = get_json('https://api.github.com/repos/ggml-org/llama.cpp/releases/tags/' + args.tag)
asset = next(a for a in release['assets'] if a['name'].endswith('bin-win-cpu-x64.zip'))
digest = (asset.get('digest') or '').removeprefix('sha256:') or None
archive = tools / asset['name']; runtime_hash = download(asset['browser_download_url'],archive,digest)
with ZipFile(archive) as bundle:
    for item in bundle.infolist():
        if not (tools / item.filename).resolve().is_relative_to(tools.resolve()):
            raise SystemExit('Unsafe runtime archive')
    bundle.extractall(tools)
repo = get_json('https://huggingface.co/api/models/Qwen/Qwen3-0.6B-GGUF/revision/23749fefcc72300e3a2ad315e1317431b06b590a?blobs=true')
revision = repo['sha']; entry = next(s for s in repo['siblings'] if s['rfilename']=='Qwen3-0.6B-Q8_0.gguf')
expected = (entry.get('lfs') or {}).get('sha256')
original = model / 'official-q8.gguf'
original_hash = download(f'https://huggingface.co/Qwen/Qwen3-0.6B-GGUF/resolve/{revision}/Qwen3-0.6B-Q8_0.gguf', original, expected)
quantizer = next(tools.rglob('llama-quantize.exe'))
target = model / 'qwen.gguf'
if not target.exists():
    with (model/'quantization.log').open('w',encoding='utf-8') as output:
        subprocess.run([str(quantizer),'--allow-requantize',str(original),str(target),'Q4_K_M','1'],check=True,stdout=output,stderr=output)
meta = {'model':'qwen3-0.6b-q4_k_m','source':'Qwen/Qwen3-0.6B-GGUF','revision':revision,'source_sha256':original_hash,
        'sha256':hashlib.sha256(target.read_bytes()).hexdigest(),'bytes':target.stat().st_size,
        'llama_tag':args.tag,'llama_commit':get_json('https://api.github.com/repos/ggml-org/llama.cpp/git/ref/tags/'+args.tag)['object']['sha'],
        'runtime_zip_sha256':runtime_hash,'quantization':'Q4_K_M','license':'Apache-2.0','requantized_from':'Q8_0'}
(model/'manifest.json').write_text(json.dumps(meta,indent=2)+'\n',encoding='utf-8')
print(json.dumps(meta,indent=2),flush=True)
