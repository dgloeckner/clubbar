#!/usr/bin/env python3
"""Convert a Codacy Analysis CLI JSON report into SARIF 2.1.0.

Why this exists instead of `--format sarif`
-------------------------------------------
The CLI's own SARIF writer reads back every file it reports an issue in, to
hash a snippet for the result fingerprint, and decodes it through better-files'
`UnicodeDecoder`. That decoder mishandles a multi-byte UTF-8 character that
straddles the 8192-byte read boundary: it reports the leading bytes as
malformed input, and the resulting `MalformedInputException` kills the whole
run *after* the analysis has finished, so no report is written at all.

Three files in this repository trip it today -- an em dash or a box-drawing
character that happens to land on byte 8190 -- and any file can drift onto that
boundary with a one-byte edit. The JSON formatter reads no files, so converting
its output here sidesteps the bug entirely.

Usage: codacy_sarif.py <codacy-report.json> <output.sarif>
"""

import json
import sys

SCHEMA = "https://json.schemastore.org/sarif-2.1.0.json"

# GitHub rejects an upload whose run carries more results than this.
MAX_RESULTS = 25000

# Codacy's levels, in SARIF's vocabulary.
LEVELS = {"Error": "error", "Warning": "warning", "Info": "note"}


def issues(report):
    """Yield the issues in a Codacy JSON report.

    circe derives the encoder from the sealed `Result` trait, so every entry is
    wrapped in a single-key object naming its variant: `Issue` for a finding,
    `FileError` for a file a tool could not read. Only findings become results.
    """
    for entry in report:
        issue = entry.get("Issue") if isinstance(entry, dict) else None
        if issue:
            yield issue


def convert(report):
    """Return the SARIF rules and results for a Codacy report, and a count of
    entries too incomplete to place."""
    rules, rule_index, results, skipped = [], {}, [], 0

    for issue in issues(report):
        pattern = issue.get("patternId", {}).get("value")
        filename = issue.get("filename")
        message = issue.get("message", {}).get("text")
        if not (pattern and filename and message):
            skipped += 1
            continue

        if pattern not in rule_index:
            rule_index[pattern] = len(rules)
            rules.append({"id": pattern, "name": pattern,
                          "shortDescription": {"text": pattern}})

        # A location is another circe-wrapped variant -- `LineLocation` for a
        # single line, `FullLocation` for a range -- and both carry `line`.
        # Codacy reports a whole-file issue as line 0, which SARIF rejects.
        location = issue.get("location") or {}
        variant = next(iter(location.values()), {}) if isinstance(location, dict) else {}
        line = variant.get("line", 1) if isinstance(variant, dict) else 1
        results.append({
            "ruleId": pattern,
            "ruleIndex": rule_index[pattern],
            "level": LEVELS.get(issue.get("level"), "note"),
            "message": {"text": message},
            "locations": [{"physicalLocation": {
                "artifactLocation": {"uri": filename},
                "region": {"startLine": max(1, line)}}}],
        })

    return rules, results, skipped


def main(argv):
    """Convert the report named by argv[1] into the SARIF file named by argv[2]."""
    if len(argv) != 3:
        sys.exit(f"usage: {argv[0]} <codacy-report.json> <output.sarif>")

    with open(argv[1], encoding="utf-8") as handle:
        report = json.load(handle)
    if not isinstance(report, list):
        sys.exit("not a Codacy JSON report: expected a top-level array")

    rules, results, skipped = convert(report)

    if skipped:
        print(f"::warning::Skipped {skipped} malformed entries in the Codacy report.")
    if len(results) > MAX_RESULTS:
        print(f"::warning::Codacy reported {len(results)} issues; uploading the "
              f"first {MAX_RESULTS}, which is all GitHub accepts per run.")
        results = results[:MAX_RESULTS]

    sarif = {
        "$schema": SCHEMA,
        "version": "2.1.0",
        "runs": [{
            "tool": {"driver": {"name": "Codacy", "informationUri":
                                "https://github.com/codacy/codacy-analysis-cli",
                                "rules": rules}},
            "results": results,
        }],
    }

    with open(argv[2], "w", encoding="utf-8") as handle:
        json.dump(sarif, handle)

    print(f"Converted {len(results)} issues across {len(rules)} rules.")


if __name__ == "__main__":
    main(sys.argv)
