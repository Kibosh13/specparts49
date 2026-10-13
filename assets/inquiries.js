(() => {
  const config = window.SP49_CONTACT || {};
  const hasEndpoint = typeof config.endpoint === 'string' && /^https:\/\//.test(config.endpoint);
  const hasEmail = typeof config.email === 'string' && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(config.email);
  const dialog = document.getElementById('inquiry-dialog');
  const formHelp = hasEndpoint ? 'Обращение будет отправлено компании из формы.' : hasEmail ? 'После нажатия откроется письмо в вашей почтовой программе. Проверьте его и отправьте.' : 'Отправка на почту ещё не подключена. Сейчас можно сохранить обращение файлом.';
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
  async function route(data, text) {
    if (hasEndpoint) {
      const response = await fetch(config.endpoint, { method: 'POST', credentials: 'omit', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) });
      if (!response.ok) throw new Error('Не удалось отправить обращение. Попробуйте позже или сохраните текст.');
      const result = await response.json();
      if (result.success !== true) throw new Error('Отправка не подтверждена. Попробуйте позже или сохраните текст.');
      return 'Обращение отправлено компании.';
    }
    if (hasEmail) {
      location.href = 'mailto:' + encodeURIComponent(config.email) + '?subject=' + encodeURIComponent('Спецпартс49 — ' + (data.topic || 'Подбор запчастей')) + '&body=' + encodeURIComponent(text);
      return 'Письмо подготовлено. Отправьте его в почтовой программе.';
    }
    download(text + '\n\nОбращение сохранено на устройстве и не отправлено компании.', 'specparts49-obraschenie.txt');
    return 'Обращение сохранено файлом. Оно не отправлено компании.';
  }
  document.querySelectorAll('[data-inquiry]').forEach(form => {
    form.querySelector('[data-contact-status]').textContent = formHelp;
    const button = form.querySelector('[data-inquiry-submit]');
    button.innerHTML = sendLabel + '<svg class="ico" viewBox="0 0 20 20" aria-hidden="true"><path d="M4 10h12M11 5l5 5-5 5"/></svg>';
    form.addEventListener('submit', async event => {
      event.preventDefault();
      if (!form.reportValidity()) return;
      const data = Object.fromEntries(new FormData(form));
      data.page = location.origin + location.pathname;
      const status = form.querySelector('.inquiry-result');
      button.disabled = true;
      status.textContent = hasEndpoint ? 'Отправляем обращение…' : '';
      try { status.textContent = await route(data, textFor(data)); }
      catch (error) { status.textContent = error.message || 'Не удалось отправить обращение.'; }
      finally { button.disabled = false; }
    });
  });
  document.querySelectorAll('[data-open-inquiry]').forEach(button => button.addEventListener('click', () => {
    const form = dialog.querySelector('form');
    form.elements.topic.value = button.dataset.topic || 'Подбор запчастей';
    form.querySelector('.inquiry-result').textContent = '';
    if (button.dataset.topic === 'Наличие на складе') form.elements.message.placeholder = 'Укажите артикул или название детали, модель техники и нужное количество.';
    else if (button.dataset.topic === 'Акционные позиции') form.elements.message.placeholder = 'Какая запчасть или группа товаров вас интересует?';
    else form.elements.message.placeholder = 'Например: нужен подбор Caterpillar по SIS, артикул неизвестен.';
    dialog.showModal();
    form.elements.email.focus();
  }));
  document.querySelector('[data-close-inquiry]')?.addEventListener('click', () => dialog.close());
  dialog?.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });

  const request = document.getElementById('request-form');
  const sendRequest = document.getElementById('send-request-email');
  if (request && sendRequest) {
    sendRequest.disabled = !(hasEndpoint || hasEmail);
    sendRequest.textContent = hasEndpoint ? 'Отправить на почту' : hasEmail ? 'Открыть письмо' : 'Отправить на почту';
    sendRequest.addEventListener('click', async () => {
      const data = Object.fromEntries(new FormData(request));
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
        const message = await route({ ...data, topic: 'Подбор запчастей', message: body, page: location.origin + location.pathname }, body);
        if (typeof toast === 'function') toast(message);
      } catch (error) { if (typeof toast === 'function') toast(error.message); }
      finally { sendRequest.disabled = false; }
    });
    const params = new URLSearchParams(location.search);
    if (params.get('service') === 'sis') {
      request.elements.brand.value = 'Caterpillar';
      request.elements.condition.value = 'Подбор Caterpillar по SIS без артикула';
      if (!request.elements.comment.value) request.elements.comment.value = 'Нужен подбор запчасти Caterpillar по программе SIS, артикул неизвестен.';
    }
    if (params.get('article')) request.elements.article.value=params.get('article').slice(0,150);
    if (['Euroricambi','MCP'].includes(params.get('brand'))) {
      const brand = params.get('brand');
      request.elements.comment.value = 'Интересует продукция ' + brand + '. ' + request.elements.comment.value;
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
