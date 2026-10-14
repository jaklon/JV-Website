"""Package reviewed chat sources and synthetic evaluation evidence, without secrets/binaries."""
from datetime import datetime, timedelta, timezone
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED
import hashlib
import json

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / 'cloud/chat'
RELEASE = ROOT / 'release'
FILES = [
    'README.md', 'VALIDATION.md', 'Dockerfile', '.dockerignore', 'go.mod',
    'main.go', 'grounding.go', 'main_test.go', 'grounding_test.go', 'prepare-model.py', 'evaluate.py',
    'run-local-evaluation.py', 'evaluate-extended.py', 'readiness.example.json',
    'evaluation.json', 'evaluation-extended.json', 'evaluation-baseline.json', 'evaluation-before-grounding.json', 'manual-review.json',
]
contents = {name: (SOURCE / name).read_bytes() for name in FILES}
contents['model-manifest.json'] = (SOURCE / 'model/manifest.json').read_bytes()
for name in ['server/tests/chat-retrieval-fixture.php','server/src/chat.php','server/src/policy.php']:
    contents['fixtures/'+name]=(ROOT/name).read_bytes()
contents['local-test-results.json']=(ROOT/'release/chat-test-results.json').read_bytes()
deployment_path = RELEASE / 'chat-deployment-results.json'
deployment = json.loads(deployment_path.read_text(encoding='utf-8')) if deployment_path.exists() else None
if deployment:
    for name in ['evaluation-linux.json', 'evaluation-extended-linux.json', 'evaluation-gcp.json', 'evaluation-extended-gcp.json']:
        data = (SOURCE / name).read_bytes()
        evidence = json.loads(data)
        if not evidence.get('fixture_only') or evidence['correct'] != evidence['total']:
            raise SystemExit('Deployment evidence must contain passing synthetic cases only.')
        contents[name] = data
    contents['deployment-results.json'] = deployment_path.read_bytes()
report = json.loads(contents['evaluation.json'])
extended=json.loads(contents['evaluation-extended.json'])
if not report.get('fixture_only') or not extended.get('fixture_only'):
    raise SystemExit('Only synthetic evaluation evidence may be packaged.')
if not json.loads(contents['local-test-results.json'])['all_local_tests_pass']:
    raise SystemExit('Run the local test suite before packaging this update.')
if report['correct']!=report['total'] or not extended['all_pass']:
    raise SystemExit('All quality cases must pass before packaging this repaired update.')
if any(json.loads(contents['readiness.example.json']).get(gate) for gate in
       ['quality_pass', 'cost_pass', 'runtime_pass', 'iam_pass']):
    raise SystemExit('Readiness example must remain disabled.')
stamp = datetime.now(timezone(timedelta(hours=7))).date().isoformat()
RELEASE.mkdir(exist_ok=True)
archive = RELEASE / f'jv-story-chat-gcp-source-{stamp}.zip'
with ZipFile(archive, 'w', ZIP_DEFLATED, compresslevel=9) as bundle:
    for name, value in sorted(contents.items()):
        bundle.writestr(name, value)
with ZipFile(archive) as bundle:
    if bundle.testzip() or set(bundle.namelist()) != set(contents):
        raise SystemExit('ZIP integrity failure.')
    for name, expected in contents.items():
        if bundle.read(name) != expected:
            raise SystemExit('ZIP content mismatch.')
digest = hashlib.sha256(archive.read_bytes()).hexdigest()
archive.with_suffix('.zip.sha256').write_text(f'{digest}  {archive.name}\n', encoding='utf-8')
metadata = {
    'archive': archive.name, 'sha256': digest, 'project_id': 'jv-story',
    'deployed': bool(deployment and deployment.get('iam_pass') and deployment.get('runtime_pass')),
    'enabled': False, 'hosting_status': 'awaiting_private_invoker_key',
    'quality': f"{report['correct']}/{report['total']}",
    'files': [{'path': name, 'bytes': len(value), 'sha256': hashlib.sha256(value).hexdigest()}
              for name, value in sorted(contents.items())],
}
(RELEASE / 'manifest-chat-gcp.json').write_text(json.dumps(metadata, indent=2)+'\n', encoding='utf-8')
print(f'Ready: {archive}')
print(f'{len(contents)} files; {archive.stat().st_size} bytes; quality {metadata["quality"]}; GCP deployed={metadata["deployed"]}; hosting awaiting private invoker key.')
print('Verified: exact allowlist, CRC and contents. No model weights, runtime binaries or credentials bundled.')
