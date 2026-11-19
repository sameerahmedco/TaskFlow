"""Require bright, Light-mode screenshots in TaskFlow's default GitHub README.

This enforces BOTH the referenced file paths and their actual pixel brightness,
so an accidental dark screenshot overwrite cannot silently change the gallery.
Only optional text links may reference dark or custom-theme screenshots.
"""
from pathlib import Path
import re
from PIL import Image

ROOT=Path(__file__).resolve().parent.parent
readme=(ROOT/'README.md').read_text()
inline=re.findall(r'!\[[^]]*\]\(([^)]+\.png)\)',readme,re.IGNORECASE)
assert len(inline)>=5,'Need at least five inline Light screenshot references in README'
seen=set()
for image in inline:
    assert image.startswith('docs/screenshots/'),'Inline screenshots must be local GitHub repo images: '+image
    assert not any(term in image.lower() for term in ('dark','midnight','theme-')),'Non-default screenshot in README gallery: '+image
    if image in seen:continue
    seen.add(image)
    path=ROOT/image
    assert path.exists(),'Missing screenshot: '+image
    with Image.open(path) as source:
        im=source.convert('RGB').resize((80,45))
        raw=im.tobytes()
        pixels=[raw[i:i+3] for i in range(0,len(raw),3)]
        light_fraction=sum(min(rgb)>=215 for rgb in pixels)/len(pixels)
        assert light_fraction>=0.55,f'{image} looks like dark mode: bright pixels {light_fraction:.0%}'
        upper_left=im.getpixel((0,0))
        assert min(upper_left)>=220,f'{image}: default screenshot upper-left is not white/light: {upper_left}'
    print(f'PASS Light README screenshot: {image} (bright pixels {light_fraction:.0%})')
assert 'Light is the default' in readme,'README must explicitly state Light default'
print(f'PASS {len(seen)} unique bright/white GitHub README screenshots. Dark/colored themes are linked separately.')
