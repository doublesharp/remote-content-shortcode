#!/usr/bin/env python3
"""Independently bind a release ZIP's exact inventory and bytes to this checkout."""
import argparse
from pathlib import Path
import stat
import zipfile


def verify(archive, root):
    files = {
        'remote-content-shortcode.php', 'readme.txt', 'LICENSE.txt', 'third-party.txt',
        'composer.json', 'composer.lock', 'SECURITY.md', 'docs/integrations.md',
        'docs/security.md', 'docs/troubleshooting.md', 'docs/upgrading.md', 'uninstall.php',
        'blocks/remote-content/block.json', 'blocks/remote-content/editor.js',
        'blocks/remote-content/editor.asset.php',
    }
    for path in (root / 'inc').rglob('*'):
        if path.is_symlink():
            raise ValueError('Linked source is not a release input.')
        if path.is_file():
            files.add(path.relative_to(root).as_posix())
    expected = {'remote-content-shortcode/' + name: name for name in files}
    with zipfile.ZipFile(archive) as release:
        entries = release.infolist()
        if len(entries) != len(expected) or set(release.namelist()) != set(expected):
            raise ValueError('Release ZIP inventory differs from the checkout.')
        if sum(info.file_size for info in entries) > 16 * 1024 * 1024:
            raise ValueError('Release ZIP exceeds the total size limit.')
        for info in entries:
            source = root / expected[info.filename]
            if source.is_symlink() or not source.is_file() or info.file_size > 8 * 1024 * 1024:
                raise ValueError('Invalid release input.')
            kind = stat.S_IFMT(info.external_attr >> 16)
            if kind not in (0, stat.S_IFREG) or info.file_size != source.stat().st_size:
                raise ValueError('Release entry type or size differs from the checkout.')
            if release.read(info) != source.read_bytes():
                raise ValueError('Release entry bytes differ from the checkout: ' + info.filename)


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('archive', type=Path)
    args = parser.parse_args()
    verify(args.archive, Path(__file__).resolve().parents[1])
    print('PASS release inventory and bytes match the reviewed checkout')
