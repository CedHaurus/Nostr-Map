function copyRelay() {
  navigator.clipboard.writeText('wss://relay.nostrmap.net').then(() => {
    const btn   = document.getElementById('btn-copy-relay');
    const label = document.getElementById('copy-label');
    const copyI = document.getElementById('copy-icon');
    const checkI= document.getElementById('check-icon');
    if (btn) {
      btn.classList.add('copied');
      if (label)  label.textContent = 'Copié !';
      if (copyI)  copyI.style.display = 'none';
      if (checkI) checkI.style.display = '';
      setTimeout(() => {
        btn.classList.remove('copied');
        if (label)  label.textContent = 'Copier';
        if (copyI)  copyI.style.display = '';
        if (checkI) checkI.style.display = 'none';
      }, 2000);
    }
  });
}
