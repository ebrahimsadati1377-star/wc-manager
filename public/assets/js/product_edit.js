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
  // BAJI Professional Product Workflow V2
  // input -> analysis -> 7 images -> technical QC -> SEO -> preview -> publish
  // ---------------------------------------------------------------
  const aiRawInput = document.getElementById('aiRawProductImage');
  const aiFaceInput = document.getElementById('aiFaceReferenceImage');
  const aiStatus = document.getElementById('aiBuildStatus');
  const aiProgress = document.getElementById('aiWorkflowProgress');
  const aiProviderBadge = document.getElementById('aiProviderBadge');
  const aiRunBtn = document.getElementById('aiWorkflowRunBtn');
  const aiResetBtn = document.getElementById('aiWorkflowResetBtn');
  const aiAdoptBtn = document.getElementById('aiAdoptGalleryBtn');
  const aiAnalyzeBtn = document.getElementById('aiAnalyzeBtn');
  const aiImagesBtn = document.getElementById('aiImagesBtn');
  const aiQcBtn = document.getElementById('aiQcBtn');
  const aiSeoBtn = document.getElementById('aiSeoBtn');
  const aiPreviewBtn = document.getElementById('aiPreviewBtn');
  const aiPreviewBox = document.getElementById('aiWorkflowPreview');

  let workflowJobId = null;
  let workflowBusy = false;
  const workflowStorageKey = 'baji_product_workflow_v2_' + (window.PRODUCT_ID || 'new');

  async function aiJson(url, payload) {
    const response = await fetch(url, {
      method: 'POST',
      headers: {'Content-Type':'application/json','X-CSRF-Token':window.CSRF_TOKEN},
      body: JSON.stringify(payload || {})
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || !data.success) throw new Error(data.message || 'خطای Workflow');
    return data;
  }

  async function aiJsonRetry(url, payload, attempts = 2) {
    let last;
    for (let i = 1; i <= attempts; i++) {
      try { return await aiJson(url, payload); }
      catch (e) { last = e; if (i < attempts) await new Promise(r => setTimeout(r, 1200)); }
    }
    throw last;
  }

  async function uploadRawProductImage(file) {
    const fd = new FormData();
    fd.append('image', file);
    fd.append('csrf_token', window.CSRF_TOKEN);
    const response = await fetch('ajax/upload.php', {method:'POST',body:fd});
    const data = await response.json();
    if (!response.ok || !data.success) throw new Error(data.message || 'آپلود عکس ناموفق بود.');
    return data.url;
  }

  function workflowStatus(message, percent = null, type = 'muted') {
    if (aiStatus) {
      aiStatus.textContent = message;
      aiStatus.className = 'small mb-2 text-' + type;
    }
    if (aiProgress && percent !== null) {
      aiProgress.style.width = Math.max(0, Math.min(100, Number(percent) || 0)) + '%';
    }
  }

  function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, ch => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'
    })[ch]);
  }

  function setWorkflowBusy(busy) {
    workflowBusy = busy;
    [aiRunBtn, aiResetBtn, aiAdoptBtn, aiAnalyzeBtn, aiImagesBtn, aiQcBtn, aiSeoBtn, aiPreviewBtn]
      .filter(Boolean).forEach(btn => { btn.disabled = busy; });
  }

  function enableStageButtons(state) {
    if (workflowBusy) return;
    [aiAnalyzeBtn, aiImagesBtn, aiQcBtn, aiSeoBtn, aiPreviewBtn].filter(Boolean).forEach(btn => btn.disabled = true);
    if (!workflowJobId || state === 'published') return;
    aiAnalyzeBtn.disabled = false;
    if (['analyzed','images_generating','images_ready','needs_review','qc_passed','seo_ready','preview_ready'].includes(state)) aiImagesBtn.disabled = false;
    if (['images_ready','needs_review','qc_passed','seo_ready','preview_ready'].includes(state)) aiQcBtn.disabled = false;
    if (['qc_passed','seo_ready','preview_ready'].includes(state)) aiSeoBtn.disabled = false;
    if (['seo_ready','preview_ready'].includes(state)) aiPreviewBtn.disabled = false;
  }

  function applyAiAnalysis(a, attrs) {
    document.getElementById('f_name').value = a.name || document.getElementById('f_name').value;
    document.getElementById('f_short_description').value = a.short_description || '';
    document.getElementById('f_description').value = a.description || '';
    document.getElementById('f_seo_title').value = a.seo_title || '';
    document.getElementById('f_meta_description').value = a.meta_description || '';
    document.getElementById('f_focus_keyword').value = a.focus_keyword || '';
    if (Array.isArray(a.category_ids) && a.category_ids.length) {
      document.querySelectorAll('.cat-checkbox').forEach(cb => {
        cb.checked = a.category_ids.includes(parseInt(cb.value, 10));
      });
    }
    document.getElementById('attributesWrap').innerHTML = '';
    if (Array.isArray(attrs)) attrs.forEach(attr => addAttributeRow(attr));
  }

  async function runPreflight() {
    try {
      const result = await aiJson('ajax/product_workflow_preflight.php', {
        regular_price: document.getElementById('f_regular_price').value.trim(),
        sale_price: document.getElementById('f_sale_price').value.trim()
      });
      const p = result.preflight || {};
      const provider = p.provider || {};
      const selected = provider.selected || 'arena';
      if (aiProviderBadge) aiProviderBadge.textContent = selected.toUpperCase();

      const q = p.face_reference_quality || {};
      if (q.reference_pass === false && q.width) {
        workflowStatus('هشدار: چهره مرجع BAJI کم‌کیفیت است (' + q.width + '×' + q.height + '). بهتر است نسخه اصلی و باکیفیت جایگزین شود.', 0, 'warning');
        return;
      }

      if (provider.available && !provider.available[selected]) {
        workflowStatus(
          selected.toUpperCase() + ' هنوز آماده نیست. فعلاً ۷ عکس ساخته‌شده در ChatGPT را در گالری بگذار و «استفاده از ۷ عکس فعلی گالری» را بزن.',
          0,
          'warning'
        );
        return;
      }
      workflowStatus('سیستم آماده اجرای Workflow است.', 0, 'success');
    } catch (e) {
      workflowStatus('Preflight: ' + e.message, 0, 'danger');
    }
  }

  async function startWorkflow() {
    const file = aiRawInput?.files?.[0] || null;
    if (!file) throw new Error('اول عکس خام محصول را انتخاب کن.');
    if (!document.getElementById('type_simple').checked) throw new Error('Workflow حرفه‌ای فعلاً برای محصول ساده فعال است.');

    const regular = document.getElementById('f_regular_price').value.trim();
    if (!regular) throw new Error('قیمت اصلی را وارد کن.');

    workflowStatus('آپلود عکس خام و ساخت Job...', 5, 'primary');
    const rawUrl = await uploadRawProductImage(file);
    const faceFile = aiFaceInput?.files?.[0] || null;
    const faceUrl = faceFile ? await uploadRawProductImage(faceFile) : '';

    const result = await aiJson('ajax/product_workflow_start.php', {
      product_id: window.PRODUCT_ID || 0,
      product_name: document.getElementById('f_name').value.trim() || 'محصول جدید باجی',
      raw_product_image_url: rawUrl,
      face_reference_url: faceUrl,
      regular_price: regular,
      sale_price: document.getElementById('f_sale_price').value.trim(),
      stock_quantity: document.getElementById('f_manage_stock').checked
        ? document.getElementById('f_stock_quantity').value.trim() : '',
      notes: document.getElementById('aiProductNotes').value.trim(),
      category_ids: collectCategories().map(c => c.id),
      short_description: document.getElementById('f_short_description').value,
      description: document.getElementById('f_description').value
    });

    workflowJobId = parseInt(result.job.id, 10);
    sessionStorage.setItem(workflowStorageKey, String(workflowJobId));
    enableStageButtons('draft_input');
    workflowStatus('Job ساخته شد. آماده تحلیل.', 8, 'primary');
    return workflowJobId;
  }

  async function ensureWorkflowStarted() {
    if (!workflowJobId) return startWorkflow();
    return workflowJobId;
  }

  async function analyzeWorkflow() {
    await ensureWorkflowStarted();
    workflowStatus('۱/۵ — تحلیل محصول و استخراج اطلاعات واقعی...', 15, 'primary');
    const result = await aiJson('ajax/product_workflow_analyze.php', {job_id: workflowJobId});
    applyAiAnalysis(result.analysis || {}, result.attributes || []);
    if (result.analysis?.analysis_warning) {
      workflowStatus(result.analysis.analysis_warning, 20, 'warning');
    } else {
      workflowStatus('تحلیل کامل شد؛ مشخصات نامعلوم حدس زده نمی‌شوند.', 20, 'success');
    }
    enableStageButtons('analyzed');
    return result.analysis;
  }

  async function generateWorkflowImages() {
    await ensureWorkflowStarted();
    images = [];
    renderGallery();

    for (let i = 1; i <= 7; i++) {
      workflowStatus('۲/۵ — ساخت عکس ' + i + ' از ۷...', 20 + i * 5, 'primary');
      const made = await aiJsonRetry('ajax/product_workflow_generate_image.php', {
        job_id: workflowJobId,
        index: i
      }, 2);
      if (!made.image?.id || !made.image?.src) throw new Error('تصویر ' + i + ' کامل ثبت نشد.');
      images.push({id: made.image.id, src: made.image.src, name: 'BAJI ' + i});
      renderGallery();
    }

    workflowStatus('هر ۷ عکس مستقل آماده و در WordPress ثبت شد.', 55, 'success');
    enableStageButtons('images_ready');
  }

  async function adoptCurrentGallery() {
    await ensureWorkflowStarted();
    if (images.length !== 7) throw new Error('گالری باید دقیقاً ۷ تصویر داشته باشد.');
    workflowStatus('ثبت ۷ عکس فعلی گالری در Workflow...', 45, 'primary');
    await aiJson('ajax/product_workflow_adopt_gallery.php', {
      job_id: workflowJobId,
      images: images.map(i => ({id: i.id || 0, src: i.src || ''}))
    });

    const state = await aiJson('ajax/product_workflow_status.php', {job_id: workflowJobId});
    images = (state.images || []).map(row => ({
      id: parseInt(row.wordpress_media_id, 10),
      src: row.public_url,
      name: 'BAJI ' + row.image_index
    }));
    renderGallery();
    workflowStatus('۷ عکس گالری وارد Workflow شد.', 55, 'success');
    enableStageButtons('images_ready');
  }

  function renderQcFailures(qc) {
    const failed = (qc.checks || []).filter(x => !x.technical_pass);
    if (!failed.length) return;

    aiPreviewBox.classList.remove('d-none');
    aiPreviewBox.innerHTML =
      '<div class="alert alert-warning p-2 mb-2">QC فنی رد شد؛ فقط عکس‌های مشکل‌دار را بازسازی کن.</div>' +
      failed.map(check => {
        const idx = parseInt(check.image_index, 10);
        const img = images[idx - 1];
        return '<div class="border rounded p-2 mb-2 bg-white">' +
          (img ? '<img src="' + escapeHtml(img.src) + '" class="w-100 rounded mb-2" style="aspect-ratio:9/16;object-fit:cover">' : '') +
          '<div class="small text-danger mb-1">تصویر ' + idx + ': ' + escapeHtml(check.reason || 'استاندارد فنی رد شد') + '</div>' +
          '<button type="button" class="btn btn-sm btn-outline-danger w-100 aiRetryImageBtn" data-index="' + idx + '">بازسازی همین عکس</button>' +
          '</div>';
      }).join('');

    aiPreviewBox.querySelectorAll('.aiRetryImageBtn').forEach(btn => {
      btn.addEventListener('click', () => retryWorkflowImage(parseInt(btn.dataset.index, 10)));
    });
  }

  async function runWorkflowQc() {
    await ensureWorkflowStarted();
    workflowStatus('۳/۵ — کنترل ۹:۱۶، رزولوشن، حجم و تصاویر تکراری...', 62, 'primary');
    const result = await aiJson('ajax/product_workflow_qc.php', {job_id: workflowJobId});
    if (!result.qc?.all_technical_pass) {
      renderQcFailures(result.qc || {});
      enableStageButtons('needs_review');
      throw new Error('QC فنی رد شد. عکس‌های مشکل‌دار را بازسازی کن.');
    }
    workflowStatus('QC فنی هر ۷ تصویر پاس شد.', 68, 'success');
    enableStageButtons('qc_passed');
    return result.qc;
  }

  async function buildWorkflowSeo() {
    await ensureWorkflowStarted();
    workflowStatus('۴/۵ — ساخت SEO و متادیتای محصول...', 75, 'primary');
    const result = await aiJson('ajax/product_workflow_build_seo.php', {job_id: workflowJobId});
    const seo = result.seo || {};
    document.getElementById('f_name').value = seo.name || document.getElementById('f_name').value;
    document.getElementById('f_short_description').value = seo.short_description || '';
    document.getElementById('f_description').value = seo.description || '';
    document.getElementById('f_seo_title').value = seo.seo_title || '';
    document.getElementById('f_meta_description').value = seo.meta_description || '';
    document.getElementById('f_focus_keyword').value = seo.focus_keyword || '';
    workflowStatus('SEO آماده شد.', 80, 'success');
    enableStageButtons('seo_ready');
    return seo;
  }

  async function loadWorkflowPreview() {
    await ensureWorkflowStarted();
    workflowStatus('۵/۵ — ساخت پیش‌نمایش نهایی...', 88, 'primary');
    const result = await aiJson('ajax/product_workflow_preview.php', {job_id: workflowJobId});
    renderWorkflowPreview(result.preview || {});
    workflowStatus('پیش‌نمایش آماده است؛ هر ۷ عکس را بررسی کن.', 90, 'success');
    enableStageButtons('preview_ready');
    return result.preview;
  }

  function renderWorkflowPreview(p) {
    aiPreviewBox.classList.remove('d-none');
    const face = p.face_reference_quality || {};
    const faceWarning = face.reference_pass === false
      ? '<div class="alert alert-warning p-2 small">کیفیت چهره مرجع پایین است' +
        (face.width ? ' (' + face.width + '×' + face.height + ')' : '') +
        '. برای محصولات بعدی نسخه اصلی چهره BAJI را جایگزین کن.</div>'
      : '';

    const cards = (p.images || []).map(img => {
      const idx = parseInt(img.image_index, 10);
      return '<div class="col-6 mb-2"><div class="border rounded p-1 bg-white">' +
        '<img src="' + escapeHtml(img.public_url) + '" class="w-100 rounded" style="aspect-ratio:9/16;object-fit:cover">' +
        '<div class="d-flex justify-content-between align-items-center mt-1">' +
        '<small>#' + idx + ' • QC ' + escapeHtml(img.qc_score || '-') + '</small>' +
        '<button type="button" class="btn btn-link btn-sm p-0 aiRetryImageBtn" data-index="' + idx + '">بازسازی</button>' +
        '</div></div></div>';
    }).join('');

    aiPreviewBox.innerHTML =
      faceWarning +
      '<div class="border rounded p-2 bg-white">' +
      '<div class="fw-bold mb-1">' + escapeHtml(p.name) + '</div>' +
      '<div class="small text-muted mb-2">قیمت اصلی: ' + escapeHtml(p.regular_price || '-') +
      ' • تخفیف: ' + escapeHtml(p.sale_price || '-') + '</div>' +
      '<div class="row g-1">' + cards + '</div>' +
      '<hr class="my-2">' +
      '<div class="small mb-1"><strong>SEO:</strong> ' + escapeHtml(p.seo_title || '') + '</div>' +
      '<div class="small text-muted mb-2">' + escapeHtml(p.meta_description || '') + '</div>' +
      '<div class="form-check mb-2">' +
      '<input class="form-check-input" type="checkbox" id="aiVisualApproval">' +
      '<label class="form-check-label small" for="aiVisualApproval">هر ۷ عکس، لباس، رنگ و چهره را بررسی و تأیید کردم.</label>' +
      '</div>' +
      '<button type="button" class="btn btn-success w-100" id="aiPublishApprovedBtn" disabled>تأیید و انتشار نهایی</button>' +
      '</div>';

    const approval = document.getElementById('aiVisualApproval');
    const publishBtn = document.getElementById('aiPublishApprovedBtn');
    approval.addEventListener('change', () => { publishBtn.disabled = !approval.checked; });
    publishBtn.addEventListener('click', publishWorkflow);

    aiPreviewBox.querySelectorAll('.aiRetryImageBtn').forEach(btn => {
      btn.addEventListener('click', () => retryWorkflowImage(parseInt(btn.dataset.index, 10)));
    });
  }

  async function retryWorkflowImage(index) {
    if (!workflowJobId) return;
    try {
      setWorkflowBusy(true);
      workflowStatus('بازسازی تصویر ' + index + '...', 58, 'primary');
      const result = await aiJson('ajax/product_workflow_generate_image.php', {
        job_id: workflowJobId,
        index: index,
        force: true
      });
      const pos = images.findIndex((_, i) => i + 1 === index);
      const item = {id: result.image.id, src: result.image.src, name: 'BAJI ' + index};
      if (pos >= 0) images[pos] = item;
      else images[index - 1] = item;
      images = images.filter(Boolean);
      renderGallery();
      aiPreviewBox.classList.add('d-none');
      await runWorkflowQc();
      await buildWorkflowSeo();
      await loadWorkflowPreview();
    } catch (e) {
      workflowStatus('بازسازی ناموفق: ' + e.message, null, 'danger');
    } finally {
      setWorkflowBusy(false);
      try {
        const state = await aiJson('ajax/product_workflow_status.php', {job_id: workflowJobId});
        enableStageButtons(state.job?.workflow_status || 'needs_review');
      } catch (_) {}
    }
  }

  async function publishWorkflow() {
    const btn = document.getElementById('aiPublishApprovedBtn');
    if (btn) btn.disabled = true;
    try {
      workflowStatus('در حال انتشار نهایی در WooCommerce...', 96, 'primary');
      const result = await aiJson('ajax/product_workflow_publish.php', {
        job_id: workflowJobId,
        approved: true
      });
      sessionStorage.removeItem(workflowStorageKey);
      workflowStatus('محصول با موفقیت منتشر شد.', 100, 'success');
      if (result.result?.permalink) {
        aiPreviewBox.insertAdjacentHTML(
          'beforeend',
          '<a class="btn btn-outline-success w-100 mt-2" target="_blank" rel="noopener" href="' +
          escapeHtml(result.result.permalink) + '">مشاهده محصول منتشرشده</a>'
        );
      }
    } catch (e) {
      workflowStatus('انتشار ناموفق: ' + e.message, null, 'danger');
      if (btn) btn.disabled = false;
    }
  }

  async function runWorkflowToPreview() {
    setWorkflowBusy(true);
    try {
      await ensureWorkflowStarted();
      await analyzeWorkflow();
      await generateWorkflowImages();
      await runWorkflowQc();
      await buildWorkflowSeo();
      await loadWorkflowPreview();
    } catch (e) {
      workflowStatus('Workflow متوقف شد: ' + e.message + ' — محصول منتشر نشد.', null, 'danger');
    } finally {
      setWorkflowBusy(false);
      if (workflowJobId) {
        try {
          const state = await aiJson('ajax/product_workflow_status.php', {job_id: workflowJobId});
          enableStageButtons(state.job?.workflow_status || 'draft_input');
        } catch (_) {
          enableStageButtons('draft_input');
        }
      }
    }
  }

  async function restoreWorkflowState() {
    const saved = parseInt(sessionStorage.getItem(workflowStorageKey) || '0', 10);
    if (!saved) return;
    try {
      workflowJobId = saved;
      const state = await aiJson('ajax/product_workflow_status.php', {job_id: saved});
      const jobState = state.job?.workflow_status || 'draft_input';

      if (jobState === 'published') {
        sessionStorage.removeItem(workflowStorageKey);
        workflowStatus('این Workflow قبلاً منتشر شده است.', 100, 'success');
        return;
      }

      const restored = (state.images || []).filter(x => x.wordpress_media_id && x.public_url);
      if (restored.length) {
        images = restored.map(x => ({
          id: parseInt(x.wordpress_media_id, 10),
          src: x.public_url,
          name: 'BAJI ' + x.image_index
        }));
        renderGallery();
      }

      enableStageButtons(jobState);
      workflowStatus('Workflow قبلی بازیابی شد: ' + jobState, state.job?.progress_percent || 0, 'primary');

      if (jobState === 'preview_ready') {
        await loadWorkflowPreview();
      }
    } catch (_) {
      workflowJobId = null;
      sessionStorage.removeItem(workflowStorageKey);
    }
  }

  aiRunBtn?.addEventListener('click', runWorkflowToPreview);
  aiAdoptBtn?.addEventListener('click', async () => {
    try {
      setWorkflowBusy(true);
      await adoptCurrentGallery();
      await runWorkflowQc();
      await buildWorkflowSeo();
      await loadWorkflowPreview();
    } catch (e) {
      workflowStatus('گالری وارد Workflow نشد: ' + e.message, null, 'danger');
    } finally {
      setWorkflowBusy(false);
      if (workflowJobId) {
        try {
          const state = await aiJson('ajax/product_workflow_status.php', {job_id: workflowJobId});
          enableStageButtons(state.job?.workflow_status || 'draft_input');
        } catch (_) {}
      }
    }
  });

  aiResetBtn?.addEventListener('click', () => {
    if (workflowBusy) return;
    sessionStorage.removeItem(workflowStorageKey);
    workflowJobId = null;
    aiPreviewBox.innerHTML = '';
    aiPreviewBox.classList.add('d-none');
    images = productData?.images ? productData.images.map(i => ({id:i.id,src:i.src,name:i.name})) : [];
    renderGallery();
    [aiAnalyzeBtn, aiImagesBtn, aiQcBtn, aiSeoBtn, aiPreviewBtn].filter(Boolean).forEach(btn => btn.disabled = true);
    workflowStatus('Workflow جدید آماده است.', 0, 'muted');
  });

  aiAnalyzeBtn?.addEventListener('click', async () => {
    try { await analyzeWorkflow(); } catch(e) { workflowStatus(e.message, null, 'danger'); }
  });
  aiImagesBtn?.addEventListener('click', async () => {
    try {
      setWorkflowBusy(true);
      await generateWorkflowImages();
    } catch(e) {
      workflowStatus(e.message, null, 'danger');
    } finally {
      setWorkflowBusy(false);
      if (workflowJobId) {
        try {
          const state = await aiJson('ajax/product_workflow_status.php', {job_id: workflowJobId});
          enableStageButtons(state.job?.workflow_status || 'draft_input');
        } catch (_) {
          enableStageButtons('draft_input');
        }
      }
    }
  });
  aiQcBtn?.addEventListener('click', async () => {
    try { await runWorkflowQc(); } catch(e) { workflowStatus(e.message, null, 'danger'); }
  });
  aiSeoBtn?.addEventListener('click', async () => {
    try { await buildWorkflowSeo(); } catch(e) { workflowStatus(e.message, null, 'danger'); }
  });
  aiPreviewBtn?.addEventListener('click', async () => {
    try { await loadWorkflowPreview(); } catch(e) { workflowStatus(e.message, null, 'danger'); }
  });

  setTimeout(async () => {
    await runPreflight();
    await restoreWorkflowState();
  }, 0);

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
        { key: '_bajistyle_product_video_id', value: parseInt(document.getElementById('f_video_url')?.value?.trim() || '0', 10) || null },
        { key: '_yoast_wpseo_title', value: document.getElementById('f_seo_title')?.value || '' },
        { key: '_yoast_wpseo_metadesc', value: document.getElementById('f_meta_description')?.value || '' },
        { key: '_yoast_wpseo_focuskw', value: document.getElementById('f_focus_keyword')?.value || '' },
        { key: 'rank_math_title', value: document.getElementById('f_seo_title')?.value || '' },
        { key: 'rank_math_description', value: document.getElementById('f_meta_description')?.value || '' },
        { key: 'rank_math_focus_keyword', value: document.getElementById('f_focus_keyword')?.value || '' }
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