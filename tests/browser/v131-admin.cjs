'use strict';
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const {chromium} = require('playwright');
const root = process.cwd(), calls = [], errors = [];
fs.mkdirSync(path.join(root, 'work'), {recursive: true});
const original = fs.readFileSync(path.join(root, 'tests/browser/v101-admin.cjs'), 'utf8');
let shell = Function('return ' + original.match(/const shell = (`[\s\S]*?`);/)[1])();
shell = shell.replace('data-command="add">انتخاب محصول', 'data-command="new-campaign">کمپین جدید').replace('<div id="exin-bf-draft-status">', '<section id="exin-bf-campaigns"></section><div id="exin-bf-draft-status">');
shell=shell.replace('</nav>','<button data-view="operations">نمایش و اعلان</button><button data-view="history">تاریخچه</button></nav>');
const health = {shipping_methods: [], shipping_warnings: [], order_statuses: {'wc-processing': 'در حال پردازش'}};
let campaign = {id:130,title:'کمپین خودکار',status:'draft',version:1,days:3,power:2,strategy:'linear',rounding:'half_up',start_utc:'2026-11-27 00:00:00',end_utc:'2026-11-30 00:00:00',timezone:'Asia/Tehran',settings:{currency:'IRR',precision:0,existing_sale_policy:'preserve',discount_plan:{mode:'auto_progressive',steps:3,interval:'daily',interval_minutes:1440,seed:'exin-ui-seed'}}}, count=50,processed=0,campaignCounter=130;
const row = id => ({id, name: `محصول ${id}`, price:'1000000', stock_status:'instock', stock_quantity:5, selectable:true, type:'simple', sku:`EX-${id}`, image:''});
const item = id => ({product_id:id, product:{...row(id),stock_status:id===998?'outofstock':'instock',stock_quantity:id===998?0:5}, base_price:'1000000', floor_price:'0', status:'included', featured:0, priority:0});
(async () => {
 const browser = await chromium.launch({executablePath:process.env.CHROMIUM_PATH || '/usr/bin/chromium',headless:true,args:['--no-sandbox']});
 const page = await browser.newPage({viewport:{width:1440,height:1000}}); let checks = 0;
 const check = (value, label) => {assert.ok(value,label);checks++;};
 page.on('pageerror',e=>errors.push(e.message));
 await page.route('http://exin.test/**',async route=>{
  const request=route.request(), url=new URL(request.url());
  if(url.pathname.startsWith('/assets/')){const file=path.join(root,url.pathname.slice(1));return route.fulfill({contentType:file.endsWith('.css')?'text/css':'application/javascript',body:fs.readFileSync(file,'utf8')});}
  if(!url.searchParams.has('rest_route'))return route.fulfill({contentType:'text/html',body:shell});
  const endpoint=url.searchParams.get('rest_route').replace('/exin-bf/v1/admin/',''), body=request.method()==='POST'?request.postDataJSON():null;calls.push({endpoint,body});let result;
  if(endpoint==='state')result={campaign, campaigns:campaign?[campaign]:[],items:[item(Number(url.searchParams.get("page"))===2?998:999)],count,server_time:Date.now()/1000,health,logs:[],analytics:{}};
  else if(endpoint.endsWith('/bulk')) result={results:body.items.map(row=>({product_id:row.product_id,ok:row.product_id!==998,error:'کف معتبر بزرگ‌تر از صفر وارد کنید.'}))};
  else if(endpoint.endsWith('/new-products'))result={products:[{id:42,name:'محصول تازه',price:'1000000'}],page:1,has_more:false};
  else if(endpoint.endsWith('/presentation')){campaign.settings={...campaign.settings,...body};result={campaign};}
  else if(endpoint.endsWith('/history'))result={rows:[{created_at:'2026-11-21 12:00:00',action:'price_apply',product_id:42,before_price:'1000000',after_price:'950000',currency:'IRR',result:'changed',error_code:''}],has_more:false};
  else if(endpoint==='campaigns')result={campaigns:campaign?[campaign]:[],total:campaign?1:0,has_more:false};
  else if(endpoint==='campaign') {campaign={...body,id:body.id||++campaignCounter,status:'draft',version:(campaign?.version||0)+1,start_utc:'2026-11-27 00:00:00',end_utc:'2026-12-04 00:00:00',settings:{...body.settings,shipping_threshold:(BigInt(String(body.settings.shipping_threshold||0).replace(/,/g,''))*10n).toString(),shipping_subsidy_cap:(BigInt(String(body.settings.shipping_subsidy_cap||0).replace(/,/g,''))*10n).toString(),precision:0,currency:'IRR'}};result={campaign};}
  else if(endpoint==='product-filters')result={categories:[{id:1,name:'گوشی'}],taxonomies:[{name:'product_brand',label:'برند',terms:[{slug:'exin',name:'اکسین'}]},{name:'pa_color',label:'رنگ',terms:[{slug:'blue',name:'آبی'}]}]};
  else if(endpoint==='products')result={products:Array.from({length:12},(_,i)=>row(i+1)),total:12,has_more:true};
  else if(endpoint.endsWith('/simulation'))result={count:2,in_stock:2,page:body.page||1,stage_start:body.stage_start||0,next_stage:body.stage_start?null:12,has_more:!body.page||body.page===1,rejected_on_page:0,start_utc:campaign.start_utc,end_utc:campaign.end_utc,warnings:[],samples:[{product_id:1,name:'محصول خودکار',base_price:'1000000',floor_price:'500000',maximum_discount_percent:'50.00',schedule:['900000','800000'],details:[{discount_percent:'10.00'},{discount_percent:'20.00'}]}]};
  else if(endpoint.endsWith('/selection-jobs')) {processed=0;result={token:'smoke-token',processed:0,total:12,added:0,failed:0,status:'running'};}
  else if(endpoint.includes('/selection-jobs/')){processed=Math.min(12,processed+10);count=processed+1;result={token:'smoke-token',processed,total:12,added:processed,failed:0,complete:processed>=12,status:processed>=12?'completed':'running',errors:[]};}
  else if(endpoint.endsWith('/action')){campaign.status=body.action==='archive'?'archived':'scheduled';result={campaign};}
  else if(endpoint.endsWith('/copy')){campaign={...campaign,id:++campaignCounter,title:'نسخه جدید',status:'draft'};result={campaign};}
  else throw new Error('Unknown endpoint '+endpoint);
  return route.fulfill({contentType:'application/json',body:JSON.stringify(result)});
 });
 try {
  await page.goto('http://exin.test/wp-admin/');
  await page.locator('[data-view="products"]').click();
  await page.locator('[data-inline-floor="999"]').fill('۱۲۳٬۴۵۶');
  await page.locator('[data-command="next"]').click();
  await page.locator('[data-inline-floor="998"]').fill('۲۳۴٬۵۶۷');
  await page.locator('[data-command="previous"]').click();
  check((await page.locator('[data-inline-floor="999"]').inputValue()).replace(/[^0-9]/g,'')==='123456','Unsaved edit survives paging');
  await page.locator('[data-command="save-floors"]').click();
  const bulk=calls.find(c=>c.endpoint.endsWith('/bulk'));
  check(bulk.body.action==='floors'&&bulk.body.items.length===2,'One bulk saves edits from both pages');
  check(bulk.body.items[0].product_id===999&&bulk.body.items[1].product_id===998,'Separate product IDs preserved');
  await page.waitForFunction(() => document.getElementById('exin-bf-message').textContent.includes('#998'));
  check((await page.locator('#exin-bf-message').innerText()).includes('#998'),'Failed product ID is shown');
  check((await page.locator('#exin-bf-message').innerText()).includes('بزرگ‌تر از صفر'),'Server validation error is shown');
  await page.locator('[data-command="next"]').click();
  check((await page.locator('[data-inline-floor="998"]').inputValue()).replace(/[^0-9]/g,'')==='234567','Failed floor remains editable after paging');
  check((await page.locator('[data-product-row="998"]').innerText()).includes('ناموجود'),'Selected unavailable member remains labelled');
  await page.locator('[data-command="save-floors"]').click();
  const retry=calls.filter(c=>c.endpoint.endsWith('/bulk')).at(-1);
  check(retry.body.items.length===1&&retry.body.items[0].product_id===998,'Retry keeps only unsaved floors');
  check(!calls.some(c=>c.endpoint.endsWith('/action')),'Never activates or changes campaign');
  check(errors.length===0,'No JS errors');
  const report={suite:'v131-inline-floors',checks,passed:checks,failed:0,boundary:'One Chromium and real shipped JavaScript; mocked REST. Real services and migration are tested separately.'};fs.writeFileSync(path.join(root,'work/v131-smoke.json'),JSON.stringify(report,null,2)+'\n');console.log(JSON.stringify(report));
 } catch(error){console.error(JSON.stringify({errors,text:await page.locator('body').innerText()}));throw error;} finally {await browser.close();}
})().catch(error=>{console.error(error);process.exit(1);});
