// Générer le QR code côté client
new QRCode(document.getElementById('qr-code'), {
    text:   document.getElementById('totp-data').dataset.uri,
    width:  180,
    height: 180,
    colorDark:  '#000000',
    colorLight: '#ffffff',
    correctLevel: QRCode.CorrectLevel.M
});

// Copier la clé secrète
function copySecret() {
    const raw = document.getElementById('totp-data').dataset.secret;
    navigator.clipboard.writeText(raw).then(() => {
        const btn = document.getElementById('copy-btn');
        btn.textContent = '✓ Copié';
        setTimeout(() => btn.textContent = '📋 Copier', 2000);
    });
}

// Auto-submit sur 6 chiffres
const input = document.getElementById('totp-input');
input.addEventListener('input', function() {
    const digits = this.value.replace(/\D/g, '');
    if (digits.length === 6) {
        this.value = digits;
        document.getElementById('verify-form').submit();
    }
});
input.addEventListener('keyup', function() {
    let v = this.value.replace(/\D/g, '');
    if (v.length > 3) v = v.slice(0,3) + ' ' + v.slice(3,6);
    this.value = v;
});
