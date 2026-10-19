import csv,sys
S=sys.argv[1]
BASE=50000
EXCL={'gmail.com','elestio.app','vm.elestio.app'}
OVR={'bromolla.se':('Bromölla kommun','212000-0894'),'koping.se':('Köpings kommun','212000-2114'),
     'malmo.se':('Malmö stad','212000-1124'),'style4.se':('Style4 Solutions AB','556910-5926')}
GROUPS=[('Arvika kommun','212000-1892',['arvika.se','arvika.com']),
        ('Göteborgs Stad','212000-1355',['goteborg.se','educ.goteborg.se','intraservice.goteborg.se','storningsjouren.goteborg.se']),
        ('Göteborgs Spårvägar AB',None,['sparvagen.goteborg.se'])]
plan={r[0]:r for r in csv.reader(open(S+'/plan.tsv'),delimiter='\t')}
pub={r[0] for r in csv.reader(open(S+'/pub.tsv'),delimiter='\t') if r[1]=='1'}
used={r[0]:int(r[1]) for r in csv.reader(open(S+'/used.tsv'),delimiter='\t')}
grouped={d for g in GROUPS for d in g[2]}
orgs=[]
for name,nr,doms in GROUPS: orgs.append((name,nr,doms))
for d,r in sorted(plan.items()):
    if d in EXCL or d in grouped: continue
    name,nr=OVR.get(d,(r[2] or d, r[3] or None))
    orgs.append((name,nr,[d]))
nrs=[o[1] for o in orgs if o[1]]
assert len(nrs)==len(set(nrs)), [n for n in nrs if nrs.count(n)>1]
q=lambda v:'NULL' if v is None else "'"+str(v).replace("'","''")+"'"
out=['START TRANSACTION;']
rep=[]
for name,nr,doms in orgs:
    haspub = 1 if (len(doms)==1 and doms[0] in pub) else 0
    u=sum(used.get(d,0) for d in doms); bal=max(0,BASE-u)
    out.append(f"INSERT INTO organizations (name, org_number, has_pub_agreement) VALUES ({q(name)}, {q(nr)}, {haspub});")
    out.append("SET @o = LAST_INSERT_ID();")
    for i,d in enumerate(doms):
        out.append(f"INSERT INTO organization_domains (organization_id, domain, is_primary) VALUES (@o, {q(d)}, {1 if i==0 else 0});")
    out.append(f"INSERT INTO ai_quotas (organization_id, domain, monthly_token_quota, alert_threshold_pct, behavior, notes) VALUES (@o, NULL, {BASE}, 80, 'block', 'Skapad vid massinförande av organisationer 2026-10-19');")
    out.append(f"INSERT INTO organization_token_balance (organization_id, balance) VALUES (@o, {bal});")
    out.append(f"INSERT INTO token_transactions (organization_id, type, tokens_delta, balance_after, note) VALUES (@o, 'monthly_refill', {bal}, {bal}, {q('Gratisbas oktober 2026 minus redan förbrukat på domänen (' + str(u) + ')' if u else 'Gratisbas oktober 2026')});")
    rep.append((name,nr or '',','.join(doms),haspub,bal))
out.append('COMMIT;')
open(S+'/create_orgs.sql','w').write('\n'.join(out)+'\n')
with open(S+'/create_orgs_plan.tsv','w') as f:
    f.write('namn\torg_nr\tdomaner\tpub\tsaldo\n')
    for r in rep: f.write('\t'.join(map(str,r))+'\n')
print(len(orgs),'organisationer,',sum(len(o[2]) for o in orgs),'domäner, PUB kopierat:',sum(r[3] for r in rep))
for r in rep:
    if r[4]<BASE or not r[1] or r[0].endswith(('.se','.com')): print(' ',r)
