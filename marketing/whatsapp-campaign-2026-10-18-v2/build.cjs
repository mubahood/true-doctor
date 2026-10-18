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
/* Bespoke compositions appended to the shared, editable vector device helpers. */
const LABEL='CLINIC & HOSPITAL MANAGEMENT SYSTEM';
const rotate=(art,deg,cx,cy)=>`<g transform="rotate(${deg} ${cx} ${cy})">${art}</g>`;
const turnPhone=(x,y,w,deg,ui)=>rotate(phone(x,y,w,ui),deg,x+w/2,y+w/360*344);
const turnLaptop=(x,y,w,deg,ui)=>rotate(laptop(x,y,w,ui),deg,x+w/2,y+w/900*252.5);
const panel=(x,y,w,h,content,deg=0)=>rotate(`<g>${rect(x,y,w,h,C.white,5,`stroke="${C.line}" filter="url(#cardShadow)"`)}<g transform="translate(${x} ${y})">${content}</g></g>`,deg,x+w/2,y+h/2);
function patientCompact(){
 return phoneHeader('Patients')+rect(0,110,320,40,C.pale)+icon('search',12,120,20,C.muted)+txt(44,136,'Search patients',17,400,C.muted)+['Brian Mugisha','Grace Atim','Sandra Nakiganda','Patience Akello'].map((s,i)=>txt(0,190+i*66,s,22,650)+txt(0,214+i*66,'Patient record',15,400,C.muted)+icon('chevron',296,176+i*66,20)+rule(0,230+i*66,320,230+i*66)).join('')+rect(0,482,320,48,C.pale)+icon('plus',147,494,24,C.blue);
}
function visitCompact(){
 return phoneHeader('Visit')+txt(0,117,'Brian Mugisha',22,650)+rect(0,139,320,42,C.pale)+txt(16,166,'Saved on phone',20,650,C.blue)+icon('check',275,149,24,'#16804A',2.6)+rect(0,204,320,174,C.white,0,`stroke="${C.line}"`)+txt(16,239,'Vitals',24,700)+rule(0,254,320,254)+txt(16,287,'TEMP',15,500,C.muted)+txt(178,287,'BP',15,500,C.muted)+txt(16,324,'38.4°C',28,700)+txt(178,324,'118/76',28,700)+txt(16,359,'Pulse 92 · SpO₂ 97%',18,500)+rect(0,404,320,50,C.blue)+txt(160,437,'Save visit',21,650,C.white,'text-anchor="middle"')+rect(0,482,320,48,C.pale)+icon('check',148,494,24,C.blue);
}
function dashCompact(){
 return phoneHeader('Dashboard')+rect(0,111,320,88,C.pale)+txt(16,143,'Open visits',19,500,C.muted)+txt(16,184,'36',36,750,C.blue)+txt(0,239,'Collected today',19,500,C.muted)+txt(0,278,'UGX 2,450,000',29,750,C.blue)+rule(0,302,320,302)+txt(0,346,'Billed & collected',20,650)+[45,80,66,109,144,125,171].map((h,i)=>rect(10+i*44,556-h,27,h,C.blue)).join('')+rule(0,557,320,557);
}
const patientCard=(x,y,w=390,deg=-8)=>panel(x,y,w,146,rect(0,0,7,146,C.blue)+txt(25,35,'PATIENT RECORD',16,700,C.blue,'letter-spacing="1.4"')+txt(25,78,'Brian Mugisha',30,750)+txt(25,117,'Visit · Outpatient',21,400,C.muted),deg);
const labCard=(x,y,w=352,deg=8)=>panel(x,y,w,142,txt(24,36,'LAB RESULT',17,750,C.blue,'letter-spacing="1.4"')+txt(24,80,'Full Blood Count',27,700)+txt(24,119,'Result ready',22,500,C.muted)+icon('check',w-55,96,25,C.blue),deg);
const invoiceCard=(x,y,w=344,deg=9)=>panel(x,y,w,170,txt(24,36,'INVOICE',17,750,C.blue,'letter-spacing="1.4"')+txt(24,82,'UGX 25,000',32,750)+rule(24,105,w-24,105)+icon('check',24,125,24,C.blue,2.4)+txt(61,148,'Paid',24,700,C.blue),deg);
const stockCard=(x,y,w=330,deg=-8)=>panel(x,y,w,128,txt(23,35,'STOCK ALERT',16,750,C.blue,'letter-spacing="1.3"')+txt(23,76,'Paracetamol',27,700)+txt(23,109,'24 packs remaining',21,450,C.muted),deg);
function exerciseBook(){
 let pages='';
 for(let i=0;i<9;i++) pages+=rule(22,42+i*19,299,42+i*19,'#B9C9DA',1);
 const book=rect(0,0,330,292,'#17334F',4)+rect(12,12,312,264,'#E9E9DF')+rect(22,20,301,251,'#FAFAF4')+pages+rule(54,22,54,269,'#9BACBD',1.5)+`<path d="M22 20H323V271L302 260 284 273 268 261 250 272 232 260 211 272 193 261 175 270 155 258 137 271 118 259 98 271 78 259 58 271 40 259 22 270Z" fill="none" stroke="#E2E4DE" stroke-width="3"/>`+rule(10,282,328,282,'#47596C',4);
 return rotate(`<g transform="translate(55 972)" filter="url(#cardShadow)">${book}</g>`,-14,220,1118);
}
function tornSheet(x,y,w,h,deg){
 return rotate(`<g filter="url(#cardShadow)"><path d="M${x} ${y}h${w}v${h-10}l-18 10-17-11-18 11-18-10-18 10-18-11-18 11-18-10-18 10H${x}Z" fill="#FFFDF7"/>${Array.from({length:5},(_,i)=>rule(x+16,y+22+i*17,x+w-18,y+22+i*17,'#D7E0E9',1)).join('')}</g>`,deg,x+w/2,y+h/2);
}
const dept=(label,detail,x,y,w,deg)=>panel(x,y,w,108,rect(0,0,7,108,C.blue)+txt(24,43,label,29,800,C.blue)+txt(24,79,detail,20,450,C.muted),deg);

const campaign=[
 {id:'01-ditch-the-paper',dark:false,headline:'Ditch the paper.',lines:[[{t:'Ditch the',c:C.blue}],[{t:'paper.',c:C.orange}]],sizes:[119,157],sub:'Patients, visits, lab and billing — all in one system.',urlColor:C.white,urlX:64,
 background:(h,o)=>`<path d="M0 ${1020+o} 1080 ${482+o}V${h}H0Z" fill="${C.blue}"/>`,
 visual:()=>tornSheet(165,851,244,139,-21)+exerciseBook()+turnPhone(497,531,480,12,patientCompact())+tornSheet(285,775,249,122,7)+patientCard(80,646,393,-9)+panel(197,877,360,119,txt(23,34,'PATIENT RECORD',16,750,C.blue,'letter-spacing="1.2"')+txt(23,76,'Grace Atim',29,750)+txt(23,104,'Visit · Outpatient',18,400,C.muted),7)},
 {id:'02-no-network',dark:true,headline:'No network? No problem.',lines:[[{t:'No network?',c:C.white}],[{t:'No ',c:C.white},{t:'problem.',c:C.orange}]],sizes:[113,116],sub:"Works offline. Syncs when you're back.",urlColor:C.white,urlX:1008,urlEnd:true,
 background:(h,o)=>`<circle cx="160" cy="${1005+o}" r="494" fill="${C.white}"/><circle cx="1020" cy="${720+o}" r="380" fill="none" stroke="#FFFFFF" stroke-width="65" opacity=".06"/>`,
 visual:()=>`<path d="M620 716C915 605 1028 797 945 943M923 922l22 21 21-28M797 1165C650 1245 477 1140 540 1007M520 1020l20-13 13 24" stroke="#FFFFFF" stroke-width="4" stroke-dasharray="8 12" fill="none"/>`+turnLaptop(395,805,654,12,dashboardLaptopUI())+turnPhone(146,542,438,-12,visitCompact())+panel(598,720,388,133,txt(25,39,'VISIT SAVED',17,750,C.blue,'letter-spacing="1.4"')+txt(25,81,'Saved on phone',27,750)+txt(25,113,'Waiting to sync',20,450,C.muted)+icon('check',333,35,29,'#16804A',2.4),8)+`<circle cx="776" cy="585" r="57" fill="${C.orange}"/>`+icon('signal',744,553,64,C.ink,2.2)},
 {id:'03-stop-money-leaks',dark:false,headline:'Stop money leaks.',lines:[[{t:'Stop money',c:C.blue}],[{t:'leaks.',c:C.orange}]],sizes:[116,157],sub:'Every visit billed. Every shilling counted.',urlColor:C.blue,urlX:64,
 background:(h,o)=>`<circle cx="600" cy="${1050+o}" r="575" fill="#F2F6FB"/>`,
 visual:()=>txt(47,861,'UGX',383,900,'#DCEBFA','letter-spacing="-25" data-role="backdrop"')+turnLaptop(65,709,909,-10,cashUI())+invoiceCard(666,559,343,12)+panel(87,1087,370,146,txt(24,36,'RECEIPT',17,750,C.blue,'letter-spacing="1.4"')+txt(24,81,'UGX 25,000',33,750)+icon('check',24,107,25,C.blue,2.4)+txt(64,130,'Paid',24,700,C.blue),-7)},
 {id:'04-every-department',dark:false,headline:'Every department. One system.',lines:[[{t:'Every department.',c:C.blue}],[{t:'One',c:C.orange},{t:' system.',c:C.blue}]],sizes:[91,120],sub:'Reception · Doctor · Lab · Pharmacy · Wards · Cashier',urlColor:C.blue,urlX:64,
 background:(h,o)=>`<circle cx="555" cy="${1035+o}" r="540" fill="#EDF4FC"/><circle cx="598" cy="${940+o}" r="340" fill="${C.blue}"/>`,
 visual:()=>`<g stroke="${C.blue}" stroke-width="2" fill="none"><path d="M540 962 207 618M540 962 835 640M540 962 175 856M540 962 900 863M540 962 232 1171M540 962 855 1185"/></g>`+turnLaptop(173,802,748,-10,dashboardLaptopUI())+dept('Reception','Patients · 36',65,569,303,-8)+dept('Doctor','Visits in progress · 15',663,596,350,8)+dept('Lab','Results ready · 12',35,787,294,-8)+dept('Pharmacy','Stock alert · 24 packs',749,810,297,9)+dept('Wards','Beds occupied · 3',73,1110,322,-7)+dept('Cashier','UGX 2,450,000',693,1135,339,6)},
 {id:'05-run-smarter',dark:true,headline:'Run your clinic smarter.',lines:[[{t:'Run your clinic',c:C.white}],[{t:'smarter.',c:C.orange}]],sizes:[104,157],sub:'Free 14-day trial. Live demo today.',urlColor:C.white,urlX:1008,urlEnd:true,
 background:(h,o)=>`<circle cx="850" cy="${1055+o}" r="639" fill="none" stroke="#FFFFFF" stroke-width="95" opacity=".07"/>`,
 visual:()=>turnLaptop(212,715,802,12,dashboardLaptopUI())+turnPhone(91,557,435,-12,dashCompact())+labCard(622,595,355,10)+stockCard(671,948,340,-9)+rect(601,1179,407,86,C.orange)+txt(804.5,1234,'Start free trial',34,800,C.ink,'text-anchor="middle" data-role="cta"')}
];

function poster(c,format){
 const height=format==='status'?1920:1350;
 const offset=(height-1350)/2;
 serial=0;
 const title=c.lines.map((parts,i)=>`<text x="${c.id==='02-no-network'?540:c.id==='03-stop-money-leaks'||(c.id==='05-run-smarter'&&i===1)?1008:64}" text-anchor="${c.id==='02-no-network'?'middle':c.id==='03-stop-money-leaks'||(c.id==='05-run-smarter'&&i===1)?'end':'start'}" y="${i===0?282:429}" font-size="${c.sizes[i]}" font-weight="900" letter-spacing="-5.5" data-role="headline-line">${parts.map(p=>`<tspan fill="${p.c}">${esc(p.t)}</tspan>`).join('')}</text>`).join('');
 return `<svg xmlns="http://www.w3.org/2000/svg" width="1080" height="${height}" viewBox="0 0 1080 ${height}" role="img" aria-label="${esc(c.headline)}"><defs><style>@font-face{font-family:Inter;src:url(data:font/woff2;base64,${font}) format('woff2');font-weight:100 900}text{font-family:Inter,sans-serif;font-kerning:normal}</style><filter id="deviceShadow" x="-30%" y="-30%" width="160%" height="180%"><feDropShadow dx="0" dy="15" stdDeviation="14" flood-color="#0F1B2D" flood-opacity=".22"/></filter><filter id="cardShadow" x="-30%" y="-40%" width="160%" height="190%"><feDropShadow dx="0" dy="12" stdDeviation="10" flood-color="#0F1B2D" flood-opacity=".17"/></filter></defs>${rect(0,0,1080,height,c.dark?C.blue:C.white)}${c.background(height,offset)}<g transform="translate(0 ${offset})"><image x="64" y="46" width="52" height="57" href="data:image/png;base64,${logo}"/>${txt(133,88,'True-Doctor',34,750,c.dark?C.white:C.blue,'data-role="logo"')}${c.dark?'':rect(64,120,750,41,C.blue)}${txt(78,147,LABEL,26,800,C.white,'letter-spacing="1.6" data-role="category"')}${title}${txt(c.id==='02-no-network'?540:c.id==='03-stop-money-leaks'?1008:64,491,c.sub,c.id==='04-every-department'?27:28,450,c.dark?C.white:C.ink,`text-anchor="${c.id==='02-no-network'?'middle':c.id==='03-stop-money-leaks'?'end':'start'}" data-role="support"`)}${c.visual()}${txt(c.urlX,1313,'true-doctor.online',34,800,c.urlColor,`${c.urlEnd?'text-anchor="end" ':''}data-role="url"`)}</g></svg>`.replace(/[ \t]+$/gm,'');
}

async function main(){
 fs.copyFileSync(path.join(repo,'public/fonts/Inter-LICENSE.txt'),path.join(root,'Inter-LICENSE.txt'));
 for(const name of ['status','broadcast','editable-svg'])fs.mkdirSync(path.join(root,name),{recursive:true});
 const browser=await chromium.launch({executablePath:process.env.CHROME_PATH||'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',headless:true});
 const page=await browser.newPage({deviceScaleFactor:1});
 const report=[];
 for(const format of ['broadcast','status'])for(const c of campaign){
  const h=format==='status'?1920:1350,svg=poster(c,format);
  fs.writeFileSync(path.join(root,'editable-svg',`${c.id}-${format}.svg`),svg);
  await page.setViewportSize({width:1080,height:h});
  await page.setContent(`<html><head><style>html,body{padding:0;margin:0;width:1080px;height:${h}px;overflow:hidden}</style></head><body>${svg}</body></html>`);
  await page.evaluate(async()=>{await document.fonts.load('900 157px Inter');await document.fonts.load('450 28px Inter');await document.fonts.ready;});
  const qa=await page.evaluate(()=>({font:document.fonts.check('900 157px Inter'),items:[...document.querySelectorAll('text')].map(el=>{const b=el.getBoundingClientRect();return{text:el.textContent,role:el.dataset.role||'ui',x:b.x,y:b.y,right:b.right,bottom:b.bottom};})}));
  await page.screenshot({path:path.join(root,format,c.id+'.png')});
  const meta=await sharp(path.join(root,format,c.id+'.png')).metadata();
  const normal=s=>s.replace(/\s+/g,' ').trim();
  const byRole=r=>qa.items.filter(i=>i.role===r).map(i=>i.text);
  const edgeErrors=qa.items.filter(i=>i.x<0||i.right>1080||i.y<0||i.bottom>h);
  const safeErrors=format==='status'?qa.items.filter(i=>i.y<250||i.bottom>1670):[];
  const checks={dimensions:meta.width===1080&&meta.height===h,font:qa.font,headline:normal(byRole('headline-line').join(' '))===c.headline,support:byRole('support')[0]===c.sub,category:byRole('category')[0]===LABEL,url:byRole('url')[0]==='true-doctor.online',headlineWordCount:c.headline.split(/\s+/).length>=2&&c.headline.split(/\s+/).length<=4,oneOrangeHeadlineWord:c.lines.flat().filter(p=>p.c===C.orange).map(p=>p.t.trim()).join(' ').split(/\s+/).length===1,noPhoneNumber:!qa.items.some(i=>/256|WhatsApp/.test(i.text)),textInsideCanvas:edgeErrors.length===0,statusTextSafe:safeErrors.length===0};
  if(c.id==='05-run-smarter')checks.cta=byRole('cta')[0]==='Start free trial';
  report.push({file:`${format}/${c.id}.png`,width:meta.width,height:meta.height,checks,edgeErrors,safeErrors});
  console.log(`${format}/${c.id} ${Object.values(checks).every(Boolean)?'PASS':'FAIL'}`);
  if(edgeErrors.length||safeErrors.length)console.log(JSON.stringify({edgeErrors,safeErrors}));
 }
 await browser.close();
 const inputs=await Promise.all(campaign.map(async(c,i)=>({input:await sharp(path.join(root,'broadcast',c.id+'.png')).resize(324,405).png().toBuffer(),left:24+i*348,top:24})));
 await sharp({create:{width:1764,height:453,channels:3,background:'#E5ECF4'}}).composite(inputs).png().toFile(path.join(root,'campaign-preview.png'));
 fs.writeFileSync(path.join(root,'QA.json'),JSON.stringify({generatedAt:new Date().toISOString(),statusSafeArea:{y:250,height:1420},renderer:'Chromium, embedded Inter, vector mockups',results:report},null,2)+'\n');
 fs.writeFileSync(path.join(root,'copy.json'),JSON.stringify(campaign.map(({id,headline,sub})=>({id,category:LABEL,headline,support:sub,website:'true-doctor.online'})),null,2)+'\n');
 const cards=campaign.map(c=>`<article><h2>${esc(c.headline)}</h2><div><a href="broadcast/${c.id}.png"><img src="broadcast/${c.id}.png" alt="${esc(c.headline)} — broadcast"></a><a href="status/${c.id}.png"><img src="status/${c.id}.png" alt="${esc(c.headline)} — Status"></a></div></article>`).join('');
 fs.writeFileSync(path.join(root,'index.html'),`<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>True-Doctor · Campaign redesign</title><style>*{box-sizing:border-box}body{margin:0;padding:32px;background:#E5ECF4;color:#0F1B2D;font:16px Arial,sans-serif}h1{font-size:26px}h2{font-size:20px}article{margin-bottom:48px}article div{display:flex;gap:24px;align-items:start}a{width:min(45%,440px)}img{display:block;width:100%;height:auto}</style><h1>True-Doctor · Campaign redesign</h1><p>Broadcast 1080 × 1350 on the left. Status 1080 × 1920 on the right. Click for the full-resolution PNG.</p>${cards}</html>`);
 if(report.some(r=>!Object.values(r.checks).every(Boolean)))process.exitCode=1;
}
main().catch(e=>{console.error(e);process.exitCode=1;});
