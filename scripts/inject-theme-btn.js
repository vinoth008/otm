const fs = require('fs'), path = require('path');
const dirs = ['frontend/admin', 'frontend/customer', 'frontend/receptionist', 'frontend/staff'];
const btnTopbar = '<button class="topbar-btn" id="themeBtn"><i class="fa-solid fa-moon"></i></button>';
const btnNav = '<button class="btn btn-outline-light btn-sm" id="themeBtn"><i class="fa-solid fa-moon"></i></button>';
dirs.forEach(dir => {
  fs.readdirSync(dir).filter(f => f.endsWith('.html') && f !== '404.html').forEach(f => {
    const file = path.join(dir, f);
    let h = fs.readFileSync(file, 'utf8');
    if (h.includes('themeBtn')) { console.log('already: ' + file); return; }
    if (h.includes('topbar-right')) {
      h = h.replace('<div class="topbar-right">', '<div class="topbar-right">\n        ' + btnTopbar);
    } else if (h.includes('<nav class="navbar')) {
      h = h.replace('<div class="ms-auto d-flex align-items-center gap-3">', '<div class="ms-auto d-flex align-items-center gap-3">\n        ' + btnNav);
    } else {
      console.log('NO ANCHOR: ' + file); return;
    }
    fs.writeFileSync(file, h);
    console.log('injected btn: ' + file);
  });
});
console.log('done');