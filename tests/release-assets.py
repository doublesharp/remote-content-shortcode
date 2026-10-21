#!/usr/bin/env python3
"""Check release publication ordering and refusal paths without contacting GitHub."""

import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
BASE = Path(os.environ['RUNNER_TEMP']) if os.environ.get('GITHUB_ACTIONS') == 'true' else Path('/Volumes/Scratch/remote-content-shortcode/tmp')
if not BASE.is_dir():
    raise SystemExit('Scratch or GitHub RUNNER_TEMP must exist for release tests.')


class ReleaseAssets(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='release-assets-', dir=BASE)
        self.directory = Path(self.temp.name)
        self.log = self.directory / 'calls.json'
        self.archive = self.directory / 'remote-content-shortcode-2.0.zip'
        self.archive.write_bytes(b'test plugin ZIP bytes')
        self.checksum = self.directory / (self.archive.name + '.sha256')
        self.checksum.write_text(hashlib.sha256(self.archive.read_bytes()).hexdigest() + '  ' + self.archive.name + '\n')
        gh = self.directory / 'gh'
        gh.write_text('''#!''' + sys.executable + '''
import json, os, sys
with open(os.environ['RCS_GH_LOG'], 'a') as log: log.write(json.dumps(sys.argv[1:])+'\\n')
if sys.argv[1] == 'api':
    state=os.environ['RCS_GH_STATE']
    if state == 'missing': print('HTTP/2.0 404 Not Found\\n\\n{}'); sys.exit(1)
    if state == 'api-error': print('HTTP/2.0 403 Forbidden\\n\\n{}'); sys.exit(1)
    print('HTTP/2.0 200 OK\\n\\n'+json.dumps({'draft': state == 'draft'}))
if sys.argv[1:3] == ['release', 'upload'] and os.environ.get('RCS_GH_UPLOAD_FAIL') == '1': sys.exit(1)
''')
        gh.chmod(0o700)
        self.env = os.environ | {'PATH': str(self.directory) + os.pathsep + os.environ['PATH'],
                                 'GH_REPO': 'doublesharp/remote-content-shortcode',
                                 'RCS_GH_LOG': str(self.log), 'RCS_GH_STATE': 'missing'}

    def tearDown(self):
        self.temp.cleanup()

    def run_release(self, *extra):
        return subprocess.run([sys.executable, str(ROOT / 'tools/github-release.py'), '--tag', '2.0',
                               '--version', '2.0', '--directory', str(self.directory), *extra],
                              env=self.env, capture_output=True, text=True, timeout=10)

    def calls(self):
        return [json.loads(line) for line in self.log.read_text().splitlines()] if self.log.exists() else []

    def test_dry_run_prepares_notes_without_github_calls(self):
        self.assertEqual(0, self.run_release('--prepare-only').returncode)
        self.assertEqual([], self.calls())
        notes = (self.directory / 'release-notes.md').read_text()
        self.assertIn('CVE-2024-2089', notes)
        self.assertIn(self.archive.name, notes)

    def test_checksum_mismatch_stops_before_github(self):
        self.archive.write_bytes(b'changed bytes')
        self.assertNotEqual(0, self.run_release().returncode)
        self.assertEqual([], self.calls())

    def test_new_release_stays_draft_until_both_assets_upload(self):
        self.assertEqual(0, self.run_release().returncode)
        calls = self.calls()
        self.assertEqual(['api', 'release', 'release', 'release'], [call[0] for call in calls])
        self.assertEqual('create', calls[1][1])
        self.assertIn('--verify-tag', calls[1])
        self.assertIn('--draft', calls[1])
        self.assertEqual('upload', calls[2][1])
        self.assertIn(str(self.archive), calls[2])
        self.assertIn(str(self.checksum), calls[2])
        self.assertEqual(['release', 'edit', '2.0', '--draft=false'], calls[3])

    def test_published_release_is_not_overwritten(self):
        self.env['RCS_GH_STATE'] = 'published'
        self.assertNotEqual(0, self.run_release().returncode)
        self.assertEqual(1, len(self.calls()))

    def test_api_failure_stops_without_creation(self):
        self.env['RCS_GH_STATE'] = 'api-error'
        self.assertNotEqual(0, self.run_release().returncode)
        self.assertEqual(1, len(self.calls()))

    def test_existing_draft_gets_assets_before_publication(self):
        self.env['RCS_GH_STATE'] = 'draft'
        self.assertEqual(0, self.run_release().returncode)
        self.assertEqual(['upload', 'edit'], [call[1] for call in self.calls()[1:]])

    def test_failed_upload_never_publishes_the_draft(self):
        self.env['RCS_GH_UPLOAD_FAIL'] = '1'
        self.assertNotEqual(0, self.run_release().returncode)
        self.assertEqual(['create', 'upload'], [call[1] for call in self.calls()[1:]])


if __name__ == '__main__':
    unittest.main()
