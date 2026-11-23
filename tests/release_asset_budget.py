#!/usr/bin/env python3
"""Lightweight public-release asset budget, no external dependencies."""
from pathlib import Path
import gzip
import sys

ROOT=Path(__file__).resolve().parent.parent
app=(ROOT/'assets/js/app.js').read_bytes()
compressed=gzip.compress(app,mtime=0)
css=sum(p.stat().st_size for p in (ROOT/'assets/css').glob('*.css'))
limits={
    'app.js uncompressed':(len(app),500_000),
    'app.js gzip':(len(compressed),120_000),
    'all application CSS':(css,400_000),
}
if len(sys.argv)>1:
    deploy=Path(sys.argv[1]);limits['slim deployment ZIP']=(deploy.stat().st_size,1_000_000)
failed=False
for label,(size,maximum) in limits.items():
    good=size<=maximum
    print(('PASS' if good else 'FAIL')+f' {label}: {size:,} / {maximum:,} bytes')
    failed |= not good
sys.exit(1 if failed else 0)
