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
let campaign = {id:120,title:'کمپین زنده',status:'active',version:7,start_utc:'2026-11-21 00:00:00',end_utc:'2026-12-21 00:00:00',timezone:'Asia/Tehran',settings:{currency:'IRR',precision:0}}, count = 0, processed = 0, campaignCounter = 0;
const row = id => ({id, name: `محصول ${id}`, price:'1000000', stock_status:'instock', stock_quantity:5, selectable:true, type:'simple', sku:`EX-${id}`, image:''});
const item = id => ({product_id:id, product:row(id), base_price:'1000000', floor_price:'0', status:'included', featured:0, priority:0});
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
  if(endpoint==='state')result={campaign, campaigns:campaign?[campaign]:[],items:[item(999)],count,server_time:Date.now()/1000,health,logs:[],analytics:{}};
  else if(endpoint.endsWith('/new-products'))result={products:[{id:42,name:'محصول تازه',price:'1000000'}],page:1,has_more:false};
  else if(endpoint.endsWith('/presentation')){campaign.settings={...campaign.settings,...body};result={campaign};}
  else if(endpoint.endsWith('/history'))result={rows:[{created_at:'2026-11-21 12:00:00',action:'price_apply',product_id:42,before_price:'1000000',after_price:'950000',currency:'IRR',result:'changed',error_code:''}],has_more:false};
  else if(endpoint==='campaigns')result={campaigns:campaign?[campaign]:[],total:campaign?1:0,has_more:false};
  else if(endpoint==='campaign') {campaign={...body,id:body.id||++campaignCounter,status:'draft',version:(campaign?.version||0)+1,start_utc:'2026-11-27 00:00:00',end_utc:'2026-12-04 00:00:00',settings:{...body.settings,shipping_threshold:(BigInt(String(body.settings.shipping_threshold||0).replace(/,/g,''))*10n).toString(),shipping_subsidy_cap:(BigInt(String(body.settings.shipping_subsidy_cap||0).replace(/,/g,''))*10n).toString(),precision:0,currency:'IRR'}};result={campaign};}
  else if(endpoint==='product-filters')result={categories:[{id:1,name:'گوشی'}],taxonomies:[{name:'product_brand',label:'برند',terms:[{slug:'exin',name:'اکسین'}]},{name:'pa_color',label:'رنگ',terms:[{slug:'blue',name:'آبی'}]}]};
  else if(endpoint==='products')result={products:Array.from({length:12},(_,i)=>row(i+1)),total:12,has_more:true};
  else if(endpoint.endsWith('/simulation'))result={count:body.filters?1200:count,in_stock:body.filters?1200:count,start_utc:campaign.start_utc,end_utc:campaign.end_utc,warnings:[],samples:[{product_id:1,name:'نمونه',base_price:'1000000',floor_price:'700000',schedule:['900000','800000','700000']}]};
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
  await page.locator('[data-command="scope-instock"]').waitFor();
  check(await page.locator('.exin-bf-scope').count()===1,'Active campaign has its product-scope controls');
  const before=JSON.stringify({version:campaign.version,start:campaign.start_utc,end:campaign.end_utc});
  await page.locator('main [data-command="add"]').first().click();
  await page.locator('#exin-bf-floor-policy-mode').waitFor();
  check(await page.locator('#exin-bf-floor-policy-mode').inputValue()==='shared','Live picker defaults to an explicit group floor');
  check(await page.locator('#exin-bf-floor-policy-mode option[value="none"]').count()===0,'Live picker does not offer missing-floor enrollment');
  check(await page.locator('#exin-bf-floor-policy-value').isEnabled(),'Live floor amount is editable');
  await page.locator('#exin-bf-floor-policy-value').fill('۷۰٬۰۰۰');
  await page.locator('[data-picker-select="2"]').check();
  await page.locator('[data-picker-floor="2"]').fill('۸۵٬۰۰۰');
  await page.locator('[data-command="picker-select-filtered"]').click();
  await page.locator('[data-command="selection-confirm"]').waitFor();
  check(!calls.some(c=>c.endpoint.endsWith('/selection-jobs')),'Preview precedes live enrollment');
  const preview=calls.filter(c=>c.endpoint.endsWith('/simulation')).at(-1).body;
  check(preview.floor_policy.mode==='shared'&&String(preview.floor_policy.value).replaceAll(',','')==='70000','Group floor reaches REST in declared display units');
  check(String(preview.floor_policy.floors['2']).replaceAll(',','')==='85000','Individual exception accompanies group floor');
  await page.locator('[data-command="selection-confirm"]').click();
  await page.locator('#exin-bf-dialog').waitFor({state:'hidden'});
  check(calls.filter(c=>c.endpoint.includes('/selection-jobs/')).length===2,'Browser resumes bounded server batches');
  check(before===JSON.stringify({version:campaign.version,start:campaign.start_utc,end:campaign.end_utc}),'Live version and dates remain unchanged');
  check(!calls.some(c=>c.endpoint==='campaign'||c.endpoint.endsWith('/action')),'Scope flow never saves or restarts the live campaign');
  check((await page.locator('body').innerText()).includes('مرحلهٔ فعلی'),'Completion explains current-stage pricing');
  campaign.status='draft';campaign.settings.discount_plan={mode:'fixed_percent',initial_discount:'5',steps:3,interval:'hourly'};
  await page.locator('[data-command="refresh"]').count().then(async count=>{if(count)await page.locator('[data-command="refresh"]').first().click();else await page.reload();});
  await page.locator('[data-view="products"]').click();
  await page.locator('main [data-command="add"]').first().click();
  await page.locator('#exin-bf-floor-policy-mode').selectOption('percent');
  await page.locator('#exin-bf-floor-policy-value').fill('20');
  await page.locator('[data-picker-select="2"]').check();
  await page.locator('[data-command="picker-select-filtered"]').click();
  await page.locator('[data-command="selection-confirm"]').waitFor();
  const draftPreview=calls.filter(c=>c.endpoint.endsWith('/simulation')).at(-1).body;
  check(draftPreview.floor_policy.mode==='percent'&&Object.keys(draftPreview.floor_policy.floors).length===0,'Draft default zero does not override a group floor');
  check(errors.length===0,'No JavaScript exceptions');
  const report={suite:'v122-live-product-picker',checks,passed:checks,failed:0,boundary:'One Chromium, real shipped admin JavaScript, mocked REST. Real live selection/restore financial services are tested separately.'};
  fs.writeFileSync(path.join(root,'work/v122/admin-smoke.json'),JSON.stringify(report,null,2)+'\n');console.log(JSON.stringify(report));
 } catch(error){console.error(JSON.stringify({errors,text:await page.locator('body').innerText()}));throw error;} finally {await browser.close();}
})().catch(error=>{console.error(error);process.exit(1);});
