# SecureSOT — Deployment Guide

## Why GitHub Pages Doesn't Work

GitHub Pages only serves **static files** (HTML, CSS, JS). It **cannot execute PHP**, so the backend API (`backend/api/index.php`, `backend/php/*.php`) will never run there. You need a PHP-enabled host for the backend.

---

## Option A: Single PHP Host (Recommended)

Upload the **entire project** to one PHP host. Both frontend and backend live on the same domain — no CORS issues.

### Supported hosts
- InfinityFree (free)
- 000WebHost (free)
- Any shared hosting with PHP 8.1+ and `allow_url_fopen`

### Steps

1. **Upload all files** via FTP/cPanel File Manager to the `public_html` (or `htdocs`) directory.

2. **Create `.env`** in the project root (same level as `config.php`):
   ```
   cp .env.example .env
   ```
   Then edit `.env` with your real values:
   ```ini
   MONGO_URI=mongodb+srv://user:pass@cluster.mongodb.net/?retryWrites=true&w=majority
   DB_NAME=smart_transaction_control
   SMTP_HOST=smtp.gmail.com
   SMTP_PORT=587
   SMTP_USERNAME=your@gmail.com
   SMTP_PASSWORD=your_app_password
   SMTP_FROM_NAME="SecureSOT"
   SMTP_SECURE=tls
   ```

3. **Set `composer/` permissions** (if using Composer autoloader on the host):
   ```bash
   composer install --no-dev
   ```
   If the host doesn't have Composer, upload the `vendor/` directory from your local install.

4. **Ensure writable directories** exist:
   ```
   logs/          ← PHP error logs
   uploads/       ← user file uploads
   backend/logs/  ← backend error logs
   ```

5. **Verify**:
   - Visit `https://yourdomain.com/backend/api/index.php?module=auth&action=status`
   - Should return `{"success":true,...}` (not a PHP error page)
   - Visit `https://yourdomain.com/` — the app should load

---

## Option B: Split Deployment (Frontend + Backend on separate hosts)

### Frontend → GitHub Pages

1. Push to GitHub (`main` branch).
2. In repo Settings → Pages → Source: `main` branch, folder: `/ (root)`.
3. The site will be at `https://username.github.io/otm/`.

### Backend → PHP Host

1. Upload the `backend/` folder + `config.php` + `vendor/` + `.env` to your PHP host.
2. The API base URL in `frontend/assets/js/api.js` must point to your PHP host:
   ```js
   // Change from relative path to absolute:
   const API_BASE = 'https://your-php-host.com/backend/api/index.php';
   ```
3. The PHP host's `backend/config/cors.php` already allows any origin — no changes needed.
4. Ensure `logs/`, `uploads/`, `backend/logs/` are writable.

---

## Verifying the Connection

From the browser console on the deployed site:
```js
fetch('https://your-host/backend/api/index.php?module=auth&action=status')
  .then(r => r.json())
  .then(d => console.log('API OK:', d))
  .catch(e => console.error('API FAILED:', e));
```

If you see `"API OK:"` with a JSON object, the backend is live.

---

## Troubleshooting

| Problem | Fix |
|---------|-----|
| Blank page or PHP source code | Host doesn't support PHP — switch to a PHP host |
| "Invalid JSON response" | Backend returned an error page — check `logs/error.log` on the host |
| CORS error in browser console | Frontend and backend on different domains — use Option A, or verify `backend/config/cors.php` allows your frontend domain |
| MongoDB connection refused | Whitelist your host's IP in MongoDB Atlas → Network Access |
| OTP emails not sending | Verify Gmail App Password in `.env`; check `logs/smtp_debug.log` |

---

## Environment Variables Reference

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `MONGO_URI` | Yes | *(hardcoded)* | MongoDB Atlas connection string |
| `DB_NAME` | Yes | `smart_transaction_control` | Database name |
| `SMTP_HOST` | Yes | `smtp.gmail.com` | SMTP server |
| `SMTP_PORT` | Yes | `587` | SMTP port |
| `SMTP_USERNAME` | Yes | — | Gmail address |
| `SMTP_PASSWORD` | Yes | — | Gmail App Password |
| `SMTP_FROM_NAME` | No | `SecureSOT` | Sender display name |
| `SMTP_SECURE` | No | `tls` | `tls` or `ssl` |
| `APP_NAME` | No | `Smart Transaction Control` | Application name |
| `PYTHON_API_URL` | No | *(empty)* | Python ML API (optional) |
