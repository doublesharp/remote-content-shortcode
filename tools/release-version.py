#!/usr/bin/env python3
"""Validate release version metadata and print the version for workflows."""

import argparse
import json
from pathlib import Path
import re

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--tag', help='Require a numeric Git tag matching the release metadata.')
args = parser.parse_args()
root = Path(__file__).resolve().parents[1]
header = re.search(r'^\s*\* Version:\s*(\S+)', (root / 'remote-content-shortcode.php').read_text(), re.M)
stable = re.search(r'^Stable tag:\s*(\S+)', (root / 'readme.txt').read_text(), re.M)
block = json.loads((root / 'blocks/remote-content/block.json').read_text())['version']
if not header or not stable or not re.fullmatch(r'\d+\.\d+(?:\.\d+)?', header[1]):
    parser.error('Plugin header and readme must specify a numeric release version.')
version = header[1]
if stable[1] != version or block != version or (args.tag is not None and args.tag != version):
    parser.error('Git tag, plugin header, stable tag, and block version must match exactly.')
print(version)
