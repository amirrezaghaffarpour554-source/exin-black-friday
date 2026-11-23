"""Exercise the exact UI filter SQL predicate on a bounded SQLite fixture (not Woo integration)."""
import re, sqlite3, pathlib, time, json
root=pathlib.Path(__file__).resolve().parents[2]
s=(root/'src/Application/CatalogProductQuery.php').read_text()
predicate=re.search(r'"( AND %d = EXISTS \(SELECT 1 FROM %i exin_floor[^"\n]+)"',s).group(1)
predicate=predicate.replace('%i','wp_exin_bf_items',1).replace('%i','wp_posts',1).replace('%d','?')
db=sqlite3.connect(':memory:')
db.executescript('CREATE TABLE wp_posts(ID INTEGER PRIMARY KEY,title TEXT); CREATE TABLE wp_exin_bf_items(campaign_id INTEGER,product_id INTEGER,status TEXT,floor_price TEXT,PRIMARY KEY(campaign_id,product_id));')
db.executemany('INSERT INTO wp_posts VALUES (?,?)',[(i,f'Product {i}') for i in range(1,1740)])
db.executemany('INSERT INTO wp_exin_bf_items VALUES (?,?,?,?)',[(42,1,'included','60'),(42,2,'included','0'),(42,3,'included','-1'),(43,4,'included','60'),(42,5,'removed','60')])
before=db.iterdump();baseline=list(before)
query='SELECT ID FROM wp_posts WHERE 1=1'+predicate
start=time.perf_counter()
assert db.execute('SELECT COUNT(*) FROM ('+query+')',(0,42)).fetchone()[0]==1738
assert db.execute(query,(1,42)).fetchall()==[(1,)]
assert len(db.execute(query+' ORDER BY ID LIMIT 25 OFFSET 25',(0,42)).fetchall())==25
assert db.execute(query+" AND title LIKE ?",(0,42,'Product 1700')).fetchall()==[(1700,)]
assert list(db.iterdump())==baseline
print(json.dumps({'suite':'admin-floor-filter-sql','passed':5,'failed':0,'products':1739,'seconds':round(time.perf_counter()-start,6),'boundary':'Exact production predicate on SQLite; not Woo/MariaDB integration'}))
