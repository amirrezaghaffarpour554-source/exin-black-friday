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
let campaign = {id:130,title:'کمپین خودکار',status:'draft',version:1,days:3,power:2,strategy:'linear',rounding:'half_up',start_utc:'2026-11-27 00:00:00',end_utc:'2026-11-30 00:00:00',timezone:'Asia/Tehran',settings:{currency:'IRR',precision:0,existing_sale_policy:'preserve',discount_plan:{mode:'auto_progressive',steps:3,interval:'daily',interval_minutes:1440,seed:'exin-ui-seed'}}}, count=2,processed=0,campaignCounter=130;
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
  await page.locator('[data-view="settings"]').click();
  await page.locator('#bf-discount_mode').waitFor();
  check(await page.locator('#bf-discount_mode option[value^="auto_"]').count()===6,'All six automatic methods are available');
  for(const mode of ['auto_progressive','auto_descending','auto_rotation','auto_random','auto_wave','auto_hybrid']){
    await page.locator('#bf-discount_mode').selectOption(mode);
    check(await page.locator('[name="auto_last_day"]').inputValue()==='disabled','Last-day override defaults disabled for '+mode);
    check(await page.locator('#bf-initial_discount').count()===0,'Automatic mode needs no manual stage percentage');
  }
  check(await page.locator('[data-step]').count()===5,'Five automatic setup steps are presented');
  check(await page.locator('[data-step="3"]').getAttribute('aria-current')==='step','Method step is correctly highlighted');
  await page.locator('#bf-discount_mode').selectOption('auto_random');
  await page.locator('#bf-interval').selectOption('flash');
  check(await page.locator('#bf-interval_minutes').inputValue()==='5','Flash switches to a safe five-minute default');
  await page.locator('#bf-auto_min').fill('۲۰');await page.locator('#bf-auto_max').fill('۸۰');
  await page.locator('[name="auto_selection_mode"]').evaluate(el=>el.closest('details').open=true);
  await page.locator('[name="auto_selection_mode"]').selectOption('count');
  await page.locator('#bf-auto_selection_value').fill('۲');
  await page.locator('[data-command="step-review"]').click();
  await page.locator('#exin-bf-confirm-activate').waitFor();
  const payload=calls.filter(c=>c.endpoint==='campaign').at(-1).body.settings.discount_plan;
  check(payload.mode==='auto_random'&&payload.interval==='flash'&&payload.interval_minutes===5,'Automatic mode and timing reach REST');
  check(payload.selection_mode==='count'&&payload.selection_value===2,'Cohort count is normalized');
  check(payload.seed==='exin-ui-seed','Stored seed persists through an ordinary save');
  check(payload.last_day==='disabled','No implicit final-day override');
  check(!calls.some(c=>c.endpoint.endsWith('/action')),'Preview never activates or reprices a campaign');
  check((await page.locator('main').innerText()).includes('سقف 50.00'),'Preview displays maximum allowed discount');
  await page.locator('[data-command="auto-preview-next"]').click();
  check(calls.filter(c=>c.endpoint.endsWith('/simulation')).at(-1).body.page===2,'Product preview is paged');
  await page.locator('[data-command="auto-preview-stages"]').click();
  check(calls.filter(c=>c.endpoint.endsWith('/simulation')).at(-1).body.stage_start===12,'Future stage preview is independently paged');
  campaign.status='active';campaign.settings.discount_plan={mode:'custom',interval:'custom',steps:2,points:[{offset_minutes:0,kind:'percent',value:'5'},{offset_minutes:60,kind:'percent',value:'10'}],duration_minutes:120};
  await page.reload();await page.locator('[data-view="settings"]').click();
  check(await page.locator('#bf-discount_mode').isDisabled(),'Existing live manual plan remains locked');
  check(await page.locator('#bf-discount_mode').inputValue()==='custom','Existing manual mode is not converted automatically');
  check(errors.length===0,'No JavaScript exceptions');
  const report={suite:'v130-automatic-admin-smoke',checks,passed:checks,failed:0,boundary:'One Chromium and real shipped JavaScript; mocked REST. Real services and migration are tested separately.'};fs.writeFileSync(path.join(root,'work/v130/admin-smoke.json'),JSON.stringify(report,null,2)+'\n');console.log(JSON.stringify(report));
 } catch(error){console.error(JSON.stringify({errors,text:await page.locator('body').innerText()}));throw error;} finally {await browser.close();}
})().catch(error=>{console.error(error);process.exit(1);});
