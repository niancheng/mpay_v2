from pathlib import Path
p=Path('.dev/parts/02.b64');s=p.read_text().strip()
assert s[2882:2884]=='/t'
s=s[:2882]+''+s[2884:]
assert s[2729:2732]=='muF'
s=s[:2729]+''+s[2732:]
assert s[2555:2556]=='p'
s=s[:2555]+'t'+s[2556:]
p.write_text(s+"\n")
