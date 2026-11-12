const { chromium } = require('playwright-core');
(async()=>{
 const b=await chromium.launch({executablePath:'C:/Users/milos/AppData/Local/Google/Chrome/Application/chrome.exe',headless:true,args:['--no-sandbox']});
 const c=await b.newContext({baseURL:'http://127.0.0.1:4173'}); const p=await c.newPage();
 p.on('console',m=>console.log('CONSOLE',m.type(),m.text()));
 p.on('pageerror',e=>console.log('PAGEERROR',e.message));
 p.on('requestfailed',r=>console.log('REQFAIL',r.url(),r.failure()?.errorText));
 await p.goto('/',{waitUntil:'load'});
 await p.waitForFunction(()=>window.AV&&AV.ready);
 console.log('before', await p.evaluate(()=>({auth:AV.api.authenticated,online:AV.api.online,badge:document.querySelector('#envBadge').textContent})));
 await p.click('#enter'); await p.waitForSelector('#demoEnter');
 console.log('modal open');
 await p.click('#demoEnter');
 await p.waitForTimeout(3000);
 console.log('after url', p.url());
 console.log('after', await p.evaluate(()=>({auth:AV.api.authenticated,online:AV.api.online,badge:document.querySelector('#envBadge')?.textContent,ready:AV.ready,err:AV.api.lastError,cookie:document.cookie,text:document.body.innerText.slice(0,300)})));
 console.log('cookies', await c.cookies());
 await b.close();
})().catch(e=>{console.error('ERR',e);process.exit(1)});
