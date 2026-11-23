"""TaskFlow release builder: reject unsafe in-tree output paths (no artifacts created)."""
import subprocess
import sys
from pathlib import Path
ROOT=Path(__file__).resolve().parent.parent
for destination in (ROOT,ROOT/'nested-output',ROOT/'docs'/'release-dist'):
    result=subprocess.run([sys.executable,str(ROOT/'bin/build-release.py'),'--out',str(destination)],
        capture_output=True,text=True,timeout=15)
    assert result.returncode!=0 and 'output' in result.stderr.lower(),(destination,result.stdout,result.stderr)
    assert not (destination/'TaskFlow-v3-Public-GitHub-Ready.zip').exists(),destination
print('PASS release-builder refuses root and unexcluded in-tree output destinations')
