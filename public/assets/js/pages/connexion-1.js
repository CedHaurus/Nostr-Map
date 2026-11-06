  function toggleGuide(id) {
    document.getElementById(id).classList.toggle('open');
  }

  function switchTab(tab) {
    ['extension', 'nsec'].forEach(t => {
      document.getElementById('tab-btn-' + t).classList.toggle('active', t === tab);
      document.getElementById('tab-' + t).classList.toggle('active', t === tab);
    });
  }

  function initNsecToggle(toggleId, inputId, eyeOffId, eyeOnId) {
    const btn   = document.getElementById(toggleId);
    const input = document.getElementById(inputId);
    if (!btn || !input) return;
    btn.addEventListener('click', () => {
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      document.getElementById(eyeOffId).style.display = show ? 'none' : '';
      document.getElementById(eyeOnId).style.display  = show ? ''     : 'none';
    });
  }
