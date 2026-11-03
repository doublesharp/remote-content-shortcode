#!/usr/bin/env python3
"""Make an isolated Pro update fixture from an existing installable ZIP."""
import argparse
from pathlib import Path
import re
import zipfile

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('base_zip', type=Path)
parser.add_argument('version')
parser.add_argument('output', type=Path)
args = parser.parse_args()
if not re.fullmatch(r'2\.0\.[1-9][0-9]*', args.version):
    parser.error('Use a stable test version such as 2.0.1, published only in the sandbox')
if not Path('/Volumes/Scratch').is_mount() or Path('/Volumes/Scratch') not in args.output.resolve().parents:
    parser.error('Output must be under mounted /Volumes/Scratch')
if args.base_zip.resolve() == args.output.resolve() or args.output.exists():
    parser.error('Choose a new output archive')
main = 'remote-content-shortcode-pro/remote-content-shortcode-pro.php'
with zipfile.ZipFile(args.base_zip) as base:
    files = {entry.filename: base.read(entry) for entry in base.infolist()}
if main not in files:
    parser.error('The input must be the separately installable Pro ZIP')
php = files[main].decode()
php, headers = re.subn(r'(?m)^( \* Version: )[^\r\n]+', lambda m: m[1] + args.version, php)
php, constants = re.subn(r"define\('RCS_PRO_VERSION', '[^']+'\);", "define('RCS_PRO_VERSION', '" + args.version + "');", php)
if headers != 1 or constants != 1:
    parser.error('Unexpected Pro version declarations')
php += "\n// Isolated Entitle delivery fixture; installed production sources are unchanged.\n"
php += "define('RCS_LAB_RELEASE_MARKER', '" + args.version + "');\n"
files[main] = php.encode()
readme = 'remote-content-shortcode-pro/readme.txt'
files[readme] = re.sub(rb'(?m)^Stable tag: [^\r\n]+', b'Stable tag: ' + args.version.encode(), files[readme])
args.output.parent.mkdir(parents=True, exist_ok=True)
with zipfile.ZipFile(args.output, 'x', zipfile.ZIP_DEFLATED) as output:
    for name, data in sorted(files.items()):
        if not name.startswith('remote-content-shortcode-pro/') or '..' in Path(name).parts:
            parser.error('Unexpected package path')
        info = zipfile.ZipInfo(name, (1980, 1, 1, 0, 0, 0))
        info.external_attr = 0o100644 << 16
        info.compress_type = zipfile.ZIP_DEFLATED
        output.writestr(info, data)
print(args.output)
