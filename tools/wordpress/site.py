#!/usr/bin/env python3
"""Persistent local WordPress; disposable packages and PHP temp files use Scratch."""
import argparse
import os
from pathlib import Path
import secrets
import subprocess
import sys
from urllib.request import urlopen
import zipfile

ROOT = Path(__file__).resolve().parents[2]
STATE = ROOT / '.local-wordpress'
SCRATCH = Path('/Volumes/Scratch/remote-content-shortcode/tmp/wordpress-local')
URL = 'http://localhost:8088'
COMPOSE = ['docker', 'compose', '-f', str(Path(__file__).with_name('compose.yaml'))]


def run(args, **kwargs):
    return subprocess.run(args, check=True, **kwargs)


def compose(*args, **kwargs):
    return run(COMPOSE + list(args), env={**os.environ, 'RCS_WP_SCRATCH': str(SCRATCH)}, **kwargs)


def wp(*args, **kwargs):
    return compose('run', '--rm', '--no-deps', '-T', 'cli', *args, **kwargs)


def private_file(path, content):
    with open(path, 'x', opener=lambda p, f: os.open(p, f, 0o600)) as handle:
        handle.write(content)


def initialize():
    if not Path('/Volumes/Scratch').is_mount():
        sys.exit('Mount /Volumes/Scratch before starting this site.')
    STATE.mkdir(exist_ok=True, mode=0o700)
    for path in [STATE / 'wordpress', STATE / 'database', SCRATCH, SCRATCH / 'tmp']:
        path.mkdir(parents=True, exist_ok=True)
    # Both the Apache UID and the MariaDB UID need their container temp directory.
    (SCRATCH / 'tmp').chmod(0o1777)
    runtime = STATE / 'runtime.env'
    if not runtime.exists():
        password = secrets.token_hex(24)
        config = "define('WP_ENVIRONMENT_TYPE', 'local'); define('WP_TEMP_DIR', '/tmp'); define('FS_METHOD', 'direct'); define('WP_DEBUG_DISPLAY', false); define('WP_AUTO_UPDATE_CORE', false); define('AUTOMATIC_UPDATER_DISABLED', true);"
        private_file(runtime, f'WORDPRESS_DB_HOST=database:3306\nWORDPRESS_DB_NAME=wordpress\nWORDPRESS_DB_USER=wordpress\nWORDPRESS_DB_PASSWORD={password}\nWORDPRESS_CONFIG_EXTRA="{config}"\n')
        private_file(STATE / 'database.env', f'MARIADB_DATABASE=wordpress\nMARIADB_USER=wordpress\nMARIADB_PASSWORD={password}\nMARIADB_ROOT_PASSWORD={secrets.token_hex(32)}\n')
        private_file(STATE / 'admin-password', secrets.token_urlsafe(24) + '\n')


def package_free():
    """Use Free's runtime allowlist without installing host PHP extensions."""
    files = ['remote-content-shortcode.php', 'readme.txt', 'LICENSE.txt', 'third-party.txt',
             'composer.json', 'composer.lock', 'SECURITY.md', 'docs/integrations.md',
             'docs/security.md', 'docs/troubleshooting.md', 'docs/upgrading.md', 'uninstall.php']
    files += [str(p.relative_to(ROOT)) for p in (ROOT / 'inc').rglob('*') if p.is_file()]
    files += ['blocks/remote-content/block.json', 'blocks/remote-content/editor.js', 'blocks/remote-content/editor.asset.php']
    destination = SCRATCH / 'remote-content-shortcode.zip'
    with zipfile.ZipFile(destination, 'w', zipfile.ZIP_DEFLATED) as archive:
        for relative in sorted(files):
            source = ROOT / relative
            if source.is_symlink():
                sys.exit('Refusing symlink package input: ' + relative)
            archive.write(source, 'remote-content-shortcode/' + relative)
    return '/workspace-output/' + destination.name


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('command', choices=['up', 'stop', 'status', 'wp', 'install-free', 'install-pro', 'seed', 'check', 'activate'])
    parser.add_argument('args', nargs=argparse.REMAINDER)
    args = parser.parse_args()
    initialize()
    if args.command == 'up':
        compose('up', '-d', '--wait', '--wait-timeout', '180', 'database', 'wordpress')
        installed = subprocess.run(COMPOSE + ['run', '--rm', '--no-deps', '-T', 'cli', 'core', 'is-installed'],
                                   env={**os.environ, 'RCS_WP_SCRATCH': str(SCRATCH)}, capture_output=True)
        if installed.returncode:
            # WP-CLI echoes prompted credentials, so suppress this command's output.
            wp('core', 'install', '--url=' + URL, '--title=WordPress Plugin Lab', '--admin_user=admin',
               '--admin_email=developer@example.test', '--skip-email', '--prompt=admin_password',
               input=(STATE / 'admin-password').read_text(), text=True, capture_output=True)
            wp('option', 'update', 'blog_public', '0')
            wp('rewrite', 'structure', '/%postname%/')
        print(f'{URL}/wp-admin/ — admin; password: {STATE / "admin-password"}')
    elif args.command == 'wp':
        wp(*args.args)
    elif args.command == 'install-free':
        wp('plugin', 'install', package_free(), '--force', '--activate')
    elif args.command == 'install-pro':
        if len(args.args) != 1:
            parser.error('install-pro requires the path to an installable Pro ZIP')
        source = Path(args.args[0]).resolve()
        target = SCRATCH / 'remote-content-shortcode-pro.zip'
        if source != target:
            import shutil
            shutil.copyfile(source, target)
        wp('plugin', 'install', '/workspace-output/' + target.name, '--force', '--activate')
    elif args.command in ['seed', 'check']:
        wp('--user=admin', 'eval-file', '/workspace-tools/fixtures.php', args.command)
        if args.command == 'check':
            posts = {'free-shortcode': 'Less copy-paste.', 'free-block': 'Less copy-paste.',
                     'free-fallback': 'Private destination safely refused'}
            if (STATE / 'wordpress/wp-content/plugins/remote-content-shortcode-pro').is_dir():
                posts['pro-source'] = 'Less copy-paste.'
            for slug, expected in posts.items():
                with urlopen(URL + '/' + slug + '/', timeout=30) as response:
                    if response.status != 200 or expected not in response.read().decode():
                        sys.exit('Published post check failed: ' + slug)
                print('PASS anonymous post: ' + slug)
    elif args.command == 'activate':
        if len(args.args) != 2:
            parser.error('activate requires an HTTPS Entitle tenant API URL and an owner-only license-key file')
        key = Path(args.args[1])
        stat = key.lstat()
        if key.is_symlink() or not key.is_file() or stat.st_mode & 0o077 or stat.st_uid != os.getuid() or stat.st_size > 2048:
            sys.exit('Use an owner-only regular license-key file of at most 2 KiB.')
        wp('--user=admin', 'eval-file', '/workspace-tools/license.php', args.args[0],
           input=key.read_text().strip() + '\n', text=True)
    elif args.command == 'stop':
        compose('stop')
    else:
        compose('ps')


if __name__ == '__main__':
    main()
