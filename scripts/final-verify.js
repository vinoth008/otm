const fs=require('fs'),path=require('path');
const dirs=['frontend/admin','frontend/customer','frontend/receptionist','frontend/staff'];
let total=0,ok=0,bad=[];
dirs.forEach(dir=>{
  fs.readdirSync(dir).filter(f=>f.endsWith('.html')&&f!=='404.html').forEach(f=>{
    const file=path.join(dir,f);const h=fs.readFileSync(file,'utf8');total++;
    const hasCss=h.includes('app_theme.css');
    const hasJs=h.includes('app_theme.js');
    const hasBtn=h.includes('themeBtn');
    if(hasCss&&hasJs&&hasBtn)ok++;else bad.push(file+' css='+hasCss+' js='+hasJs+' btn='+hasBtn);
  });
});
console.log('Total:'+total+' OK:'+ok);
bad.forEach(x=>console.log('  '+x));