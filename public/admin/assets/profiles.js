function showBanModal(npub, name) {
    document.getElementById('ban-npub').value = npub;
    document.getElementById('ban-name').textContent = name;
    document.getElementById('ban-modal').style.display = 'flex';
}
function closeBanModal() { document.getElementById('ban-modal').style.display = 'none'; }

function confirmDelete(npub, name) {
    document.getElementById('delete-npub').value = npub;
    document.getElementById('delete-name').textContent = name;
    document.getElementById('delete-modal').style.display = 'flex';
}
function closeDeleteModal() { document.getElementById('delete-modal').style.display = 'none'; }

function confirmRequestDelete(npub, name) {
    document.getElementById('reqdelete-npub').value = npub;
    document.getElementById('reqdelete-name').textContent = name;
    document.getElementById('reqdelete-modal').style.display = 'flex';
}

// Fermer sur clic hors modal
['ban-modal','delete-modal','reqdelete-modal'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e) {
        if (e.target === this) this.style.display = 'none';
    });
});
