const fs = require('fs');
const h = fs.readFileSync('frontend/customer/dashboard.html', 'utf8');
const idx = h.indexOf('themeBtn');
console.log('themeBtn context:', idx >= 0 ? h.substring(idx, idx + 200) : 'NOT FOUND');