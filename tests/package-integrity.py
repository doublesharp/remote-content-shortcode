#!/usr/bin/env python3
"""A matching checksum must not make substituted release bytes acceptable."""
import importlib.util
import os
from pathlib import Path
import stat
import tempfile
import unittest
import zipfile

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('verify_package', ROOT / 'tools/verify-package.py')
verifier = importlib.util.module_from_spec(spec)
spec.loader.exec_module(verifier)


class PackageIntegrity(unittest.TestCase):
    def setUp(self):
        self.work = tempfile.TemporaryDirectory(dir=os.environ['TMPDIR'])
        self.archive = Path(self.work.name) / 'plugin.zip'
        self.entries = {}
        files = ['remote-content-shortcode.php', 'readme.txt', 'LICENSE.txt', 'third-party.txt',
                 'composer.json', 'composer.lock', 'SECURITY.md', 'docs/integrations.md',
                 'docs/security.md', 'docs/troubleshooting.md', 'docs/upgrading.md', 'uninstall.php',
                 'blocks/remote-content/block.json', 'blocks/remote-content/editor.js',
                 'blocks/remote-content/editor.asset.php']
        files += [p.relative_to(ROOT).as_posix() for p in (ROOT / 'inc').rglob('*') if p.is_file()]
        for name in files:
            self.entries['remote-content-shortcode/' + name] = (ROOT / name).read_bytes()

    def tearDown(self):
        self.work.cleanup()

    def build(self, entries=None):
        with zipfile.ZipFile(self.archive, 'w') as archive:
            for name, data in (self.entries if entries is None else entries).items():
                archive.writestr(name, data)

    def test_matching_source(self):
        self.build()
        verifier.verify(self.archive, ROOT)

    def test_changed_bytes_are_rejected(self):
        self.entries['remote-content-shortcode/remote-content-shortcode.php'] = b'substituted'
        self.build()
        with self.assertRaises(ValueError):
            verifier.verify(self.archive, ROOT)

    def test_extra_and_missing_entries_are_rejected(self):
        for entries in (self.entries | {'remote-content-shortcode/extra.php': b'inert'},
                        {k: v for k, v in self.entries.items() if not k.endswith('readme.txt')}):
            self.build(entries)
            with self.assertRaises(ValueError):
                verifier.verify(self.archive, ROOT)

    def test_duplicate_entry_is_rejected(self):
        self.build()
        with zipfile.ZipFile(self.archive, 'a') as archive:
            archive.writestr('remote-content-shortcode/readme.txt', self.entries['remote-content-shortcode/readme.txt'])
        with self.assertRaises(ValueError):
            verifier.verify(self.archive, ROOT)

    def test_link_entry_is_rejected(self):
        with zipfile.ZipFile(self.archive, 'w') as archive:
            for name, data in self.entries.items():
                info = zipfile.ZipInfo(name)
                info.external_attr = (stat.S_IFLNK | 0o777) << 16
                archive.writestr(info, data)
        with self.assertRaises(ValueError):
            verifier.verify(self.archive, ROOT)


if __name__ == '__main__':
    unittest.main()
