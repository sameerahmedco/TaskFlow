"""Offline live Unix-socket ClamAV protocol test using a fake scanner.

The fake server verifies framing and failure handling, NOT malware detection.
"""
from __future__ import annotations

import os
import json
from pathlib import Path
import socket
import struct
import subprocess
import tempfile
import threading
import time

ROOT=Path(__file__).resolve().parent.parent
PROBE=ROOT/'tests/attachment_scan_probe.php'


def recv_exact(conn: socket.socket, count: int) -> bytes:
    data=bytearray()
    while len(data)<count:
        part=conn.recv(count-len(data))
        if not part:
            raise AssertionError('Scanner protocol stream closed early')
        data.extend(part)
    return bytes(data)


def protocol_server(server: socket.socket, response: bytes | None, expected: bytes | None, observed: list):
    try:
        server.settimeout(6)
        conn,_=server.accept()
        with conn:
            conn.settimeout(6)
            cmd=bytearray()
            while not cmd.endswith(b'\0') and len(cmd)<64:
                cmd.extend(recv_exact(conn,1))
            if expected is None:
                assert bytes(cmd)==b'zPING\0',bytes(cmd)
            else:
                assert bytes(cmd)==b'zINSTREAM\0',bytes(cmd)
                frames=[]
                while True:
                    size=struct.unpack('!I',recv_exact(conn,4))[0]
                    if size==0:break
                    assert size<=65536,size
                    frames.append(recv_exact(conn,size))
                assert b''.join(frames)==expected,'ClamAV data frames changed upload bytes'
            observed.append('framing-ok')
            if response is None:
                time.sleep(1.8)
            else:
                conn.sendall(response)
    except (OSError,AssertionError) as exc:
        observed.append(('ERROR',str(exc)))
    finally:
        server.close()


def fake_clamd(mode: str, payload: bytes, response: bytes | None, expected: bytes | None, timeout: str='3'):
    with tempfile.TemporaryDirectory(prefix='tf-clamd-') as temp:
        sock_path=str(Path(temp)/'clamd.sock')
        address=socket.socket(socket.AF_UNIX,socket.SOCK_STREAM)
        address.bind(sock_path)
        address.listen(1)
        observations=[]
        thread=threading.Thread(target=protocol_server,args=(address,response,expected,observations),daemon=True)
        thread.start()
        upload=Path(temp)/'upload.bin'
        upload.write_bytes(payload)
        env=os.environ.copy()
        env.update(TASKFLOW_APP_ENV='production',TASKFLOW_CLAMD_SOCKET=sock_path,
                   TASKFLOW_CLAMD_TIMEOUT_SECONDS=timeout)
        result=subprocess.run(['php',str(PROBE),mode,str(upload)],env=env,
                              capture_output=True,text=True,timeout=10)
        thread.join(timeout=4)
        assert observations==['framing-ok'],observations
        return result


def check_preflight_scanner(response: bytes, expected_pass: bool):
    """Preflight must report the scanner separately from unavailable MySQL."""
    with tempfile.TemporaryDirectory(prefix='tf-preflight-clam-') as temp:
        sock_path=str(Path(temp)/'clamd.sock')
        private=Path(temp)/'private'
        private.mkdir(mode=0o700)
        server=socket.socket(socket.AF_UNIX,socket.SOCK_STREAM)
        server.bind(sock_path)
        server.listen(1)
        observations=[]
        thread=threading.Thread(target=protocol_server,args=(server,response,None,observations),daemon=True)
        thread.start()
        env=os.environ.copy()
        env.update(TASKFLOW_APP_ENV='production', TASKFLOW_CLAMD_SOCKET=sock_path,
                   TASKFLOW_ATTACHMENTS_DIR=str(private), TASKFLOW_CLAMD_TIMEOUT_SECONDS='3')
        result=subprocess.run(['php',str(ROOT/'bin/release-preflight.php'),'--json'],env=env,
                              capture_output=True,text=True,timeout=12)
        thread.join(timeout=4)
        assert observations==['framing-ok'],observations
        report=json.loads(result.stdout)
        scanner=[c for c in report['checks'] if c['id']=='attachment_malware_scanner']
        assert len(scanner)==1,scanner
        assert (scanner[0]['result']=='pass')==expected_pass,scanner


def run():
    checks=0
    expected=b'Synthetic harmless file contents\x00\x04\x06\n'+b'A'*150000
    clean=fake_clamd('scan',expected,b'stream: OK\0',expected)
    assert (clean.returncode,clean.stdout)==(0,'CLEAN'),clean
    checks+=1
    infected=fake_clamd('scan',expected,b'stream: Eicar-Test-Signature FOUND\0',expected)
    assert (infected.returncode,infected.stdout)==(3,'DETECTED'),infected
    checks+=1
    engine_error=fake_clamd('scan',expected,b'stream: INSTREAM size limit exceeded. ERROR\0',expected)
    assert (engine_error.returncode,engine_error.stdout)==(4,'UNAVAILABLE'),engine_error
    checks+=1
    bad=fake_clamd('scan',expected,b'stream: OK\0stream: hidden FOUND\0',expected)
    assert (bad.returncode,bad.stdout)==(4,'UNAVAILABLE'),bad
    checks+=1
    closed=fake_clamd('scan',expected,b'',expected)
    assert (closed.returncode,closed.stdout)==(4,'UNAVAILABLE'),closed
    checks+=1
    hung=fake_clamd('scan',expected,None,expected,timeout='1')
    assert (hung.returncode,hung.stdout)==(4,'UNAVAILABLE'),hung
    checks+=1
    pong=fake_clamd('ping',b'check',b'PONG\0',None)
    assert (pong.returncode,pong.stdout)==(0,'PONG'),pong
    checks+=1
    not_pong=fake_clamd('ping',b'check',b'ERROR\0',None)
    assert (not_pong.returncode,not_pong.stdout)==(0,'UNAVAILABLE'),not_pong
    checks+=1
    check_preflight_scanner(b'PONG\0',True)
    checks+=1
    check_preflight_scanner(b'ERROR\0',False)
    checks+=1
    print(f'PASS {checks} offline ClamAV socket/preflight scenarios (simulated daemon, NOT a real malware scan)')

if __name__=='__main__':
    run()
