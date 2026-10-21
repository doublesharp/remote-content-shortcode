#!/usr/bin/env python3
"""Coverage runner process cleanup and profile policy regressions; no Docker."""

import ast
import json
import os
from pathlib import Path
import signal
import subprocess
import sys
import tempfile
import time
import unittest
from unittest import mock


SOURCE = Path(__file__).resolve().parents[1] / 'tools/coverage.py'
TREE = ast.parse(SOURCE.read_text(), filename=str(SOURCE))
HELPERS = ast.Module(body=[node for node in TREE.body
                          if isinstance(node, (ast.Import, ast.ImportFrom, ast.FunctionDef))],
                     type_ignores=[])
NAMESPACE = {'__file__': str(SOURCE), 'ROOT': SOURCE.parents[1]}
exec(compile(HELPERS, str(SOURCE), 'exec'), NAMESPACE)


class CoverageRunnerTests(unittest.TestCase):
    def setUp(self):
        if os.environ.get('GITHUB_ACTIONS') == 'true':
            base = Path(os.environ['RUNNER_TEMP']) / 'remote-content-shortcode/tmp'
        else:
            if not Path('/Volumes/Scratch').is_mount():
                self.fail('Scratch must be mounted before creating process fixtures.')
            base = Path('/Volumes/Scratch/remote-content-shortcode/tmp')
        base.mkdir(parents=True, exist_ok=True)
        temporary = tempfile.TemporaryDirectory(prefix='coverage-runner-tests-', dir=base)
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        NAMESPACE['REPORT'] = self.root

    def child(self, marker, ready, ignore=False):
        handler = 'signal.SIG_IGN' if ignore else 'lambda *_: (marker.write_text("cleaned"), sys.exit(0))'
        return ('import os, signal, sys, time; from pathlib import Path; '
                f'marker=Path({str(marker)!r}); signal.signal(signal.SIGTERM, {handler}); '
                f'Path({str(ready)!r}).write_text(str(os.getpid())); time.sleep(30)')

    def wait_for(self, path, seconds=3):
        deadline = time.monotonic() + seconds
        while not path.exists() and time.monotonic() < deadline:
            time.sleep(0.01)
        self.assertTrue(path.exists(), 'Child did not reach the expected lifecycle marker.')

    def test_success_and_nonzero_status_keep_log_and_error_context(self):
        NAMESPACE['logged']([sys.executable, '-c', 'print("coverage-output")'], 'success', 2)
        self.assertEqual((self.root / 'success.txt').read_text(), 'coverage-output\n')
        with self.assertRaisesRegex(RuntimeError, r'failure exited 7; see .*failure.txt'):
            NAMESPACE['logged']([sys.executable, '-c', 'raise SystemExit(7)'], 'failure', 2)

    def test_timeout_sends_sigterm_to_child_and_descendant_before_reporting_failure(self):
        marker, ready = self.root / 'cleaned', self.root / 'ready'
        grand_marker, grand_ready = self.root / 'grand-cleaned', self.root / 'grand-ready'
        grand = self.child(grand_marker, grand_ready)
        parent = ('import subprocess; '
                  f'subprocess.Popen([{sys.executable!r}, "-c", {grand!r}]); ' + self.child(marker, ready))
        with self.assertRaisesRegex(RuntimeError, r'deadline exceeded 0.3 seconds; see .*deadline.txt'):
            NAMESPACE['logged']([sys.executable, '-c', parent], 'deadline', 0.3)
        self.wait_for(marker)
        self.wait_for(grand_marker)

    def test_sigterm_ignoring_child_is_killed_and_reaped(self):
        self.assertIn('grace_period', NAMESPACE['logged'].__code__.co_varnames,
                      'The runner needs bounded graceful shutdown followed by forced cleanup')
        marker, ready = self.root / 'unused', self.root / 'ignore-ready'
        started = time.monotonic()
        with self.assertRaisesRegex(RuntimeError, 'deadline exceeded'):
            NAMESPACE['logged']([sys.executable, '-c', self.child(marker, ready, ignore=True)],
                                'deadline', 0.2, grace_period=0.1, kill_period=1)
        self.assertLess(time.monotonic() - started, 2)
        self.wait_for(ready)
        with self.assertRaises(ProcessLookupError):
            os.kill(int(ready.read_text()), 0)

    def test_outer_sigterm_and_keyboard_interrupt_clean_up_before_failure(self):
        self.assertTrue(callable(NAMESPACE.get('terminate')),
                        'SIGTERM must unwind coverage runner cleanup rather than abandon children')
        for incoming in (signal.SIGTERM, signal.SIGINT):
            with self.subTest(signal=incoming):
                marker, ready = self.root / f'cleaned-{incoming}', self.root / f'ready-{incoming}'
                child = self.child(marker, ready)
                wrapper = ('import ast, pathlib, signal, sys; '
                           f'source=pathlib.Path({str(SOURCE)!r}); '
                           'tree=ast.parse(source.read_text()); '
                           'tree.body=[node for node in tree.body if isinstance(node, '
                           '(ast.Import, ast.ImportFrom, ast.FunctionDef)) or '
                           '(isinstance(node, ast.Expr) and isinstance(node.value, ast.Call) and '
                           'isinstance(node.value.func, ast.Attribute) and node.value.func.attr == "signal")]; '
                           f'ns={{"REPORT": pathlib.Path({str(self.root)!r})}}; '
                           'exec(compile(tree, str(source), "exec"), ns); '
                           f'ns["logged"]([{sys.executable!r}, "-c", {child!r}], "outer", 30)')
                process = subprocess.Popen([sys.executable, '-B', '-c', wrapper],
                                           stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                                           start_new_session=True)
                try:
                    self.wait_for(ready)
                    os.kill(process.pid, incoming)
                    code = process.wait(timeout=5)
                    self.assertNotEqual(code, 0)
                    if incoming == signal.SIGTERM:
                        self.assertEqual(code, 124)
                    self.wait_for(marker)
                finally:
                    if process.poll() is None:
                        os.killpg(process.pid, signal.SIGKILL)
                        process.wait(timeout=2)
                    if ready.exists():
                        try:
                            os.killpg(int(ready.read_text()), signal.SIGKILL)
                        except ProcessLookupError:
                            pass

    def test_profile_loading_requires_declared_cases_and_cannot_lower_gates(self):
        self.assertTrue(callable(NAMESPACE.get('read_profile')),
                        'Runner profile loading must use strict exporter validation')
        profile = {'prefixes': ['/pro-source/'], 'cases': ['first', 'second'],
                   'minimums': {'lines': 100, 'functions': 100, 'branches': 95}}
        path = self.root / 'profile.json'
        path.write_text(json.dumps(profile))
        self.assertEqual(NAMESPACE['read_profile'](path), profile)
        for text in ('null', json.dumps(dict(profile, cases=[])),
                     json.dumps(dict(profile, minimums=dict(profile['minimums'], branches=94))),
                     json.dumps(dict(profile, minimums=dict(profile['minimums'], lines=99))),
                     json.dumps(dict(profile, minimums=dict(profile['minimums'], functions=99))),
                     json.dumps(profile)[:-1] + ', "cases": ["duplicate-key"]}'):
            with self.subTest(text=text):
                path.write_text(text)
                with self.assertRaises(ValueError):
                    NAMESPACE['read_profile'](path)

    def test_default_configuration_preserves_free_source_and_profile_policy(self):
        preamble = []
        for node in TREE.body:
            if (isinstance(node, ast.Expr) and isinstance(node.value, ast.Call)
                    and isinstance(node.value.func, ast.Attribute) and node.value.func.attr == 'mkdir'):
                break
            preamble.append(node)
        environment = dict(os.environ)
        for name in ('RCS_COVERAGE_PROFILE', 'RCS_COVERAGE_SOURCE', 'RCS_COVERAGE_REPORT_DIR'):
            environment.pop(name, None)
        namespace = {'__file__': str(SOURCE)}
        with mock.patch.dict(os.environ, environment, clear=True):
            exec(compile(ast.Module(body=preamble, type_ignores=[]), str(SOURCE), 'exec'), namespace)
        self.assertIsNone(namespace['profile'])
        self.assertIsNone(namespace['PROFILE_INPUT'])
        self.assertEqual(namespace['TARGET'], SOURCE.parents[1].resolve())
        self.assertIn(namespace['BASE'], namespace['REPORT'].parents)

    def test_invalid_source_and_external_report_or_profile_paths_fail_before_build(self):
        environment = dict(os.environ, PYTHONDONTWRITEBYTECODE='1')
        for name in ('RCS_COVERAGE_PROFILE', 'RCS_COVERAGE_SOURCE', 'RCS_COVERAGE_REPORT_DIR'):
            environment.pop(name, None)
        for overrides, error in (
                ({'RCS_COVERAGE_SOURCE': str(self.root / 'missing')}, 'source directory is missing'),
                ({'RCS_COVERAGE_REPORT_DIR': '/outside-scratch/reports'}, 'reports must stay'),
                ({'RCS_COVERAGE_PROFILE': '/outside-scratch/profile.json'}, 'profile must be prepared')):
            with self.subTest(overrides=overrides):
                result = subprocess.run([sys.executable, '-B', str(SOURCE)],
                                        env=dict(environment, **overrides), capture_output=True,
                                        text=True, timeout=3)
                self.assertNotEqual(result.returncode, 0)
                self.assertIn(error, result.stderr)


if __name__ == '__main__':
    unittest.main()
