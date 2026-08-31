function openCatModal(cat) {
  document.getElementById('catForm').reset();
  document.getElementById('catFormError').textContent = '';
  document.getElementById('cat_image_preview').classList.add('d-none');
  document.getElementById('cat_image_url').value = '';
  document.getElementById('cat_id').value = '';

  if (cat && cat.id) {
    document.getElementById('catModalTitle').textContent = 'ویرایش دسته‌بندی';
    document.getElementById('cat_id').value = cat.id;
    document.getElementById('cat_name').value = cat.name || '';
    document.getElementById('cat_parent').value = cat.parent || 0;
    document.getElementById('cat_description').value = cat.description || '';
    if (cat.image && cat.image.src) {
      document.getElementById('cat_image_url').value = cat.image.src;
      const prev = document.getElementById('cat_image_preview');
      prev.src = cat.image.src;
      prev.classList.remove('d-none');
    }
  } else {
    document.getElementById('catModalTitle').textContent = 'دسته‌بندی جدید';
  }
}

document.getElementById('cat_image_file').addEventListener('change', function () {
  const file = this.files[0];
  if (!file) return;
  const fd = new FormData();
  fd.append('image', file);
  fd.append('csrf_token', window.CSRF_TOKEN);
  const preview = document.getElementById('cat_image_preview');
  preview.classList.remove('d-none');
  preview.src = URL.createObjectURL(file);

  fetch('ajax/upload.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        document.getElementById('cat_image_url').value = data.url;
        preview.src = data.url;
      } else {
        document.getElementById('catFormError').textContent = data.message;
      }
    });
});

document.getElementById('catForm').addEventListener('submit', function (e) {
  e.preventDefault();
  const btn = document.getElementById('catSubmitBtn');
  btn.disabled = true;
  const fd = new FormData(this);
  fd.append('csrf_token', window.CSRF_TOKEN);
  fetch('ajax/category_save.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        location.reload();
      } else {
        document.getElementById('catFormError').textContent = data.message;
        btn.disabled = false;
      }
    })
    .catch(() => { document.getElementById('catFormError').textContent = 'خطای غیرمنتظره'; btn.disabled = false; });
});

function deleteCat(id, name) {
  if (!confirm('آیا از حذف دسته‌بندی «' + name + '» مطمئن هستید؟')) return;
  const fd = new FormData();
  fd.append('id', id);
  fd.append('csrf_token', window.CSRF_TOKEN);
  fetch('ajax/category_delete.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.success) location.reload();
      else alert(data.message);
    });
}
