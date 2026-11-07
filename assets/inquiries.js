(() => {
  const config = window.SP49_CONTACT || {};
  const hasEndpoint = typeof config.endpoint === 'string' && (/^https:\/\//.test(config.endpoint) || (['localhost','127.0.0.1'].includes(location.hostname) && config.endpoint.startsWith(location.origin + '/')));
  const hasEmail = typeof config.email === 'string' && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(config.email);
  const dialog = document.getElementById('inquiry-dialog');
  const formHelp = hasEndpoint ? 'Запрос будет отправлен в отдел продаж. После отправки мы покажем номер заявки.' : hasEmail ? 'После нажатия откроется письмо в вашей почтовой программе. Проверьте его и отправьте.' : 'Отправка на почту ещё не подключена. Сейчас можно сохранить обращение файлом.';
  const sendLabel = hasEndpoint ? 'Отправить обращение' : hasEmail ? 'Открыть письмо' : 'Сохранить обращение';
  const escapeHtml = value => String(value).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  function download(text, name) {
    const link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob(['\uFEFF' + text], { type: 'text/plain;charset=utf-8' }));
    link.download = name;
    document.body.append(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(link.href), 1000);
  }
  function textFor(data) {
    return ['ОБРАЩЕНИЕ В СПЕЦПАРТС49', '', 'Тема: ' + (data.topic || 'Подбор запчастей'), 'Имя: ' + data.name, 'Email для ответа: ' + data.email, 'Телефон: ' + (data.phone || 'не указан'), '', data.message, '', 'Подготовлено на сайте: ' + location.origin + location.pathname].join('\n');
  }
  function validateFiles(form) {
    const files = [...(form?.querySelector('input[type=file]')?.files || [])];
    if (files.length > 3) throw new Error('Прикрепите не более 3 файлов.');
    let total = 0;
    for (const file of files) {
      if (!/\.(jpe?g|png|webp|pdf)$/i.test(file.name)) throw new Error('Разрешены JPG, PNG, WebP и PDF.');
      if (file.size > 5 * 1024 * 1024) throw new Error('Каждый файл должен быть меньше 5 МБ.');
      total += file.size;
    }
    if (total > 15 * 1024 * 1024) throw new Error('Общий размер файлов должен быть меньше 15 МБ.');
    return files;
  }
  function formData(form) {
    return Object.fromEntries([...new FormData(form)].filter(([, v]) => typeof v === 'string'));
  }
  async function route(data, text, form) {
    if (hasEndpoint) {
      const files = validateFiles(form);
      const payload = new FormData();
      for (const [key, value] of Object.entries(data)) if (typeof value === 'string' || typeof value === 'boolean') payload.set(key, String(value));
      if (!form.dataset.requestId) form.dataset.requestId = crypto.randomUUID();
      payload.set('requestId', form.dataset.requestId);
      for (const file of files) payload.append('attachments[]', file, file.name);
      const response = await fetch(config.endpoint, { method: 'POST', credentials: 'omit', body: payload });
      const result = await response.json();
      if (!response.ok) throw new Error(result.error || 'Не удалось отправить обращение. Попробуйте позже или сохраните текст.');
      if (result.success !== true) throw new Error('Отправка не подтверждена. Попробуйте позже или сохраните текст.');
      delete form.dataset.requestId;
      return 'Запрос принят. Номер: ' + (result.id || 'уточняется') + (result.confirmation ? '. Подтверждение отправлено на ваш email.' : '. Менеджер свяжется с вами по указанным контактам.');
    }
    if (hasEmail) {
      location.href = 'mailto:' + encodeURIComponent(config.email) + '?subject=' + encodeURIComponent('Спецпартс49 — ' + (data.topic || 'Подбор запчастей')) + '&body=' + encodeURIComponent(text);
      return 'Письмо подготовлено. Отправьте его в почтовой программе.';
    }
    download(text + '\n\nОбращение сохранено на устройстве и не отправлено компании.', 'specparts49-obraschenie.txt');
    return 'Обращение сохранено файлом. Оно не отправлено компании.';
  }
  document.querySelectorAll('.attachment-field input[type=file]').forEach(input => input.addEventListener('change', () => {
    const status = input.closest('.attachment-field').querySelector('.attachment-list');
    try { const files=validateFiles(input.form);status.textContent=files.map(f=>f.name+' · '+(f.size/1024/1024).toFixed(1)+' МБ').join(' / ');input.setCustomValidity(''); }
    catch(error){status.textContent=error.message;input.setCustomValidity(error.message);}
  }));
  document.querySelectorAll('[data-inquiry]').forEach(form => {
    form.querySelector('[data-contact-status]').textContent = formHelp;
    const button = form.querySelector('[data-inquiry-submit]');
    button.innerHTML = sendLabel + '<svg class="ico" viewBox="0 0 20 20" aria-hidden="true"><path d="M4 10h12M11 5l5 5-5 5"/></svg>';
    form.addEventListener('submit', async event => {
      event.preventDefault();
      if (!form.reportValidity()) return;
      const data = formData(form);
      data.page = location.origin + location.pathname;
      const status = form.querySelector('.inquiry-result');
      button.disabled = true;
      status.textContent = hasEndpoint ? 'Отправляем обращение…' : '';
      try { status.textContent = await route(data, textFor(data), form); status.className='inquiry-result success'; }
      catch (error) { status.textContent = error.message || 'Не удалось отправить обращение.'; status.className='inquiry-result error'; }
      finally { button.disabled = false; }
    });
  });
  document.querySelectorAll('[data-open-inquiry]').forEach(button => button.addEventListener('click', () => {
    if (!dialog) return;
    const form = dialog.querySelector('form');
    form.elements.topic.value = button.dataset.topic || 'Подбор запчастей';
    form.querySelector('.inquiry-result').textContent = '';
    if (button.dataset.topic === 'Наличие на складе') form.elements.message.placeholder = 'Укажите артикул или название детали, модель техники и нужное количество.';
    else if (button.dataset.topic === 'Акционные позиции') form.elements.message.placeholder = 'Какая запчасть или группа товаров вас интересует?';
    else form.elements.message.placeholder = 'Например: нужен подбор Caterpillar по SIS, артикул неизвестен.';
    dialog.querySelector('#inquiry-title').textContent = button.dataset.topic || 'Вопрос — ответ онлайн';
    dialog.showModal();
    form.elements.email.focus();
  }));
  document.querySelector('[data-close-inquiry]')?.addEventListener('click', () => dialog.close());
  dialog?.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });

  const request = document.getElementById('request-form');
  const sendRequest = document.getElementById('send-request-email');
  if (request && sendRequest) {
    sendRequest.disabled = !(hasEndpoint || hasEmail);
    sendRequest.textContent = hasEndpoint ? 'Отправить запрос' : hasEmail ? 'Открыть письмо' : 'Отправить на почту';
    request.addEventListener('submit', event=>{event.preventDefault();sendRequest.click();});
    sendRequest.addEventListener('click', async () => {
      const data = formData(request);
      if (!data.name?.trim() || !data.email?.trim() || !data.consent) {
        if (typeof toast === 'function') toast('Укажите имя, email и согласие на использование контактов.');
        return;
      }
      if (!request.reportValidity()) return;
      const draft = window.SP49_buildRequestText?.();
      if (!draft) return;
      const body = draft.replace('Документ подготовлен на сайте и не отправлен компании.', 'Запрос подготовлен на сайте.');
      sendRequest.disabled = true;
      try {
        const message = await route({ ...data, topic: 'Подбор запчастей', message: body, page: location.origin + location.pathname }, body, request);
        const status = request.querySelector('.request-send-result');if(status){status.textContent=message;status.className='request-send-result inquiry-result success';}
        if (typeof toast === 'function') toast(message);
      } catch (error) { const status=request.querySelector('.request-send-result');if(status){status.textContent=error.message;status.className='request-send-result inquiry-result error';}if (typeof toast === 'function') toast(error.message); }
      finally { sendRequest.disabled = false; }
    });
    const params = new URLSearchParams(location.search);
    if (params.get('service') === 'sis') {
      request.elements.brand.value = 'Caterpillar';
      request.elements.condition.value = 'Подбор Caterpillar по SIS без артикула';
      if (!request.elements.comment.value) request.elements.comment.value = 'Нужен подбор запчасти Caterpillar по программе SIS, артикул неизвестен.';
    }
    if (params.get('article')) request.elements.article.value=params.get('article').slice(0,150);
    const requestedBrand = params.get('brand')?.trim();
    if (requestedBrand && /^[\p{L}\p{N} .&-]{1,60}$/u.test(requestedBrand)) {
      const prefix = 'Интересует продукция ' + requestedBrand + '. ';
      if (!request.elements.comment.value.startsWith(prefix)) request.elements.comment.value = prefix + request.elements.comment.value;
    }
  }
  const offersContainer = document.getElementById('offers-content');
  const offers = Array.isArray(window.SP49_OFFERS) ? window.SP49_OFFERS : [];
  if (offersContainer && offers.length) {
    offersContainer.innerHTML = '<div class="product-grid">' + offers.map(offer => {
      const photo = typeof offer.image === 'string' && /^assets\/[\w./-]+$/.test(offer.image) ? offer.image : 'assets/brands/gear-small.webp';
      return '<article class="product-card"><div class="product-art"><span class="product-kind">АКЦИЯ</span><img src="' + photo + '" alt="' + escapeHtml(offer.title || 'Запасная часть') + '"></div><div class="product-info"><small>' + escapeHtml(offer.manufacturer || '') + '</small><h3>' + escapeHtml(offer.title || 'Запасная часть') + '</h3><p class="offer-article">Артикул: ' + escapeHtml(offer.article || 'уточняется') + '</p><p class="offer-price">' + (offer.oldPrice ? '<del>' + escapeHtml(offer.oldPrice) + '</del> ' : '') + '<strong>' + escapeHtml(offer.price || 'По запросу') + '</strong></p><a class="button small" href="request.html?article=' + encodeURIComponent(offer.article || '') + '">Запросить предложение</a></div></article>';
    }).join('') + '</div>';
  }
})();
