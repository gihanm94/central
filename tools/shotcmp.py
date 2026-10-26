# python3 tools/shotcmp.py <folder> <name>...  → <folder>/cmp<name>.png (PHP left, Go right)
import sys
from PIL import Image
d = sys.argv[1]
for n in sys.argv[2:]:
    a = Image.open(f'{d}/{n}-php.png'); b = Image.open(f'{d}/{n}-go.png')
    o = Image.new('RGB', (a.width + b.width + 10, max(a.height, b.height)), 'red')
    o.paste(a, (0, 0)); o.paste(b, (a.width + 10, 0)); o.thumbnail((2000, 3000)); o.save(f'{d}/cmp{n}.png')
