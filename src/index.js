import React, { useState, useEffect, useRef } from 'react';
import { createRoot } from 'react-dom/client';

const ReactComponent = ({ city, state, shippingMethod }) => {
  const [widgetHtml, setWidgetHtml] = useState('');
  const requestId = useRef(0);

  // 1. Загружаем HTML-виджет для выбранного способа доставки
  useEffect(() => {
    if (!city || !shippingMethod || shippingMethod.includes('yd_courier')) {
      setWidgetHtml('');
      return;
    }
    if (typeof wp_data === 'undefined' || !wp_data.ajax_url) return;

    const currentRequest = ++requestId.current;

    jQuery.post(
        wp_data.ajax_url,
        {
          action: 'yd_update_widget_data',
          security: (typeof wp_data !== 'undefined' && wp_data.yd_nonce) ? wp_data.yd_nonce : '',
          city,
          state,
          shipping_method: shippingMethod,
        },
        (response) => {
          // Защита от race condition: игнорируем ответы от устаревших запросов
          if (currentRequest !== requestId.current) return;
          if (response.success) {
            setWidgetHtml(response.data.yd_widget_link);
          }
        }
    );
  }, [city, state, shippingMethod]);

  // 2. Глобальные коллбэки для сохранения выбранного ПВЗ
  useEffect(() => {
    window.updateYdAddress = (newAddress) => {
      const method = window.currentYdMethod;
      if (!method) return;

      const normalizedMethod = method.split(':')[0];

      let btn = document.querySelector(`.bxbbutton[data-method="${normalizedMethod}"]`);
      if (!btn) {
        btn = document.querySelector('.bxbbutton');
      }
      if (btn) {
        btn.textContent = newAddress;
        btn.dataset.address = newAddress;
      }
    };

    window.ydSelectCallback = (point) => {
      const method = (window.currentYdMethod || '').split(':')[0];
      const address = `${point.Name || point.name}, ${point.Address || point.address}`;
      if (typeof wp_data === 'undefined' || !wp_data.ajax_url) return;
      jQuery.post(wp_data.ajax_url, {
        action: 'yd_update',
        nonce: (typeof wp_data !== 'undefined' && wp_data.yd_nonce) ? wp_data.yd_nonce : '',
        method: method,
        code: point.id || point.Id,
        address: address,
      }, () => {
        jQuery(document.body).trigger('update_checkout');
      });

      if (typeof window.updateYdAddress === 'function') {
        window.updateYdAddress(address);
      }
    };

    return () => {
      delete window.updateYdAddress;
      delete window.ydSelectCallback;
    };
  }, []);

  return <div dangerouslySetInnerHTML={{ __html: widgetHtml }} />;
};

let previousMethod = null;
const ydPickupMethods = ['yd_self', 'yd_self_after'];

const hideWidget = () => {
  document.querySelectorAll('#yandex-dostavka-shipping-widget').forEach((el) => {
    if (el._reactRoot) {
      try { el._reactRoot.unmount(); } catch (e) { /* already unmounted */ }
    }
    el.remove();
  });
};

const deleteCookie = (name) => {
  document.cookie = `${name}=; Max-Age=0; path=/;`;
};

const renderWidget = () => {
  const shippingInputs = document.querySelectorAll('input[type="radio"][value*="yd"]');
  let selected = null;
  shippingInputs.forEach((input) => {
    if (input.checked) {
      selected = input;
    }
  });

  const currentMethod = selected?.value || '';
  const normalizedMethod = currentMethod.split(':')[0];

  if (previousMethod && ydPickupMethods.includes(previousMethod)) {
    deleteCookie('yd_pvz_code');
  }

  previousMethod = normalizedMethod;

  hideWidget();

  if (!selected) return;

  const label = selected.closest('label') || selected.closest('.wc-block-components-shipping-method-option');
  if (!label) return;

  const container = document.createElement('div');
  container.id = 'yandex-dostavka-shipping-widget';
  container.style.marginTop = '10px';
  label.appendChild(container);

  window.currentYdMethod = selected.value;

  const city = jQuery('#shipping-city').val() || jQuery('#billing-city').val();
  const state = jQuery('#shipping-state').val() || jQuery('#billing-state').val();

  // Скрываем поля адреса/индекса при ПВЗ в block checkout
  const isPVZ = normalizedMethod.indexOf('yd_self') !== -1;
  const blockAddrFields = document.querySelectorAll(
    '.wc-block-components-address-form__address_1,' +
    '.wc-block-components-address-form__address_2,' +
    '.wc-block-components-address-form__postcode'
  );
  blockAddrFields.forEach((el) => {
    el.style.display = isPVZ ? 'none' : '';
  });

  container._reactRoot = createRoot(container);
  container._reactRoot.render(
      <ReactComponent city={city} state={state} shippingMethod={selected.value} />
  );
};

let ndObserver = null;

const startObservingShippingMethods = () => {
  if (ndObserver) ndObserver.disconnect();

  ndObserver = new MutationObserver((mutations) => {
    for (const mutation of mutations) {
      for (const node of mutation.addedNodes) {
        if (!(node instanceof HTMLElement)) continue;
        const ydRadio = node.querySelector?.('input[type="radio"][value*="yd"]');
        const shippingBlock = node.classList?.contains('wp-block-woocommerce-checkout-shipping-methods-block');
        if (ydRadio || shippingBlock) {
          setTimeout(renderWidget, 100);
          return;
        }
      }
    }
  });

  ndObserver.observe(document.body, {
    childList: true,
    subtree: true,
  });
};

const waitForShippingMethods = (attempts = 0) => {
  const radios = document.querySelectorAll('input[type="radio"][value*="yd"]');
  if (radios.length) {
    renderWidget();
  } else if (attempts < 20) {
    setTimeout(() => waitForShippingMethods(attempts + 1), 100);
  }
};

document.addEventListener('DOMContentLoaded', () => {
  waitForShippingMethods();
  startObservingShippingMethods();

  jQuery(document).on('change input', '#billing-city, #billing-state, #shipping-city, #shipping-state', () => {
    renderWidget();
  });
  jQuery(document).on('change', 'input[type="radio"]', () => {
    renderWidget();
  });
});
