'use strict';
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const {chromium} = require('playwright');
const root = process.cwd(), calls = [], errors = [];
fs.mkdirSync(path.join(root, 'work'), {recursive: true});
const original = fs.readFileSync(path.join(root, 'tests/browser/v101-admin.cjs'), 'utf8');
let shell = Function('return ' + original.match(/const shell = (`[\s\S]*?`);/)[1])();
shell = shell.replace('data-command="add">انتخاب محصول', 'data-command="new-campaign">کمپین جدید').replace('<div id="exin-bf-draft-status">', '<section id="exin-bf-campaigns"></section><div id="exin-bf-draft-status">');
const health = {shipping_methods: [], shipping_warnings: [], order_statuses: {'wc-processing': 'در حال پردازش'}};
let campaign = null, count = 0, processed = 0, campaignCounter = 0;
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
  if(endpoint==='state')result={campaign, campaigns:campaign?[campaign]:[],items:Array.from({length:Math.min(25,count)},(_,i)=>item(i+1)),count,server_time:Date.now()/1000,health,logs:[],analytics:{}};
  else if(endpoint==='campaigns')result={campaigns:campaign?[campaign]:[],total:campaign?1:0,has_more:false};
  else if(endpoint==='campaign') {campaign={...body,id:body.id||++campaignCounter,status:'draft',version:(campaign?.version||0)+1,start_utc:'2026-11-27 00:00:00',end_utc:'2026-12-04 00:00:00',settings:{...body.settings,shipping_threshold:(BigInt(String(body.settings.shipping_threshold||0).replace(/,/g,''))*10n).toString(),shipping_subsidy_cap:(BigInt(String(body.settings.shipping_subsidy_cap||0).replace(/,/g,''))*10n).toString(),precision:0,currency:'IRR'}};result={campaign};}
  else if(endpoint==='product-filters')result={categories:[{id:1,name:'گوشی'}],taxonomies:[{name:'product_brand',label:'برند',terms:[{slug:'exin',name:'اکسین'}]},{name:'pa_color',label:'رنگ',terms:[{slug:'blue',name:'آبی'}]}]};
  else if(endpoint==='products')result={products:Array.from({length:20},(_,i)=>row(i+1)),total:1200,has_more:true};
  else if(endpoint.endsWith('/simulation'))result={count:body.filters?1200:count,in_stock:body.filters?1200:count,start_utc:campaign.start_utc,end_utc:campaign.end_utc,warnings:[],samples:[{product_id:1,name:'نمونه',base_price:'1000000',floor_price:'0',schedule:['950000','900000','850000']}]};
  else if(endpoint.endsWith('/selection-jobs')) {processed=0;result={token:'smoke-token',processed:0,total:1200,added:0,failed:0,status:'running'};}
  else if(endpoint.includes('/selection-jobs/')){processed+=25;count=processed;result={token:'smoke-token',processed,total:1200,added:processed,failed:0,complete:processed>=1200,status:processed>=1200?'completed':'running',errors:[]};}
  else if(endpoint.endsWith('/action')){campaign.status=body.action==='archive'?'archived':'scheduled';result={campaign};}
  else if(endpoint.endsWith('/copy')){campaign={...campaign,id:++campaignCounter,title:'نسخه جدید',status:'draft'};result={campaign};}
  else throw new Error('Unknown endpoint '+endpoint);
  return route.fulfill({contentType:'application/json',body:JSON.stringify(result)});
 });
 try{
  await page.goto('http://exin.test/wp-admin/');
  await page.locator('main [data-command="new-campaign"]').click();
  await page.locator('[data-command="scope-all"]').waitFor();
  check(calls.find(c=>c.endpoint==='campaign').body.settings.discount_plan.initial_discount==='5','New general campaign starts at explicit five percent');
  check(await page.locator('[data-step="1"]').getAttribute('aria-current')==='step','Product scope is first step');
  await page.locator('[data-command="scope-all"]').click();
  await page.locator('[data-command="selection-confirm"]').waitFor();
  check(!calls.some(c=>c.endpoint.endsWith('/selection-jobs')),'Scope preview does not start enrollment or activate');
  await page.locator('[data-command="selection-confirm"]').click();
  await page.locator('#exin-bf-dialog').waitFor({state:'hidden'});
  await page.locator('.exin-bf-table').waitFor();
  check(count===1200,'Server job enrolls more than 200 products');
  check(calls.filter(c=>c.endpoint==='products').length===1,'Browser never scans all product pages');
  check(calls.filter(c=>c.endpoint.includes('/selection-jobs/')).length===48,'Selection resumes bounded server steps');
  await page.locator('[data-view="settings"]').click();
  await page.locator('#bf-discount_mode').selectOption('stepped');
  await page.locator('#bf-final_discount').fill('۱۵');
  await page.locator('#bf-interval').selectOption('hourly');
  await page.locator('#bf-steps').fill('3');
  await page.locator('[data-command="step-review"]').click();
  await page.locator('#exin-bf-confirm-activate').waitFor();
  const saved=calls.filter(c=>c.endpoint==='campaign').at(-1).body;
  check(saved.settings.discount_plan.interval==='hourly'&&saved.settings.discount_plan.steps===3,'Hourly stepped configuration reaches REST');
  check(!calls.some(c=>c.endpoint.endsWith('/action')),'Draft and previews never send activation');
  check(await page.locator('#exin-bf-activate').isDisabled(),'Activation requires explicit manager checkbox');
  check(await page.locator('main .exin-bf-simulation').count()===1,'Final simulation shows sample stage prices');
  await page.setViewportSize({width:390,height:844});
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Mobile page has no horizontal overflow');
  check(errors.length===0,'No JavaScript exceptions');
  const report={suite:'v110-admin-focused-mocked-rest',checks,passed:checks,failed:0,errors,boundary:'Actual JS/DOM Chromium; mocked REST, not real WordPress'};
  fs.writeFileSync(path.join(root,'work/v110-admin-smoke.json'),JSON.stringify(report,null,2)+'\n');console.log(JSON.stringify(report));
 }catch(error){console.error(JSON.stringify({errors,calls:calls.map(c=>c.endpoint),text:await page.locator('body').innerText()}));throw error;}finally{await browser.close();}
})().catch(error=>{console.error(error);process.exit(1);});
