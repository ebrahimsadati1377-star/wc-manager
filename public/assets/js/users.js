function openUserModal(u) {
  document.getElementById('userForm').reset();
  document.getElementById('userFormError').textContent = '';
  document.getElementById('u_id').value = '';
  document.getElementById('pwHint').textContent = '';
  document.getElementById('u_password').required = true;

  if (u && u.id) {
    document.getElementById('userModalTitle').textContent = 'ویرایش کاربر';
    document.getElementById('u_id').value = u.id;
    document.getElementById('u_full_name').value = u.full_name;
    document.getElementById('u_username').value = u.username;
    document.getElementById('u_role').value = u.role;
    document.getElementById('u_is_active').checked = !!parseInt(u.is_active);
    document.getElementById('pwHint').textContent = '(خالی بگذارید تا تغییر نکند)';
    document.getElementById('u_password').required = false;
  } else {
    document.getElementById('userModalTitle').textContent = 'کاربر جدید';
  }
}

document.getElementById('userForm').addEventListener('submit', function (e) {
  e.preventDefault();
  const fd = new FormData(this);
  fd.append('csrf_token', window.CSRF_TOKEN);
  fd.append('is_active', document.getElementById('u_is_active').checked ? '1' : '0');
  fetch('ajax/user_save.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.success) location.reload();
      else document.getElementById('userFormError').textContent = data.message;
    });
});

function deleteUser(id, name) {
  if (!confirm('آیا از حذف کاربر «' + name + '» مطمئن هستید؟')) return;
  const fd = new FormData();
  fd.append('id', id);
  fd.append('csrf_token', window.CSRF_TOKEN);
  fetch('ajax/user_delete.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.success) location.reload();
      else alert(data.message);
    });
}
