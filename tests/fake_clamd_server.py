#!/usr/bin/env python3
"""TEST-ONLY local ClamAV simulator for disposable MySQL authenticated HTTP CI.

This simulator deliberately DOES NOT detect real malware or use signature data.
It is excluded from deployment ZIPs. Refuses non-disposable configuration.
"""
from __future__ import annotations

import os
from pathlib import Path
import socket
import struct
import sys

MALWARE_MARKER=b'X-TF-FAKE-MALWARE-TEST-MARKER'
ERROR_MARKER=b'X-TF-FAKE-SCANNER-ERROR-MARKER'


def exact(conn: socket.socket, size: int) -> bytes:
    data=bytearray()
    while len(data)<size:
        piece=conn.recv(size-len(data))
        if not piece:raise ConnectionError('Unexpected test stream closure')
        data.extend(piece)
    return bytes(data)


def handle(conn: socket.socket) -> None:
    conn.settimeout(8)
    command=bytearray()
    while len(command)<32 and not command.endswith(b'\0'):
        command.extend(exact(conn,1))
    if command==b'zPING\0':
        conn.sendall(b'PONG\0')
        return
    if command!=b'zINSTREAM\0':
        conn.sendall(b'UNKNOWN COMMAND\0')
        return
    payload=bytearray()
    while True:
        size=struct.unpack('!I',exact(conn,4))[0]
        if size==0:break
        if size>65536 or len(payload)+size>9*1024*1024:
            conn.sendall(b'stream: INSTREAM size limit exceeded. ERROR\0')
            return
        payload.extend(exact(conn,size))
    if ERROR_MARKER in payload:
        conn.sendall(b'stream: Test scanner error. ERROR\0')
    elif MALWARE_MARKER in payload:
        conn.sendall(b'stream: SIMULATED-Test-Only FOUND\0')
    else:
        conn.sendall(b'stream: OK\0')


def main() -> int:
    if os.getenv('TASKFLOW_TEST_ALLOW_DISPOSABLE')!='yes':
        print('Refusing fake ClamAV outside disposable CI tests.',file=sys.stderr)
        return 2
    if len(sys.argv)!=2:
        return 2
    path=Path(sys.argv[1]);sock=str(path)
    if not sock.startswith('/tmp/') or len(sock)>100 or path.exists() or path.is_symlink():
        print('Refusing unsafe test socket path.',file=sys.stderr)
        return 2
    with socket.socket(socket.AF_UNIX,socket.SOCK_STREAM) as server:
        server.bind(sock)
        os.chmod(sock,0o600)
        server.listen(8)
        try:
            while True:
                conn,_=server.accept()
                with conn:
                    try:handle(conn)
                    except (OSError,ConnectionError):pass
        finally:
            path.unlink(missing_ok=True)
    return 0

if __name__=='__main__':raise SystemExit(main())
