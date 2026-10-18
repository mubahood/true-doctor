/* Editable vector artwork. Run with Node.js and the bundled Playwright/sharp runtime. */
const fs = require('node:fs');
const path = require('node:path');
const { createRequire } = require('node:module');
const root = __dirname;
const repo = path.resolve(root, '../..');
const dependencies = process.env.WORKSPACE_NODE_MODULES || '/Users/mac/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules';
const fromBundle = createRequire(path.join(dependencies, 'package.json'));
const { chromium } = fromBundle('playwright');
const sharp = fromBundle('sharp');
const C = { blue: '#015CC4', ink: '#0F1B2D', orange: '#F28C28', white: '#FFFFFF', line: '#D9E3EE', pale: '#F2F6FB', muted: '#536579' };
const font = fs.readFileSync(path.join(repo, 'public/fonts/inter-latin-wght.woff2')).toString('base64');
const logo = fs.readFileSync(path.join(repo, 'public/images/logo-icon.png')).toString('base64');
const esc = s => String(s).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;');
const rect = (x,y,w,h,fill,rx=0,attrs='') => `<rect x="${x}" y="${y}" width="${w}" height="${h}" fill="${fill}" rx="${rx}" ${attrs}/>`;
const rule = (x1,y1,x2,y2,color=C.line,width=1) => `<line x1="${x1}" y1="${y1}" x2="${x2}" y2="${y2}" stroke="${color}" stroke-width="${width}"/>`;
const txt = (x,y,value,size=24,weight=400,color=C.ink,attrs='') => `<text x="${x}" y="${y}" font-size="${size}" font-weight="${weight}" fill="${color}" ${attrs}>${esc(value)}</text>`;
const iconPaths = {
 menu: '<path d="M4 6h16M4 12h16M4 18h16"/>',
 search: '<circle cx="10" cy="10" r="6"/><path d="m15 15 5 5"/>',
 plus: '<path d="M12 5v14M5 12h14"/>',
 arrow: '<path d="M4 12h16m-6-6 6 6-6 6"/>',
 chevron: '<path d="m9 5 7 7-7 7"/>',
 check: '<path d="m5 12 4 4L19 6"/>',
 reception: '<rect x="5" y="4" width="14" height="17" rx="1"/><path d="M9 4V2h6v2M8 10h8M8 14h8M8 18h5"/>',
 doctors: '<path d="M5 3v7a5 5 0 0 0 10 0V3M3 3h4M13 3h4M10 15v2a4 4 0 0 0 8 0v-3"/><circle cx="18" cy="11" r="3"/>',
 lab: '<path d="M9 2h6M10 2v7L4 19q-1 3 2 3h12q3 0 2-3L14 9V2M7 15h10"/>',
 pharmacy: '<path d="M5 19a6 6 0 0 1 0-8l6-6a6 6 0 0 1 8 8l-6 6a6 6 0 0 1-8 0Zm3-11 8 8"/>',
 wards: '<path d="M3 5v16M21 13v8M3 17h18M7 10h10q4 0 4 4v3H3V9"/><rect x="5" y="8" width="5" height="5" rx="1"/>',
 cashier: '<path d="M6 2h12v20l-3-2-3 2-3-2-3 2V2ZM9 7h6M9 11h6M9 15h4"/>',
 visit: '<path d="M4 12h4l2-7 4 14 2-7h4"/>',
 home: '<path d="m3 10 9-8 9 8M5 8v13h14V8M9 21v-7h6v7"/>',
 signal: '<path d="M4 18v3M9 13v8M14 8v13M19 3v18M3 3l18 18"/>',
};
const icon = (name,x,y,size=24,color=C.blue,width=1.8) => `<svg x="${x}" y="${y}" width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="${color}" stroke-width="${width}" stroke-linecap="round" stroke-linejoin="round">${iconPaths[name]}</svg>`;
let serial = 0;

function phoneHeader(title) {
 return txt(0,23,'True-Doctor',20,700,C.blue)+icon('menu',290,5,24)+rule(0,43,320,43)+txt(0,85,title,30,750);
}
function bottomNav(selected='Patients') {
 const labels = ['Patients','Visits','Billing'];
 return rect(-2,544,324,60,C.white)+rule(0,544,320,544)+labels.map((label,i)=>icon(['reception','visit','cashier'][i],35+i*109,552,22,label===selected?C.blue:C.muted)+txt(46+i*109,593,label,13,label===selected?700:400,label===selected?C.blue:C.muted,'text-anchor="middle"')).join('');
}
function patientUI() {
 const rows = [['Brian Mugisha','PT-2026-000001B'],['Grace Atim','PT-2026-000002B'],['Ronald Mugisha','PT-2026-000003B'],['Sandra Nakiganda','PT-2026-000004B'],['Patience Akello','PT-2026-000005B']];
 return phoneHeader('Patients')+rect(276,59,44,36,C.blue)+icon('plus',286,65,22,C.white)+rect(0,110,320,44,C.pale)+icon('search',13,121,21,C.muted)+txt(47,138,'Search patients',18,400,C.muted)+rows.map(([name,id],i)=>{const y=189+i*70;return txt(0,y,name,20,650)+txt(0,y+24,id,14,400,C.muted)+icon('chevron',295,y-8,19,C.muted)+rule(0,y+39,320,y+39);}).join('')+bottomNav();
}
function visitUI() {
 return phoneHeader('Visit')+txt(0,115,'Brian Mugisha',21,600)+rect(0,137,320,40,C.pale)+icon('signal',11,147,19,C.blue)+txt(39,163,'No signal · Saved on phone',16,600,C.blue)+rect(0,200,320,182,C.white,0,`stroke="${C.line}"`)+txt(15,232,'Vitals',22,700)+rule(0,247,320,247)+txt(15,277,'TEMP',14,500,C.muted)+txt(177,277,'BP',14,500,C.muted)+txt(15,309,'38.4°C',27,650)+txt(177,309,'118/76',27,650)+txt(15,340,'PULSE',14,500,C.muted)+txt(177,340,'SpO₂',14,500,C.muted)+txt(15,369,'92',24,650)+txt(177,369,'97%',24,650)+txt(0,425,'Complaints',20,650)+rect(0,442,320,58,C.pale)+txt(14,477,'Fever, headache',18)+rect(0,519,320,50,C.blue)+txt(160,551,'Save on phone',18,650,C.white,'text-anchor="middle"');
}
function receiptUI() {
 return phoneHeader('Receipt')+rect(0,115,320,62,C.pale)+txt(16,154,'UGX 25,000',28,750,C.blue)+txt(0,222,'Consultation',21,650)+txt(0,253,'UGX 25,000',20)+rule(0,282,320,282)+txt(0,322,'Payment method',17,400,C.muted)+txt(0,354,'Mobile money',21,600)+rule(0,385,320,385)+txt(0,427,'Total paid',20,600)+txt(0,467,'UGX 25,000',30,750)+rect(0,507,320,45,C.pale)+icon('check',18,519,23,C.blue)+txt(56,537,'Paid',19,650,C.blue);
}
function dashboardPhoneUI() {
 return phoneHeader('Dashboard')+rect(0,112,320,94,C.pale)+txt(17,145,'Open visits',18,500,C.muted)+txt(17,188,'36',36,750,C.blue)+rect(0,222,320,96,C.white,0,`stroke="${C.line}"`)+txt(17,255,'Collected today',18,400,C.muted)+txt(17,296,'UGX 2,450,000',28,750,C.blue)+txt(0,364,'Billed & collected',20,650)+[38,65,48,91,118,103,139].map((h,i)=>rect(14+i*43,520-h,26,h,C.blue)).join('')+rule(0,521,320,521)+bottomNav('Visits');
}
function phone(x,y,w,ui) {
 const id = `screen-${++serial}`;
 const s = w/360;
 return `<g transform="translate(${x} ${y}) scale(${s})"><defs><clipPath id="${id}">${rect(10,10,340,668,C.white,30)}</clipPath></defs>${rect(0,0,360,688,C.ink,39,'filter="url(#deviceShadow)"')}${rect(4,4,352,680,'#293646',36)}${rect(9,9,342,670,C.white,31)}<g clip-path="url(#${id})">${rect(10,10,340,668,C.white,30)}<g transform="translate(20 48)">${ui}</g></g><circle cx="180" cy="27" r="5.5" fill="${C.ink}"/>${rect(137,668,86,4,C.ink,2)}${rect(359,119,3,58,C.ink,1)}</g>`;
}
function sidebar() {
 return rect(0,0,145,440,C.pale)+txt(16,33,'True-Doctor',18,750,C.blue)+rule(0,54,804,54)+['Patients','Visits','Lab','Pharmacy','Billing'].map((s,i)=>txt(20,99+i*49,s,17,i===4?700:450,i===4?C.blue:C.muted)).join('');
}
function cashUI() {
 return sidebar()+txt(170,37,'Cash-up',22,700)+rect(166,77,407,139,C.pale)+txt(187,111,'Cash collected',22,500,C.muted)+txt(187,166,'UGX 2,450,000',39,750,C.blue)+txt(607,105,'Invoices',18,400,C.muted)+txt(607,151,'18',35,700)+txt(170,261,'By method',23,700)+rule(170,279,778,279)+txt(170,316,'Cash',21,500)+txt(777,316,'UGX 2,450,000',21,650,C.ink,'text-anchor="end"')+rule(170,335,778,335)+txt(170,371,'Mobile money',21,500)+txt(777,371,'UGX 830,000',21,650,C.ink,'text-anchor="end"')+rule(170,390,778,390)+txt(170,426,'Debtors',21,500)+txt(777,426,'UGX 180,000',21,650,C.ink,'text-anchor="end"');
}
function dashboardLaptopUI() {
 return sidebar()+txt(170,37,'Dashboard',22,700)+[ ['Open visits','36'], ['Collected today','UGX 2,450,000'], ['Outstanding','UGX 180,000'] ].map(([a,b],i)=>rect(166+i*209,78,197,105,C.pale)+txt(179+i*209,108,a,16,500,C.muted)+txt(179+i*209,151,b,i===0?31:20,750,C.blue)).join('')+txt(170,226,'Billed & collected',22,650)+[48,74,58,112,139,125,163,149].map((h,i)=>rect(179+i*74,411-h,38,h,C.blue)).join('')+rule(170,412,777,412);
}
function laptop(x,y,w,ui) {
 const s = w/900;
 return `<g transform="translate(${x} ${y}) scale(${s})">${rect(34,0,832,469,C.ink,15,'filter="url(#deviceShadow)"')}${rect(42,8,816,453,'#293646',9)}${rect(48,15,804,440,C.white,3)}<g transform="translate(48 15)">${ui}</g><circle cx="450" cy="8" r="2.5" fill="#718096"/><path d="M0 469H900L867 499Q861 505 848 505H52Q39 505 33 499Z" fill="#CCD5DF"/><path d="M0 469H900L890 480H10Z" fill="#E7ECF2"/><path d="M360 469H540L531 479H369Z" fill="#9DAAB9"/>${rule(40,500,860,500,'#AEBAC8',2)}</g>`;
}
function books() {
 const one = (x,y,w,h,opacity) => `<g opacity="${opacity}">${rect(x,y,w,h,'#AAB8C7',3)}${rect(x+3,y+7,w-7,h-15,'#FBFCFE',1)}${Array.from({length:6},(_,i)=>rule(x+8,y+16+i*6,x+w-12,y+16+i*6,'#CBD4DE',1)).join('')}${rect(x-5,y-3,w+8,9,'#75899E',2)}${rect(x-5,y+h-7,w+8,9,'#75899E',2)}${rule(x+22,y+7,x+22,y+h-8,'#93A3B4',2)}</g>`;
 return `<g transform="translate(96 817)">${one(0,178,392,71,.8)}${one(14,100,372,77,.6)}${one(38,25,338,74,.36)}<path d="M40 24 76 0h303l-3 24Z" fill="#BAC7D4" opacity=".35"/></g>`;
}
function tile(label,name,x,y) {
 return rect(x,y,220,133,C.white,0,`stroke="${C.line}" stroke-width="1.5"`)+icon(name,x+22,y+20,38)+txt(x+22,y+107,label,23,650);
}
const campaign = [
 {id:'01-the-problem', dark:false, headline:'Still running your clinic on paper?', lines:['Still running your','clinic on paper?'], size:88, sub:'Patients, lab, pharmacy and billing in one system.', subLines:['Patients, lab, pharmacy and billing in one system.'], visual:()=>books()+icon('arrow',499,858,81,C.orange,1.8)+phone(626,518,342,patientUI())},
 {id:'02-works-offline', dark:true, headline:'No network? Keep working.', lines:['No network?','Keep working.'], size:96, sub:'Records save on the phone and sync when the network returns.', subLines:['Records save on the phone and sync','when the network returns.'], visual:()=>phone(360,516,360,visitUI())+`<circle cx="739" cy="615" r="43" fill="${C.orange}"/>`+icon('check',715,591,48,C.ink,2.3)},
 {id:'03-stop-money-leaks', dark:false, headline:'Know every shilling. Every day.', lines:['Know every shilling.','Every day.'], size:85, sub:'Invoices, mobile money, debtors and daily cash-up.', subLines:['Invoices, mobile money, debtors and daily cash-up.'], visual:()=>laptop(212,731,796,cashUI())+phone(72,547,278,receiptUI())},
 {id:'04-everything-in-one', dark:false, headline:'One system. Every department.', lines:['One system.','Every department.'], size:91, sub:'Reception · Doctors · Lab · Pharmacy · Wards · Cashier', subLines:['Reception · Doctors · Lab · Pharmacy · Wards · Cashier'], visual:()=>[656,846,1036].map(y=>rule(292,y,388,y,C.line,2)+rule(692,y,788,y,C.line,2)).join('')+phone(388,565,304,dashboardPhoneUI())+tile('Reception','reception',72,584)+tile('Doctors','doctors',72,774)+tile('Lab','lab',72,964)+tile('Pharmacy','pharmacy',788,584)+tile('Wards','wards',788,774)+tile('Cashier','cashier',788,964)},
 {id:'05-call-to-action', dark:true, headline:'Try True-Doctor free for 14 days.', lines:['Try True-Doctor','free for 14 days.'], size:91, sub:'No card needed. Open the live demo today.', subLines:['No card needed. Open the live demo today.'], visual:()=>rect(72,484,654,76,C.orange)+txt(399,532,'WhatsApp us: +256 783 204 665',30,700,C.ink,'text-anchor="middle" data-role="cta"')+laptop(72,731,858,dashboardLaptopUI())+phone(743,611,264,dashboardPhoneUI())},
];

function poster(c,format) {
 const height = format==='status'?1920:1350;
 const offset = (height-1350)/2;
 const color = c.dark?C.white:C.ink;
 const bg = c.dark?C.blue:C.white;
 serial=0;
 return `<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1080" height="${height}" viewBox="0 0 1080 ${height}" role="img" aria-label="${esc(c.headline)}">
 <defs><style>@font-face{font-family:Inter;src:url(data:font/woff2;base64,${font}) format('woff2');font-weight:100 900;}text{font-family:Inter,sans-serif;font-kerning:normal;} .headline{letter-spacing:-4px;}</style><filter id="deviceShadow" x="-20%" y="-15%" width="140%" height="145%"><feDropShadow dx="0" dy="12" stdDeviation="10" flood-color="${C.ink}" flood-opacity="${c.dark?.2:.12}"/></filter></defs>
 ${rect(0,0,1080,height,bg)}<g transform="translate(0 ${offset})">
 <image x="72" y="57" width="55" height="60" href="data:image/png;base64,${logo}"/>${txt(143,101,'True-Doctor',35,750,c.dark?C.white:C.blue,'data-role="logo"')}
 ${rule(72,145,1008,145,c.dark?'#FFFFFF35':C.line,1)}
 <text x="72" y="264" font-size="${c.size}" font-weight="800" fill="${color}" class="headline" data-role="headline">${c.lines.map((s,i)=>`<tspan x="72" dy="${i===0?0:99}">${esc(s)}</tspan>`).join(' ')}</text>
 ${[2,3].includes(campaign.indexOf(c))?rect(72,389,75,7,C.orange):''}
 <text x="72" y="${c.id==='04-everything-in-one'?445:434}" font-size="${c.id==='04-everything-in-one'?28:30}" font-weight="400" fill="${color}" data-role="sub">${c.subLines.map((s,i)=>`<tspan x="72" dy="${i===0?0:40}">${esc(s)}</tspan>`).join(' ')}</text>
 ${c.visual()}
 ${rect(0,1242,1080,108+offset,C.blue)}${c.dark?rule(72,1242,1008,1242,'#FFFFFF55',1):''}
 ${txt(72,1307,'WhatsApp +256 783 204 665  ·  true-doctor.online',31,550,C.white,'data-role="footer" xml:space="preserve"')}
 </g></svg>`;
}

async function main() {
 fs.copyFileSync(path.join(repo,'public/fonts/Inter-LICENSE.txt'),path.join(root,'Inter-LICENSE.txt'));
 for (const format of ['status','broadcast','editable-svg']) fs.mkdirSync(path.join(root,format),{recursive:true});
 const browser=await chromium.launch({executablePath:process.env.CHROME_PATH||'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',headless:true});
 const page=await browser.newPage({deviceScaleFactor:1});
 const report=[];
 for(const format of ['broadcast','status']) {
  for(const c of campaign) {
   const h=format==='status'?1920:1350;
   const svg=poster(c,format).replace(/[ \t]+$/gm,'');
   fs.writeFileSync(path.join(root,'editable-svg',`${c.id}-${format}.svg`),svg);
   await page.setViewportSize({width:1080,height:h});
   await page.setContent(`<html><head><style>*{box-sizing:border-box}html,body{margin:0;width:1080px;height:${h}px;overflow:hidden}</style></head><body>${svg}</body></html>`);
   await page.evaluate(async()=>{await document.fonts.load('800 96px Inter');await document.fonts.load('400 30px Inter');await document.fonts.ready;});
   const qa=await page.evaluate(()=>{
    const items=[...document.querySelectorAll('text')].map(el=>{const b=el.getBoundingClientRect();return {text:el.textContent,role:el.dataset.role||'ui',x:b.x,y:b.y,right:b.right,bottom:b.bottom};});
    return {fontLoaded:document.fonts.check('800 96px Inter'),items};
   });
   const out=path.join(root,format,c.id+'.png');
   await page.screenshot({path:out,fullPage:false});
   const meta=await sharp(out).metadata();
   const edgeErrors=qa.items.filter(i=>i.x<0||i.right>1080||i.y<0||i.bottom>h);
   const safeErrors=format==='status'?qa.items.filter(i=>i.y<250||i.bottom>1670):[];
   const normal=s=>s.replace(/\s+/g,' ').trim();
   const byRole=role=>qa.items.find(i=>i.role===role)?.text;
   const checks={fontLoaded:qa.fontLoaded,dimensions:meta.width===1080&&meta.height===h,headline:normal(byRole('headline'))===c.headline,support:normal(byRole('sub'))===c.sub,footer:byRole('footer')==='WhatsApp +256 783 204 665  ·  true-doctor.online',allTextInsideCanvas:edgeErrors.length===0,allStatusTextInsideSafeArea:safeErrors.length===0};
   if(c.id==='05-call-to-action') checks.cta=byRole('cta')==='WhatsApp us: +256 783 204 665';
   report.push({file:`${format}/${c.id}.png`,width:meta.width,height:meta.height,checks,edgeErrors,safeErrors});
   console.log(`${format}/${c.id}: ${Object.values(checks).every(Boolean)?'PASS':'FAIL'}`);
  }
 }
 await browser.close();
 const inputs=await Promise.all(campaign.map(async(c,i)=>({input:await sharp(path.join(root,'broadcast',c.id+'.png')).resize(324,405).png().toBuffer(),left:24+i*348,top:24})));
 await sharp({create:{width:1764,height:453,channels:3,background:'#E8EDF3'}}).composite(inputs).png().toFile(path.join(root,'campaign-preview.png'));
 fs.writeFileSync(path.join(root,'QA.json'),JSON.stringify({generatedAt:new Date().toISOString(),renderer:'Chromium; Inter embedded; vector device and UI mockups',statusTextSafeArea:{x:0,y:250,width:1080,height:1420},artworkGrid:{margin:72,contentHeight:1350,statusOffset:285,footerY:1242},results:report},null,2)+'\n');
 fs.writeFileSync(path.join(root,'copy.json'),JSON.stringify(campaign.map(({id,headline,sub})=>({id,headline,sub})),null,2)+'\n');
 const cards=campaign.map(c=>`<article><h2>${esc(c.headline)}</h2><div class="pair"><a href="broadcast/${c.id}.png"><img src="broadcast/${c.id}.png" alt="${esc(c.headline)} broadcast poster"/></a><a href="status/${c.id}.png"><img src="status/${c.id}.png" alt="${esc(c.headline)} Status poster"/></a></div></article>`).join('');
 fs.writeFileSync(path.join(root,'index.html'),`<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>True-Doctor · WhatsApp campaign</title><style>@font-face{font-family:Inter;src:url(data:font/woff2;base64,${font}) format('woff2')}*{box-sizing:border-box}body{margin:0;background:#E8EDF3;color:#0F1B2D;font-family:Inter,sans-serif;padding:36px}h1{font-size:28px;margin:0 0 8px}p{margin:0 0 36px;font-size:15px}h2{font-size:20px}.pair{display:flex;gap:24px;align-items:start}.pair a{width:min(43%,440px)}img{width:100%;height:auto;box-shadow:0 4px 24px #0F1B2D18}article{margin:0 0 48px}a{color:#015CC4}</style></head><body><h1>True-Doctor · WhatsApp campaign</h1><p>Left: 1080 × 1350 broadcast. Right: 1080 × 1920 Status. Click a poster for its full-resolution PNG. Editable SVG files are in the editable-svg folder.</p>${cards}</body></html>`);
 if(report.some(r=>!Object.values(r.checks).every(Boolean))) process.exitCode=1;
}
main().catch(e=>{console.error(e);process.exitCode=1;});
