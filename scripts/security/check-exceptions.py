#!/usr/bin/env python3
"""Checks security/vulnerability-exceptions.yaml (Phase 9A.2).

Fails when an entry has no statement, no expired_at, has expired, or is
dated more than 60 days ahead. Parses the small YAML subset the file uses
(no PyYAML needed on the CI runner).
"""
import datetime
import re
import sys

path = sys.argv[1] if len(sys.argv) > 1 else "security/vulnerability-exceptions.yaml"
today = datetime.date.today()
limit = today + datetime.timedelta(days=60)

entries, current = [], None
for line in open(path):
    if re.match(r"\s*-\s+id:\s*", line):
        current = {"id": line.split("id:", 1)[1].strip()}
        entries.append(current)
    elif current is not None and re.match(r"\s+statement:", line):
        current["statement"] = True
    elif current is not None and re.match(r"\s+expired_at:", line):
        current["expired_at"] = line.split("expired_at:", 1)[1].strip()

problems = []
for e in entries:
    if not e.get("statement"):
        problems.append(f"{e['id']}: no statement (reason)")
    if "expired_at" not in e:
        problems.append(f"{e['id']}: no expired_at")
        continue
    try:
        expiry = datetime.date.fromisoformat(e["expired_at"])
    except ValueError:
        problems.append(f"{e['id']}: expired_at is not a date (YYYY-MM-DD)")
        continue
    if expiry < today:
        problems.append(f"{e['id']}: expired on {expiry}; review it (fix, re-justify with a new date, or remove)")
    elif expiry > limit:
        problems.append(f"{e['id']}: expired_at {expiry} is more than 60 days ahead (limit {limit})")

for p in problems:
    print(f"FAIL: {p}")
if problems:
    sys.exit(1)
print(f"OK: {len(entries)} exception(s), all reviewed and dated within 60 days.")
