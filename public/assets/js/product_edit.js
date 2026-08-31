(function () {
  'use strict';

  const productData = window.PRODUCT_DATA;
  const variationsData = window.VARIATIONS_DATA || [];
  const globalAttributes = window.GLOBAL_ATTRIBUTES || [];

  // ---------------------------------------------------------------
  // Type toggle (simple vs variable)
  // ---------------------------------------------------------------
  function updateTypeUI() {
    const isVariable = document.getElementById('type_variable').checked;
    document.getElementById('simplePricing').style.display = isVariable ? 'none' : '';
    document.getElementById('variationsCard').style.display = isVariable ? '' : 'none';
  }
  document.getElementById('type_simple').addEventListener('change', updateTypeUI);
  document.getElementById('type_variable').addEventListener('change', updateTypeUI);
  updateTypeUI();

  document.getElementById('f_manage_stock').addEventListener('change', function () {
    document.getElementById('stockQtyWrap').style.display = this.checked ? '' : 'none';
  });
  document.getElementById('stockQtyWrap').style.display = document.getElementById('f_manage_stock').checked ? '' : 'none';

  // ---------------------------------------------------------------
  // Image gallery
  // ---------------------------------------------------------------
  let images = []; // [{id?, src, name}]
  const galleryWrap = document.getElementById('galleryWrap');
  const galleryFileInput = document.getElementById('galleryFileInput');

  function renderGallery() {
    galleryWrap.innerHTML = '';
    images.forEach((img, idx) => {
      const div = document.createElement('div');
      div.className = 'gallery-item';
      div.dataset.index = idx;
      div.innerHTML = `
        ${idx === 0 ? '<span class="featured-badge">شاخص</span>' : ''}
        <img src="${img.src}">
        <button type="button" class="remove-btn" data-idx="${idx}">×</button>
      `;
      galleryWrap.appendChild(div);
    });
    const addBtn = document.createElement('div');
    addBtn.className = 'gallery-add';
    addBtn.id = 'galleryAddBtn';
    addBtn.textContent = '+';
    galleryWrap.appendChild(addBtn);
    addBtn.addEventListener('click', () => galleryFileInput.click());

    galleryWrap.querySelectorAll('.remove-btn').forEach(btn => {
      btn.addEventListener('click', function () {
        images.splice(parseInt(this.dataset.idx, 10), 1);
        renderGallery();
      });
    });

    if (window.Sortable) {
      Sortable.create(galleryWrap, {
        animation: 150,
        filter: '#galleryAddBtn, .remove-btn',
        onEnd: function () {
          const newOrder = [];
          galleryWrap.querySelectorAll('.gallery-item').forEach(el => {
            newOrder.push(images[parseInt(el.dataset.index, 10)]);
          });
          images = newOrder;
          renderGallery();
        }
      });
    }
  }

  galleryFileInput.addEventListener('change', function () {
    Array.from(this.files).forEach(file => uploadImage(file));
    this.value = '';
  });

  function uploadImage(file) {
    const fd = new FormData();
    fd.append('image', file);
    fd.append('csrf_token', window.CSRF_TOKEN);
    return fetch('ajax/upload.php', { method: 'POST', body: fd })
      .then(r => r.json())
      .then(data => {
        if (data.success) {
          images.push({ src: data.url, name: data.name });
          renderGallery();
        } else {
          alert('خطا در آپلود تصویر: ' + data.message);
        }
        return data;
      });
  }

  if (productData && productData.images) {
    images = productData.images.map(i => ({ id: i.id, src: i.src, name: i.name }));
  }
  renderGallery();

  // ---------------------------------------------------------------
  // Attributes builder
  // ---------------------------------------------------------------
  const attributesWrap = document.getElementById('attributesWrap');
  const attrTemplate = document.getElementById('attrRowTemplate');
  let attrIndex = 0;

  function addAttributeRow(prefill) {
    const clone = attrTemplate.content.cloneNode(true);
    const rowEl = clone.querySelector('.attr-row');
    rowEl.dataset.index = attrIndex++;

    const select = rowEl.querySelector('.attr-select');
    const customInput = rowEl.querySelector('.attr-custom-name');
    const valuesInput = rowEl.querySelector('.attr-values');
    const usedCheckbox = rowEl.querySelector('.attr-used-for-variation');
    const removeBtn = rowEl.querySelector('.remove-attr-btn');

    select.addEventListener('change', function () {
      customInput.classList.toggle('d-none', this.value !== 'custom');
    });
    removeBtn.addEventListener('click', function () {
      rowEl.remove();
    });

    if (prefill) {
      if (prefill.id && prefill.id > 0) {
        select.value = String(prefill.id);
      } else {
        select.value = 'custom';
        customInput.classList.remove('d-none');
        customInput.value = prefill.name || '';
      }
      valuesInput.value = (prefill.options || []).join(', ');
      usedCheckbox.checked = !!prefill.variation;
    }
    customInput.classList.toggle('d-none', select.value !== 'custom');

    attributesWrap.appendChild(rowEl);
  }

  document.getElementById('addAttrBtn').addEventListener('click', () => addAttributeRow());

  if (productData && productData.attributes && productData.attributes.length) {
    productData.attributes.forEach(a => addAttributeRow(a));
  }

  function collectAttributes() {
    const rows = attributesWrap.querySelectorAll('.attr-row');
    const result = [];
    rows.forEach(row => {
      const select = row.querySelector('.attr-select');
      const customInput = row.querySelector('.attr-custom-name');
      const valuesInput = row.querySelector('.attr-values');
      const usedCheckbox = row.querySelector('.attr-used-for-variation');

      const options = valuesInput.value.split(',').map(v => v.trim()).filter(Boolean);
      if (!options.length) return;

      if (select.value === 'custom') {
        const name = customInput.value.trim();
        if (!name) return;
        result.push({ id: 0, name: name, options: options, variation: usedCheckbox.checked, visible: true });
      } else {
        result.push({ id: parseInt(select.value, 10), options: options, variation: usedCheckbox.checked, visible: true });
      }
    });
    return result;
  }

  // ---------------------------------------------------------------
  // Categories
  // ---------------------------------------------------------------
  function collectCategories() {
    return Array.from(document.querySelectorAll('.cat-checkbox:checked')).map(cb => ({ id: parseInt(cb.value, 10) }));
  }

  // ---------------------------------------------------------------
  // Save product (create / update)
  // ---------------------------------------------------------------
  document.getElementById('productForm').addEventListener('submit', function (e) {
    e.preventDefault();
    const btn = document.getElementById('saveProductBtn');
    const msgEl = document.getElementById('saveResultMsg');
    btn.disabled = true;
    msgEl.textContent = 'در حال ذخیره...';
    msgEl.className = 'small text-muted';

    const type = document.querySelector('input[name="f_type"]:checked').value;

    const payload = {
      id: window.PRODUCT_ID || 0,
      name: document.getElementById('f_name').value.trim(),
      sku: document.getElementById('f_sku').value.trim(),
      status: document.getElementById('f_status').value,
      short_description: document.getElementById('f_short_description').value,
      description: document.getElementById('f_description').value,
      type: type,
      categories: collectCategories(),
      attributes: collectAttributes(),
      
      // 👈 اصلاح شد: ارسال هوشمند تصویر همراه با ID (در صورت وجود)
      images: images.map(i => i.id ? { id: i.id, src: i.src } : { src: i.src }),
      
      meta_data: [
        {
          key: '_bajistyle_product_video_id',
          // Attachment ID is integer
          value: parseInt(document.getElementById('f_video_url')?.value?.trim() || '0', 10) || null
        }
      ]
    };

    if (type === 'simple') {
      payload.regular_price = document.getElementById('f_regular_price').value;
      payload.sale_price = document.getElementById('f_sale_price').value;
      payload.stock_status = document.getElementById('f_stock_status').value;
      payload.manage_stock = document.getElementById('f_manage_stock').checked;
      payload.stock_quantity = document.getElementById('f_stock_quantity').value;
    }

    fetch('ajax/product_save.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN },
      body: JSON.stringify(payload)
    })
      .then(r => r.json())
      .then(data => {
        if (data.success) {
          msgEl.textContent = '✅ محصول با موفقیت ذخیره شد.';
          msgEl.className = 'small text-success';
          if (!window.IS_EDIT || window.PRODUCT_ID !== data.product.id) {
            window.location.href = 'product_edit.php?id=' + data.product.id + '&saved=1';
          } else if (type === 'variable') {
            window.location.reload();
          }
        } else {
          msgEl.textContent = '❌ ' + data.message;
          msgEl.className = 'small text-danger';
        }
      })
      .catch(() => {
        msgEl.textContent = '❌ خطای غیرمنتظره در ارتباط با سرور.';
        msgEl.className = 'small text-danger';
      })
      .finally(() => { btn.disabled = false; });
  });
  // ---------------------------------------------------------------
  // Variations
  // ---------------------------------------------------------------
  const variationsWrap = document.getElementById('variationsWrap');

  function renderVariations(list) {
    if (!variationsWrap) return;
    if (!list.length) {
      variationsWrap.innerHTML = '<p class="text-muted small mb-0">هنوز تنوعی ساخته نشده. ابتدا ویژگی‌ها را با گزینه «استفاده برای تنوع» تنظیم و محصول را ذخیره کنید، سپس روی «تولید ترکیب‌های جدید» بزنید.</p>';
      return;
    }
    let html = '<div class="table-responsive"><table class="table table-sm align-middle variation-table"><thead><tr>' +
      '<th>تصویر</th><th>ترکیب</th><th>SKU</th><th>قیمت اصلی</th><th>قیمت حراج</th><th>موجودی</th><th>فعال</th><th></th></tr></thead><tbody>';

    list.forEach(v => {
      const attrsText = (v.attributes || []).map(a => a.option).join(' / ');
      const hasImage = !!(v.image && v.image.src);
      const img = hasImage ? v.image.src : 'https://placehold.co/60x60?text=%20';
      html += `<tr class="variation-row" data-id="${v.id}">
        <td>
          <img src="${img}" class="var-thumb" onclick="document.getElementById('varfile_${v.id}').click()">
          <input type="file" id="varfile_${v.id}" class="d-none" accept="image/*">
          <input type="hidden" class="var-image-url" value="${hasImage ? v.image.src : ''}">
        </td>
        <td class="small">${attrsText}</td>
        <td><input type="text" class="form-control form-control-sm var-sku" dir="ltr" value="${v.sku || ''}"></td>
        <td><input type="number" class="form-control form-control-sm var-regular-price" value="${v.regular_price || ''}"></td>
        <td><input type="number" class="form-control form-control-sm var-sale-price" value="${v.sale_price || ''}"></td>
        <td><input type="number" class="form-control form-control-sm var-stock" value="${v.stock_quantity ?? ''}" style="width:80px"></td>
        <td class="text-center"><input type="checkbox" class="form-check-input var-enabled" ${v.status === 'publish' ? 'checked' : ''}></td>
        <td class="text-nowrap">
          <button type="button" class="btn btn-sm btn-success save-var-btn">ذخیره</button>
          <button type="button" class="btn btn-sm btn-outline-danger del-var-btn">حذف</button>
        </td>
      </tr>`;
    });
    html += '</tbody></table></div>';
    variationsWrap.innerHTML = html;

    variationsWrap.querySelectorAll('.variation-row').forEach(row => {
      const vid = row.dataset.id;
      const fileInput = row.querySelector(`#varfile_${vid}`);
      fileInput.addEventListener('change', function () {
        const file = this.files[0];
        if (!file) return;
        const fd = new FormData();
        fd.append('image', file);
        fd.append('csrf_token', window.CSRF_TOKEN);
        fetch('ajax/upload.php', { method: 'POST', body: fd })
          .then(r => r.json())
          .then(data => {
            if (data.success) {
              row.querySelector('.var-image-url').value = data.url;
              row.querySelector('.var-thumb').src = data.url;
            } else {
              alert(data.message);
            }
          });
      });

      row.querySelector('.save-var-btn').addEventListener('click', function () {
        this.disabled = true;
        const fd = new FormData();
        fd.append('csrf_token', window.CSRF_TOKEN);
        fd.append('product_id', window.PRODUCT_ID);
        fd.append('variation_id', vid);
        fd.append('sku', row.querySelector('.var-sku').value);
        fd.append('regular_price', row.querySelector('.var-regular-price').value);
        fd.append('sale_price', row.querySelector('.var-sale-price').value);
        fd.append('stock_quantity', row.querySelector('.var-stock').value);
        fd.append('image_url', row.querySelector('.var-image-url').value);
        fd.append('enabled', row.querySelector('.var-enabled').checked ? '1' : '0');
        fetch('ajax/variation_save.php', { method: 'POST', body: fd })
          .then(r => r.json())
          .then(data => {
            if (!data.success) alert(data.message);
          })
          .finally(() => { this.disabled = false; });
      });

      row.querySelector('.del-var-btn').addEventListener('click', function () {
        if (!confirm('این تنوع حذف شود؟')) return;
        const fd = new FormData();
        fd.append('csrf_token', window.CSRF_TOKEN);
        fd.append('product_id', window.PRODUCT_ID);
        fd.append('variation_id', vid);
        fetch('ajax/variation_delete.php', { method: 'POST', body: fd })
          .then(r => r.json())
          .then(data => {
            if (data.success) row.remove();
            else alert(data.message);
          });
      });
    });
  }

  renderVariations(variationsData);

  const genBtn = document.getElementById('genVariationsBtn');
  if (genBtn) {
    genBtn.addEventListener('click', function () {
      if (!window.PRODUCT_ID) {
        alert('ابتدا محصول را ذخیره کنید.');
        return;
      }
      this.disabled = true;
      const fd = new FormData();
      fd.append('csrf_token', window.CSRF_TOKEN);
      fd.append('product_id', window.PRODUCT_ID);
      fd.append('attributes', JSON.stringify(collectAttributes()));
      fetch('ajax/variation_generate.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            renderVariations(data.variations);
          } else {
            alert(data.message);
          }
        })
        .finally(() => { this.disabled = false; });
    });
  }
})();


// ---------------------------------------------------------------
  // Video upload handler - Upload to WP Media Library via REST API
  // ---------------------------------------------------------------
  const videoFileInput = document.getElementById('videoFileInput');
  const uploadVideoBtn = document.getElementById('uploadVideoBtn');
  const videoUrlInput = document.getElementById('f_video_url');
  const videoMsg = document.getElementById('videoUploadMsg');

  if (uploadVideoBtn && videoFileInput) {
    // وقتی روی دکمه کلیک شد، پنجره انتخاب فایل باز شود
    uploadVideoBtn.addEventListener('click', () => videoFileInput.click());

    // به محض اینکه کاربر فایل ویدیو را انتخاب کرد
    videoFileInput.addEventListener('change', function () {
      const file = this.files[0];
      if (!file) return;

      // غیرفعال کردن دکمه در زمان آپلود برای جلوگیری از کلیک مجدد
      uploadVideoBtn.disabled = true;
      videoMsg.textContent = 'در حال آپلود ویدیو به کتابخانه رسانه وردپرس...';
      videoMsg.className = 'small text-muted';

      const fd = new FormData();
      fd.append('file', file);
      // WP REST API media endpoint expects the file in 'file' field
      // Use WP Application Password auth via WC Manager backend
      fd.append('csrf_token', window.CSRF_TOKEN);

      // Upload to WP Media Library via WC Manager backend endpoint
      fetch('ajax/upload_video.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            // Save WP Media Library attachment ID (not local URL)
            videoUrlInput.value = data.attachment_id;
            videoMsg.textContent = '✅ ویدیو با موفقیت آپلود و در کتابخانه رسانه ذخیره شد.';
            videoMsg.className = 'small text-success';
          } else {
            alert('خطا در آپلود ویدیو: ' + data.message);
            videoMsg.textContent = '❌ خطا در آپلود ویدیو.';
            videoMsg.className = 'small text-danger';
          }
        })
        .catch(() => {
          videoMsg.textContent = '❌ خطای غیرمنتظره در آپلود ویدیو.';
          videoMsg.className = 'small text-danger';
        })
        .finally(() => {
          uploadVideoBtn.disabled = false;
          videoFileInput.value = ''; // خالی کردن اینپوت برای آپلودهای بعدی
        });
    });
  }


  // ---------------------------------------------------------------
  // مدیریت تغییر دسته‌جمعی تنوع‌ها (Bulk Edit)
  // ---------------------------------------------------------------
  
  // ۱. باز و بسته کردن پنل تغییر دسته‌جمعی
  document.getElementById('toggleBulkBtn')?.addEventListener('click', function() {
    const section = document.getElementById('bulkEditSection');
    if (section) {
      section.style.display = section.style.display === 'none' ? 'block' : 'none';
    }
  });

  // ۲. فرآیند اعمال مقادیر روی تمام اینپوت‌های موجود در صفحه
  document.getElementById('applyBulkBtn')?.addEventListener('click', function() {
    const bulkRegular = document.getElementById('bulk_regular_price').value.trim();
    const bulkSale = document.getElementById('bulk_sale_price').value.trim();
    const bulkStock = document.getElementById('bulk_stock_qty').value.trim();
    
    const wrap = document.getElementById('variationsWrap');
    if (!wrap) return;

    let appliedCount = 0;

    // اعمال قیمت اصلی (تغییر کلاس .var-regular-price بر اساس پروژه شما)
    if (bulkRegular !== '') {
      wrap.querySelectorAll('.var-regular-price').forEach(input => {
        input.value = bulkRegular;
        input.dispatchEvent(new Event('input', { bubbles: true })); // تریگر کردن رویداد برای تغییرات احتمالی آرایه‌ها
      });
      appliedCount++;
    }

    // اعمال قیمت حراج (تغییر کلاس .var-sale-price بر اساس پروژه شما)
    if (bulkSale !== '') {
      wrap.querySelectorAll('.var-sale-price').forEach(input => {
        input.value = bulkSale;
        input.dispatchEvent(new Event('input', { bubbles: true }));
      });
      appliedCount++;
    }

    // اعمال تعداد موجودی (تغییر کلاس .var-stock-qty بر اساس پروژه شما)
    if (bulkStock !== '') {
      wrap.querySelectorAll('.var-stock').forEach(input => {
        input.value = bulkStock;
        input.dispatchEvent(new Event('input', { bubbles: true }));
      });
      appliedCount++;
    }

    // اگر متغیرها را در یک آرایه جاوااسکریپتی سراسری (مثل دیتای تصاویر) ذخیره می‌کنید:
    // در صورتی که تابع کلکتور فرم شما مستقیم از روی اینپوت‌های DOM مقادیر را می‌خواند، کد بالا کافیست.
    // اما اگر آرایه‌ای به نام مثلا window.variations دارید، باید اینجا آن را هم آپدیت کنید.

    if (appliedCount > 0) {
      alert('⚡ تغییرات با موفقیت روی تمام تنوع‌ها اعمال شد. برای نهایی شدن، فرم محصول را ذخیره کنید.');
      // خالی کردن فرم تغییر دسته‌جمعی پس از اعمال موفقیت‌آمیز
      document.getElementById('bulk_regular_price').value = '';
      document.getElementById('bulk_sale_price').value = '';
      document.getElementById('bulk_stock_qty').value = '';
      document.getElementById('bulk_edit_section').style.display = 'none';
    } else {
      alert('لطفاً ابتدا حداقل یکی از فیلدها را پر کنید.');
    }
  });




  document.getElementById('saveAllVariationsBtn')?.addEventListener('click', function () {
    const wrap = document.getElementById('variationsWrap');
    if (!wrap) return;
  
    const btn = this;
    btn.disabled = true;
    const originalText = btn.textContent;
    btn.textContent = '⏳ در حال ذخیره گروهی...';
  
    const updatePayload = [];
  
    wrap.querySelectorAll('.variation-row').forEach(row => {
      const varId = row.dataset.id;
      if (!varId) return;
  
      const regularPrice = row.querySelector('.var-regular-price')?.value.trim() || '';
      const salePrice = row.querySelector('.var-sale-price')?.value.trim() || '';
      const stockStatus = row.querySelector('.var-stock-status')?.value || 'instock';
      
      // 👈 اصلاح شد: استفاده از کلاس واقعی شما یعنی var-stock
      const stockQtyRaw = row.querySelector('.var-stock')?.value.trim(); 
  
      const hasStockValue = stockQtyRaw !== undefined && stockQtyRaw !== '';
      const stockQty = hasStockValue ? parseInt(stockQtyRaw, 10) : 0;
  
      updatePayload.push({
        id: parseInt(varId, 10),
        regular_price: regularPrice,
        sale_price: salePrice,
        manage_stock: true, 
        stock_quantity: stockQty,
        stock_status: stockQty > 0 ? 'instock' : stockStatus
      });
    });
  
    if (updatePayload.length === 0) {
      alert('تنوعی برای ذخیره‌سازی یافت نشد.');
      btn.disabled = false;
      btn.textContent = originalText;
      return;
    }
  
    fetch('ajax/variations_save_batch.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN },
      body: JSON.stringify({
        product_id: window.PRODUCT_ID,
        update: updatePayload
      })
    })
      .then(r => r.json())
      .then(data => {
        if (data.success) {
          alert('✅ تمام تنوع‌ها با موفقیت و به صورت یکجا ذخیره شدند.');
          window.location.reload();
        } else {
          alert('❌ خطا در ذخیره گروهی: ' + data.message);
        }
      })
      .catch(() => alert('❌ خطای غیرمنتظره در ارتباط با سرور.'))
      .finally(() => {
        btn.disabled = false;
        btn.textContent = originalText;
      });
  });




  function showNotification(message, type = 'success') {
    const container = document.getElementById('toastContainer');
    const toast = document.createElement('div');
    toast.className = `toast align-items-center text-white bg-${type} border-0 show`;
    toast.innerHTML = `
        <div class="d-flex">
            <div class="toast-body">${message}</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>`;
    container.appendChild(toast);
    setTimeout(() => toast.remove(), 4000); // حذف خودکار بعد از ۴ ثانیه
}