'use strict';
// Shipped RTL JavaScript in Chromium, explicitly mocked REST; no real store.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),{chromium}=require('playwright');
const fixture=fs.readFileSync('tests/browser/v101-admin.cjs','utf8');
let shell=Function('return '+fixture.match(/const shell = (`[\s\S]*?`);/)[1])();
shell=shell.replace('data-command="add">انتخاب محصول','data-command="new-campaign">کمپین جدید').replace('<div id="exin-bf-draft-status">','<section id="exin-bf-campaigns"></section><div id="exin-bf-draft-status">');
let status='active',checks=0,fail=false;const calls=[],errors=[];const check=(ok,label)=>{assert.ok(ok,label);checks++;};
(async()=>{
 const browser=await chromium.launch({executablePath:'/usr/bin/chromium',headless:true,args:['--no-sandbox']});const page=await browser.newPage({viewport:{width:1440,height:1000}});page.on('pageerror',e=>errors.push(e.message));
 await page.route('http://exin.test/**',async route=>{
  const request=route.request(),url=new URL(request.url());
  if(url.pathname.startsWith('/assets/'))return route.fulfill({contentType:url.pathname.endsWith('.css')?'text/css':'application/javascript',body:fs.readFileSync(path.join(process.cwd(),url.pathname.slice(1)),'utf8')});
  if(!url.searchParams.has('rest_route'))return route.fulfill({contentType:'text/html',body:shell});
  const endpoint=url.searchParams.get('rest_route').replace('/exin-bf/v1/admin/',''),body=request.method()==='POST'?request.postDataJSON():null;calls.push({endpoint,body});
  const campaign={id:4,title:'کمپین ایزوله',status,version:3,days:7,power:2,strategy:'linear',rounding:'half_up',start_utc:'2026-11-23 11:00:00',end_utc:'2026-11-30 11:00:00',timezone:'UTC',settings:{currency:'IRR',precision:0,discount_plan:{mode:'custom',points:[{offset_minutes:0,kind:'percent',value:'10'}],duration_minutes:10080}}};
  let result;
  if(endpoint==='state')result={campaign,campaigns:[campaign],items:[1,2].map(id=>({product_id:id,product:{id,name:'محصول '+id,price:'1200000',stock_status:'instock'},base_price:'1000000',floor_price:'600000',status:'conflict',conflict:'external_price_changed',priority:0,featured:0})),count:2,health:{shipping_methods:[],shipping_warnings:[],order_statuses:{}},logs:[],analytics:{}};
  else if(endpoint==='campaigns')result={campaigns:[campaign],total:1,has_more:false};
  else if(endpoint.endsWith('/bulk')){
   if(body.action==='recovery_preview')result={results:body.items.map(row=>row.product_id===1?{product_id:1,ok:true,old_base:'1000000',current_price:'1200000',base:'1200000',floor:'600000',preview_price:'600000',reason:'external_price_changed',token:'signed-preview'}:{product_id:2,ok:false,error:'عملیات ناتمام <img src=x onerror=alert(1)>'})};
   else if(body.action==='recovery_approve'){await new Promise(resolve=>setTimeout(resolve,100));result={results:body.items.map(row=>({product_id:row.product_id,ok:!fail,pricing:'changed',status:'active',error:'قیمت تغییر کرده است؛ پیش‌نمایش تازه بگیرید'}))};}
   else throw new Error('Unexpected bulk action '+body.action);
  }else throw new Error('Unexpected endpoint '+endpoint);
  await route.fulfill({contentType:'application/json',body:JSON.stringify(result)});
 });
 try{
  await page.goto('http://exin.test/wp-admin/');await page.locator('[data-view="products"]').click();
  check(await page.locator('[data-command="item-recover"][data-id="1"]').isEnabled(),'Active conflict recovery accessible');check(await page.locator('.exin-bf-row-select').first().isDisabled(),'Membership selection stays locked');
  await page.locator('[data-command="item-recover"][data-id="1"]').click();await page.locator('#exin-bf-recovery-confirm').waitFor();
  const modal=page.locator('#exin-bf-dialog-content');for(const label of ['مبنای قبلی','قیمت فعلی','مبنای جدید','کف ثبت‌شده','قیمت پیش‌نمایش','دلیل تعارض'])check((await modal.innerText()).includes(label),'Financial preview field '+label);
  await page.locator('[data-command="confirm-recovery"]').click();check(!calls.some(c=>c.body?.action==='recovery_approve'),'Approval cannot run without explicit confirmation');
  await page.locator('#exin-bf-recovery-confirm').check();await page.locator('[data-command="confirm-recovery"]').click();await page.waitForFunction(()=>document.querySelector('#exin-bf-dialog-content').textContent.includes('تعارض رفع'));
  const approved=calls.find(c=>c.body?.action==='recovery_approve');check(approved.body.confirm_recovery===true&&approved.body.items[0].token==='signed-preview','Only signed confirmed preview is submitted');check(!('base_price' in approved.body.items[0]),'Client never supplies a replacement base');
  await page.locator('[data-command="close-dialog"]').click();await page.locator('[data-recovery-select="1"]').check();await page.locator('[data-recovery-select="2"]').check();await page.locator('[data-command="recover-selected"]').click();await page.locator('#exin-bf-recovery-confirm').waitFor();
  check((await modal.innerText()).includes('عملیات ناتمام'),'Unsafe row reason visible alongside safe preview');check(await modal.locator('img').count()===0,'Server error text is escaped');
  fail=true;await page.locator('#exin-bf-recovery-confirm').check();await page.locator('[data-command="confirm-recovery"]').click();await page.waitForFunction(()=>document.querySelector('#exin-bf-dialog-content').textContent.includes('پیش‌نمایش تازه'));
  const batch=calls.filter(c=>c.body?.action==='recovery_approve').at(-1);check(batch.body.items.length===1&&batch.body.items[0].product_id===1,'Unsafe products excluded from confirmed bulk');check((await modal.innerText()).includes('#1'),'Per-product execution failure visible');
  await page.locator('[data-command="close-dialog"]').click();await page.locator('[data-inline-floor="1"]').fill('70000');const previews=calls.filter(c=>c.body?.action==='recovery_preview').length;await page.locator('[data-command="item-recover"][data-id="1"]').click();check(calls.filter(c=>c.body?.action==='recovery_preview').length===previews,'Unsaved floor edits must be settled before recovery');
  status='scheduled';await page.goto('http://exin.test/wp-admin/');await page.locator('[data-view="products"]').click();check(await page.locator('[data-command="item-recover"]').count()===0,'No recovery controls outside active campaign');
  check(errors.length===0,'No JavaScript errors');check(!calls.some(c=>c.body&&['add','remove','floors'].includes(c.body.action)),'No membership or floor writes');
  console.log(JSON.stringify({suite:'conflict-recovery-ui',passed:checks,failed:0,boundary:'Chromium and shipped JS, mocked REST; no Production'}));
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exit(1);});
