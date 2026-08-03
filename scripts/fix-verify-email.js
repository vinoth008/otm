const fs = require('fs');
const f = 'frontend/auth/verify-email.html';
let h = fs.readFileSync(f, 'utf8');

// Fix submit handler
h = h.replace(
  "        if (!data.success) return showError(data.message);\n\n        await Swal.fire({",
  "        await Swal.fire({"
);
h = h.replace(
  "      } catch (err) {\n        showError('Unable to connect to server');\n      }",
  "      } catch (err) {\n        Swal.fire({ icon: 'error', title: 'Verification Failed', text: err.message });\n      }"
);

// Fix resend handler
h = h.replace(
  "        const data = await postJSON(`${API_BASE}/resend_email_verification.php`, { user_id: uid });\n        if (!data.success) return showError(data.message);\n        await Swal.fire({",
  "        const res = await apiPost('?module=auth&action=verify_email', { user_id: uid, action: 'resend' });\n        await Swal.fire({"
);
h = h.replace(
  '${data.data.verify_link}',
  '${res.data.verify_link}'
);
h = h.replace(
  "      } catch (err) {\n        showError('Unable to resend verification');\n      }",
  "      } catch (err) {\n        Swal.fire({ icon: 'error', title: 'Unable to resend verification', text: err.message });\n      }"
);

fs.writeFileSync(f, h);
console.log('done');