#!/usr/bin/env python3
"""Real-cookie HTTP authorization smoke against a disposable MySQL fixture.

Run ONLY from the CI service job after mysql_http_seed.php, with
TASKFLOW_HTTP_TEST_URL=http://127.0.0.1:18081 and a localhost PHP server.
The fixture is not a mocked browser: requests actually authenticate and hit PDO.
"""
from __future__ import annotations
import json
import os
from copy import copy
from http.cookiejar import CookieJar
from urllib.error import HTTPError, URLError
from urllib.request import Request, build_opener, HTTPCookieProcessor
from urllib.parse import urlencode

BASE=os.getenv('TASKFLOW_HTTP_TEST_URL', '')
if BASE != 'http://127.0.0.1:18081' or os.getenv('TASKFLOW_TEST_ALLOW_DISPOSABLE') != 'yes':
    raise SystemExit('Refusing HTTP test outside the disposable localhost CI fixture.')
FIXTURE=json.loads(os.environ['TASKFLOW_HTTP_FIXTURE'])
PASS='Disposable-QA-Passphrase-2026!'
checks=0

def check(got,expected,description):
    global checks
    checks+=1
    if got != expected:
        raise AssertionError(f'{description}: expected {expected!r}, got {got!r}')
    print('PASS',description,flush=True)

class Client:
    def __init__(self):
        self.cookiejar=CookieJar()
        self.opener=build_opener(HTTPCookieProcessor(self.cookiejar))
        self.csrf=None
    def call(self,path,payload=None,csrf=True,override=None):
        headers={'Accept':'application/json'}
        if payload is not None:
            data=json.dumps(payload).encode()
            headers['Content-Type']='application/json'
            if csrf and self.csrf:headers['X-CSRF-Token']=self.csrf
            if override:headers['X-HTTP-Method-Override']=override
            method='POST'
        else:
            data=None;method='GET'
        req=Request(BASE+'/api/'+path,data=data,headers=headers,method=method)
        try:
            response=self.opener.open(req,timeout=8)
        except HTTPError as error:
            response=error
        with response:
            status=response.status
            raw=response.read(200_000)
            try: body=json.loads(raw.decode())
            except ValueError:raise AssertionError(f'Unexpected non-JSON HTTP {status} for {path}: {raw[:250]!r}')
            return status,body,dict(response.headers.items())
    def token(self):
        status,body,_=self.call('csrf.php')
        check(status,200,'anonymous CSRF token endpoint')
        self.csrf=body['csrf_token']
        assert isinstance(self.csrf,str) and len(self.csrf)>=32
    def login(self,email):
        self.token()
        status,body,_=self.call('login.php',{'email':email,'password':PASS})
        check(status,200,'login with server-backed session: '+email)
        self.csrf=body['csrf_token']
        assert 'user' in body and not body.get('mfa_required')
        return body['user']
    def expect(self,path,status,payload=None,description=None,**kwargs):
        code,body,headers=self.call(path,payload,**kwargs)
        check(code,status,description or path)
        return body,headers

def upload_attachment(client,task_id,content,filename='evidence.pdf',csrf=True):
    """Send real multipart/form-data with the logged-in cookie jar."""
    boundary='TaskFlow-QA-'+os.urandom(10).hex()
    payload=(
        f'--{boundary}\r\nContent-Disposition: form-data; name="task_id"\r\n\r\n{task_id}\r\n'
        f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="{filename}"\r\n'
        'Content-Type: application/pdf\r\n\r\n'
    ).encode()+content+f'\r\n--{boundary}--\r\n'.encode()
    headers={'Content-Type':f'multipart/form-data; boundary={boundary}','Accept':'application/json'}
    if csrf and client.csrf:headers['X-CSRF-Token']=client.csrf
    req=Request(BASE+'/api/attachments.php',data=payload,headers=headers,method='POST')
    try: response=client.opener.open(req,timeout=10)
    except HTTPError as error:response=error
    with response:
        return response.status,json.loads(response.read(200_000).decode())

def attachment_download(client,attachment_id):
    req=Request(BASE+'/api/attachments.php?download_id='+str(attachment_id),method='GET')
    try:response=client.opener.open(req,timeout=10)
    except HTTPError as error:response=error
    with response:
        return response.status,response.read(9*1024*1024),{k.lower():v for k,v in response.headers.items()}

print('TaskFlow v3 authenticated HTTP and authorization smoke',flush=True)
anonymous=Client()
anonymous.expect('me.php',401,description='anonymous cannot read private profile')
anonymous.expect('task_reviews.php?team_id='+str(FIXTURE['team1'])+'&scope=team',401,
                 description='anonymous cannot read review queue')
anonymous.expect('attachments.php?task_id='+str(FIXTURE['member_task']),401,
                 description='anonymous cannot see attachment metadata')

outsider=Client()
u=outsider.login('outsider@taskflow.test')
check(u['team_id'],FIXTURE['team2'],'outsider belongs to second team')
outsider.expect('task.php?id='+str(FIXTURE['member_task']),403,
               description='other-team task denied')
outsider.expect('comments.php?task_id='+str(FIXTURE['member_task']),403,
               description='other-team discussion denied')
outsider.expect('task_reviews.php?team_id='+str(FIXTURE['team1'])+'&scope=team',403,
               description='other-team review queue denied')
outsider.expect('task_reviews.php?team_id='+str(FIXTURE['team2'])+'&scope=team',403,
               description='member cannot view full own-team queue')
outsider.expect('task_reviews.php',403,
    {'team_id':FIXTURE['team1'],'task_id':FIXTURE['member_task'],'action':'request','note':'bad'},
    'cross-team review submission denied')
private_inbox,_=outsider.expect('inbox.php',200,description='recipient can view personal inbox')
assert any(n['id']==FIXTURE['private_notice'] for n in private_inbox['notifications'])

member=Client()
member.token()
member.expect('login.php',403,{'email':'member@taskflow.test','password':PASS},
              'login CSRF required',csrf=False)
member.login('member@taskflow.test')
profile,headers=member.expect('me.php',200,description='authenticated member profile')
check(len(profile['teams']),1,'member only receives own team')
check(profile['teams'][0]['id'],FIXTURE['team1'],'member team scope')
assert 'no-store' in headers.get('Cache-Control','')
member.expect('task.php?id='+str(FIXTURE['private_task']),403,
    description='member cannot access another team task')
member.expect('comments.php',403,{'task_id':FIXTURE['private_task'],'content':'Cross-team comment'},
    'other-team comment post denied')
member.expect('task_reviews.php?team_id='+str(FIXTURE['team1'])+'&scope=team',403,
    description='member cannot read manager team queue')
member.expect('task_reviews.php',403,
    {'team_id':FIXTURE['team1'],'task_id':FIXTURE['member_task'],'action':'request','note':'Ready'},
    'review submission requires CSRF',csrf=False)
request,_=member.expect('task_reviews.php',200,
    {'team_id':FIXTURE['team1'],'task_id':FIXTURE['member_task'],'action':'request','note':'Review my work'},
    'member can submit own task review')
member.expect('task_reviews.php',409,
    {'team_id':FIXTURE['team1'],'task_id':FIXTURE['member_task'],'action':'request','note':''},
    'cannot submit duplicate pending review')
member.expect('task_reviews.php',409,
    {'team_id':FIXTURE['team1'],'task_id':FIXTURE['member_task'],'action':'approve','version':1,'note':''},
    'member cannot approve own task')
member.expect('task.php',409,
    {'id':FIXTURE['member_task'],'status':'done'},'single-task completion cannot bypass pending review',override='PUT')
member.expect('bulk.php',409,
    {'team_id':FIXTURE['team1'],'task_ids':[FIXTURE['member_task']],'field':'status','value':'done'},
    'bulk completion cannot bypass pending review')
my_queue,_=member.expect('task_reviews.php?team_id='+str(FIXTURE['team1'])+'&scope=mine',200,
    description='member can read own submissions')
check(len(my_queue['reviews']),1,'own queue contains one pending review')
notice,_=member.expect('inbox.php',200,{'action':'read','id':FIXTURE['private_notice']},
    'cannot mark another user inbox notification read')
check(notice['updated_count'],0,'foreign inbox notification unaffected')
comments,_=member.expect('comments.php',201,
    {'task_id':FIXTURE['member_task'],'content':'Review ready for QA.'},
    'member can post within own team')

manager=Client()
manager.login('manager@taskflow.test')
team_queue,_=manager.expect('task_reviews.php?team_id='+str(FIXTURE['team1'])+'&scope=team',200,
    description='manager sees team review queue')
assert any(row['task_id']==FIXTURE['member_task'] for row in team_queue['reviews'])
manager.expect('task_reviews.php?team_id='+str(FIXTURE['team2'])+'&scope=team',403,
    description='manager denied other team queue')
manager.expect('task_reviews.php',400,
    {'team_id':FIXTURE['team1'],'task_id':FIXTURE['member_task'],'action':'request_changes',
     'version':1,'note':'bad'},'request changes requires meaningful note')
manager.expect('task_reviews.php',200,
    {'team_id':FIXTURE['team1'],'task_id':FIXTURE['member_task'],'action':'request_changes',
     'version':1,'note':'Please attach acceptance test evidence.'},
     'manager can request changes')
member.expect('task_reviews.php',200,
    {'team_id':FIXTURE['team1'],'task_id':FIXTURE['member_task'],'action':'request','note':'Updated with test results'},
    'member can resubmit after changes')
manager.expect('task_reviews.php',409,
    {'team_id':FIXTURE['team1'],'task_id':FIXTURE['member_task'],'action':'approve','version':1,'note':''},
    'stale manager review decision rejected')
manager.expect('task_reviews.php',200,
    {'team_id':FIXTURE['team1'],'task_id':FIXTURE['member_task'],'action':'approve','version':3,'note':'Accepted'},
    'manager approves current review')
completed,_=member.expect('task.php?id='+str(FIXTURE['member_task']),200,
    description='approved task remains accessible')
check(completed['task']['status'],'done','approval marks task done')
manager.expect('task_reviews.php',200,
    {'team_id':FIXTURE['team1'],'task_id':FIXTURE['manager_task'],'action':'request','note':'Self review attempt'},
    'manager may request a review of own work')
manager.expect('task_reviews.php',409,
    {'team_id':FIXTURE['team1'],'task_id':FIXTURE['manager_task'],'action':'approve','version':1,'note':'Own approval'},
    'manager cannot approve their own review')
# Message delivery: a committed chat/DM must survive notifier failures and
# team/org chat share one flood guard with direct messages.
member.expect('chat.php',403,
    {'scope':'team','team_id':FIXTURE['team1'],'content':'CSRF required'},
    'team chat requires CSRF',csrf=False)
member.expect('chat.php',403,
    {'scope':'team','team_id':FIXTURE['team2'],'content':'Other team'},
    'member cannot send team chat to a different team')
member.expect('chat.php',201,
    {'scope':'team','team_id':FIXTURE['team1'],'content':'@QA Manager'},
    'member sends team chat with a same-team mention')
mentioned,_=manager.expect('inbox.php',200,description='manager can read mention notifications')
assert any(n['category']=='mentions' and 'mentioned you in chat' in n['title'] for n in mentioned['notifications'])
member.expect('dm.php',201,
    {'recipient_id':FIXTURE['manager'],'content':'Please review the staging checklist.'},
    'member sends direct message to active manager')
dm_alert,_=manager.expect('inbox.php',200,description='manager can see direct-message notification')
assert any(n['category']=='messages' and 'New message from' in n['title'] for n in dm_alert['notifications'])
for n in range(13):
    member.expect('chat.php',201,
        {'scope':'team','team_id':FIXTURE['team1'],'content':f'Delivery QA {n}'},
        f'chat and DM share posting quota: send {n+3}')
member.expect('dm.php',429,
    {'recipient_id':FIXTURE['manager'],'content':'This should hit the shared rate limit.'},
    '16th chat/DM message blocked with HTTP 429')
team_messages,_=member.expect('chat.php?scope=team&team_id='+str(FIXTURE['team1']),200,
    description='team chat remains readable after rate limit')
check(len(team_messages['messages']),14,'rate-limited send did not duplicate team message')
dm_messages,_=member.expect('dm.php?with='+str(FIXTURE['manager']),200,
    description='direct message remains readable after rate limit')
check(len(dm_messages['messages']),1,'rate-limited DM did not persist')

# Private attachment lifecycle using the real authenticated HTTP API and
# TASKFLOW_ATTACHMENTS_DIR set to an isolated 0700 directory in CI.
PDF=b'%PDF-1.4\n1 0 obj<<>>endobj\n%%EOF\n'
status,_=upload_attachment(member,FIXTURE['member_task'],PDF,csrf=False)
check(status,403,'attachment upload requires CSRF')
status,_=upload_attachment(outsider,FIXTURE['member_task'],PDF)
check(status,403,'other-team member cannot upload task attachment')
# Only the disposable CI job runs this test-only socket simulator.
if os.getenv('TASKFLOW_FAKE_CLAMD_TEST')=='yes':
    status,_=upload_attachment(member,FIXTURE['member_task'],
        PDF+b'\nX-TF-FAKE-MALWARE-TEST-MARKER\n')
    check(status,422,'simulated ClamAV detection rejects upload before storage')
    status,_=upload_attachment(member,FIXTURE['member_task'],
        PDF+b'\nX-TF-FAKE-SCANNER-ERROR-MARKER\n')
    check(status,503,'simulated ClamAV scanner error fails closed')
    empty,_=member.expect('attachments.php?task_id='+str(FIXTURE['member_task']),200,
        description='rejected simulated scans leave no attachment DB records')
    check(len(empty['attachments']),0,'scanner failure created no persisted attachment')
status,upload=upload_attachment(member,FIXTURE['member_task'],PDF)
check(status,201,'task creator uploads a validated private PDF')
attachment_id=upload['id']
file_list,_=member.expect('attachments.php?task_id='+str(FIXTURE['member_task']),200,
    description='authorized member lists task attachments')
assert any(int(a['id'])==attachment_id for a in file_list['attachments'])
status,_,_=attachment_download(outsider,attachment_id)
check(status,403,'other-team member cannot download private attachment')
status,raw,headers=attachment_download(manager,attachment_id)
check(status,200,'manager can securely download authorized attachment')
check(raw,PDF,'downloaded attachment bytes match uploaded file')
check(headers.get('x-content-type-options'),'nosniff','download refuses MIME sniffing')
check('attachment' in headers.get('content-disposition',''),True,'file always downloads, never runs inline')
check('no-store' in headers.get('cache-control',''),True,'private attachment download is not cacheable')
check('sandbox' in headers.get('content-security-policy',''),True,'file delivery is sandboxed')
outsider.expect('attachments.php',403,{'id':attachment_id},override='DELETE',
    description='another team cannot delete private attachment')
member.expect('attachments.php',403,{'id':attachment_id},override='DELETE',csrf=False,
    description='attachment deletion requires CSRF')
member.expect('attachments.php',200,{'id':attachment_id},override='DELETE',
    description='original uploader can delete task attachment')
status,_,_=attachment_download(member,attachment_id)
check(status,404,'deleted private attachment is no longer downloadable')
member.expect('logout.php',200,{},'member logs out')
member.expect('me.php',401,description='logged out session cannot read private profile')
# A bogus selector-only logout or mismatched validator must not invalidate a
# legitimate, unexpired remember login in another browser. Real PDO integration.
remember_creator=Client()
remember_creator.token()
code,body,_=remember_creator.call('login.php',
    {'email':'member@taskflow.test','password':PASS,'remember':True})
check(code,200,'remember-login creator authenticates with password')
remember=next((copy(cookie) for cookie in remember_creator.cookiejar
               if cookie.name=='taskflow_remember'),None)
assert remember is not None and len(remember.value)==89

def copied_remember_client(validator_suffix: str|None=None):
    client=Client()
    cookie=copy(remember)
    if validator_suffix is not None:
        cookie.value=cookie.value[:-1]+validator_suffix
    client.cookiejar.set_cookie(cookie)
    return client

wrong_last='0' if remember.value[-1]!='0' else '1'
tampered=copied_remember_client(wrong_last)
tampered.expect('me.php',401,description='invalid remember validator never authenticates')
malicious_logout=copied_remember_client(wrong_last)
malicious_logout.token()
malicious_logout.expect('logout.php',200,{},'selector-only logout cannot destroy another valid remember token')
valid_remember=copied_remember_client()
valid_remember.expect('me.php',200,description='valid remember login survives forged validator/logout')
valid_remember.token()
valid_remember.expect('logout.php',200,{},'valid remembered user can explicitly log out')

print(f'All {checks} real HTTP authorization checks passed (disposable MySQL only).',flush=True)
