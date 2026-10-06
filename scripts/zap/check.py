#!/usr/bin/env python3
"""Fails on high-risk ZAP alerts not accepted in accepted.txt (Phase 8B.7).

Usage: check.py zap-report.json accepted.txt

accepted.txt: one ZAP plugin ID per line, optionally followed by "# reason";
each is explained in docs/security/zap-accepted.md. Medium and low alerts
are listed for review but never fail the build.
"""
import json
import sys

report, accepted_file = sys.argv[1], sys.argv[2]
accepted = set()
with open(accepted_file) as f:
    for line in f:
        plugin = line.split("#", 1)[0].strip()
        if plugin:
            accepted.add(plugin)

with open(report) as f:
    data = json.load(f)

risks = {"3": "High", "2": "Medium", "1": "Low", "0": "Informational"}
failed = []
for site in data.get("site", []):
    for alert in site.get("alerts", []):
        plugin, risk = str(alert.get("pluginid")), str(alert.get("riskcode"))
        status = "accepted" if plugin in accepted else ("FAIL" if risk == "3" else "review")
        print(f"{risks.get(risk, risk):13} {plugin:>6}  {alert.get('alert')}  ({alert.get('count', '?')} instances)  [{status}]")
        if risk == "3" and plugin not in accepted:
            failed.append(alert.get("alert"))

if failed:
    print(f"\n{len(failed)} high-risk ZAP alert(s) not accepted: {', '.join(failed)}", file=sys.stderr)
    sys.exit(1)
print("\nNo unaccepted high-risk ZAP alerts.")
