(function () {
  'use strict';

  function normalizeText(value) {
    return String(value || '')
      .toLowerCase()
      .replace(/ي/g, 'ی')
      .replace(/ك/g, 'ک')
      .replace(/[\u200c\u200e\u200f]/g, ' ')
      .replace(/ـ/g, '')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function enhanceCategorySelect(select) {
    if (!select || select.dataset.searchEnhanced === '1') return;
    select.dataset.searchEnhanced = '1';

    const wrapper = document.createElement('div');
    wrapper.className = 'basalam-category-search';

    const inputGroup = document.createElement('div');
    inputGroup.className = 'input-group input-group-sm mb-2';

    const search = document.createElement('input');
    search.type = 'search';
    search.className = 'form-control';
    search.placeholder = 'جستجوی دسته باسلام؛ نام یا شناسه...';
    search.autocomplete = 'off';
    search.setAttribute('aria-label', 'جستجوی دسته‌بندی باسلام');

    const clearButton = document.createElement('button');
    clearButton.type = 'button';
    clearButton.className = 'btn btn-outline-secondary';
    clearButton.textContent = 'پاک کردن';
    clearButton.title = 'پاک کردن جستجو';

    const status = document.createElement('div');
    status.className = 'small text-muted mt-1';
    status.setAttribute('aria-live', 'polite');

    const parent = select.parentNode;
    parent.insertBefore(wrapper, select);
    wrapper.appendChild(inputGroup);
    inputGroup.appendChild(search);
    inputGroup.appendChild(clearButton);
    wrapper.appendChild(select);
    wrapper.appendChild(status);

    const options = Array.from(select.options).map(function (option) {
      const title = option.dataset.title || option.textContent || '';
      option.dataset.searchValue = normalizeText(title + ' ' + option.textContent + ' ' + option.value);
      return option;
    });

    function applyFilter() {
      const query = normalizeText(search.value);
      const terms = query ? query.split(' ').filter(Boolean) : [];
      let matches = 0;

      options.forEach(function (option, index) {
        if (index === 0) {
          option.hidden = terms.length > 0;
          return;
        }

        const haystack = option.dataset.searchValue || '';
        const matched = terms.length === 0 || terms.every(function (term) {
          return haystack.includes(term);
        });

        option.hidden = !matched;
        if (matched) matches += 1;
      });

      if (terms.length === 0) {
        status.textContent = 'برای پیدا کردن سریع دسته، بخشی از نام یا ID را بنویسید.';
      } else if (matches === 0) {
        status.textContent = 'دسته‌ای پیدا نشد.';
      } else {
        status.textContent = matches.toLocaleString('fa-IR') + ' دسته پیدا شد.';
      }
    }

    search.addEventListener('input', applyFilter);
    search.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        search.value = '';
        applyFilter();
      }
    });

    clearButton.addEventListener('click', function () {
      search.value = '';
      applyFilter();
      search.focus();
    });

    select.addEventListener('change', function () {
      search.value = '';
      applyFilter();
    });

    applyFilter();
  }

  function init() {
    document.querySelectorAll('.category-map-select').forEach(enhanceCategorySelect);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
