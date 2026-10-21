#!/usr/bin/env python3
"""Build the disposable coverage runtime, collect all suites, and gate LCOV totals."""

import hashlib
import json
import importlib.util
import os
from pathlib import Path
import secrets
import shutil
import signal
import subprocess
import sys

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[1]


def read_profile(path):
    """Validate the exact declared collection policy before creating build outputs."""
    spec = importlib.util.spec_from_file_location('coverage_export', ROOT / 'tools/coverage-export.py')
    exporter = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(exporter)
    profile = json.loads(path.read_text(), object_pairs_hook=exporter.profile_object)
    if not isinstance(profile, dict):
        raise ValueError('Coverage profile must be an object')
    exporter.configure_profile(profile)
    return profile


IN_ACTIONS = os.environ.get('GITHUB_ACTIONS') == 'true'
if IN_ACTIONS:
    BASE = Path(os.environ['RUNNER_TEMP']) / 'remote-content-shortcode'
else:
    if not Path('/Volumes/Scratch').is_mount():
        sys.exit('Scratch must be mounted before running local Docker coverage.')
    BASE = Path('/Volumes/Scratch/remote-content-shortcode')
PROFILE_INPUT = Path(os.environ['RCS_COVERAGE_PROFILE']).resolve() if os.environ.get('RCS_COVERAGE_PROFILE') else None
TARGET = Path(os.environ.get('RCS_COVERAGE_SOURCE', ROOT)).resolve()
if PROFILE_INPUT and not (BASE in PROFILE_INPUT.parents or (IN_ACTIONS and Path(os.environ['RUNNER_TEMP']).resolve() in PROFILE_INPUT.parents)):
    sys.exit('Coverage profile must be prepared under project Scratch or RUNNER_TEMP.')
if not TARGET.is_dir():
    sys.exit('Coverage source directory is missing.')
profile = read_profile(PROFILE_INPUT) if PROFILE_INPUT else None
RUN = 'coverage-' + secrets.token_hex(4)
WORK = BASE / 'tmp' / RUN
REPORT = Path(os.environ.get('RCS_COVERAGE_REPORT_DIR', BASE / 'reports' / ('coverage' if IN_ACTIONS else RUN))).resolve()
if not (BASE in REPORT.parents or (IN_ACTIONS and Path(os.environ['RUNNER_TEMP']).resolve() in REPORT.parents)):
    sys.exit('Coverage reports must stay on project Scratch or RUNNER_TEMP.')
WORK.mkdir(parents=True)
REPORT.mkdir(parents=True, exist_ok=True)
(REPORT / 'raw').mkdir(exist_ok=True)
os.environ['TMPDIR'] = str(WORK)
os.environ['PYTHONDONTWRITEBYTECODE'] = '1'
IMAGE = 'remote-content-coverage:' + RUN
ARCHIVES = {
    'xdebug': ('https://github.com/xdebug/xdebug/archive/refs/tags/3.5.3.tar.gz',
               '954e56021668121ecc50b92d2ad1ce945f22ecf81ffc5bb5835219485b12ef5f'),
    'uopz': ('https://github.com/krakjoe/uopz/archive/14c8fc2d6eff14ec9acd926b9cab85d6961c64ac.tar.gz',
             '2057cc0eee2eecf22615d211fed1b5863e2b49196ebcf595616d03d5c58a8ffb'),
    'vld': ('https://github.com/derickr/vld/archive/a95224149bd1bf73246ec409e0934f33d915e1af.tar.gz',
            'e3f5b026399556ee575282ba63a20196f7949acf4836200b4a30a1f464cf4aef'),
}


def terminate(_signum, _frame):
    raise SystemExit(124)


def logged(arguments, name, timeout, environment=None, *, grace_period=30, kill_period=5):
    """Keep output on Scratch/runner storage and terminate CI cleanly on deadline."""
    log = REPORT / (name + '.txt')
    with log.open('w') as output:
        process = subprocess.Popen(arguments, env=environment, stdout=output,
                                   stderr=subprocess.STDOUT, start_new_session=True)
        try:
            status = process.wait(timeout=timeout)
        except (subprocess.TimeoutExpired, KeyboardInterrupt, SystemExit) as error:
            try:
                os.killpg(process.pid, signal.SIGTERM)
            except ProcessLookupError:
                pass
            try:
                process.wait(timeout=grace_period)
            except subprocess.TimeoutExpired:
                try:
                    os.killpg(process.pid, signal.SIGKILL)
                except ProcessLookupError:
                    pass
                process.wait(timeout=kill_period)
            if isinstance(error, subprocess.TimeoutExpired):
                raise RuntimeError(f'{name} exceeded {timeout} seconds; see {log}') from error
            raise
    if status:
        raise RuntimeError(f'{name} exited {status}; see {log}')


signal.signal(signal.SIGTERM, terminate)

try:
    logged([sys.executable, str(ROOT / 'tests/coverage-export.py')], 'exporter-tests', 30)
    logged([sys.executable, str(ROOT / 'tests/coverage-runner.py')], 'runner-tests', 30)
    # The build context contains only pinned archives and this Dockerfile.
    for name, (url, expected) in ARCHIVES.items():
        archive = WORK / (name + '.tar.gz')
        subprocess.run(['curl', '--fail', '--silent', '--show-error', '--location',
                        '--connect-timeout', '10', '--max-time', '28', '--output', str(archive), url],
                       check=True, timeout=30)
        if hashlib.sha256(archive.read_bytes()).hexdigest() != expected:
            raise RuntimeError('Archive checksum mismatch: ' + name)
    shutil.copy2(ROOT / 'tests/coverage.Dockerfile', WORK / 'Dockerfile')
    shutil.copy2(ROOT / 'tests/coverage-vld.patch', WORK / 'coverage-vld.patch')
    logged(['docker', 'build', '--tag', IMAGE, str(WORK)], 'build', 180)
    excluded = {'tests', 'tools', '.git', '.svn', 'vendor'}
    files = sorted(path.relative_to(TARGET).as_posix() for path in TARGET.rglob('*.php')
                   if path.is_file() and not path.is_symlink()
                   and not excluded.intersection(path.relative_to(TARGET).parts)
                   and not path.relative_to(TARGET).as_posix().startswith('inc/vendor/'))
    if not files:
        raise RuntimeError('No first-party PHP files found.')
    (WORK / 'files.json').write_text(json.dumps(files, indent=2) + '\n')
    shutil.copy2(WORK / 'files.json', REPORT / 'files.json')
    shutil.copy2(ROOT / 'tools/coverage-prepend.php', WORK / 'prepend.php')
    if profile:
        shutil.copy2(PROFILE_INPUT, WORK / 'profile.json')
        shutil.copy2(PROFILE_INPUT, REPORT / 'profile.json')
    environment = dict(os.environ, RCS_TEST_IMAGE=IMAGE, RCS_COVERAGE_DIR=str(WORK),
                       RCS_REPORT_DIR=str(REPORT), RCS_FAULT_TESTS='0' if profile else '1', RCS_PHP_VERSION='8.2',
                       RCS_WORDPRESS_VERSION='7.1.3')
    logged([sys.executable, str(ROOT / 'tools/ci.py')], 'validation', 300, environment)
    logged([sys.executable, str(ROOT / 'tools/coverage-export.py'), '--root', str(TARGET),
            '--report', str(REPORT), '--manifest', str(WORK / 'files.json'), '--expected-cases', str(len(profile['cases']) if profile else 47),
            *(['--profile', str(WORK / 'profile.json')] if profile else [])],
           'export', 30)
    print((REPORT / 'summary.txt').read_text(), end='')
    print('Coverage reports: ' + str(REPORT))
finally:
    # These are unique task-owned paths and an image tag, never shared caches.
    try:
        subprocess.run(['docker', 'image', 'rm', IMAGE], capture_output=True, timeout=15)
    finally:
        shutil.rmtree(WORK)
