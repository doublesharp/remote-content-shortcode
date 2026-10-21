#!/usr/bin/env python3
"""Publish installable assets after the workflow verifies the WordPress SVN release."""

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--tag', required=True)
parser.add_argument('--version', required=True)
parser.add_argument('--directory', required=True, type=Path)
parser.add_argument('--prepare-only', action='store_true', help='Verify assets and write notes without calling GitHub.')
args = parser.parse_args()
if args.tag != args.version or not re.fullmatch(r'\d+\.\d+(?:\.\d+)?', args.version):
    parser.error('The existing numeric Git tag and plugin version must match.')
root = Path(__file__).resolve().parents[1]
directory = args.directory.resolve()
runner = Path(os.environ['RUNNER_TEMP']).resolve() if os.environ.get('GITHUB_ACTIONS') == 'true' and os.environ.get('RUNNER_TEMP') else None
if Path('/Volumes/Scratch') not in directory.parents and not (runner and runner in directory.parents):
    parser.error('Release files must live on Scratch or under GitHub RUNNER_TEMP.')
archive = directory / f'remote-content-shortcode-{args.version}.zip'
checksum = directory / (archive.name + '.sha256')
expected = hashlib.sha256(archive.read_bytes()).hexdigest() + '  ' + archive.name + '\n'
if checksum.read_text() != expected:
    parser.error('Release ZIP and SHA256 file do not match.')
readme = (root / 'readme.txt').read_text()
changelog = readme.split('== Changelog ==', 1)[1]
match = re.search(r'^= ' + re.escape(args.version) + r' =\s*\n(.*?)(?=^= |\Z)', changelog, re.M | re.S)
if not match:
    parser.error('No changelog entry exists for this version.')
notes = directory / 'release-notes.md'
notes.write_text(
    f'Download **{archive.name}** and upload it through WordPress Plugins > Add New > Upload Plugin. '
    'The matching `.sha256` file verifies the download.\n\n'
    'WordPress.org directory availability and update distribution depend on WordPress review and release confirmation.\n\n'
    'See [upgrading from 1.x](https://github.com/doublesharp/remote-content-shortcode/blob/' + args.tag + '/docs/upgrading.md) '
    'before updating an existing installation.\n\n' + match[1].strip() + '\n'
)
print('Verified ZIP checksum and prepared version-specific release notes.')
if args.prepare_only:
    raise SystemExit(0)
# A retry can complete assets left on a draft, but must never replace an existing published release.
result = subprocess.run(['gh', 'api', '--include', f'repos/{os.environ["GH_REPO"]}/releases/tags/{args.tag}'], capture_output=True, text=True, timeout=30)
if result.returncode == 0:
    body = result.stdout.split('\r\n\r\n', 1)[-1] if '\r\n\r\n' in result.stdout else result.stdout.split('\n\n', 1)[-1]
    release = json.loads(body)
    if not release.get('draft'):
        raise SystemExit('A published GitHub release already exists; it will not be overwritten.')
elif not re.search(r'^HTTP/\S+ 404\b', result.stdout, re.M):
    raise SystemExit('Unable to check the existing GitHub release; publication stopped.')
else:
    subprocess.run(['gh', 'release', 'create', args.tag, '--verify-tag', '--draft',
                    '--title', 'Remote Content Shortcode ' + args.version, '--notes-file', str(notes)], check=True, timeout=30)
subprocess.run(['gh', 'release', 'upload', args.tag, str(archive), str(checksum), '--clobber'], check=True, timeout=30)
subprocess.run(['gh', 'release', 'edit', args.tag, '--draft=false'], check=True, timeout=30)
print('Published the GitHub release with its verified plugin ZIP and checksum.')
