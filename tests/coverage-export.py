#!/usr/bin/env python3
"""Measurement-integrity regressions for the coverage report exporter."""

import copy
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest


SPEC = importlib.util.spec_from_file_location(
    'coverage_export', Path(__file__).resolve().parents[1] / 'tools/coverage-export.py')
coverage = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(coverage)
if os.environ.get('GITHUB_ACTIONS') == 'true':
    SCRATCH = Path(os.environ['RUNNER_TEMP']) / 'remote-content-shortcode/tmp'
else:
    if not Path('/Volumes/Scratch').is_mount():
        raise SystemExit('Scratch must be mounted before running coverage exporter tests.')
    SCRATCH = Path('/Volumes/Scratch/remote-content-shortcode/tmp')
SCRATCH.mkdir(parents=True, exist_ok=True)


def block(start, end, line, targets, hit=0, edge_hits=None):
    return {'shape': {'op_start': start, 'op_end': end, 'line_start': line,
                      'line_end': line, 'out': {str(i): target for i, target in enumerate(targets)}},
            'hit': hit, 'out_hit': {str(i): value for i, value in enumerate(
                edge_hits if edge_hits is not None else [0] * len(targets))}}


def instruction(opcode, operands='', line=3):
    return {'opcode': opcode, 'operands': operands, 'line': line}


class CoverageExportTests(unittest.TestCase):
    def setUp(self):
        if hasattr(coverage, 'configure_profile'):
            coverage.configure_profile()
            self.addCleanup(coverage.configure_profile)
        self.temporary = tempfile.TemporaryDirectory(prefix='coverage-export-tests-', dir=SCRATCH)
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.report = self.root / 'report'
        self.report.mkdir()
        self.source = self.root / 'fixture.php'
        self.source.write_text('<?php\nfunction fixture() {\n    while (true) {}\n}\n')

    def profile(self):
        return {'prefixes': ['/pro-source/', '/wordpress/wp-content/plugins/private-plugin/'],
                'cases': ['pro-first', 'pro-second'],
                'minimums': {'lines': 100, 'functions': 100, 'branches': 95}}

    def configure(self, profile):
        self.assertTrue(callable(getattr(coverage, 'configure_profile', None)),
                        'The exporter must support explicit coverage profiles')
        coverage.configure_profile(profile)

    def test_profile_defaults_preserve_free_roots_cases_and_minimums(self):
        original_cases = set(coverage.CASES)
        self.assertEqual(len(original_cases), 47)
        self.configure(self.profile())
        coverage.configure_profile()
        self.assertEqual(coverage.PREFIXES,
                         ('/wordpress/wp-content/plugins/remote-content-shortcode/', '/source/'))
        self.assertEqual(coverage.CASES, original_cases)
        self.assertEqual(coverage.MINIMUMS, {'lines': 100, 'functions': 100, 'branches': 95})

    def test_profile_rejects_invalid_shape_roots_cases_and_weakened_gates_atomically(self):
        valid = self.profile()
        bad_profiles = [None, [], {}, dict(valid, extra=True)]
        for key in valid:
            missing = copy.deepcopy(valid)
            del missing[key]
            bad_profiles.append(missing)
        for prefixes in ([], '/source/', ['/source'], ['source/'], ['/'], ['/source//'],
                         ['/source/../'], ['/./'], ['/source/\\bad/'], ['/source/\x00/'],
                         ['/source/', '/source/'], [4]):
            bad_profiles.append(dict(valid, prefixes=prefixes))
        for cases in ([], 'pro-first', ['same', 'same'], ['../escape'], ['UPPER'], ['bad_name'],
                      ['bad\n'], [''], [False]):
            bad_profiles.append(dict(valid, cases=cases))
        for minimums in ({}, {'lines': 100, 'functions': 100, 'branches': 94},
                         {'lines': 99, 'functions': 100, 'branches': 95},
                         {'lines': 100, 'functions': 99, 'branches': 95},
                         {'lines': 100, 'functions': 100, 'branches': 101},
                         {'lines': 100, 'functions': 100, 'branches': True},
                         {'lines': 100, 'functions': 100, 'branches': '95'},
                         dict(valid['minimums'], extra=100)):
            bad_profiles.append(dict(valid, minimums=minimums))
        self.configure(valid)
        for bad in bad_profiles:
            # None is the documented reset operation, rather than a JSON profile.
            if bad is None:
                continue
            with self.subTest(profile=bad):
                with self.assertRaises(ValueError):
                    coverage.configure_profile(bad)
                self.assertEqual(coverage.PREFIXES, tuple(valid['prefixes']))
                self.assertEqual(coverage.CASES, set(valid['cases']))
                self.assertEqual(coverage.MINIMUMS, valid['minimums'])
        self.configure(dict(valid, minimums=dict(valid['minimums'], branches=100)))
        self.assertEqual(coverage.MINIMUMS['branches'], 100)

    def test_profile_custom_roots_apply_to_raw_records_names_and_opcode_metadata(self):
        self.configure(self.profile())
        raw_dir, data = self.raw_collection()
        entry = data['coverage'].pop('/source/fixture.php')
        entry['functions']['{closure:/pro-source/fixture.php:3-4}'] = {'branches': {}}
        data['coverage']['/pro-source/fixture.php'] = entry
        data['coverage']['/pro-source/vendor/excluded.php'] = {'lines': {'1': 1}}
        data['hashes'].append('vendor-is-outside-manifest')
        for target in raw_dir.glob('*.json'):
            target.write_text(json.dumps(data))
        merged, cases, _ = coverage.merge(self.root, self.report, ['fixture.php'], 2)
        self.assertEqual(set(merged), {'fixture.php'})
        self.assertEqual(set(cases), {'pro-first', 'pro-second'})
        self.assertIn('{closure:fixture.php:3-4}', merged['fixture.php']['functions'])
        self.assertIsNone(coverage.relative('/source/fixture.php', ['fixture.php']))
        dump = self.root / 'opcodes.txt'
        dump.write_text('filename:       /pro-source/fixture.php\nfunction name:  fixture\n'
                        '    3     0  E >   RETURN                           null\n'
                        'PASS captured compiler metadata\n')
        self.assertEqual(coverage.parse_opcodes(dump, ['fixture.php'])[0]['file'], 'fixture.php')

    def test_profile_requires_declared_case_names_even_when_counts_match(self):
        profile = self.profile()
        profile['prefixes'] = ['/source/']
        self.configure(profile)
        raw_dir, _ = self.raw_collection()
        coverage.merge(self.root, self.report, ['fixture.php'], 2)
        (raw_dir / 'pro-first.json').rename(raw_dir / 'unlisted-case.json')
        with self.assertRaisesRegex(AssertionError, 'test collection mismatch'):
            coverage.merge(self.root, self.report, ['fixture.php'], 2)
        (raw_dir / 'unlisted-case.json').unlink()
        with self.assertRaisesRegex(AssertionError, 'test collection mismatch'):
            coverage.merge(self.root, self.report, ['fixture.php'], 1)

    def test_cli_rejects_invalid_profiles_before_reading_collected_records(self):
        profile = self.root / 'profile.json'
        for value in ('null', '{"prefixes": []}',
                      json.dumps(self.profile())[:-1] + ', "cases": ["duplicate-key"]}'):
            with self.subTest(value=value):
                profile.write_text(value)
                result = subprocess.run([sys.executable, str(SPEC.origin), '--root', str(self.root),
                                         '--report', str(self.report), '--manifest', str(self.root / 'absent.json'),
                                         '--expected-cases', '2', '--profile', str(profile)],
                                        capture_output=True, text=True)
                self.assertNotEqual(result.returncode, 0)
                self.assertIn('Invalid coverage profile', result.stderr)
                self.assertNotIn('absent.json', result.stderr.split('error:')[-1])

    def section(self, ops, name='fixture', owner=None):
        return {'file': 'fixture.php', 'name': name, 'owner': owner, 'ops': ops}

    def loop(self, operand='<true>'):
        function = {'branches': {0: block(0, 0, 3, [0, 1], hit=1, edge_hits=[1, 0]),
                                 1: block(1, 1, 4, [])}}
        info = {'lines': {3: 1, 4: 0}, 'functions': {'fixture': function}}
        ops = {0: instruction('JMPNZ', operand + ', ->0'),
               1: instruction('RETURN', 'null', line=4)}
        return info, {'fixture': ops}

    def dead_lines(self, info, maps, braces=None):
        return coverage.compiler_dead_lines(self.root, 'fixture.php', info, maps,
                                            {'fixture.php': [4]} if braces is None else braces)

    def test_dead_epilogue_allows_only_unreachable_debug_markers(self):
        info, maps = self.loop()
        info['functions']['fixture']['branches'][1] = block(1, 2, 4, [])
        maps['fixture'][1] = instruction('EXT_STMT', line=4)
        maps['fixture'][2] = instruction('RETURN', 'null', line=4)
        self.assertEqual(len(self.dead_lines(info, maps)), 1)
        maps['fixture'][1]['opcode'] = 'ECHO'
        self.assertEqual(self.dead_lines(info, maps), [])
        maps['fixture'][1]['opcode'] = 'EXT_STMT'
        info['functions']['fixture']['branches'][0]['shape']['out']['1'] = 1
        maps['fixture'][0]['operands'] = '!0, ->0'
        self.assertEqual(self.dead_lines(info, maps), [])

    def test_non_decisions_are_classified_without_consulting_hits(self):
        for opcode, operands in [('FAST_CALL', '->8'),
                                 ('SWITCH_STRING', "!0, 'a': ->8, default: ->1"),
                                 ('SWITCH_LONG', '!0, 2: ->8, default: ->1'),
                                 ('CATCH', "'Throwable', !0, ->8")]:
            for hits in ([0, 0], [1, 0], [0, 1], [1, 1]):
                with self.subTest(opcode=opcode, hits=hits):
                    self.assertIsNotNone(coverage.classify(
                        block(0, 0, 3, [1, 8], hit=int(any(hits)), edge_hits=hits),
                        instruction(opcode, operands)))

    def test_untested_typed_catch_and_source_conditions_remain_decisions(self):
        candidate = block(0, 0, 3, [1, 8])
        for opcode, operands in [('CATCH', "'LengthException', !0, ->8"),
                                 ('JMPZ', '!0, ->8'), ('COALESCE', '!0, ->8')]:
            with self.subTest(opcode=opcode):
                self.assertIsNone(coverage.classify(candidate, instruction(opcode, operands)))

    def test_bad_successors_and_unknown_opcodes_fail_closed(self):
        for opcode, operands in [('JMPZ', '!0, ->9'), ('FAST_CALL', '->9'),
                                 ('CATCH', "'Throwable', !0, ->9"), ('NEW_OPCODE', '->8')]:
            with self.subTest(opcode=opcode):
                with self.assertRaises(AssertionError):
                    coverage.classify(block(0, 0, 3, [1, 8]), instruction(opcode, operands))

    def test_only_literal_conditions_have_a_proved_single_successor(self):
        shape = block(0, 0, 3, [1, 8])['shape']
        for opcode, value, expected in [('JMPZ', '<true>', 1), ('JMPZ', '<false>', 8),
                                        ('JMPNZ', '-2', 8), ('JMPNZ', '0', 1),
                                        ('JMPNZ', '!0', None), ('COALESCE', '<true>', None)]:
            with self.subTest(opcode=opcode, value=value):
                self.assertEqual(coverage.literal_successor(shape, instruction(opcode, value + ', ->8')),
                                 expected)

    def test_opcode_identity_rejects_stale_indices_lines_and_ambiguous_maps(self):
        function = {'branches': {0: block(0, 0, 3, [1]), 1: block(1, 1, 4, [])}}
        ops = {0: instruction('NOP'), 1: instruction('RETURN', 'null', line=4)}
        self.assertEqual(coverage.function_opcodes('fixture.php', 'fixture', function,
                                                  [self.section(ops)]), ops)
        stale_index = {0: ops[0], 2: ops[1]}
        stale_line = copy.deepcopy(ops)
        stale_line[1]['line'] = 99
        ambiguous = copy.deepcopy(ops)
        ambiguous[0]['opcode'] = 'ECHO'
        for sections in ([self.section(stale_index)], [self.section(stale_line)],
                         [self.section(ops), self.section(ambiguous)]):
            with self.subTest(sections=sections):
                with self.assertRaisesRegex(AssertionError, 'No unique exact opcode map'):
                    coverage.function_opcodes('fixture.php', 'fixture', function, sections)

    def test_native_opcode_dump_preserves_owner_source_lines_and_scope(self):
        dump = self.root / 'opcode-map.txt'
        dump.write_text(
            'filename:       /wordpress/wp-includes/unrelated.php\n'
            'function name:  unrelated\n'
            'Class Example:\n'
            'filename:       /source/fixture.php\n'
            'function name:  fixture\n'
            '    3     0  E >   JMPZ                             !0, ->2\n'
            '          1        ECHO                             \'ok\'\n'
            '    4     2      > RETURN                           null\n'
            'End of class Example\n'
            'filename:       /source/fixture.php\n'
            'function name:  helper\n'
            '    9     0  E >   RETURN                           null\n'
            'PASS captured compiler metadata\n')
        sections = coverage.parse_opcodes(dump, ['fixture.php'])
        self.assertEqual(len(sections), 2)
        self.assertEqual(sections[0]['owner'], 'Example')
        self.assertEqual(sections[0]['ops'][1]['line'], 3)
        self.assertEqual(sections[0]['ops'][0]['opcode'], 'JMPZ')
        self.assertEqual(sections[0]['ops'][0]['operands'], '!0, ->2')
        self.assertIsNone(sections[1]['owner'])
        dump.write_text(dump.read_text().replace('PASS captured compiler metadata', ''))
        with self.assertRaisesRegex(AssertionError, 'capture did not complete'):
            coverage.parse_opcodes(dump, ['fixture.php'])

    def test_call_argument_specialization_preserves_exact_control_flow_identity(self):
        function = {'branches': {0: block(0, 1, 3, [2]), 2: block(2, 2, 4, [])}}
        ops = {0: instruction('SEND_VAR_EX', '!0, 1'),
               1: instruction('SEND_VAL_EX', "'value', 2"),
               2: instruction('RETURN', 'null', line=4)}
        specialized = copy.deepcopy(ops)
        specialized[0]['opcode'] = 'SEND_VAR'
        specialized[1]['opcode'] = 'SEND_VAL'
        result = coverage.function_opcodes('fixture.php', 'fixture', function,
                                           [self.section(ops), self.section(specialized)])
        self.assertEqual(result[2], ops[2])
        ops[0]['opcode'] = 'SEND_VAR_NO_REF_EX'
        coverage.function_opcodes('fixture.php', 'fixture', function,
                                   [self.section(ops), self.section(specialized)])
        specialized[0]['opcode'] = 'JMPZ'
        with self.assertRaisesRegex(AssertionError, 'No unique exact opcode map'):
            coverage.function_opcodes('fixture.php', 'fixture', function,
                                       [self.section(ops), self.section(specialized)])
        specialized[0]['opcode'] = 'SEND_VAR'
        specialized[0]['operands'] = '!1, 1'
        with self.assertRaisesRegex(AssertionError, 'No unique exact opcode map'):
            coverage.function_opcodes('fixture.php', 'fixture', function,
                                       [self.section(ops), self.section(specialized)])

    def test_export_removes_entire_non_decisions_from_numerator_and_denominator(self):
        branches, ops = {}, {}
        definitions = [('FAST_CALL', '->8'), ('SWITCH_LONG', '!0, 2: ->8, default: ->2'),
                       ('CATCH', "'Throwable', !0, ->8"), ('JMPZ', '!0, ->8')]
        for index, (opcode, operands) in enumerate(definitions):
            branches[index] = block(index, index, 3, [index + 1, 8],
                                    hit=int(index != 3), edge_hits=[int(index != 3), 0])
            ops[index] = instruction(opcode, operands)
        ops[8] = instruction('RETURN', 'null', line=4)
        branches[8] = block(8, 8, 4, [], hit=1)
        info = {'lines': {3: 1, 4: 1}, 'functions': {'fixture': {'branches': branches}}, 'sha256': 'fixture'}
        sections = [self.section(ops)]
        _, raw, excluded_raw, _ = coverage.export(self.root, self.report, {'fixture.php': info}, sections, False)
        _, corrected, excluded, _ = coverage.export(self.root, self.report, {'fixture.php': info}, sections, True)
        self.assertEqual((raw['branches_hit'], raw['branches_total']), (3, 8))
        self.assertEqual((corrected['branches_hit'], corrected['branches_total']), (0, 2))
        self.assertEqual(corrected['lines_total'], raw['lines_total'])
        self.assertEqual(excluded_raw, [])
        self.assertEqual(len(excluded), 3)
        self.assertIn('BRF:8\nBRH:3', (self.report / 'raw-lcov.info').read_text())
        self.assertIn('BRF:2\nBRH:0', (self.report / 'lcov.info').read_text())
        self.assertEqual((self.report / 'lcov.info').read_text().count(',-\n'), 2)

    def raw_collection(self):
        raw_dir = self.report / 'raw'
        raw_dir.mkdir()
        digest = hashlib.sha256(self.source.read_bytes()).hexdigest()
        data = {'runtime': {'php': '8.3', 'xdebug': '3.4', 'opcache_cli': '0'},
                'hashes': [digest], 'coverage': {'/source/fixture.php': {
                    'lines': {'3': 1, '4': -1, '5': -2}, 'functions': {'{main}': {'branches': {}}}}}}
        for case in coverage.CASES:
            (raw_dir / (case + '.json')).write_text(json.dumps(data))
        return raw_dir, data

    def test_merge_requires_current_source_hashes_and_preserves_raw_line_union(self):
        raw_dir, data = self.raw_collection()
        data['coverage']['/source/fixture.php']['lines']['3'] = -1
        data['coverage']['/source/fixture.php']['lines']['4'] = 1
        (raw_dir / (sorted(coverage.CASES)[0] + '.json')).write_text(json.dumps(data))
        merged, cases, _ = coverage.merge(self.root, self.report, ['fixture.php'], len(coverage.CASES))
        self.assertEqual(len(cases), len(coverage.CASES))
        self.assertEqual(merged['fixture.php']['lines'], {3: 1, 4: 1})
        self.assertEqual(merged['fixture.php']['functions'], {})
        self.source.write_text(self.source.read_text() + '// changed after capture\n')
        with self.assertRaisesRegex(AssertionError, 'source mismatch'):
            coverage.merge(self.root, self.report, ['fixture.php'], len(coverage.CASES))

    def test_merge_rejects_mixed_runtimes_and_missing_test_processes(self):
        raw_dir, data = self.raw_collection()
        target = raw_dir / (sorted(coverage.CASES)[0] + '.json')
        data['runtime']['php'] = '8.4'
        target.write_text(json.dumps(data))
        with self.assertRaisesRegex(AssertionError, 'Mixed coverage runtimes'):
            coverage.merge(self.root, self.report, ['fixture.php'], len(coverage.CASES))
        target.unlink()
        with self.assertRaisesRegex(AssertionError, 'test collection mismatch'):
            coverage.merge(self.root, self.report, ['fixture.php'], len(coverage.CASES))

    def test_literal_infinite_loop_excludes_only_tokenized_implicit_epilogue(self):
        info, maps = self.loop()
        dead = self.dead_lines(info, maps)
        self.assertEqual([(entry['line'], entry['opcode']) for entry in dead], [(4, 1)])
        self.assertEqual(self.dead_lines(info, maps, {'fixture.php': []}), [])

    def test_reachable_break_keeps_implicit_return_line(self):
        self.source.write_text('<?php\nfunction fixture() {\n    while (true) { if ($stop) break; }\n}\n')
        info = {'lines': {3: 1, 4: 0}, 'functions': {'fixture': {'branches': {
            0: block(0, 0, 3, [1, 3]), 1: block(1, 1, 3, [2, 3]),
            2: block(2, 2, 3, [0]), 3: block(3, 3, 4, [])}}}}
        maps = {'fixture': {0: instruction('JMPZ', '<true>, ->3'),
                            1: instruction('JMPNZ', '!0, ->3'),
                            2: instruction('JMP', '->0'), 3: instruction('RETURN', 'null', line=4)}}
        self.assertEqual(self.dead_lines(info, maps), [])

    def test_variable_loop_keeps_unhit_but_reachable_implicit_return(self):
        self.source.write_text('<?php\nfunction fixture($keep) {\n    while ($keep) {}\n}\n')
        info, maps = self.loop('!0')
        self.assertEqual(self.dead_lines(info, maps), [])

    def test_explicit_unreachable_return_null_is_still_source_coverage(self):
        self.source.write_text('<?php\nfunction fixture() {\n    while (true) {}\n    return null;\n}\n')
        info, maps = self.loop()
        self.assertEqual(self.dead_lines(info, maps, {'fixture.php': [5]}), [])

    def test_finally_and_exception_entry_roots_keep_possible_continuations(self):
        for exception_entry in ('finally', 'catch'):
            with self.subTest(exception_entry=exception_entry):
                info, maps = self.loop()
                if exception_entry == 'finally':
                    self.source.write_text('<?php\nfunction fixture() {\n'
                                           '    try { while (true) {} } finally {}\n}\n')
                    info['functions']['fixture']['branches'] = {
                        0: block(0, 0, 3, [0, 1]), 1: block(1, 1, 3, [2, 3]),
                        2: block(2, 2, 3, [0]), 3: block(3, 3, 3, [4]),
                        4: block(4, 4, 4, [])}
                    maps['fixture'] = {0: instruction('JMPNZ', '<true>, ->0'),
                                       1: instruction('FAST_CALL', '->3'),
                                       2: instruction('JMP', '->0'),
                                       3: instruction('FAST_RET'),
                                       4: instruction('RETURN', 'null', line=4)}
                else:
                    self.source.write_text('<?php\nfunction fixture() {\n'
                                           '    try { while (true) {} } catch (Throwable $e) {}\n}\n')
                    info['functions']['fixture']['branches'] = {
                        0: block(0, 0, 3, [0, 1]),
                        1: block(1, 1, 3, [2, 2147483645]), 2: block(2, 2, 4, [])}
                    maps['fixture'] = {0: instruction('JMPNZ', '<true>, ->0'),
                                       1: instruction('CATCH', "'Throwable', !0"),
                                       2: instruction('RETURN', 'null', line=4)}
                self.assertEqual(self.dead_lines(info, maps), [])

    def test_opcode_provenance_rejects_stale_sources_and_compiler_modes(self):
        runtime = {'php': '8.2', 'uopz_disabled': '0'}
        merged = {'fixture.php': {'sha256': 'current-source'}}
        provenance = {'runtime': dict(runtime), 'hashes': {'fixture.php': 'current-source'}}
        coverage.validate_provenance(provenance, merged, runtime)
        stale = copy.deepcopy(provenance)
        stale['hashes']['fixture.php'] = 'old-catch-operand-with-same-opcode-graph'
        with self.assertRaisesRegex(AssertionError, 'source hashes differ'):
            coverage.validate_provenance(stale, merged, runtime)
        other_mode = copy.deepcopy(provenance)
        other_mode['runtime']['uopz_disabled'] = '1'
        with self.assertRaisesRegex(AssertionError, 'compiler flags differ'):
            coverage.validate_provenance(other_mode, merged, runtime)

    def test_executed_proved_dead_line_is_an_integrity_error(self):
        info, maps = self.loop()
        info['lines'][4] = 1
        with self.assertRaisesRegex(AssertionError, 'Proved-dead epilogue was executed'):
            self.dead_lines(info, maps)


if __name__ == '__main__':
    unittest.main()
