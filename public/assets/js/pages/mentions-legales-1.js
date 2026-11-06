import { auth } from '/assets/js/auth.js?v=20260423-modal';
auth.initUI({
  loginBtn:  document.getElementById('btn-login'),
  logoutBtn: document.getElementById('btn-logout'),
  userMenu:  document.getElementById('user-menu'),
  slugEl:    document.getElementById('user-slug-display'),
});
