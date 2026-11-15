// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
'use strict';

// Optional integration test against the real Laravel-rendered interface.
// All Telegram endpoints are intercepted with synthetic metadata.
// Example: PUPPETEER_MODULE=/path/to/puppeteer \
//   PUPPETEER_EXECUTABLE=/usr/bin/google-chrome \
//   AUTOMATIC_KIMAI_PREVIEW_URL=http://127.0.0.1:6817/?view=telegram \
//   node tests/Browser/telegram-search.cjs
const assert=require('node:assert/strict');
const {test}=require('node:test');

test('Telegram searches across pages, query changes and selected groups',async()=>{
  const puppeteer=require(process.env.PUPPETEER_MODULE||'puppeteer');
  const browser=await puppeteer.launch({
    headless:true,
    ...(process.env.PUPPETEER_EXECUTABLE?{
      executablePath:process.env.PUPPETEER_EXECUTABLE,
    }:{}),
    args:['--no-sandbox','--disable-dev-shm-usage'],
  });
  try{
    const page=await browser.newPage();
    const errors=[];
    let catalogCalls=0;
    let communitiesCalls=0;
    // Simulate passing time without sleeping for Telegram's rolling quota;
    // the fake server answers each request immediately.
    await page.evaluateOnNewDocument(()=>{
      let now=Date.now();
      Date.now=()=>{now+=7000;return now;};
    });
    await page.setRequestInterception(true);
    page.on('pageerror',error=>errors.push(error.name));
    page.on('request',request=>{
      try{
        const path=new URL(request.url()).pathname;
        const json=body=>request.respond({
          status:200,contentType:'application/json',body:JSON.stringify(body),
        });
        if(path==='/telegram/status')return json({
          status:'authorized',enabled:true,
          account:{display_name:'Conta sintética',username:'qa'},
        });
        if(path==='/telegram/groups')return json({
          groups:[{id:-1002,title:'Grupo comum',recommended:false,archived:false}],
          selected_ids:[-1002],
          selected_groups:[{id:-1002,title:'Grupo comum',recommended:false}],
          recommended_groups:[],suggestions_allowed:false,
          next_cursor:null,scanned_dialog_count:1,folder:0,
        });
        if(path==='/telegram/communities'){
          communitiesCalls++;
          return json({status:'ok',communities:[]});
        }
        if(path==='/telegram/avatar')return request.respond({status:404,body:''});
        if(path==='/telegram/catalog'){
          const payload=JSON.parse(request.postData()||'{}');
          const folder=Number(payload.folder);
          const prior=payload.cursor===undefined?0:
            Number(String(payload.cursor).replace('p',''));
          if(![0,1].includes(folder)||!Number.isInteger(prior)||prior<0){
            return request.abort();
          }
          catalogCalls++;
          const next=prior+1;
          const title=next===14&&folder===0?'Coordenação de projetos':
            next>=13?'Segundo termo garantido '+next:'Primeiro termo '+next;
          return json({
            status:'ok',folder,scanned_dialog_count:100,
            next_cursor:next>=14?null:'p'+next,
            groups:[{
              id:-(10000+folder*100+next),title,
              archived:folder===1,recommended:false,
            }],
          });
        }
        if(new URL(request.url()).origin===
          new URL(process.env.AUTOMATIC_KIMAI_PREVIEW_URL||
            'http://127.0.0.1:6817/?view=telegram').origin){
          return request.continue();
        }
        return request.abort();
      }catch(_error){try{request.abort()}catch(_ignored){}}
    });
    const url=process.env.AUTOMATIC_KIMAI_PREVIEW_URL||
      'http://127.0.0.1:6817/?view=telegram';
    const response=await page.goto(url,{
      waitUntil:'domcontentloaded',timeout:16000,
    });
    assert.equal(response.status(),200);
    await page.waitForSelector('#tg-groups-step:not([hidden])',{timeout:8000});
    const type=async text=>page.evaluate(q=>{
      const input=document.querySelector('#tg-search');
      input.value=q;
      input.dispatchEvent(new Event('input',{bubbles:true}));
    },text);
    const summary=()=>page.evaluate(()=>({
      status:document.querySelector('#tg-search-status').textContent,
      available:document.querySelectorAll(
        '#tg-group-list label.telegram-group-option:not([hidden])').length,
      progressHidden:document.querySelector('#tg-search-progress').hidden,
      selection:document.querySelector('#tg-selected-count').textContent,
    }));
    await type('Primeiro');
    await page.waitForFunction(
      ()=>document.querySelector('#tg-search-status').textContent
        .includes('Busca completa'),{timeout:25000},
    );
    const initial=await summary();
    assert.ok(initial.available>=20,'first pages were not indexed');
    assert.equal(initial.progressHidden,true);
    assert.equal(initial.selection,'1','synthetic selection must survive');
    assert.equal(communitiesCalls,0,
      'slow native community discovery must not block a group search');

    await type('Segundo');
    const found=await summary();
    assert.ok(found.available>=2,
      'second query must find items past former 12-page cutoff');
    assert.ok(found.status.includes('Busca completa'));
    assert.equal(catalogCalls,28,
      'all pages must be scanned once, and queries must reuse the index');

    await type('coordenacao');
    const accent=await summary();
    assert.equal(accent.available,1,
      'unaccented query must match a title with accent');

    await type('Grupo comum');
    const selected=await summary();
    assert.equal(selected.available,0,
      'an already-selected group is not a second available checkbox');
    assert.match(selected.status,/1 já selecionados/);
    assert.ok(selected.progressHidden);
    assert.deepEqual(errors,[]);
    await page.evaluate(()=>{
      document.querySelector('#tg-communities-panel').open=true;
    });
    await page.waitForFunction(()=>
      document.querySelector('#tg-communities-status').textContent
        .includes('Nenhuma comunidade'),{timeout:4500});
    assert.equal(communitiesCalls,1,
      'community discovery should only start when the user opens it');
    console.log(JSON.stringify({
      browser_telegram_search:'passed',
      catalog_pages:catalogCalls,
      indexed_through_page_14:true,
      next_term_found:found.available,
      accented_match:accent.available,
      selected_groups_preserved:selected.selection,
      script_errors:errors.length,
      lazy_community_queries:communitiesCalls,
    }));
  }finally{
    await browser.close();
  }
});
