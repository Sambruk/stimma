import json,re,sys,csv
S=sys.argv[1]
accts=[]
for l in open(S+'/crm.jsonl'):
    l=l.strip()
    if l: accts+=json.loads(l)['list']
def host(u):
    if not u: return None
    u=re.sub(r'^(https?://)+','',u.strip().lower()); u=u.split('/')[0].split('#')[0]
    return re.sub(r'^www\.','',u)
idx={}
for a in accts:
    for h in {host(a.get('website')), (a.get('emailAddress') or '').lower().split('@')[-1] or None}:
        if h: idx.setdefault(h,[]).append(a)
users={}
for r in csv.reader(open(S+'/dom_users.tsv'),delimiter='\t'):
    if r[0]!='dom': users[r[0]]=int(r[1])
out=[]
for d in open(S+'/allowed_noorg.txt').read().split():
    cands=idx.get(d) or []
    # subdomän: prova föräldern
    via=''
    if not cands and d.count('.')>1:
        p='.'.join(d.split('.')[-2:]); cands=idx.get(p) or []; via=p
    a=cands[0] if len(cands)==1 else None
    out.append((d,users.get(d,0),a and (a.get('cFormelltNamn') or a['name']) or '',a and a.get('cOrganisationsnummer') or '',a and a.get('cMedlemsstatus') or '', via, len(cands)))
for o in out: print('\t'.join(map(str,o)))
