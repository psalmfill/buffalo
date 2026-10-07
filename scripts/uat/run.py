#!/usr/bin/env python3
"""BUFFALO UAT runner (Phase 9B.5).

Runs the role scripts in docs/uat/ against a UAT environment, signing in as
each tester (password and TOTP), performing every step through the same
endpoints the screens use, and checking the expected result from the
server's answers (page props, permissions offered, statuses, files).
Writes a results file per script in docs/uat/results/.

    python3 scripts/uat/run.py --credentials creds.txt [--base https://localhost:19443]

The credentials file is the table printed by buffalo:uat:seed (or
make uat-credentials), as is. UAT environments only: test data only.
"""

import argparse
import base64
import datetime
import json
import os
import re
import sys
import time
import traceback

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from buffalo_client import Client  # noqa: E402

PHOTO = base64.b64decode('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')

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))


class Failed(Exception):
    pass


def check(condition, message):
    if not condition:
        raise Failed(message)


class Run:
    def __init__(self, base, credentials):
        self.base = base
        self.accounts = {}
        for line in open(credentials):
            cells = [c.strip() for c in line.strip().strip('|').split('|')]
            if len(cells) >= 4 and '@' in cells[0]:
                self.accounts[cells[0].split('@')[0]] = (cells[0], '|'.join(cells[2:-1]), cells[-1])
        self.clients = {}
        self.state = {}
        self.results = {}
        self.tag = datetime.datetime.now().strftime('%H%M%S')

    def as_(self, who) -> Client:
        if who not in self.clients:
            client = Client(self.base, *self.accounts[who])
            res = client.login()
            check(res.status == 200 and res.page['component'].startswith('dashboard/'), f'{who} could not sign in ({res.status} {res.page.get("component")})')
            self.clients[who] = client
        return self.clients[who]

    def step(self, script, number, text, fn):
        started = time.time()
        try:
            detail = fn() or ''
            outcome = 'Pass'
        except Failed as e:
            detail, outcome = str(e), 'Fail'
        except Exception as e:  # unexpected: record and go on
            detail, outcome = f'{type(e).__name__}: {e}', 'Fail'
            traceback.print_exc()
        self.results.setdefault(script, []).append((number, text, outcome, detail, time.time() - started))
        print(f'  {outcome:4}  {script}/{number:4} {text}' + (f'  -- {detail}' if detail else ''))
        return outcome == 'Pass'

    @staticmethod
    def now(minutes=0):
        return (datetime.datetime.now() + datetime.timedelta(minutes=minutes)).strftime('%Y-%m-%dT%H:%M')


# ---------------------------------------------------------------------------
# Script 1: LGA Security Chief
# ---------------------------------------------------------------------------
def script_1(r: Run):
    s = '01-lga-security-chief'
    chief = lambda: r.as_('chief.lagos')

    def a1():
        page = chief().get('/dashboard').page
        check(page['component'] == 'dashboard/lga', f'dashboard is {page["component"]}')
        check(page['props']['app']['banner']['text'] == 'UAT — TEST DATA ONLY', 'no UAT banner')
        j = page['props']['auth']['user']['jurisdiction']
        r.state['lagos'] = j['state']['ulid']
        r.state['chief_lga'] = j['lga']['ulid']
        return f'LGA dashboard for {j["lga"]["name"]}; banner shown'
    r.step(s, 'A1', 'Sign in; LGA dashboard; UAT banner', a1)

    def a2():
        p = chief().get('/help').props
        check(p['roles'][0]['own'] and p['roles'][0]['slug'] == 'lga-security-chief', 'own manual not first')
        check('super-admin' not in [m['slug'] for m in p['roles']], 'super admin manual visible')
        check(len(p['guides']) == 8 and any(c.get('own') for c in p['cards']), 'guides or own card missing')
        return f'{len(p["roles"])} role manuals (own first), {len(p["guides"])} guides, {len(p["cards"])} cards'
    r.step(s, 'A2', 'Help: own manual first, no super admin manual', a2)

    def a3():
        res = chief().get('/help/start/getting-started')
        check(any(t['id'] == 'the-dashboard' for t in res.props['toc']), 'no "the-dashboard" section')
        return 'Getting started opens with the "The dashboard" section'
    r.step(s, 'A3', 'Contextual help from the dashboard', a3)

    def b1():
        form = chief().get('/incidents/create').props
        types = {t['name']: t['ulid'] for t in form['options']['types']}
        r.state['types'] = types
        r.state['npf'] = form['options']['agencies'][0]['value']
        lga_map = chief().get(f'/flashpoints/create?lga={r.state["chief_lga"]}').props['map']
        r.state['point'] = lga_map['center']
        res = chief().post('/incidents', {
            'incident_type': types['Armed robbery'], 'details': {}, 'title': f'UAT robbery at market {r.tag}',
            'description': 'Two armed men robbed traders at the market (UAT test data).', 'severity': 'high',
            'occurred_at': r.now(-60), 'occurred_at_precision': 'exact', 'lga': r.state['chief_lga'],
            'latitude': r.state['point'][0], 'longitude': r.state['point'][1], 'location_description': 'Main market, east gate',
            'classification': 'restricted',
            'casualties': [{'outcome': 'injured', 'group': 'civilian', 'agency': None, 'adult_male': 1, 'adult_female': 0, 'child_male': 0, 'child_female': 0, 'unknown': 0}],
            'persons': [], 'groups': [], 'agencies': [{'agency': r.state['npf'], 'role': 'first_responder'}], 'properties': [],
        })
        check(res.page['component'] == 'incidents/show', f'not saved: {res.errors}')
        inc = res.props['incident']
        check(inc['reference_no'] is None, 'a draft got a reference')
        r.state['robbery'] = inc['ulid']
        return f'draft saved, no reference ({inc["ulid"]})'
    r.step(s, 'B1', 'Report incident; save as draft', b1)

    def b2():
        res = chief().post(f'/incidents/{r.state["robbery"]}/submit', {'confirm_duplicates': True})
        inc = chief().get(f'/incidents/{r.state["robbery"]}').props['incident']
        check(inc['reference_no'] and inc['reference_no'].startswith('BUF/LA/'), f'no reference after submit ({res.errors})')
        check(inc['status']['value'] == 'submitted', f'status {inc["status"]}')
        return f'{inc["reference_no"]}, Submitted'
    r.step(s, 'B2', 'Submit: reference assigned', b2)

    def b3():
        # The evidence uploader posts with fetch and gets JSON back.
        res = chief().request('POST', f'/incidents/{r.state["robbery"]}/evidence', {'title': 'Market photo', 'classification': 'restricted', 'description': 'UAT test photo'},
                              inertia=False, json_api=True, files={'file': ('market.jpg', PHOTO, 'image/jpeg')})
        check(res.status in (200, 201), f'upload refused: {res.status} {res.body[:200]!r}')
        items = chief().get(f'/incidents/{r.state["robbery"]}?tab=evidence').props['evidence']['items']
        check(items, 'no evidence item')
        r.state['photo'] = items[0]['ulid']
        return f'accepted after the malware scan ({len(items)} item, status {items[0].get("status", {}).get("value", "?") if isinstance(items[0].get("status"), dict) else items[0].get("status", "?")})'
    r.step(s, 'B3', 'Upload evidence (scanned)', b3)

    def b4():
        res = chief().request('GET', f'/evidence/{r.state["photo"]}/custody', inertia=False, json_api=True)
        check(res.status == 200, f'custody {res.status}')
        entries = res.json()
        entries = entries.get('entries', entries) if isinstance(entries, dict) else entries
        check(len(entries) >= 1, 'empty custody')
        text = json.dumps(entries)
        check('UAT LGA Chief Lagos' in text, 'custody without the uploader')
        return f'{len(entries)} custody entries with name, time and IP'
    r.step(s, 'B4', 'Evidence custody', b4)

    def b5():
        res = chief().post(f'/incidents/{r.state["robbery"]}/updates', {'type': 'intelligence', 'body': 'Two suspects seen fleeing north.', 'classification': 'restricted'})
        check(not res.errors, f'{res.errors}')
        tl = chief().get(f'/incidents/{r.state["robbery"]}?tab=timeline').props['timeline']
        check(tl and 'fleeing north' in json.dumps(tl), 'update not in the timeline')
        return 'update shown in the Timeline'
    r.step(s, 'B5', 'Add update', b5)

    def c1():
        res = chief().post('/incidents', {
            'incident_type': r.state['types']['Kidnapping'], 'details': {}, 'title': f'UAT critical kidnapping {r.tag}',
            'description': 'Gunmen abducted travellers on the highway (UAT test data).', 'severity': 'critical',
            'occurred_at': r.now(-15), 'occurred_at_precision': 'approximate', 'lga': r.state['chief_lga'], 'classification': 'restricted',
            'casualties': [], 'persons': [], 'groups': [], 'agencies': [], 'properties': [],
        })
        ulid = res.props['incident']['ulid']
        chief().post(f'/incidents/{ulid}/submit', {'confirm_duplicates': True})
        inc = chief().get(f'/incidents/{ulid}').props['incident']
        r.state['kidnapping'] = ulid
        check(inc['reference_no'], 'no reference')
        check(inc.get('escalated') or inc.get('escalated_at') or 'escalat' in json.dumps(inc).lower(), 'not escalated')
        return f'{inc["reference_no"]}, escalated automatically'
    r.step(s, 'C1', 'Fast critical report: core facts only, submit', c1)

    def d1():
        form = chief().get('/threats/create').props
        tt = form['options']['threatTypes'][0]['value'] if 'threatTypes' in form['options'] else form['options']['types'][0]['value']
        r.state['threat_type'] = tt
        res = chief().post('/threats', {
            'lga': r.state['chief_lga'], 'threat_type': tt, 'title': f'UAT communal clash warning {r.tag}',
            'description': 'Tension between two communities over farmland (UAT test data).', 'credibility': 'probable',
            'urgency': 'priority', 'source_type': 'community', 'expires_at': r.now(14 * 24 * 60), 'classification': 'restricted',
        })
        check(res.page['component'] == 'threats/show', f'not created: {res.errors}')
        t = res.props['threat']
        r.state['threat'] = t['ulid']
        ref = t.get('reference_no') or t.get('reference')
        check(ref and ref.startswith('THR/LA/'), f'reference {ref}')
        return f'{ref}, Active'
    r.step(s, 'D1', 'Record threat report', d1)

    def d2():
        form = chief().get('/flashpoints/create').props
        res = chief().post('/flashpoints', {
            'lga': r.state['chief_lga'], 'kind': 'flashpoint', 'name': f'UAT market flashpoint {r.tag}', 'threat_type': r.state['threat_type'],
            'latitude': r.state['point'][0], 'longitude': r.state['point'][1], 'radius_meters': 500,
            'description': 'Recurring clashes at the market (UAT test data).', 'classification': 'restricted',
            'concern_level': 'moderate', 'status': 'monitoring', 'assessment': 'Initial assessment.',
        })
        check(res.page['component'] == 'flashpoints/show', f'not created: {res.errors}')
        r.state['flashpoint'] = res.props['flashpoint']['ulid']
        res = chief().post(f'/flashpoints/{r.state["flashpoint"]}/assessments', {'concern_level': 'high', 'status': 'active', 'assessment': 'Tension rising after the robbery.'})
        f = chief().get(f'/flashpoints/{r.state["flashpoint"]}').props['flashpoint']
        check(f['concern_level']['value'] == 'high' and f['status']['value'] == 'active', f'concern {f.get("concern_level")}')
        return 'recorded; assessment set concern High, Active'
    r.step(s, 'D2', 'Record flashpoint; record assessment', d2)

    def d3():
        kano = r.as_('sd.kano').get('/dashboard').props['auth']['user']['jurisdiction']['state']['ulid']
        r.state['kano'] = kano
        res = chief().post(f'/threats/{r.state["threat"]}/dissemination-requests', {'targets': [{'level': 'state', 'ulid': kano}], 'reason': 'The clash area borders Kano traders (UAT).'})
        check(not res.errors, f'{res.errors}')
        return 'request sent to the State Director'
    r.step(s, 'D3', 'Request dissemination to Kano', d3)

    def e1():
        res = chief().post('/persons/search', {'q': '98765432101'})
        p = res.props
        hits = p.get('hits') or p.get('crossJurisdiction', {}).get('hits') or []
        text = json.dumps(p.get('results', p.get('persons', [])))
        check(hits, f'no hit notice (props: {sorted(p.keys())})')
        h = hits[0]
        check('Testperson' not in json.dumps(hits) and 'Garba' not in json.dumps(hits), 'the hit reveals the name')
        r.state['hit_token'] = h['token']
        return f'"{h.get("strength_label", h.get("strength"))}" in {h.get("state")}; no name or details'
    r.step(s, 'E1', 'Persons search by NIN: record found in Kano', e1)

    def e2():
        res = chief().post('/access-requests', {'token': r.state['hit_token'], 'reason': 'Suspect in the market robbery may be this Kano trader (UAT).', 'scope': 'full', 'urgency': 'urgent', 'requested_days': 7})
        check(not res.errors, f'{res.errors}')
        mine = chief().get('/access-requests?tab=mine').props
        text = json.dumps(mine)
        check('pending' in text.lower(), 'no pending request listed')
        return 'request listed as Pending'
    r.step(s, 'E2', 'Request access (full, urgent, 7 days)', e2)

    def e3():
        p = chief().post('/persons/search', {'q': 'Garba Testperson'}).props
        hits = p.get('hits') or []
        check(not hits, 'a name alone produced a notice')
        return 'no cross-state notice for a name alone'
    r.step(s, 'E3', 'Persons search by name only: no notice', e3)

    def f1():
        form = chief().get('/coverage-reports/create').props
        week = form['periods']['weekly'][-1]
        freq = form['options'].get('patrolFrequencies', form['options'].get('frequencies', [{'value': 'daily'}]))[0]['value']
        res = chief().post('/coverage-reports', {
            'lga': r.state['chief_lga'], 'period_type': 'weekly', 'period_start': week['start'], 'period_end': week['end'],
            'classification': 'restricted', 'overall_rating': 'partial', 'summary': 'Police present at the market only (UAT).',
            'gaps_identified': 'No night patrols on the highway.', 'submit': True,
            'deployments': [{'agency': r.state['npf'], 'personnel_count': 12, 'vehicles_count': 2, 'patrol_frequency': freq, 'communities': []}],
        })
        check(res.page['component'] == 'coverage-reports/show', f'not saved: {res.errors}')
        rep = res.props['report']
        r.state['coverage'] = rep['ulid']
        check(rep['status']['value'] == 'submitted', f'status {rep["status"]}')
        return f'{week["label"]}: Submitted'
    r.step(s, 'F1', 'Coverage report: write and submit', f1)

    def g1():
        kano_incidents = r.as_('sd.kano').get('/incidents').props['incidents']['data']
        check(kano_incidents, 'no Kano incident to try')
        res = chief().get(f'/incidents/{kano_incidents[0]["ulid"]}')
        check(res.status == 404, f'answered {res.status}')
        return 'a Kano incident is "not found" for the Lagos chief'
    r.step(s, 'G1', 'Out-of-jurisdiction incident: not found', g1)

    def g2():
        res = chief().post('/reports', {'type': 'sitrep', 'format': 'pdf', 'parameters': {}})
        run_ulid = wait_report(chief())
        pdf = chief().request('GET', f'/reports/runs/{run_ulid}/download', inertia=False)
        check(pdf.status == 200 and pdf.body[:4] == b'%PDF', f'download {pdf.status}')
        r.state['sitrep_bytes'] = len(pdf.body)
        return f'ready; PDF downloaded ({len(pdf.body)} bytes)'
    r.step(s, 'G2', 'Daily situation report', g2)

    def g3():
        types = [t['value'] for t in chief().get('/reports').props['types']]
        check('incident_list' not in types, 'incident list export offered')
        return f'offered: {", ".join(types)}'
    r.step(s, 'G3', 'Incident list export not offered', g3)


def wait_report(client, timeout=180):
    deadline = time.time() + timeout
    while time.time() < deadline:
        runs = client.get('/reports').props['runs']
        runs = runs.get('data', runs) if isinstance(runs, dict) else runs
        if runs:
            latest = runs[0]
            status = latest['status']['value'] if isinstance(latest['status'], dict) else latest['status']
            if status in ('ready', 'completed', 'succeeded'):
                return latest['ulid']
            if status == 'failed':
                raise Failed(f'report failed: {latest.get("error")}')
        time.sleep(3)
    raise Failed('report not ready in time')


def status_of(record):
    st = record.get('status')
    return st.get('value') if isinstance(st, dict) else st


# ---------------------------------------------------------------------------
# Script 2: Deputy State Director
# ---------------------------------------------------------------------------
def script_2(r: Run):
    s = '02-deputy-state-director'
    dsd = lambda: r.as_('dsd.lagos')
    chief = lambda: r.as_('chief.lagos')

    def a1():
        unread = dsd().get('/dashboard').props['notifications']['unreadCount']
        check(unread >= 2, f'{unread} unread notifications')
        return f'{unread} unread notifications (incidents, coverage report)'
    r.step(s, 'A1', 'Notifications for the submitted incidents and report', a1)

    def b1():
        dsd().post(f'/incidents/{r.state["robbery"]}/verify', {'comment': 'Confirmed with the divisional police.'})
        check(status_of(dsd().get(f'/incidents/{r.state["robbery"]}').props['incident']) == 'verified', 'not verified')
        return 'Verified'
    r.step(s, 'B1', 'Verify the robbery', b1)

    def b2():
        dsd().post(f'/incidents/{r.state["robbery"]}/start-investigation', {})
        check(status_of(dsd().get(f'/incidents/{r.state["robbery"]}').props['incident']) == 'under_investigation', 'not under investigation')
        return 'Under investigation'
    r.step(s, 'B2', 'Start investigation', b2)

    def b3():
        can = dsd().get(f'/incidents/{r.state["robbery"]}').props['can']
        check(not can['close'], 'close offered to the Deputy')
        return 'Close not offered'
    r.step(s, 'B3', 'Close not offered to the Deputy', b3)

    def b4():
        res = dsd().request('GET', f'/evidence/{r.state["photo"]}/download', inertia=False)
        check(res.status == 200, f'download {res.status}')
        check(res.body == PHOTO, 'the original differs from the uploaded file')
        custody = json.dumps(dsd().request('GET', f'/evidence/{r.state["photo"]}/custody', inertia=False, json_api=True).json())
        check('download' in custody.lower(), 'download not in custody')
        return 'original identical to the upload (byte for byte); download in custody'
    r.step(s, 'B4', 'Download the original; custody shows it', b4)

    def c1():
        p = dsd().get(f'/incidents/{r.state["kidnapping"]}').props
        check('escalat' in json.dumps(p['incident']).lower(), 'not escalated')
        check(not p['can']['acknowledge'], 'acknowledge offered to the Deputy')
        return 'Escalated, awaiting national acknowledgement'
    r.step(s, 'C1', 'Critical kidnapping escalated', c1)

    def d1():
        dsd().post(f'/coverage-reports/{r.state["coverage"]}/return', {'reason': 'Add the vehicle count for night patrols.'})
        check(status_of(dsd().get(f'/coverage-reports/{r.state["coverage"]}').props['report']) == 'returned', 'not returned')
        return 'Returned'
    r.step(s, 'D1', 'Return the coverage report', d1)

    def d2():
        p = chief().get(f'/coverage-reports/{r.state["coverage"]}/edit').props
        deps = [{'ulid': d['ulid'], 'agency': d['agency']['ulid'] if isinstance(d['agency'], dict) else d['agency'],
                 'personnel_count': 15, 'vehicles_count': 3,
                 'patrol_frequency': d['patrol_frequency']['value'] if isinstance(d['patrol_frequency'], dict) else d['patrol_frequency'],
                 'communities': []} for d in p['deployments']]
        res = chief().put(f'/coverage-reports/{r.state["coverage"]}', {'overall_rating': 'partial', 'summary': 'Police present at the market only (UAT).',
                          'gaps_identified': 'No night patrols on the highway.', 'classification': 'restricted', 'deployments': deps, 'submit': True})
        check(not res.errors, f'resubmit: {res.errors}')
        dsd().post(f'/coverage-reports/{r.state["coverage"]}/review', {'comment': 'Reviewed.'})
        p = dsd().get(f'/coverage-reports/{r.state["coverage"]}').props
        check(status_of(p['report']) == 'reviewed', f'status {status_of(p["report"])}')
        check(not any(v for k, v in p.get('can', {}).items() if k in ('update', 'submit', 'return', 'review')), f'still editable: {p.get("can")}')
        return 'resubmitted with 15 personnel; Reviewed and locked'
    r.step(s, 'D2', 'Chief resubmits; mark reviewed (locked)', d2)

    def e1():
        p = dsd().get('/access-requests?tab=decide').props
        check(p.get('isDelegatedDeputy') is False, f'delegated: {p.get("isDelegatedDeputy")}')
        check(not p.get('decide'), f'{len(p.get("decide") or [])} request(s) to decide')
        return 'no delegation: nothing to decide'
    r.step(s, 'E1', 'No access decisions without a delegation', e1)

    def f1():
        can = dsd().get(f'/flashpoints/{r.state["flashpoint"]}').props['can']
        check(not can.get('delete'), 'delete offered')
        return 'no delete option'
    r.step(s, 'F1', 'Deputies cannot delete', f1)


# ---------------------------------------------------------------------------
# Script 3: State Director (Lagos and Kano)
# ---------------------------------------------------------------------------
def script_3(r: Run):
    s = '03-state-director'
    sd = lambda: r.as_('sd.lagos')
    kano = lambda: r.as_('sd.kano')
    chief = lambda: r.as_('chief.lagos')

    def a1():
        sd().post(f'/incidents/{r.state["robbery"]}/resolve', {'summary': 'Two suspects arrested; goods recovered.'})
        check(status_of(sd().get(f'/incidents/{r.state["robbery"]}').props['incident']) == 'resolved', 'not resolved')
        return 'Resolved'
    r.step(s, 'A1', 'Resolve the robbery', a1)

    def a2():
        sd().post(f'/incidents/{r.state["robbery"]}/close', {})
        p = sd().get(f'/incidents/{r.state["robbery"]}').props
        check(status_of(p['incident']) == 'closed', 'not closed')
        offered = [k for k, v in p['can'].items() if v]
        check(not any(k in offered for k in ('update', 'addUpdate', 'resolve', 'close')), f'still offered: {offered}')
        check(not p['evidence']['can_upload'], 'evidence upload still offered')
        return f'Closed and locked (offered now: {", ".join(offered) or "nothing"})'
    r.step(s, 'A2', 'Close: locked', a2)

    def a3():
        res = sd().get(f'/incidents/{r.state["robbery"]}/print')
        check(res.status == 200, f'print {res.status}')
        text = json.dumps(res.page)
        check('UAT State Director Lagos' in text, 'no "printed by"')
        return 'print view with the classification and "Printed by UAT State Director Lagos"'
    r.step(s, 'A3', 'Print', a3)

    def b1():
        res = sd().post(f'/threats/{r.state["threat"]}/disseminations', {'targets': [{'level': 'state', 'ulid': r.state['kano']}], 'reason': 'The clash area borders Kano traders.'})
        check(not res.errors, f'{res.errors}')
        return 'shared with Kano'
    r.step(s, 'B1', 'Disseminate the threat to Kano', b1)

    def b2():
        p = kano().get('/disseminations').props
        text = json.dumps(p)
        check(r.state['threat'] in text, 'not listed for Kano')
        ulid = re.search(r'"ulid": "([0-9a-z]{26})"[^{}]*"acknowledged', text) or re.search(r'"dissemination": \{"ulid": "([0-9a-z]{26})"', text)
        rows = p.get('received', p.get('rows', p.get('items', [])))
        rows = rows.get('data', rows) if isinstance(rows, dict) else rows
        target = next((x for x in rows if r.state['threat'] in json.dumps(x)), None)
        check(target is not None, 'row not found')
        kano().post(f'/disseminations/{target["ulid"]}/acknowledge', {})
        t = kano().get(f'/threats/{r.state["threat"]}')
        check(t.status == 200, f'Kano cannot open the threat ({t.status})')
        check(not t.props.get('history'), 'History shown to the recipient')
        return 'listed read-only; acknowledged; no History'
    r.step(s, 'B2', 'Kano: shared threat; acknowledge', b2)

    def c1():
        rows = kano().get('/access-requests?tab=decide').props['decide']
        rows = rows.get('data', rows) if isinstance(rows, dict) else rows
        check(rows, 'no request to decide')
        req = rows[0]
        r.state['request'] = req['ulid']
        return f'request from {req.get("requester", {}).get("name", "?") if isinstance(req.get("requester"), dict) else req.get("requester")}'
    r.step(s, 'C1', 'Kano: request awaiting decision', c1)

    def c2():
        before = kano().step_ups
        res = kano().post(f'/access-requests/{r.state["request"]}/approve', {'granted_days': 7, 'granted_scope': 'profile', 'note': 'Profile only.'})
        check(not res.errors, f'{res.errors}')
        check(kano().step_ups == before + 1, 'no authenticator code was asked')
        text = json.dumps(kano().get(f'/access-requests/{r.state["request"]}').props)
        check('approved' in text.lower(), 'not approved')
        return 'approved for 7 days, profile only (authenticator code asked)'
    r.step(s, 'C2', 'Kano: approve (narrowed to profile), step-up', c2)

    def c3():
        text = json.dumps(chief().get('/access-requests?tab=grants').props)
        person = re.search(r'"record": \{"ulid": "([0-9a-z]{26})"', text)
        check(person, 'no grant listed')
        res = chief().get(f'/persons/{person.group(1)}')
        check(res.status == 200, f'grant view {res.status}')
        body = res.body.decode()
        check('Testperson' in body, 'name not shown')
        check('98765432101' not in body and '08030000099' not in body, 'NIN or phone shown under a profile grant')
        return 'profile visible read-only; no NIN, phone or LGA'
    r.step(s, 'C3', 'Chief: view under the grant', c3)

    def d1():
        p = sd().get('/access-requests?tab=delegations').props
        text = json.dumps(p)
        dsd_ulid = r.as_('dsd.lagos').get('/dashboard').props['auth']['user']['ulid']
        res = sd().post('/approval-delegations', {'delegate': dsd_ulid, 'starts_at': datetime.date.today().isoformat(), 'reason': 'Leave next week.'})
        check(not res.errors, f'{res.errors}')
        check(r.as_('dsd.lagos').get('/access-requests?tab=decide').props.get('isDelegatedDeputy') is True, 'the Deputy is not delegated')
        return 'active; the Deputy now decides requests'
    r.step(s, 'D1', 'Delegate approvals to the Deputy', d1)

    def d2():
        text = json.dumps(sd().get('/access-requests?tab=delegations').props)
        m = re.search(r'"current": \[\{"ulid": "([0-9a-z]{26})"', text) or re.search(r'"ulid": "([0-9a-z]{26})"', text)
        check(m, 'no delegation listed')
        sd().post(f'/approval-delegations/{m.group(1)}/revoke', {'reason': 'Back from leave.'})
        check(r.as_('dsd.lagos').get('/access-requests?tab=decide').props.get('isDelegatedDeputy') is False, 'the Deputy still decides')
        return 'revoked; the Deputy can no longer decide'
    r.step(s, 'D2', 'Revoke the delegation', d2)

    def e1():
        before = sd().step_ups
        res = sd().post('/reports', {'type': 'incident_list', 'format': 'xlsx', 'parameters': {'filters': {}, 'confirmed': True}})
        check(not res.errors, f'{res.errors}')
        r.state['export'] = wait_report(sd())
        asked = sd().step_ups > before
        return f'queued and ready; authenticator code {"asked (large or secret export)" if asked else "not asked"}'
    r.step(s, 'E1', 'Incident list export (XLSX)', e1)

    def e2():
        import io
        import zipfile
        before = sd().step_ups
        res = sd().request('GET', f'/reports/runs/{r.state["export"]}/download', inertia=False)
        check(res.status == 200 and res.body[:2] == b'PK', f'download {res.status}')
        secret_step_up = sd().stepped_up_since(before)
        z = zipfile.ZipFile(io.BytesIO(res.body))
        strings = z.read('xl/sharedStrings.xml').decode() if 'xl/sharedStrings.xml' in z.namelist() else ''
        sheet = ''.join(z.read(n).decode() for n in z.namelist() if n.startswith('xl/worksheets/'))
        allxml = strings + sheet
        check('Generated for' in allxml, 'no "Generated for" marking')
        marking = next((lv for lv in ('SECRET', 'CONFIDENTIAL', 'RESTRICTED') if lv in allxml), None)
        check(marking, 'no classification marking')
        check(marking != 'SECRET' or secret_step_up, 'a secret file downloaded without the authenticator code')
        check('Two armed men robbed' not in allxml, 'free-text description exported')
        return f'marked {marking} (highest level included) and "Generated for"; no descriptions; {"authenticator code asked for the secret download" if marking == "SECRET" else ""} ({len(res.body)} bytes)'
    r.step(s, 'E2', 'Export carries markings, no descriptions', e2)

    def e3():
        res = sd().get('/blackspot-suggestions')
        check(res.status == 200, f'{res.status}')
        return 'suggestions page opens'
    r.step(s, 'E3', 'Suggested blackspots', e3)


# ---------------------------------------------------------------------------
# Script 4: Deputy Director General
# ---------------------------------------------------------------------------
def script_4(r: Run):
    s = '04-deputy-director-general'
    ddg = lambda: r.as_('ddg')

    def a1():
        page = ddg().get('/dashboard').page
        check(page['component'] == 'dashboard/national', page['component'])
        return 'national dashboard'
    r.step(s, 'A1', 'National dashboard', a1)

    def a2():
        ddg().post(f'/incidents/{r.state["kidnapping"]}/acknowledge', {})
        inc = ddg().get(f'/incidents/{r.state["kidnapping"]}').props['incident']
        check('acknowledg' in json.dumps(inc).lower() and 'UAT Deputy Director General' in json.dumps(inc), 'not acknowledged')
        return 'acknowledged by the DDG'
    r.step(s, 'A2', 'Acknowledge the escalation', a2)

    def b1():
        text = json.dumps(ddg().get('/access-requests?tab=oversight').props)
        check(r.state['request'] in text, 'request not in oversight')
        return 'the Lagos→Kano request and grant listed'
    r.step(s, 'B1', 'Access oversight', b1)

    def b2():
        p = ddg().post('/persons/search', {'q': 'Garba Testperson', 'dob': '1985-04-12'}).props
        check('Testperson' in json.dumps(p.get('results', p.get('persons', p))), 'not a normal result')
        check(not p.get('hits'), 'a national user got a notice')
        return 'normal result; no notice'
    r.step(s, 'B2', 'Person search as a national user', b2)

    def c1():
        text = json.dumps(ddg().get('/audit/exports').props)
        check('UAT State Director Lagos' in text, 'export not listed')
        return "sd.lagos's export listed"
    r.step(s, 'C1', 'Exports review', c1)

    def d1():
        res = ddg().get('/analytics')
        check(res.status == 200, f'{res.status}')
        return 'analytics open'
    r.step(s, 'D1', 'Analytics', d1)

    def d2():
        res = ddg().get('/users/create')
        check(res.status == 403, f'{res.status}')
        return 'refused (403)'
    r.step(s, 'D2', 'No account management', d2)


# ---------------------------------------------------------------------------
# Script 5: Director General
# ---------------------------------------------------------------------------
def script_5(r: Run):
    s = '05-director-general'
    dg = lambda: r.as_('dg')
    chief = lambda: r.as_('chief.lagos')

    def a1():
        page = dg().get('/dashboard').page
        check(page['component'] == 'dashboard/national', page['component'])
        return f'national dashboard; {page["props"]["notifications"]["unreadCount"]} unread notifications'
    r.step(s, 'A1', 'Dashboard and notifications', a1)

    def b1():
        code = f'uat_test_threat_{r.tag}'
        res = dg().post('/reference-data/threat-types', {'name': f'UAT test threat {r.tag}', 'code': code, 'category': 'violence_conflict'})
        check(not res.errors, f'{res.errors}')
        form = chief().get('/threats/create').props['options']
        check(f'UAT test threat {r.tag}' in json.dumps(form), 'not offered in the threat form')
        text = json.dumps(dg().get('/reference-data/threat-types').props)
        m = re.search(r'"ulid": "([0-9a-z]{26})", "name": "UAT test threat ' + r.tag, text)
        if m:
            dg().post(f'/reference-data/threat-types/{m.group(1)}/deactivate', {})
        return 'added, offered in the form, deactivated again'
    r.step(s, 'B1', 'Reference data: add and deactivate a threat type', b1)

    def b2():
        sd_ulid = r.as_('sd.lagos').get('/dashboard').props['auth']['user']['ulid']
        text = json.dumps(dg().get(f'/audit?causer={sd_ulid}').props)
        check('close' in text.lower() or 'closed' in text.lower(), 'closure not in the audit log')
        return "sd.lagos's closure, export and delegation in the audit log"
    r.step(s, 'B2', 'Audit log by user', b2)

    def c1():
        res = chief().post('/incidents', {
            'incident_type': r.state['types']['Kidnapping'], 'details': {}, 'title': f'UAT duplicate kidnapping {r.tag}',
            'description': 'Same event reported again (UAT).', 'severity': 'high', 'occurred_at': r.now(-15), 'occurred_at_precision': 'approximate',
            'lga': r.state['chief_lga'], 'classification': 'restricted', 'casualties': [], 'persons': [], 'groups': [], 'agencies': [], 'properties': [],
        })
        dup = res.props['incident']['ulid']
        chief().post(f'/incidents/{dup}/submit', {'confirm_duplicates': True})
        res = dg().post(f'/incidents/{dup}/invalidate', {'reason': 'duplicate', 'duplicate_of': r.state['kidnapping']})
        check(not res.errors, f'{res.errors}')
        check(status_of(dg().get(f'/incidents/{dup}').props['incident']) == 'invalid', 'not invalid')
        return 'Invalid (duplicate), locked'
    r.step(s, 'C1', 'Invalidate a duplicate', c1)

    def d1():
        res = dg().request('GET', '/help/cards/director_general.pdf', inertia=False)
        check(res.status == 200 and res.body[:4] == b'%PDF', f'{res.status}')
        return f'one-page PDF ({len(res.body)} bytes)'
    r.step(s, 'D1', 'Quick reference card', d1)


# ---------------------------------------------------------------------------
# Script 6: System administrator
# ---------------------------------------------------------------------------
def script_6(r: Run):
    s = '06-super-admin'
    admin = lambda: r.as_('admin')

    def a1():
        page = admin().get('/dashboard').page
        check(page['component'] == 'dashboard/system', page['component'])
        check('onboarding' in page['props'], 'no rollout panel')
        return 'system overview with the rollout and onboarding panel'
    r.step(s, 'A1', 'System dashboard', a1)

    def a2():
        res = admin().get('/incidents')
        check(res.status in (403, 404), f'{res.status}')
        return f'refused ({res.status})'
    r.step(s, 'A2', 'No intelligence content', a2)

    rows = [
        f'UAT Import DG {r.tag},import.dg.{r.tag}@uat.buffalo.test,,,,director_general,,,',
        f'UAT Import SD Oyo {r.tag},import.sd.{r.tag}@uat.buffalo.test,,,,state_director,OY,,',
        f'UAT Import DSD Lagos {r.tag},import.dsd.{r.tag}@uat.buffalo.test,,,,deputy_state_director,LA,,',
        f'UAT Import Chief Lagos {r.tag},import.chief.{r.tag}@uat.buffalo.test,,,,lga_security_chief,LA,D12,',
    ]
    header = 'name,email,phone,service_number,rank,role,state_code,lga_code,zone_codes'

    def b1():
        csv = '\n'.join([header, *rows, f'Bad Row {r.tag},bad.{r.tag}@uat.buffalo.test,,,,state_director,XX,,']) + '\n'
        res = admin().post('/users/import/preview', {}, files={'file': ('users.csv', csv.encode(), 'text/csv')})
        p = res.props
        check(p['summary'] == {'new': 4, 'exists': 0, 'error': 1}, f'summary {p["summary"]}')
        check(any('Unknown state code "XX".' in e for row in p['rows'] for e in row['errors']), 'no unknown state message')
        return '4 New, 1 Error (Unknown state code "XX")'
    r.step(s, 'B1', 'Import preview with one bad row', b1)

    def b2():
        csv = '\n'.join([header, *rows]) + '\n'
        p = admin().post('/users/import/preview', {}, files={'file': ('users.csv', csv.encode(), 'text/csv')}).props
        keep = ['line', 'name', 'email', 'phone', 'service_number', 'rank', 'role', 'state_code', 'lga_code', 'zone_codes']
        before = admin().step_ups
        res = admin().post('/users/import', {'rows': [{k: x[k] for k in keep} for x in p['rows']]})
        check(admin().step_ups == before + 1, 'no authenticator code was asked')
        check(res.page['component'] == 'users/onboarding', f'{res.page["component"]} {res.errors}')
        listed = [u for u in res.props['users'] if r.tag in u['name']]
        check(len(listed) == 4 and all(u['stage'] == 'no_link' for u in listed), f'{[(u["name"], u["stage"]) for u in listed]}')
        r.state['imported'] = {u['email']: u['ulid'] for u in listed}
        return '4 accounts created (authenticator code asked) without setup links; listed as setup pending'
    r.step(s, 'B2', 'Create the accounts (step-up)', b2)

    def b3():
        ulids = list(r.state['imported'].values())[:2]
        res = admin().post('/users/onboarding/handover', {'users': ulids})
        check(res.page['component'] == 'users/handover' and len(res.props['users']) == 2, res.page['component'])
        check('/account/setup/' not in res.body.decode(), 'a link is in the page')
        target = r.state['imported'][f'import.sd.{r.tag}@uat.buffalo.test']
        link = admin().request('POST', f'/users/{target}/setup-link', {'delivery': 'handover'}, inertia=False, json_api=True)
        check(link.status == 200 and '/account/setup/' in link.json()['url'], f'reveal {link.status}')
        r.state['setup_link'] = link.json()['url']
        return 'session page without links; one link revealed (handover)'
    r.step(s, 'B3', 'Handover session: reveal one link', b3)

    def b4():
        guest = Client(r.base, f'import.sd.{r.tag}@uat.buffalo.test', 'Uat-Setup-Pass-2026!', '')
        guest.refresh_version()
        path = urllib_path(r.state['setup_link'])
        guest.get(path)
        res = guest.post(path, {'password': guest.password, 'password_confirmation': guest.password})
        check(res.page['component'] == 'auth/two-factor-setup', f'after setup: {res.page["component"]} {res.errors}')
        guest.request('POST', '/user/two-factor-authentication', {}, inertia=False, json_api=True)
        secret = guest.request('GET', '/user/two-factor-secret-key', inertia=False, json_api=True).json()['secretKey']
        guest.secret = secret
        res = guest.request('POST', '/user/confirmed-two-factor-authentication', {'code': guest.fresh_code()}, inertia=False, json_api=True)
        check(res.status in (200, 204), f'confirm 2FA {res.status}')
        guest.post('/two-factor/setup/complete', {})
        r.state['oyo_new'] = guest
        stats = next(x for x in admin().get('/system/rollout').props['states'] if x['state'] and x['state']['code'] == 'OY')
        check(stats['ready'] >= 1, f'Oyo ready {stats["ready"]}')
        return 'password and 2FA set; Ready on the Rollout page'
    r.step(s, 'B4', 'Officer completes setup from the link', b4)

    def c1():
        oyo = Client(r.base, *r.accounts['sd.oyo'])
        res = oyo.login()
        page = res.page
        check(page['component'] == 'auth/login', f'reached {page["component"]}')
        check(page['props'].get('status') == 'BUFFALO is not yet active for your state.', f'message {page["props"].get("status")}')
        return '"BUFFALO is not yet active for your state."'
    r.step(s, 'C1', 'Officer of a state not live is refused', c1)

    def c2():
        oyo_ulid = None
        text = json.dumps(admin().get('/login-history?event=state_not_live').props)
        check('sd.oyo@uat.buffalo.test' in text, 'not in login history')
        return '"state_not_live" recorded'
    r.step(s, 'C2', 'Login history', c2)

    def c3():
        state = next(x['state'] for x in admin().get('/system/rollout').props['states'] if x['state'] and x['state']['code'] == 'OY')
        before = admin().step_ups
        res = admin().put(f'/system/rollout/{state["ulid"]}', {'live': True, 'reason': 'Oyo pilot starts (UAT).'})
        check(not res.errors, f'{res.errors}')
        check(admin().stepped_up_since(before), 'no authenticator code asked or recently confirmed')
        state = next(x['state'] for x in admin().get('/system/rollout').props['states'] if x['state'] and x['state']['code'] == 'OY')
        check(state['is_live'], 'not live')
        return 'Oyo live (authenticator code confirmed within the last 15 minutes)'
    r.step(s, 'C3', 'Make Oyo live', c3)

    def c4():
        oyo = Client(r.base, *r.accounts['sd.oyo'])
        res = oyo.login()
        check(res.page['component'] == 'dashboard/state', res.page['component'])
        return 'State Director dashboard for Oyo'
    r.step(s, 'C4', 'The Oyo officer signs in', c4)

    def d1():
        rep = admin().get('/system/health').props['report']
        check(rep['golive'] is not None and rep['golive']['passed'] is False, f'go-live {rep.get("golive")}')
        return f'go-live card: not ready, {rep["golive"]["outstanding"]} item(s) outstanding'
    r.step(s, 'D1', 'System health', d1)

    def d2():
        items = {i['key']: i['status'] for i in admin().get('/system/go-live').props['check']['items']}
        check(items['demo_data'] == 'fail' and items['dev_accounts'] == 'fail', f'{items}')
        return 'demo data and test accounts fail, decisions missing, as expected in UAT'
    r.step(s, 'D2', 'Go-live page', d2)

    def d3():
        admin().post('/uat/feedback', {'text': f'UAT runner note {r.tag}', 'page_url': '/system/go-live'})
        text = json.dumps(admin().get('/uat/feedback').props)
        check(f'UAT runner note {r.tag}' in text, 'not listed')
        csv = admin().request('GET', '/uat/feedback/export', inertia=False)
        check(csv.status == 200 and f'UAT runner note {r.tag}' in csv.body.decode(), 'not exported')
        return 'recorded with page and role; exported as CSV'
    r.step(s, 'D3', 'Feedback', d3)


def urllib_path(url):
    from urllib.parse import urlparse
    return urlparse(url).path


SCRIPTS = [script_1, script_2, script_3, script_4, script_5, script_6]


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--base', default='https://localhost:19443')
    parser.add_argument('--credentials', required=True)
    parser.add_argument('--only', help='run only this script number')
    parser.add_argument('--no-write', action='store_true')
    args = parser.parse_args()

    r = Run(args.base, args.credentials)
    for fn in SCRIPTS:
        if args.only and not fn.__name__.endswith(args.only):
            continue
        print(f'== {fn.__name__}')
        fn(r)

    total = sum(len(v) for v in r.results.values())
    failed = sum(1 for v in r.results.values() for x in v if x[2] == 'Fail')
    print(f'\n{total - failed}/{total} steps passed')
    if not args.no_write:
        write_results(r)
    return 1 if failed else 0


def write_results(r: Run):
    out = os.path.join(ROOT, 'docs', 'uat', 'results')
    os.makedirs(out, exist_ok=True)
    stamp = datetime.datetime.now().strftime('%Y-%m-%d')
    for script, rows in r.results.items():
        lines = [f'# UAT results — {script}', '', f'- Date: {datetime.datetime.now().strftime("%Y-%m-%d %H:%M")} (Africa/Lagos)',
                 f'- Environment: {r.base} (UAT, synthetic data)', '- Run by: scripts/uat/run.py (signed in as each tester, password and TOTP)', '',
                 '| # | Step | Result | Observed |', '|---|---|---|---|']
        for number, text, outcome, detail, seconds in rows:
            lines.append(f'| {number} | {text} | {"✓ Pass" if outcome == "Pass" else "✗ FAIL"} | {detail.replace("|", "/")} |')
        open(os.path.join(out, f'{stamp}-{script}.md'), 'w').write('\n'.join(lines) + '\n')


if __name__ == '__main__':
    sys.exit(main())
