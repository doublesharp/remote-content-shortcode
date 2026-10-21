#!/usr/bin/env python3
"""Export raw Xdebug CFG edges and opcode-verified conditional decisions separately."""

import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess

PREFIXES = ('/wordpress/wp-content/plugins/remote-content-shortcode/', '/source/')
SELECTORS = ('small-select', 'large-select', 'large-remove', 'flat', 'complex-selector',
             'work-budget', 'ordinary', 'flat-sibling', 'flat-sibling-chain',
             'small-sibling', 'themed-page', 'autoload')
STARTUPS = ('missing-dom', 'missing-curl', 'missing-connect-option', 'direct-main',
            'direct-html', 'direct-regex')
CASES = {
    'upgrade-verify', 'cve-regression', 'encoded-fallback', 'regression',
    'integration-transport', 'features-integration', 'content-auth-features',
    'cache-features', 'admin-block-features', 'transport', 'content-boundaries',
    'admin-cache-boundaries', 'renderer-boundaries', 'transport-boundaries',
    'anonymous-rest-cache', 'render-contexts', 'proxy-transport', 'cache-write',
    'cache-read', 'cache-uninstall', 'uninstall-boundaries', 'regex-budget',
    'cache-multisite', 'dependency-failures', 'transport-dependency-failures',
} | {'selector-' + case for case in SELECTORS} | {'startup-' + case for case in STARTUPS}


def items(value):
    return enumerate(value) if isinstance(value, list) else value.items()


def relative(path, files):
    for prefix in PREFIXES:
        if path.startswith(prefix):
            result = path[len(prefix):]
            return result if result in files else None
    return None


def normalize(name):
    for prefix in PREFIXES:
        name = name.replace(prefix, '')
    return name


def validate_provenance(provenance, merged, runtime):
    assert provenance['runtime'] == runtime, 'Opcode metadata runtime or compiler flags differ'
    assert provenance['hashes'] == {rel: info['sha256'] for rel, info in merged.items()}, 'Opcode metadata source hashes differ'


def merge(root, report, files, expected):
    merged, cases, runtimes = {}, [], []
    for raw in sorted((report / 'raw').glob('*.json')):
        data = json.loads(raw.read_text())
        cases.append(raw.stem)
        runtimes.append(data['runtime'])
        for index, (path, value) in enumerate(data['coverage'].items()):
            rel = relative(path, files)
            if rel is None:
                continue
            digest = hashlib.sha256((root / rel).read_bytes()).hexdigest()
            assert data['hashes'][index] == digest, (raw.name, rel, 'source mismatch')
            dest = merged.setdefault(rel, {'lines': {}, 'functions': {}, 'sha256': digest})
            for line, hit in items(value.get('lines', {})):
                if hit == -2:
                    continue
                assert hit in (-1, 1), (raw.name, rel, line, hit)
                dest['lines'][int(line)] = max(dest['lines'].get(int(line), 0), int(hit > 0))
            for name, info in value.get('functions', {}).items():
                # File-level pseudo-function hits are unreliable in Xdebug; both reports
                # retain the same explicit function/method/closure scope.
                if name == '{main}':
                    continue
                name = normalize(name)
                function = dest['functions'].setdefault(name, {'branches': {}})
                for branch, block in items(info.get('branches', {})):
                    shape = {key: block[key] for key in ('op_start', 'op_end', 'line_start', 'line_end')}
                    shape['out'] = {str(key): target for key, target in items(block.get('out', {}))}
                    old = function['branches'].get(int(branch))
                    assert not old or old['shape'] == shape, (rel, name, branch, 'compiler graph mismatch')
                    saved = old or {'shape': shape, 'hit': 0, 'out_hit': {}}
                    saved['hit'] = max(saved['hit'], int(block['hit'] > 0))
                    for edge, hit in items(block.get('out_hit', {})):
                        edge = str(edge)
                        saved['out_hit'][edge] = max(saved['out_hit'].get(edge, 0), int(hit > 0))
                    function['branches'][int(branch)] = saved
    assert set(merged) == set(files), ('unmeasured files', set(files) - set(merged))
    assert len(cases) == expected == len(CASES) and set(cases) == CASES, ('test collection mismatch', set(cases) ^ CASES)
    assert all(runtime == runtimes[0] for runtime in runtimes), 'Mixed coverage runtimes'
    return merged, cases, runtimes[0]


def parse_opcodes(path, files):
    """Read actual CLI op_arrays dumped once after WordPress's bootstrap."""
    sections, current, owner, source_line = [], None, None, None
    for text in path.read_text().splitlines():
        if text.startswith(('Class ', 'End of class ', 'Function ', 'Dynamic Function')):
            current = None
        if text.startswith('Class ') and text.endswith(':'):
            owner = text[6:-1]
        elif text.startswith('End of class '):
            owner = None
        elif text.startswith('filename:'):
            rel = relative(text.split(':', 1)[1].strip(), files)
            current = {'file': rel, 'owner': owner, 'name': None, 'ops': {}} if rel else None
            if current:
                sections.append(current)
            source_line = None
        elif current and text.startswith('function name:'):
            current['name'] = text.split(':', 1)[1].strip()
        elif current and len(text) >= 20 and re.fullmatch(r'\s*\d+[ *]', text[5:12]):
            if text[:5].strip():
                source_line = int(text[:5])
            number = int(text[5:11])
            opcode = text[19:51].strip()
            assert opcode and source_line is not None, text
            current['ops'][number] = {'opcode': opcode, 'line': source_line, 'operands': text[51:].strip()}
    assert 'PASS captured compiler metadata' in path.read_text(), 'Opcode capture did not complete'
    assert sections, 'No first-party opcode metadata'
    return sections


def function_opcodes(rel, name, function, sections):
    matches = []
    for section in sections:
        if section['file'] != rel or not section['ops']:
            continue
        if name.startswith('{closure:'):
            if section['name'] != '{closure}':
                continue
        elif '->' in name:
            owner, method = name.split('->', 1)
            if section['owner'] != owner or section['name'] != method:
                continue
        elif section['owner'] is not None or section['name'] != name:
            continue
        # Xdebug can assign a fallthrough block the following EXT_STMT's line.
        # Match exact indices plus either terminal or immediately following line;
        # conditional successor targets are checked separately before classification.
        if all(block['shape']['op_end'] in section['ops']
               and block['shape']['line_end'] in {
                   section['ops'][block['shape']['op_end']]['line'],
                   section['ops'].get(block['shape']['op_end'] + 1, {}).get('line')}
               for block in function['branches'].values()):
            matches.append(section['ops'])
    # PHP specializes argument sends after resolving a callee. VLD may dump
    # both forms of a loaded function. They have identical control flow; keep
    # exact operands, indices and lines, and reject every other disagreement.
    specializations = {'SEND_VAR_EX': 'SEND_VAR', 'SEND_VAL_EX': 'SEND_VAL',
                       'SEND_VAR_NO_REF_EX': 'SEND_VAR'}
    unique = {json.dumps({index: dict(op, opcode=specializations.get(op['opcode'], op['opcode']))
                          for index, op in ops.items()}, sort_keys=True): ops for ops in matches}
    assert len(unique) == 1, (rel, name, 'No unique exact opcode map', len(unique))
    return next(iter(unique.values()))


def classify(block, instruction):
    """Classify independently of hits. Never discard an untested source condition."""
    shape = block['shape']
    opcode, operands = instruction['opcode'], instruction['operands']
    jumps = [int(target) for target in re.findall(r'->(\d+)', operands)]
    targets = set(shape['out'].values())
    fallthrough = shape['op_end'] + 1
    allowed = {'JMPZ', 'JMPNZ', 'JMPZ_EX', 'JMPNZ_EX', 'JMPZNZ', 'JMP_SET',
               'COALESCE', 'JMP_NULL', 'FE_RESET_R', 'FE_RESET_RW', 'FE_FETCH_R',
               'FE_FETCH_RW', 'FAST_CALL', 'CATCH', 'SWITCH_STRING', 'SWITCH_LONG'}
    assert opcode in allowed, ('Unknown decision opcode', opcode, shape)
    if opcode == 'CATCH':
        assert fallthrough in targets and all(target in jumps + [fallthrough, 2147483645] for target in targets), (instruction, shape)
        if re.search(r"'Throwable'(?:,|$)", operands):
            return 'catch Throwable matches every PHP exception; nonmatch is a compiler escape'
    else:
        assert targets == set(jumps + [fallthrough]), ('Opcode successors differ', instruction, shape)
    if opcode == 'FAST_CALL':
        return 'finally invocation and continuation form sequential control flow, not a conditional decision'
    if opcode.startswith('SWITCH_'):
        return 'Xdebug disables this jump table; actual case-comparison decisions are retained'
    if literal_successor(shape, instruction) is not None:
        return 'literal condition emitted without optimization; it has one possible successor'
    return None


def literal_successor(shape, instruction):
    if instruction['opcode'] not in ('JMPZ', 'JMPNZ'):
        return None
    match = re.fullmatch(r'(?:<(true|false)>|(-?\d+)),\s*->(\d+)', instruction['operands'])
    if not match:
        return None
    value = match[1] == 'true' if match[1] else int(match[2]) != 0
    take_jump = value if instruction['opcode'] == 'JMPNZ' else not value
    return int(match[3]) if take_jump else shape['op_end'] + 1


def compiler_dead_lines(root, rel, info, maps, closing_braces):
    """Prove only compiler implicit epilogues dead, never remove source statements."""
    excluded = []
    for name, function in info['functions'].items():
        ops = maps[name]
        blocks = function['branches']
        by_start = {block['shape']['op_start']: block for block in blocks.values()}
        roots = {min(by_start)}
        for block in blocks.values():
            instruction = ops[block['shape']['op_end']]
            if instruction['opcode'] == 'CATCH':
                roots.add(block['shape']['op_start'])
            elif instruction['opcode'] == 'FAST_CALL':
                # Exception entry can reach finally without a normally executed call.
                roots.update(int(target) for target in re.findall(r'->(\d+)', instruction['operands']))
        visited, pending = set(), list(roots)
        while pending:
            start = pending.pop()
            if start in visited or start not in by_start:
                continue
            visited.add(start)
            block = by_start[start]
            actual = literal_successor(block['shape'], ops[block['shape']['op_end']])
            # Preserve both sequential FAST_CALL routes. Only literals prune an edge.
            pending.extend([actual] if actual is not None else block['shape']['out'].values())
        reachable_ops = {op for start in visited for op in
                         range(by_start[start]['shape']['op_start'], by_start[start]['shape']['op_end'] + 1)}
        final = max(ops)
        epilogue = ops[final]
        line = epilogue['line']
        on_line = {index: op for index, op in ops.items() if op['line'] == line}
        source = (root / rel).read_text().splitlines()
        if (epilogue['opcode'] == 'RETURN' and epilogue['operands'].lower() == 'null'
                and all(index == final or op['opcode'] in ('EXT_STMT', 'NOP')
                        for index, op in on_line.items())
                and not set(on_line).intersection(reachable_ops)
                and line in closing_braces.get(rel, []) and source[line - 1].strip() == '}'):
            assert not info['lines'].get(line), ('Proved-dead epilogue was executed', rel, name, line)
            excluded.append({'file': rel, 'function': name, 'line': line, 'opcode': final,
                             'reason': 'unreachable compiler implicit RETURN null at the tokenized function closing brace'})
    return excluded


def export(root, report, merged, sections, corrected, closing_braces=None):
    trace, rows, excluded, dead_lines = ['TN:WordPress_PHP_Xdebug'], [], [], []
    for rel, info in sorted(merged.items()):
        maps = {name: function_opcodes(rel, name, function, sections)
                for name, function in info['functions'].items()} if corrected else {}
        dead = compiler_dead_lines(root, rel, info, maps, closing_braces or {}) if corrected else []
        dead_lines.extend(dead)
        omitted = {entry['line'] for entry in dead}
        lines = {line: hit for line, hit in info['lines'].items() if line not in omitted}
        trace.append('SF:' + str(root / rel))
        functions = info['functions']
        function_hits = {}
        for name, function in sorted(functions.items()):
            start = min(block['shape']['line_start'] for block in function['branches'].values())
            function_hits[name] = int(any(block['hit'] for block in function['branches'].values()))
            trace.append(f'FN:{start},{name}')
        trace.extend(f'FNDA:{hit},{name}' for name, hit in function_hits.items())
        trace.extend((f'FNF:{len(functions)}', f'FNH:{sum(function_hits.values())}'))
        branches = []
        for index, (name, function) in enumerate(sorted(functions.items())):
            ops = maps[name] if corrected else None
            for number, block in sorted(function['branches'].items()):
                shape = block['shape']
                if len(shape['out']) < 2:
                    continue
                if corrected:
                    instruction = ops[shape['op_end']]
                    reason = classify(block, instruction)
                    if reason:
                        excluded.append({'file': rel, 'function': name, 'op_end': shape['op_end'],
                                         'opcode': instruction['opcode'], 'reason': reason,
                                         'outcomes': len(shape['out']),
                                         'raw_outcomes_hit': sum(block['out_hit'].values())})
                        # These opcodes have fewer than two actual successors.
                        # Drop the entire non-decision, including its guaranteed hit.
                        continue
                low, high = sorted((shape['line_start'], shape['line_end']))
                candidates = [line for line in lines if low <= line <= high]
                if not candidates:
                    first = min(b['shape']['line_start'] for b in function['branches'].values())
                    last = max(max(b['shape']['line_start'], b['shape']['line_end']) for b in function['branches'].values())
                    candidates = [line for line in lines if first <= line <= last] or list(lines)
                location = min(candidates, key=lambda line: (abs(line - shape['line_end']), line))
                for edge in sorted(shape['out']):
                    branches.append((location, index * 10000 + number, int(edge),
                                     block['out_hit'].get(edge, 0), block['hit']))
        trace.extend('BRDA:' + ','.join(map(str, (line, block, edge, hit if visited else '-')))
                     for line, block, edge, hit, visited in branches)
        trace.extend((f'BRF:{len(branches)}', f'BRH:{sum(row[3] for row in branches)}'))
        trace.extend(f'DA:{line},{hit}' for line, hit in sorted(lines.items()))
        trace.extend((f'LF:{len(lines)}', f'LH:{sum(lines.values())}', 'end_of_record'))
        rows.append({'file': rel, 'sha256': info['sha256'], 'lines_hit': sum(lines.values()),
                     'lines_total': len(lines), 'functions_hit': sum(function_hits.values()),
                     'functions_total': len(functions), 'branches_hit': sum(row[3] for row in branches),
                     'branches_total': len(branches),
                     'uncovered_lines': [line for line, hit in sorted(lines.items()) if not hit],
                     'uncovered_functions': [name for name, hit in function_hits.items() if not hit]})
    (report / ('lcov.info' if corrected else 'raw-lcov.info')).write_text('\n'.join(trace) + '\n')
    totals = {key: sum(row[key] for row in rows) for key in
              ('lines_hit', 'lines_total', 'functions_hit', 'functions_total', 'branches_hit', 'branches_total')}
    return rows, totals, excluded, dead_lines


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', required=True, type=Path)
    parser.add_argument('--report', required=True, type=Path)
    parser.add_argument('--manifest', required=True, type=Path)
    parser.add_argument('--expected-cases', required=True, type=int)
    args = parser.parse_args()
    files = json.loads(args.manifest.read_text())
    merged, cases, runtime = merge(args.root, args.report, files, args.expected_cases)
    provenance = json.loads((args.report / 'opcode-source.json').read_text())
    validate_provenance(provenance, merged, runtime)
    sections = parse_opcodes(args.report / 'opcode-map.txt', files)
    rows, totals, excluded, dead_lines = export(args.root, args.report, merged, sections, True, provenance['closing_braces'])
    _, raw, _, _ = export(args.root, args.report, merged, sections, False)
    summary = {'commit': subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=args.root, text=True).strip(),
               'source': 'Working tree; SHA-256 verified for every included production source record',
               'runtime': runtime, 'cases': cases, 'files': rows, 'totals': totals, 'raw_totals': raw,
               'scope': 'First-party production PHP; WordPress, vendor, tests, tools, JavaScript and file-level pseudo-functions excluded',
               'branch_metric': 'Conditional successor outcomes verified against exact CLI opcodes; complete non-decisions FAST_CALL, disabled SWITCH tables, catch(Throwable), and literal compiler conditions are removed independently of hits. Source comparisons, case-comparison branches and variable loop edges are retained. Only proved-unreachable implicit epilogues at tokenized closing braces are omitted from executable source lines. Raw Xdebug line and CFG outcome counts are reported separately.',
               'hit_counts': 'Binary reachability union, not execution frequency or MC/DC/path coverage',
               'compiler_non_decisions': excluded, 'compiler_dead_lines': dead_lines}
    (args.report / 'summary.json').write_text(json.dumps(summary, indent=2) + '\n')
    messages = []
    for metric in ('lines', 'functions', 'branches'):
        hit, total = totals[metric + '_hit'], totals[metric + '_total']
        messages.append(f'{metric}: {100 * hit / total:.2f}% ({hit}/{total})')
    messages.append(f"raw Xdebug CFG outcomes: {100 * raw['branches_hit'] / raw['branches_total']:.2f}% ({raw['branches_hit']}/{raw['branches_total']})")
    messages.append(f"raw Xdebug lines: {raw['lines_hit']}/{raw['lines_total']}; compiler-only dead epilogues: {len(dead_lines)}")
    messages.extend((f'{len(cases)} test processes; {len(files)} first-party files',
                     summary['branch_metric']))
    (args.report / 'summary.txt').write_text('\n'.join(messages) + '\n')
    print('\n'.join(messages))
    assert totals['lines_hit'] == totals['lines_total'], 'Line coverage must be 100%'
    assert totals['functions_hit'] == totals['functions_total'], 'Function coverage must be 100%'
    assert 100 * totals['branches_hit'] >= 95 * totals['branches_total'], 'Conditional branch coverage must be at least 95%'


if __name__ == '__main__':
    main()
