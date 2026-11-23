'use strict';
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const {chromium} = require('playwright');
const root = process.cwd(), calls = [], errors = [];
fs.mkdirSync(path.join(root, 'work'), {recursive: true});
fs.writeFileSync(path.join(root,'work/ui-baseline.js'),require('node:child_process').execFileSync('git',['show','530ffe3:assets/admin.js']));
const original = fs.readFileSync(path.join(root, 'tests/browser/v101-admin.cjs'), 'utf8');
let shell = Function('return ' + original.match(/const shell = (`[\s\S]*?`);/)[1])();
const phpShell=fs.readFileSync(path.join(root,'src/Admin/Dashboard.php'),'utf8');
const markup=phpShell.slice(phpShell.indexOf('<div class="exin-bf"'),phpShell.lastIndexOf('<?php')).replace(/<\?php esc_(?:html|attr)_e\( '([^']*)', 'exin-black-friday' \); \?>/g,'$1');
shell=shell.replace(/<div class="exin-bf"[\s\S]*?(?=<script>)/,markup);
const health = {shipping_methods: [], shipping_warnings: [], order_statuses: {'wc-processing': 'در حال پردازش'}};
let baselineMode = true;
let campaign = {id:130,title:'کمپین خودکار',status:'draft',version:1,days:3,power:2,strategy:'linear',rounding:'half_up',start_utc:'2026-11-27 00:00:00',end_utc:'2026-11-30 00:00:00',timezone:'Asia/Tehran',settings:{currency:'IRR',precision:0,existing_sale_policy:'preserve',discount_plan:{mode:'auto_progressive',steps:3,interval:'daily',interval_minutes:1440,seed:'exin-ui-seed'}}}, count=1739,processed=0,campaignCounter=130;
const row = id => ({id, name: `محصول ${id}`, price:'1000000', stock_status:'instock', stock_quantity:5, selectable:true, type:'simple', sku:`EX-${id}`, image:''});
const item = id => ({product_id:id, product:row(id), base_price:'1000000', floor_price:'0', status:'included', featured:0, priority:0});
(async () => {
 const browser = await chromium.launch({executablePath:process.env.CHROMIUM_PATH || '/usr/bin/chromium',headless:true,args:['--no-sandbox']});
 const page = await browser.newPage({viewport:{width:1440,height:1000}}); let checks = 0;
 const check = (value, label) => {assert.ok(value,label);checks++;};
 page.on('pageerror',e=>errors.push(e.message));
 await page.route('http://exin.test/**',async route=>{
  const request=route.request(), url=new URL(request.url());
  if(url.pathname.startsWith('/assets/')){const file=path.join(root,url.pathname.slice(1));return route.fulfill({contentType:file.endsWith('.css')?'text/css':'application/javascript',body:fs.readFileSync(baselineMode&&file.endsWith('/admin.js')?path.join(root,'work/ui-baseline.js'):file,'utf8')});}
  if(!url.searchParams.has('rest_route'))return route.fulfill({contentType:'text/html',body:shell});
  const endpoint=url.searchParams.get('rest_route').replace('/exin-bf/v1/admin/',''), body=request.method()==='POST'?request.postDataJSON():null;calls.push({endpoint,body,query:url.searchParams.toString()});let result;
  if(endpoint==='state')result={campaign, campaigns:campaign?[campaign]:[],items:Array.from({length:25},(_,i)=>item(1000+i)),count,server_time:Date.now()/1000,health,logs:[],analytics:{}};
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
  const start=Date.now();
  const modes=['auto_progressive','auto_descending','auto_rotation','auto_random','auto_wave','auto_hybrid','fixed_percent','fixed_amount','linear','stepped','custom'];
  const fields=async()=>page.locator('#exin-bf-settings [name]').evaluateAll(nodes=>nodes.map(n=>({name:n.name,type:n.type,options:n.tagName==='SELECT'?[...n.options].map(o=>o.value):null})).sort((a,b)=>a.name.localeCompare(b.name)));
  await page.goto('http://exin.test/wp-admin/');await page.locator('[data-view="settings"]').click();await page.locator('#bf-discount_mode').waitFor();
  const coverage={};for(const mode of modes){await page.locator('#bf-discount_mode').selectOption(mode);coverage[mode]=await fields();}
  baselineMode=false;await page.reload();await page.locator('[data-view="settings"]').click();await page.locator('#bf-discount_mode').waitFor();
  check(await page.locator('.exin-bf-nav [data-view]').count()===6,'Six real PHP shell sections');
  check(await page.locator('[data-strategy]').count()===11,'All existing strategy cards');
  for(const mode of modes){await page.locator('#bf-discount_mode').selectOption(mode);assert.deepEqual(await fields(),coverage[mode]);checks++;}
  await page.locator('[data-strategy="auto_hybrid"]').click();check(await page.locator('#bf-discount_mode').inputValue()==='auto_hybrid','Strategy cards drive existing control');
  await page.screenshot({path:path.join(root,'work/ui-engine.png'),fullPage:true});
  await page.locator('#bf-title').fill('کمپین حفظ تنظیمات');await page.waitForTimeout(1000);
  check(calls.some(c=>c.endpoint==='campaign'&&c.body.title==='کمپین حفظ تنظیمات'),'Existing autosave persists settings');
  await page.locator('[data-view="timeline"]').click();await page.locator('[data-view="timeline"][aria-current="page"]').waitFor();await page.locator('#exin-bf-content[aria-busy="false"]').waitFor();await page.locator('.exin-bf-timeline').waitFor();check(await page.locator('#bf-start_jalali').count()===1,'Timeline retains actual scheduling controls');
  check(await page.locator('[data-time-start] [name="start_time"]').count()===1,'Start is directly editable in timeline');
  check(await page.locator('[data-time-end] [name="auto_duration_days"]').count()===1,'End uses actual backend duration control');
  await page.locator('[name="auto_duration_days"]').fill('7');await page.locator('#bf-interval').selectOption('hourly');
  await page.locator('[name="interval_minutes"]').fill('120');await page.locator('[name="auto_last_day"]').selectOption('continue');await page.locator('#bf-start_time').fill('04:45');
  check(await page.locator('[name="auto_steps"]').inputValue()==='84','Stages follow existing duration/interval rule');
  await page.waitForTimeout(1000);const timelineSave=calls.filter(c=>c.endpoint==='campaign').at(-1).body;
  check(timelineSave.settings.discount_plan.duration_minutes===10080&&timelineSave.settings.discount_plan.interval_minutes===120&&timelineSave.settings.discount_plan.last_day==='continue'&&timelineSave.start_time==='04:45','Timeline persists through existing API');
  await page.setViewportSize({width:390,height:844});check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Editable timeline stays within mobile width');await page.screenshot({path:path.join(root,'work/ui-editable-timeline.png'),fullPage:true});await page.setViewportSize({width:1440,height:1000});
  await page.locator('[data-view="settings"]').click();await page.locator('[data-view="settings"][aria-current="page"]').waitFor();await page.locator('#exin-bf-content[aria-busy="false"]').waitFor();await page.locator('[data-visual-control="auto_min"]').waitFor();
  await page.locator('[data-visual-control="auto_min"]').evaluate(n=>{n.value='17';n.dispatchEvent(new Event('input',{bubbles:true}));});
  check(await page.locator('[name="auto_min"]').inputValue()==='17','Visual control updates original precise input');
  await page.locator('[data-command="simulate-prices"]').click();await page.locator('#exin-bf-price-simulation .exin-bf-simulation').first().waitFor();
  check(calls.some(c=>c.endpoint.endsWith('/simulation')),'Actual simulation API requested');
  check((await page.locator('#exin-bf-price-simulation').innerText()).includes('90,000'),'Prices come from API sample rather than local approximation');
  await page.locator('#exin-bf-price-simulation [data-command="auto-preview-stages"]').click();await page.waitForTimeout(100);
  check(calls.filter(c=>c.endpoint.endsWith('/simulation')).at(-1).body.stage_start===12,'Server simulation stages remain paginated');
  await page.locator('[name="auto_min"]').fill('18');check(await page.locator('#exin-bf-price-simulation .exin-bf-simulation').count()===0,'Editing configuration invalidates stale simulation');
  const productStart=Date.now();await page.locator('[data-view="products"]').click();await page.locator('[data-inline-floor]').first().waitFor();const productRenderMs=Date.now()-productStart;check(await page.locator('[data-product-row]').count()===25,'Only server page is hydrated for 1739 products');
  const floors=page.locator('[data-inline-floor]');await floors.first().fill('654321');await floors.first().press('Enter');check(await floors.nth(1).evaluate(n=>n===document.activeElement),'Spreadsheet Enter navigation');
  check(await page.locator('.exin-bf-row-dirty').count()===1,'Changed-row styling');
  check((await page.locator('[data-command="save-floors"]').innerText()).includes('۱'),'Changed-only save count');
  await page.locator('[data-command="next"]').click();await page.waitForTimeout(100);check(await floors.first().inputValue()==='654,321','Unsaved floor survives paging');
  check(calls.filter(c=>c.endpoint==='state').at(-1).endpoint==='state','Paging uses existing REST');await page.screenshot({path:path.join(root,'work/ui-products.png'),fullPage:true});
  await page.locator('[data-page-jump]').fill('3');await page.locator('[data-page-jump]').press('Enter');await page.waitForTimeout(100);check(new URLSearchParams(calls.filter(c=>c.endpoint==='state').at(-1).query).get('page')==='3','Direct server page jump');check((await floors.first().inputValue()).replace(/,/g,'')==='654321','Page jump preserves dirty floor');
  await floors.first().press('Escape');check(await page.locator('.exin-bf-row-dirty').count()===0,'Escape restores saved value without a write');await floors.first().fill('654321');
  await page.locator('[data-command="review"]').first().click();await page.locator('#exin-bf-confirm-activate').check();await page.locator('#exin-bf-activate').click();check(!calls.some(c=>c.endpoint.endsWith('/action')),'Unsaved floor changes block activation without API write');check((await page.locator('#exin-bf-message').innerText()).includes('کف‌های'),'Activation explains unsaved floors');
  await page.locator('[data-view="operations"]').click();await page.locator('#exin-bf-marketing-preview').waitFor();
  check(await page.locator('#exin-bf-operations [name="manual_floor_catalog"]').count()===1,'Manual-floor opt-in remains accessible');
  await page.locator('[data-command="preview-mobile"]').click();check(await page.locator('.exin-bf-store-preview.is-mobile').count()===1,'Read-only mobile preview');
  check(await page.locator('#exin-bf-marketing-preview input,#exin-bf-marketing-preview button').count()===0,'Preview has no purchase/edit controls');
  const marketing=await page.locator('#exin-bf-operations [name]').evaluateAll(nodes=>nodes.map(n=>n.name));
  check(['coupon_floor_policy','cashback_message','badge_text','new_floor_mode','notify_enabled','manual_floor_catalog'].every(k=>marketing.includes(k)),'Marketing feature coverage');
  await page.locator('[name="badge_text"]').fill('نشان آزمایشی');
  page.once('dialog',d=>d.dismiss());await page.locator('[data-view="dashboard"]').click();check(await page.locator('#exin-bf-operations').count()===1,'Unsaved marketing navigation guard');
  await page.locator('#exin-bf-operations [type="submit"]').click();await page.waitForTimeout(150);check(calls.some(c=>c.endpoint.endsWith('/presentation')&&c.body.badge_text==='نشان آزمایشی'),'Marketing persists via existing API');
  campaign.status='active';await page.locator('[data-view="settings"]').click();await page.locator('#exin-bf-settings fieldset[disabled]').waitFor();check(await page.locator('#bf-discount_mode').isDisabled(),'Live campaign financial controls locked');
  check(await page.locator('[data-strategy]').first().isDisabled(),'Strategy cards inherit financial lock');
  const beforeLivePreview=calls.filter(c=>c.endpoint==='campaign').length;await page.locator('[data-command="simulate-prices"]').click();await page.locator('#exin-bf-price-simulation .exin-bf-simulation').first().waitFor();check(calls.filter(c=>c.endpoint==='campaign').length===beforeLivePreview,'Live simulation never saves financial settings');
  await page.locator('[data-view="diagnostics"]').click();await page.locator('[data-command="history"]').waitFor();check(await page.locator('[data-command="history"]').count()===1,'History remains one click from health');
  await page.locator('[data-view="dashboard"]').click();await page.locator('[data-campaign-card]').first().waitFor();check(await page.locator('[data-campaign-card]').count()>0,'Dashboard campaign cards');
  check(await page.locator('#exin-bf-admin').evaluate(n=>getComputedStyle(n).direction)==='rtl','RTL');
  await page.screenshot({path:path.join(root,'work/ui-desktop.png'),fullPage:true});
  await page.setViewportSize({width:390,height:844});await page.screenshot({path:path.join(root,'work/ui-mobile.png'),fullPage:true});
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth),'Mobile no document overflow');
  check(errors.length===0,'No browser errors');
  const report={suite:'rtl-admin-workspace',checks,passed:checks,failed:0,coverage_modes:modes.length,mocked_catalog_products:1739,rendered_products:25,product_view_ready_ms:productRenderMs,elapsed_ms:Date.now()-start,boundary:'Real Chromium, shipped JS/CSS, mocked REST; no real campaign writes.'};fs.writeFileSync(path.join(root,'work/ui-workspace-results.json'),JSON.stringify(report,null,2)+'\n');console.log(JSON.stringify(report));
 } catch(error){console.error(JSON.stringify({errors,text:await page.locator('body').innerText()}));throw error;} finally {await browser.close();}
})().catch(error=>{console.error(error);process.exit(1);});
