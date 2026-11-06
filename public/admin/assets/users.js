function showRoleModal(id, username, currentRole) {
    document.getElementById('role-user-id').value = id;
    document.getElementById('role-username').textContent = username;
    document.getElementById('role-select').value = currentRole;
    document.getElementById('role-modal').style.display = 'flex';
}
function showPasswordModal(id, username) {
    document.getElementById('pw-user-id').value = id;
    document.getElementById('pw-username').textContent = username;
    document.getElementById('password-modal').style.display = 'flex';
}
['role-modal','password-modal'].forEach(id => {
    document.getElementById(id).addEventListener('click', e => {
        if (e.target.id === id) document.getElementById(id).style.display = 'none';
    });
});
