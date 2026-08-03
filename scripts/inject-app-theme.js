const fs=require('fs'),path=require('path');
['frontend/admin','frontend/customer','frontend/receptionist','frontend/staff'].forEach(dir=>{
  fs.readdirSync(dir).filter(f=>f.endsWith('.html')&&f!=='404.html').forEach(f=>{
    const file=path.join(dir,f);let h=fs.readFileSync(file,'utf8');
    if(!h.includes('app_theme.css'))h=h.replace('</head>','  <link href="../css/app_theme.css" rel="stylesheet">\n</head>');
    if(!h.includes('app_theme.js'))h=h.replace('</body>','  <script src="../js/app_theme.js"></script>\n</body>');
    fs.writeFileSync(file,h);console.log('injected: '+file);
  });
});
console.log('done');