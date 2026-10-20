#!/usr/bin/env python3
"""Run a task-owned Docker test with a hard deadline and container cleanup."""

import argparse
import os
from pathlib import Path
import subprocess
import sys

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument("--name", required=True)
parser.add_argument("--log", required=True, type=Path)
parser.add_argument("--timeout", type=int, default=60)
parser.add_argument("arguments", nargs=argparse.REMAINDER)
args = parser.parse_args()
if not args.name.startswith("remote-content-") or not 1 <= args.timeout <= 60:
    parser.error("Use a task-owned container name and a timeout of 1 to 60 seconds.")
roots = [Path('/Volumes/Scratch/remote-content-shortcode')]
if os.environ.get('GITHUB_ACTIONS') == 'true' and os.environ.get('RUNNER_TEMP'):
    roots.append(Path(os.environ['RUNNER_TEMP']).resolve())
if not any(root in args.log.resolve().parents for root in roots):
    parser.error("Test logs must live on this project's Scratch directory or GitHub RUNNER_TEMP.")
arguments = args.arguments[1:] if args.arguments[:1] == ["--"] else args.arguments
args.log.parent.mkdir(parents=True, exist_ok=True)
status = 1
try:
    with args.log.open("w") as log:
        result = subprocess.run(
            ["docker", "run", "--rm", "--name", args.name, *arguments],
            stdout=log, stderr=subprocess.STDOUT, timeout=args.timeout,
        )
        status = result.returncode
except subprocess.TimeoutExpired:
    status = 124
    print(f"TIMEOUT after {args.timeout}s: {args.name}", file=sys.stderr)
finally:
    subprocess.run(["docker", "rm", "-f", args.name], capture_output=True, timeout=10)
print(f"{args.name}: exit {status}; {args.log}")
sys.exit(status)
