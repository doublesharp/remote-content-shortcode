#!/usr/bin/env python3
"""Validate the packaged plugin in disposable Docker services with short deadlines."""

import os
from pathlib import Path
import secrets
import shutil
import subprocess
import sys
import time
import zipfile

ROOT = Path(__file__).resolve().parents[1]
IN_ACTIONS = os.environ.get('GITHUB_ACTIONS') == 'true'
if IN_ACTIONS:
    BASE = Path(os.environ['RUNNER_TEMP']) / 'remote-content-shortcode'
else:
    if not Path('/Volumes/Scratch').is_mount():
        sys.exit('Scratch must be mounted before running local Docker checks.')
    BASE = Path('/Volumes/Scratch/remote-content-shortcode')
REPORTS = BASE / 'reports' if IN_ACTIONS else BASE / 'reports/github-ci'
WORK = BASE / 'tmp' / ('ci-' + secrets.token_hex(4))
REPORTS.mkdir(parents=True, exist_ok=True)
WORK.mkdir(parents=True)
os.environ['TMPDIR'] = str(WORK)
WORDPRESS = os.environ.get('RCS_WORDPRESS_VERSION', '7.1.3')
PHP = os.environ.get('RCS_PHP_VERSION', '8.2')
if PHP not in ('8.2', '8.5') or WORDPRESS not in ('6.6', '7.1.3'):
    sys.exit('Use one of the WordPress/PHP versions configured in validate.yml.')
IMAGE = 'wordpress:cli-php' + PHP
PREFIX = 'remote-content-ci-' + secrets.token_hex(4)
NETWORK = PREFIX + '-net'
SERVICES = []
NETWORK_CREATED = False
SOURCE = WORK / 'source'
WP = WORK / 'wordpress'
CERTIFICATES = WORK / 'certificates'
for path in (SOURCE, WP):
    path.mkdir()


def command(arguments, timeout=60, capture=False):
    return subprocess.run(arguments, check=True, timeout=timeout, text=True,
                          stdout=subprocess.PIPE if capture else None,
                          stderr=subprocess.STDOUT if capture else None)


def check(name, arguments, timeout=30, environment=None, isolated=True):
    """The watchdog also removes the container when a test reaches its deadline."""
    log = REPORTS / (name + '.txt')
    container = PREFIX + '-' + name
    docker = ['--user', f'{os.getuid()}:{os.getgid()}',
              '-v', f'{SOURCE}:/source:ro', '-v', f'{WP}:/wordpress',
              '-v', f'{WORK}:/work', '-v', f'{REPORTS}:/reports',
              '-w', '/wordpress', '-e', 'HOME=/work', '-e', 'WP_CLI_CACHE_DIR=/work/wp-cli-cache',
              '-e', 'RCS_PLUGIN_FILE=/wordpress/wp-content/plugins/remote-content-shortcode/remote-content-shortcode.php']
    if isolated:
        docker += ['--network', NETWORK, '--dns', '11.231.47.10']
    for key, value in (environment or {}).items():
        docker += ['-e', f'{key}={value}']
    try:
        command([sys.executable, str(ROOT / 'tests/docker-run.py'), '--name', container,
                 '--log', str(log), '--timeout', str(timeout), '--',
                 *docker, '--entrypoint', arguments[0], IMAGE, *arguments[1:]], timeout=timeout + 15)
    finally:
        if log.exists():
            print(log.read_text(), flush=True)


def wp(name, arguments, **options):
    check(name, ['php', '-d', 'memory_limit=256M', '/usr/local/bin/wp', '--allow-root', '--path=/wordpress', *arguments], **options)


try:
    # Copy only source inputs. Local .env, SVN/Git metadata, and evidence never enter Docker.
    for name in ('remote-content-shortcode.php', 'readme.txt', 'LICENSE.txt', 'third-party.txt',
                 'composer.json', 'composer.lock', 'uninstall.php', 'inc', 'blocks', 'docs', 'tests', 'tools'):
        source = ROOT / name
        if source.is_dir():
            shutil.copytree(source, SOURCE / name, ignore=shutil.ignore_patterns('__pycache__', '*.pyc'))
        else:
            shutil.copy2(source, SOURCE / name)
    for image in (IMAGE, 'mariadb:10.6.19', 'python:3.13-alpine'):
        probe = subprocess.run(['docker', 'image', 'inspect', image], capture_output=True, timeout=10)
        if probe.returncode:
            command(['docker', 'pull', image], timeout=120)
    version = command([sys.executable, str(ROOT / 'tools/release-version.py')], capture=True).stdout.strip()
    archive = REPORTS / f'remote-content-shortcode-{version}.zip'
    # Map the allowed local/runner output directory without changing the packager's rules.
    package_args = ['docker', 'run', '--rm', '--user', f'{os.getuid()}:{os.getgid()}',
                    '-v', f'{SOURCE}:/source:ro', '-v', f'{REPORTS}:{REPORTS}']
    if IN_ACTIONS:
        package_args += ['-e', 'GITHUB_ACTIONS=true', '-e', 'RUNNER_TEMP']
    command([*package_args, '--entrypoint', 'php', IMAGE, '/source/tools/package.php', str(archive)], timeout=30)
    with zipfile.ZipFile(archive) as release:
        names = release.namelist()
        forbidden = {'.env', '.git', '.svn', '.github', 'tests', 'tools', 'node_modules', 'phpQuery.php'}
        if any(forbidden.intersection(Path(name).parts) or any(part.startswith('.env.') for part in Path(name).parts) for name in names):
            raise RuntimeError('Unexpected private or development file in the release ZIP.')
        release.extractall(WORK / 'release')
    print(f'PASS release ZIP has {len(names)} allowed files', flush=True)
    check('php-lint', ['sh', '-ec', 'find /work/release -name "*.php" -print0 | xargs -0 -n 1 php -l'], isolated=False)
    wp('download-wordpress', ['core', 'download', '--version=' + WORDPRESS], isolated=False, timeout=60)
    check('download-plugin-check', ['php', '-r',
          '$body = file_get_contents("https://downloads.wordpress.org/plugin/plugin-check.latest-stable.zip"); if (false === $body) { exit(1); } if (false === file_put_contents("/work/plugin-check.zip", $body)) { exit(1); }'], isolated=False, timeout=45)
    check('certificates', ['php', '/source/tests/fixtures/certificates.php'], isolated=False)
    network = command(['docker', 'network', 'create', '--internal', '--subnet', '11.231.47.0/24', NETWORK], capture=True)
    NETWORK_CREATED = True
    fixture = PREFIX + '-fixture'
    SERVICES.append(fixture)
    command(['docker', 'run', '-d', '--name', fixture, '--network', NETWORK, '--ip', '11.231.47.10',
             '-v', f'{SOURCE}/tests/fixtures/server.py:/server.py:ro',
             '-v', f'{CERTIFICATES}:/certificates:ro', 'python:3.13-alpine', 'python', '/server.py'])
    database = PREFIX + '-db'
    SERVICES.append(database)
    command(['docker', 'run', '-d', '--name', database, '--network', NETWORK, '--ip', '11.231.47.20',
             '--tmpfs', '/var/lib/mysql:rw,size=256m', '-e', 'MARIADB_ROOT_PASSWORD=rcs-disposable-root',
             '-e', 'MARIADB_DATABASE=wordpress', '-e', 'MARIADB_USER=wordpress',
             '-e', 'MARIADB_PASSWORD=rcs-disposable-db', 'mariadb:10.6.19'])
    deadline = time.monotonic() + 45
    while True:
        ready = subprocess.run(['docker', 'exec', database, 'mariadb-admin', 'ping', '-h', '127.0.0.1',
                                '-uroot', '-prcs-disposable-root', '--silent'], capture_output=True, timeout=5)
        if ready.returncode == 0:
            break
        if time.monotonic() > deadline:
            raise RuntimeError('Disposable database did not become ready within 45 seconds.')
        time.sleep(1)
    wp('configure-wordpress', ['config', 'create', '--dbname=wordpress', '--dbuser=wordpress',
                              '--dbpass=rcs-disposable-db', '--dbhost=11.231.47.20', '--skip-check', '--skip-salts'])
    wp('install-wordpress', ['core', 'install', '--url=http://wordpress.rcs.test', '--title=RCS Docker checks',
                            '--admin_user=rcs_admin', '--admin_password=rcs-disposable-admin', '--admin_email=test@example.com', '--skip-email'])
    shutil.copytree(WORK / 'release/remote-content-shortcode', WP / 'wp-content/plugins/remote-content-shortcode')
    wp('activate-plugin', ['plugin', 'activate', 'remote-content-shortcode'])
    wp('install-plugin-check', ['plugin', 'install', '/work/plugin-check.zip', '--activate'])
    ca = WP / 'wp-includes/certificates/ca-bundle.crt'
    ca.write_bytes(ca.read_bytes() + b'\n' + (CERTIFICATES / 'ca.pem').read_bytes())
    for suite in ('regression', 'integration-transport', 'features-integration', 'content-auth-features',
                  'cache-features', 'admin-block-features', 'transport'):
        wp(suite, ['eval-file', f'/source/tests/{suite}.php'])
    wp('anonymous-rest-cache', ['--exec=define("REST_REQUEST", true);', 'eval-file', '/source/tests/anonymous-rest-cache.php'])
    wp('render-contexts', ['--exec=define("WP_ADMIN", true);', 'eval-file', '/source/tests/render-contexts.php'])
    for phase in ('write', 'read', 'uninstall'):
        wp('cache-' + phase, ['eval-file', '/source/tests/cache-features.php'], environment={'RCS_CACHE_PHASE': phase})
    for case in ('small-select', 'large-select', 'large-remove', 'flat', 'complex-selector', 'work-budget',
                 'ordinary', 'flat-sibling', 'flat-sibling-chain', 'small-sibling', 'themed-page'):
        check('selector-' + case, ['php', '/source/tests/selector-budget.php'], timeout=5,
              environment={'RCS_SELECTOR_CASE': case})
    wp('regex-budget', ['--exec=ini_set("memory_limit", "64M");', 'eval-file', '/source/tests/regex-budget.php'])
    wp('plugin-check', ['plugin', 'check', 'remote-content-shortcode', '--format=table'], timeout=60)
    print(f'PASS Docker validation: WordPress {WORDPRESS}, PHP {PHP}', flush=True)
finally:
    for service in reversed(SERVICES):
        subprocess.run(['docker', 'rm', '-f', service], capture_output=True, timeout=10)
    if NETWORK_CREATED:
        subprocess.run(['docker', 'network', 'rm', NETWORK], capture_output=True, timeout=10)
    # These paths are task-owned, never shared Docker caches or user credentials.
    shutil.rmtree(WORK)
