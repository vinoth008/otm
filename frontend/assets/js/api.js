/**
 * api.js — Unified API client that connects the frontend to the backend API.
 * All frontend pages live in frontend/<folder>/, so the backend API is
 * always at ../../backend/api/index.php relative to the current page.
 */

const API_BASE = '../../backend/api/index.php';

async function apiRequest(path, { method = 'GET', data = null, headers = {}, credentials = 'same-origin' } = {}) {
  const options = {
    method,
    credentials,
    headers: {
      'Accept': 'application/json',
      ...headers
    }
  };

  if (data !== null && method !== 'GET') {
    options.headers['Content-Type'] = 'application/json';
    options.body = JSON.stringify(data);
  }

  const response = await fetch(`${API_BASE}${path}`, options);
  let result = null;

  try {
    result = await response.json();
  } catch (e) {
    result = { success: false, message: 'Invalid JSON response from server' };
  }

  if (!response.ok || !result.success) {
    throw new Error(result.message || `Request failed with status ${response.status}`);
  }

  return result;
}

async function apiGet(path) {
  return apiRequest(path, { method: 'GET' });
}

async function apiPost(path, data) {
  return apiRequest(path, { method: 'POST', data });
}

async function apiPut(path, data) {
  return apiRequest(path, { method: 'PUT', data });
}

async function apiDelete(path, data = {}) {
  return apiRequest(path, { method: 'DELETE', data });
}

async function apiUpload(path, formData) {
  const response = await fetch(`${API_BASE}${path}`, {
    method: 'POST',
    credentials: 'same-origin',
    body: formData
  });
  let result = null;
  try {
    result = await response.json();
  } catch (e) {
    result = { success: false, message: 'Invalid JSON response from server' };
  }
  if (!response.ok || !result.success) {
    throw new Error(result.message || `Request failed with status ${response.status}`);
  }
  return result;
}
