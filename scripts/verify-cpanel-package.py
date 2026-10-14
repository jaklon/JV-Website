"""Smoke-test the actual ZIP after extraction into an isolated fake cPanel Home.

Does not contact the hosting database or Google, and creates no login bypass.
"""
from pathlib import Path
from zipfile import ZipFile
import argparse
import json
import os
import secrets
import subprocess
import struct
from urllib.parse import urljoin

root = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser()
parser.add_argument('--php', required=True, type=Path)
parser.add_argument('--update', action='store_true')
parser.add_argument('--feature', choices=['google-photos','iphone','favicon','chat'], default='google-photos')
args = parser.parse_args()
manifest = json.loads((root / (f'release/manifest-{args.feature}-update.json' if args.update else 'release/manifest.json')).read_text(encoding='utf-8'))
archive = root / 'release' / manifest['archive']
destination = root / '.test-data' / ('cpanel-' + secrets.token_hex(6))
destination.mkdir(parents=True)
(destination / '.sessions').mkdir()
def extract(path):
    with ZipFile(path) as bundle:
        if bundle.testzip():
            raise SystemExit('Invalid ZIP CRC.')
        for item in bundle.infolist():
            resolved = (destination / item.filename).resolve()
            if not resolved.is_relative_to(destination.resolve()):
                raise SystemExit('Unsafe extraction path.')
        bundle.extractall(destination)

if args.update:
    base = json.loads((root / 'release/manifest.json').read_text(encoding='utf-8'))
    extract(root / 'release' / base['archive'])
    deployed_config = destination / 'jv_story_private/config.local.php'
    preserved = deployed_config.read_bytes() + b'\n// Preserve existing hosting configuration.\n'
    deployed_config.write_bytes(preserved)
    deployed_media = destination / 'jv_story_private/storage/existing-fixture.txt'
    deployed_media.write_bytes(b'existing private media fixture')
    preserved_chat_files = {
        'chat.config.php': b'<?php return ["enabled" => false];\n',
        'chat-invoker.json': b'{"fixture_only":true}\n',
        'chat.readiness.json': b'{"quality_pass":false}\n',
    }
    for name, content in preserved_chat_files.items():
        (destination / 'jv_story_private' / name).write_bytes(content)
    portfolio_index = destination / 'public_html/jascon.my.id/index.html'
    preserved_portfolio = portfolio_index.read_bytes()
extract(archive)
if args.update:
    assert deployed_config.read_bytes() == preserved
    assert deployed_media.read_bytes() == b'existing private media fixture'
    assert portfolio_index.read_bytes() == preserved_portfolio
    for name, content in preserved_chat_files.items():
        assert (destination / 'jv_story_private' / name).read_bytes() == content
    print('Update preserves deployed config, existing storage, chat credentials/readiness and portfolio.')

environment = os.environ.copy()
environment.pop('JV_STORY_CONFIG', None)
environment.pop('OUR_STORY_CONFIG', None)

def php(code, *arguments):
    result = subprocess.run([str(args.php), '-d', 'display_errors=stderr', '-d', 'session.save_path=' + str(destination / '.sessions'), '-r', code, *map(str, arguments)],
                            capture_output=True, text=True, env=environment, timeout=30)
    if result.returncode:
        raise SystemExit('Extracted PHP runtime failed: ' + result.stderr)
    return result.stdout

private = destination / 'jv_story_private'
app = destination / 'public_html/jascon.my.id/jv-story'
web_manifest = json.loads((app / 'manifest.webmanifest').read_text(encoding='utf-8'))
manifest_url = 'https://jascon.my.id/jv-story/manifest.webmanifest'
assert web_manifest['display'] == 'standalone'
for key in ['id','start_url','scope']:
    assert urljoin(manifest_url, web_manifest[key]) == 'https://jascon.my.id/jv-story/'
for icon in web_manifest['icons'] + [{'src':'icons/apple-touch-icon.png','sizes':'180x180'}]:
    png = (app / icon['src']).read_bytes()
    assert png[:8] == b'\x89PNG\r\n\x1a\n'
    assert 'x'.join(map(str,struct.unpack('>II',png[16:24]))) == icon['sizes']
    assert png[25] == 2, 'Home Screen icons must have an opaque RGB background.'
html = (app / 'index.html').read_text(encoding='utf-8')
assert 'href="/jv-story/manifest.webmanifest"' in html
assert 'href="/jv-story/icons/apple-touch-icon.png"' in html
assert 'name="apple-mobile-web-app-capable" content="yes"' in html
print('Home Screen checks passed: standalone launch stays in JV Story; manifest and opaque PNG icons are bundled.')
if args.feature == 'favicon' or not args.update:
    assert 'href="/jv-story/favicon.svg?v=jv-2"' in html
    assert 'href="/jv-story/favicon.ico?v=jv-2"' in html
    assert 'href="/jv-story/favicon-32x32.png?v=jv-2"' in html
    for size in [16,32,48]:
        png=(app / f'favicon-{size}x{size}.png').read_bytes()
        assert png[:8] == b'\x89PNG\r\n\x1a\n' and struct.unpack('>II',png[16:24]) == (size,size)
    ico=(app / 'favicon.ico').read_bytes()
    assert struct.unpack('<HHH',ico[:6]) == (0,1,3)
    assert {(ico[6+i*16],ico[7+i*16]) for i in range(3)} == {(16,16),(32,32),(48,48)}
    print('JV Story favicon checks passed: SVG/PNG/ICO links stay in the app directory and raster sizes match.')
php('require $argv[1]; $client = new Google\\Client(); new Google\\Service\\Oauth2($client); $client->getOAuth2Service(); echo "ok";', private / 'vendor/autoload.php')
code = '$_SERVER["REQUEST_METHOD"]="GET"; $_SERVER["REQUEST_URI"]=$argv[2]; require $argv[1];'
session = json.loads(php(code, app / 'api.php', '/jv-story/api/session'))
assert session['user'] is None and isinstance(session['csrf'], str) and len(session['csrf']) == 64
assert isinstance(session['configured'], bool)
bootstrap = json.loads(php(code, app / 'api.php', '/jv-story/api/bootstrap'))
assert bootstrap['error'] == 'Silakan masuk dengan Google.'
photos = json.loads(php(code, app / 'api.php', '/jv-story/api/photos/status'))
assert photos['error'] == 'Silakan masuk dengan Google.'
media_code = '$_SERVER["REQUEST_METHOD"]="GET"; $_SERVER["REQUEST_URI"]="/jv-story/media/".str_repeat("a",32); require $argv[1];'
media = json.loads(php(media_code, app / 'media.php'))
assert media['error'] == 'Silakan masuk dengan Google.'
chat = json.loads(php(code, app / 'api.php', '/jv-story/api/chat/status'))
assert chat['error'] == 'Silakan masuk dengan Google.'
if args.feature == 'chat':
    assert (private / 'migrations/001-chat.sql').is_file()
    assert (private / 'bin/migrate-chat.php').is_file()
    assert (private / 'chat.config.example.php').is_file()
    with ZipFile(archive) as bundle:
        assert not any(Path(n).name in {'chat.config.php','chat-invoker.json','chat.readiness.json'} for n in bundle.namelist())
    print('Chat update checks passed: migration bundled, public access denied, credentials excluded.')
print('Extracted ZIP smoke checks passed: actual hosting paths, Google OAuth dependencies, session endpoint, anonymous API and media access denied.')
print('Credentials and live Google/MariaDB connectivity still require server configuration.')
