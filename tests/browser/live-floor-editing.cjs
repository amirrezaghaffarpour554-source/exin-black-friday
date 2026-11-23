'use strict';
// Real shipped JavaScript in Chromium; REST is explicitly mocked.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require('playwright');
const source=fs.readFileSync('tests/browser/v101-admin.cjs','utf8');
let shell=Function('return '+source.match(/const shell = (`[\s\S]*?`);/)[1])();
shell=shell.replace('data-command="add">انتخاب محصول','data-command="new-campaign">کمپین جدید').replace('<div id="exin-bf-draft-status">','<section id="exin-bf-campaigns"></section><div id="exin-bf-draft-status">');
let status='scheduled',floor='0',fail=false,checks=0;const calls=[],errors=[];
const digits=value=>value.replace(/[۰-۹]/g,c=>String('۰۱۲۳۴۵۶۷۸۹'.indexOf(c))).replace(/[^0-9]/g,'');
const check=(ok,why)=>{assert.ok(ok,why);checks++;};
(async()=>{
 const browser=await chromium.launch({executablePath:'/usr/bin/chromium',headless:true,args:['--no-sandbox']});
 const page=await browser.newPage({viewport:{width:1440,height:1000}});page.on('pageerror',e=>errors.push(e.message));
 await page.route('http://exin.test/**',async route=>{
  const req=route.request(),url=new URL(req.url());
  if(url.pathname.startsWith('/assets/'))return route.fulfill({contentType:url.pathname.endsWith('.css')?'text/css':'application/javascript',body:fs.readFileSync(path.join(process.cwd(),url.pathname.slice(1)),'utf8')});
  if(!url.searchParams.has('rest_route'))return route.fulfill({contentType:'text/html',body:shell});
  const endpoint=url.searchParams.get('rest_route').replace('/exin-bf/v1/admin/',''),body=req.method()==='POST'?req.postDataJSON():null;calls.push({endpoint,body,url});
  const campaign={id:4,title:'کمپین ایزوله',status,version:3,days:7,power:2,strategy:'linear',rounding:'half_up',start_utc:'2026-11-23 11:00:00',end_utc:'2026-11-30 11:00:00',timezone:'UTC',settings:{currency:'IRR',precision:0,existing_sale_policy:'preserve',discount_plan:{mode:'custom',points:[{offset_minutes:0,kind:'percent',value:'10'}],duration_minutes:10080}}};
  const pid=url.searchParams.get('page')==='2'?998:999;
  let result;
  if(endpoint==='state')result={campaign,campaigns:[campaign],items:[{product_id:pid,product:{id:pid,name:'محصول '+pid,sku:'EX-'+pid,price:'1000000',stock_status:'instock',stock_quantity:5},base_price:'1000000',floor_price:pid===999?floor:'abc',status:'included',priority:0,featured:0}],count:50,server_time:Date.now()/1000,health:{shipping_methods:[],shipping_warnings:[],order_statuses:{}},logs:[],analytics:{}};
  else if(endpoint==='campaigns')result={campaigns:[campaign],total:1,has_more:false};
  else if(endpoint.endsWith('/bulk')){
   const row=body.items[0];
   if(!fail)floor=(BigInt(digits(String(row.floor_price)))*10n).toString();
   result={results:[{product_id:row.product_id,ok:!fail,error:'کف توسط کاربر دیگری تغییر کرده است',floor_price:floor,floor_override:1,status:'active',product_price:floor,pricing:status==='active'?'changed':'scheduled'}]};
  }else throw new Error('Unexpected request '+endpoint);
  await route.fulfill({contentType:'application/json',body:JSON.stringify(result)});
 });
 try{
  for(const mode of ['scheduled','active']){
   status=mode;floor='0';fail=false;await page.goto('http://exin.test/wp-admin/');await page.locator('[data-view="products"]').click();
   const input=page.locator('[data-inline-floor="999"]');
   check(await input.isEnabled(),mode+' inline floors enabled');
   check(await page.locator('[data-command="add"]').isDisabled(),mode+' addition disabled');
   check(await page.locator('[data-command="bulk-remove"]').isDisabled(),mode+' removal disabled');
   check(await page.locator('.exin-bf-row-select').isDisabled(),mode+' membership selection disabled');
   await input.fill('600000');await page.locator('[data-command="next"]').click();
   check(await page.locator('[data-inline-floor="998"]').inputValue()==='',mode+' invalid stored floor renders safely');
   await page.locator('[data-command="previous"]').click();check(digits(await input.inputValue())==='600000',mode+' unsaved floor survives pagination');
   const reads=calls.filter(c=>c.endpoint==='state').length;
   await page.locator('[data-command="save-floors"]').click();await page.waitForFunction(()=>document.querySelector('#exin-bf-message').textContent.includes('کف'));
   check(calls.filter(c=>c.endpoint==='state').length===reads,mode+' confirmed save needs no state reload');
   check(calls.filter(c=>c.endpoint.endsWith('/bulk')).at(-1).body.items[0].expected_floor_price==='0',mode+' original raw floor sent for CAS');
   await input.fill('700000');await page.locator('[data-command="save-floors"]').click();await page.waitForTimeout(100);
   check(calls.filter(c=>c.endpoint.endsWith('/bulk')).at(-1).body.items[0].expected_floor_price==='6000000',mode+' next edit uses confirmed floor');
   fail=true;await input.fill('800000');await page.locator('[data-command="save-floors"]').click();await page.waitForFunction(()=>document.querySelector('#exin-bf-message').textContent.includes('کاربر'));
   check(digits(await input.inputValue())==='800000',mode+' rejected edit retained');
   check(await input.getAttribute('aria-invalid')==='true',mode+' server error clearly marked');
   await input.press('Escape');check(digits(await input.inputValue())==='700000',mode+' Escape restores server floor');
   await page.locator('#exin-bf-filter-floor').selectOption('missing');await page.waitForTimeout(100);
   check(calls.filter(c=>c.endpoint==='state').at(-1).url.searchParams.get('floor')==='missing',mode+' selected-table server floor filter');
  }
  check(errors.length===0,'No JS runtime errors');check(!calls.some(c=>c.endpoint.endsWith('/action')||c.endpoint.endsWith('/selection-jobs')),'No campaign restart or membership requests');
  console.log(JSON.stringify({suite:'live-floor-ui',passed:checks,failed:0,boundary:'Real Chromium/shipped JS; mocked REST; no Production access'}));
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exit(1);});
