"""Build one cPanel Home-directory archive for the verified Jascon document root.

Run npm run build:hosting first. Run the portfolio packaging script first.
No credentials are printed or placed in the public document root.
"""
from datetime import datetime, timedelta, timezone
from pathlib import Path, PurePosixPath
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import argparse
import hashlib
import json
import re
import stat

ROOT = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser()
parser.add_argument('--portfolio', type=Path, default=ROOT.parent / 'web-portofolio')
parser.add_argument('--update', action='store_true', help='Package an update, preserving deployed config and storage.')
parser.add_argument('--feature', choices=['google-photos','iphone','favicon','chat'], default='google-photos')
args = parser.parse_args()
portfolio = args.portfolio.resolve()
public_prefix = 'public_html/jascon.my.id/'
app_prefix = public_prefix + 'jv-story/'
private_prefix = 'jv_story_private/'
stamp = datetime.now(timezone(timedelta(hours=7))).date().isoformat()
release = ROOT / 'release'
release.mkdir(exist_ok=True)

manifest = json.loads((portfolio / 'deployment/manifest.json').read_text(encoding='utf-8'))
portfolio_zip = portfolio / 'deployment' / manifest['archive']
if hashlib.sha256(portfolio_zip.read_bytes()).hexdigest() != manifest['archive_sha256']:
    raise SystemExit('Portfolio archive checksum mismatch. Rebuild the portfolio package.')

contents = {}
with ZipFile(portfolio_zip) as bundle:
    if bundle.testzip():
        raise SystemExit('Invalid portfolio archive.')
    for entry in bundle.infolist():
        if entry.is_dir():
            continue
        path = PurePosixPath(entry.filename)
        if path.is_absolute() or '..' in path.parts or path.parts[0] == 'jv-story':
            raise SystemExit('Unexpected path in portfolio archive.')
        contents[public_prefix + entry.filename] = bundle.read(entry)
if contents[public_prefix + 'index.html'] != (portfolio / 'dist/index.html').read_bytes():
    raise SystemExit('Portfolio ZIP is stale. Rebuild its package first.')
if contents[public_prefix + '.htaccess'] != (portfolio / 'public/.htaccess').read_bytes():
    raise SystemExit('Portfolio Apache rules are stale.')
if b'RewriteRule ^jv-story(?:/|$) - [L]' not in contents[public_prefix + '.htaccess']:
    raise SystemExit('Missing routing exclusion for the separate JV Story application.')
if args.update:
    contents.clear()  # The update only touches JV Story, not the portfolio.

public = ROOT / 'server/public'
for name in ['index.html', 'favicon.svg', 'favicon.ico', 'favicon-16x16.png', 'favicon-32x32.png', 'favicon-48x48.png', 'manifest.webmanifest', '.htaccess', '.user.ini', 'api.php', 'media.php']:
    contents[app_prefix + name] = (public / name).read_bytes()
for directory in (['assets','icons'] if args.update else ['assets', 'icons', 'ffmpeg']):
    for path in sorted((public / directory).rglob('*')):
        if path.is_symlink():
            raise SystemExit('Symlink in public build.')
        if path.is_file():
            contents[app_prefix + path.relative_to(public).as_posix()] = path.read_bytes()

html = contents[app_prefix + 'index.html'].decode('utf-8')
if 'JV Story' not in html:
    raise SystemExit('Wrong application branding.')
for asset in re.findall(r'(?:src|href)="(/[^"?#]+)', html):
    if not asset.startswith('/jv-story/') or public_prefix + asset.lstrip('/') not in contents:
        raise SystemExit(f'Missing or incorrectly prefixed application asset: {asset}')
for name in ['ffmpeg/ffmpeg-core.js', 'ffmpeg/ffmpeg-core.wasm']:
    if not args.update and app_prefix + name not in contents:
        raise SystemExit('FFmpeg runtime was not copied into the build.')
web_manifest = json.loads(contents[app_prefix + 'manifest.webmanifest'])
if web_manifest['display'] != 'standalone' or any(web_manifest.get(key) != './' for key in ['id','start_url','scope']):
    raise SystemExit('Home Screen launch must remain inside the JV Story directory.')
for icon in web_manifest['icons']:
    if app_prefix + icon['src'] not in contents:
        raise SystemExit('Missing Home Screen icon.')

server = ROOT / 'server'
for name in ([] if args.update else ['config.local.php', 'config.example.php', 'schema.sql', 'composer.json', 'composer.lock']):
    contents[private_prefix + name] = (server / name).read_bytes()
for directory in (['src'] if args.update else ['src', 'vendor', 'bin']):
    for path in sorted((server / directory).rglob('*')):
        if path.is_symlink():
            raise SystemExit('Symlink in private backend.')
        if path.is_file():
            relative = path.relative_to(server).as_posix()
            # Google documents that unused API service wrappers may be omitted.
            # Keep OAuth2 plus all core authentication dependencies, not hundreds
            # of unrelated service wrappers (Drive, Ads, Compute, etc.).
            services = 'vendor/google/apiclient-services/src/'
            if relative.startswith(services):
                service_path = relative[len(services):]
                if service_path != 'Oauth2.php' and not service_path.startswith('Oauth2/'):
                    continue
            contents[private_prefix + relative] = path.read_bytes()
if not args.update:
    contents[private_prefix + 'storage/.gitkeep'] = b''
if args.feature == 'chat' or not args.update:
    for name in ['chat.config.example.php', 'migrations/001-chat.sql', 'bin/migrate-chat.php']:
        contents[private_prefix + name] = (server / name).read_bytes()
contents[private_prefix + '.htaccess'] = b'Require all denied\n'
guides = {'chat': ('BACA-DULU-CHAT.md','docs/UPDATE-CHAT.md'), 'iphone': ('BACA-DULU-IPHONE.md','docs/UPDATE-IPHONE.md'), 'google-photos': ('BACA-DULU-GOOGLE-PHOTOS.md','docs/UPDATE-GOOGLE-PHOTOS.md'), 'favicon': ('BACA-DULU-FAVICON.md','docs/UPDATE-FAVICON.md')}
guide_name, guide_path = guides[args.feature] if args.update else ('BACA-DULU-JV-STORY.md','docs/EXTRACT-CPANEL.md')
guide = (ROOT / guide_path).read_bytes()
contents[guide_name] = guide
(release / guide_name).write_bytes(guide)

# Credentials, original media, dev fixtures and dependencies must never be public.
for name in contents:
    path = PurePosixPath(name)
    if path.is_absolute() or '..' in path.parts:
        raise SystemExit('Unsafe archive path.')
    if name.startswith(public_prefix) and any(part in {'vendor', 'src', 'storage', 'node_modules', '.git'} for part in path.parts):
        raise SystemExit('Private/development directory in public archive.')
    if name.startswith(public_prefix) and path.name in {'config.local.php', 'config.example.php', 'schema.sql', 'composer.json', 'composer.lock'}:
        raise SystemExit('Private configuration in document root.')
    if not name.startswith((public_prefix, private_prefix)) and name != guide_name:
        raise SystemExit('Archive would modify another domain or account setting.')
    if path.name in {'chat.config.php','chat-invoker.json','chat.readiness.json'}:
        raise SystemExit('Chat credentials/readiness must never be bundled.')
    if args.update and (path.name == 'config.local.php' or name.startswith(private_prefix + 'storage/')):
        raise SystemExit('Update must preserve deployed credentials and original media.')

# public_html already exists and is shared with other domains. Do not store an
# entry that could change its permissions when cPanel extracts this ZIP.
directories = sorted({str(parent) + '/' for name in contents for parent in PurePosixPath(name).parents if str(parent) not in {'.', 'public_html'}})

def entry(name, directory=False):
    info = ZipInfo(name, date_time=datetime.now().timetuple()[:6])
    private = name.startswith(private_prefix) or name == guide_name
    permissions = (0o700 if private else 0o755) if directory else (0o600 if private else 0o644)
    info.create_system = 3
    info.external_attr = ((stat.S_IFDIR if directory else stat.S_IFREG) | permissions) << 16
    if directory:
        info.external_attr |= 0x10
    info.compress_type = ZIP_DEFLATED
    return info

archive = release / (f'jascon-jv-story-{args.feature}-update-{stamp}.zip' if args.update else f'jascon-jv-story-cpanel-{stamp}.zip')
with ZipFile(archive, 'w', compression=ZIP_DEFLATED, compresslevel=9) as bundle:
    for name in directories:
        bundle.writestr(entry(name, True), b'')
    for name, content in sorted(contents.items()):
        bundle.writestr(entry(name), content)
with ZipFile(archive) as bundle:
    if bundle.testzip() or set(bundle.namelist()) != set(contents) | set(directories):
        raise SystemExit('Archive integrity check failed.')
    for item in bundle.infolist():
        expected = entry(item.filename, item.is_dir())
        if item.external_attr != expected.external_attr:
            raise SystemExit('Incorrect Linux permissions in archive.')
        if not item.is_dir() and bundle.read(item) != contents[item.filename]:
            raise SystemExit('Archive content mismatch.')

digest = hashlib.sha256(archive.read_bytes()).hexdigest()
archive.with_suffix('.zip.sha256').write_text(f'{digest}  {archive.name}\n', encoding='utf-8')
metadata = {
    'archive': archive.name, 'sha256': digest, 'date_jakarta': stamp,
    'extract_to': '/home/cirj8428', 'document_root': '/home/cirj8428/public_html/jascon.my.id',
    'public_url': 'https://jascon.my.id/jv-story/',
    'file_count': len(contents), 'directory_count': len(directories),
    'archive_bytes': archive.stat().st_size, 'uncompressed_bytes': sum(map(len, contents.values())),
    'manual_configuration': ({'iphone':['Add to Home Screen on iPhone'],'favicon':[],'chat':['run chat migration', 'deploy private Cloud Run service', 'configure chat invoker and verify quality/cost'], 'google-photos':['enable Google Photos Picker API','add Photos Picker OAuth scope']}[args.feature]) if args.update else ['db_password', 'google_client_id', 'google_client_secret'],
    'bundled_google_api_services': [] if args.update else ['Oauth2'],
    'mode': 'update' if args.update else 'install',
    'files': [{'path': name, 'bytes': len(value), 'sha256': hashlib.sha256(value).hexdigest()} for name, value in sorted(contents.items())],
}
(release / (f'manifest-{args.feature}-update.json' if args.update else 'manifest.json')).write_text(json.dumps(metadata, indent=2) + '\n', encoding='utf-8')
print(f'Ready: {archive}')
print(f'{len(contents)} files; {archive.stat().st_size / 1_000_000:.2f} MB ZIP; {metadata["uncompressed_bytes"] / 1_000_000:.2f} MB unpacked')
print('Verified: hosting paths, asset references, ZIP CRC and Linux permissions.')
print('Extract into /home/cirj8428; existing credentials and media are preserved.' if args.update else 'Extract into /home/cirj8428; fill three credentials in jv_story_private/config.local.php.')
