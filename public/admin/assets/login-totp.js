// Auto-submit quand 6 chiffres sont saisis
const input = document.getElementById('totp-input');
input.addEventListener('input', function() {
    const digits = this.value.replace(/\D/g, '');
    if (digits.length === 6) {
        this.value = digits;
        document.getElementById('totp-form').submit();
    }
});
// Formater avec espace après 3 chiffres
input.addEventListener('keyup', function() {
    let v = this.value.replace(/\D/g, '');
    if (v.length > 3) v = v.slice(0,3) + ' ' + v.slice(3,6);
    this.value = v;
});
