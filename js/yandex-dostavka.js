// Полифилл closest для IE11 и старых браузеров
if (typeof Element !== 'undefined' && !Element.prototype.closest) {
    Element.prototype.closest = function(s) {
        var el = this;
        do {
            if (el.matches ? el.matches(s) : el.msMatchesSelector ? el.msMatchesSelector(s) : false) return el;
            el = el.parentElement || el.parentNode;
        } while (el !== null && el.nodeType === 1);
        return null;
    };
}

var ydWidgetSelectedPointAddress = false;
var ydWidgetPointCode = false;
var ydWidgetPointName = false;

jQuery(document).on('click', function (e) {
    // DEBUG: логируем все клики по кнопке ПВЗ
    var el = e.target;
    if (el && (el.className || '').toString().indexOf('bxbbutton') !== -1) {
        console.log('[YD] bxbbutton clicked directly', el.tagName, el.outerHTML.substring(0, 200));
    }
    // closest — ловим клик по <img>/<span> внутри <a>
    var selectPointLink = null;
    if (el && el.closest) {
        selectPointLink = el.closest('[data-yandex-dostavka-open]');
    }
    if (!selectPointLink && el) {
        // Фоллбэк: ищем родителя с классом bxbbutton
        var parent = el.parentElement;
        while (parent && parent !== document.body) {
            if (parent.getAttribute && parent.getAttribute('data-yandex-dostavka-open')) {
                selectPointLink = parent;
                break;
            }
            if (parent.classList && parent.classList.contains('bxbbutton')) {
                selectPointLink = parent;
                break;
            }
            parent = parent.parentElement;
        }
    }
    if (selectPointLink) {
        console.log('[YD] PVZ button found!', selectPointLink.tagName, selectPointLink.getAttribute('data-yandex-dostavka-city'));
        e.preventDefault();

        (function (selectedPointLink) {
            var city = selectedPointLink.getAttribute('data-yandex-dostavka-city') || undefined;
            var method = selectedPointLink.getAttribute('data-method');
            var token = selectedPointLink.getAttribute('data-yandex-dostavka-token');
            var targetStart = selectedPointLink.getAttribute('data-yandex-dostavka-target-start');
            var weight = selectedPointLink.getAttribute('data-yandex-dostavka-weight');
            var surch = selectedPointLink.getAttribute('data-surch');
            var paymentSum = selectedPointLink.getAttribute('data-paymentsum');
            var orderSum = selectedPointLink.getAttribute('data-ordersum');
            var height = selectedPointLink.getAttribute('data-height');
            var width = selectedPointLink.getAttribute('data-width');
            var depth = selectedPointLink.getAttribute('data-depth');
            var api = selectedPointLink.getAttribute('data-api-url');
            var pointSelectedHandler = function (result) {
                if (typeof result !== 'undefined' && result !== null) {
                    ydWidgetPointCode = result.id;
                    ydWidgetPointName = (result.name || '').replace('Алма-Ата', 'Алматы');
                    ydWidgetSelectedPointAddress = result.address || ydWidgetPointName;

                    // Заполняем скрытые поля адресом ПВЗ (ЮKassa и др. платёжки требуют)
                    var pvzAddr = 'ПВЗ: ' + ydWidgetSelectedPointAddress;
                    ['billing_address_1', 'shipping_address_1'].forEach(function(id) {
                        var el = document.getElementById(id);
                        if (el) el.value = pvzAddr;
                    });
                    // Индекс — если пуст, ставим 111111 (ЮKassa требует непустой)
                    ['billing_postcode', 'shipping_postcode'].forEach(function(id) {
                        var el = document.getElementById(id);
                        if (el && !el.value) el.value = '111111';
                    });

                    // Показываем выбранный ПВЗ отдельным блоком
                    ndShowPvzSelected(ydWidgetSelectedPointAddress);

                    if (window.updateYdAddress) {
                        window.updateYdAddress(ydWidgetSelectedPointAddress);
                    }

                    // Сохраняем в cookie и обновляем чекаут
                    var formData = new FormData();
                    formData.append('action', 'yd_update');
                    formData.append('nonce', window.wp_data && window.wp_data.yd_nonce ? window.wp_data.yd_nonce : '');
                    formData.append('method', method);
                    formData.append('code', ydWidgetPointCode);
                    formData.append('address', ydWidgetSelectedPointAddress);
                    ndAjaxPost(formData).then(function (){
                        jQuery(document.body).trigger('update_checkout');
                    });
                }
            };
            // Открываем виджет выбора ПВЗ на Яндекс Картах
            // Город берём из поля "Населённый пункт" — надёжнее чем data-атрибут
            var pvzCity = jQuery('#billing_city').val() || jQuery('#shipping_city').val() || '';
            console.log('[YD] Opening PVZ widget, city:', pvzCity);

            // Если город не введён — подсказка и фокус на поле
            if (!pvzCity || pvzCity.trim().length < 2) {
                var $cityField = jQuery('#billing_city').length ? jQuery('#billing_city') : jQuery('#shipping_city');
                if ($cityField.length) {
                    $cityField.css({'border-color': '#d63638', 'box-shadow': '0 0 0 1px #d63638'});
                    setTimeout(function() { $cityField.css({'border-color': '', 'box-shadow': ''}); }, 3000);
                    $cityField.focus();
                }
                // Показываем подсказку
                var $hint = jQuery('.nd-pvz-city-hint');
                if (!$hint.length) {
                    $hint = jQuery('<div class="nd-pvz-city-hint" style="margin:6px 0 4px 15px;padding:8px 14px;background:#fff8e1;border:1px solid #ffcc02;border-radius:6px;font-size:13px;color:#856404;">Сначала введите город в поле «Населённый пункт»</div>');
                    var $btn = jQuery('a[data-yandex-dostavka-open="true"]').first();
                    if ($btn.length) {
                        $btn.closest('p').length ? $btn.closest('p').after($hint) : $btn.after($hint);
                    }
                }
                setTimeout(function() { jQuery('.nd-pvz-city-hint').fadeOut(400, function() { jQuery(this).remove(); }); }, 5000);
                return;
            }

            if (typeof YD_PVZ !== 'undefined') {
                YD_PVZ.open(pvzCity, function(point) {
                    pointSelectedHandler({
                        id: point.id,
                        name: point.name || pvzCity,
                        address: point.address || ''
                    });
                });
            } else {
                alert('Виджет выбора ПВЗ не загружен. Обновите страницу.');
            }
        })(selectPointLink);
    }
});

/** Сброс выбранного ПВЗ (при смене города, региона и т.п.) */
function ndResetPvzSelection() {
    ndDeleteCookie('yd_pvz_code');
    ndDeleteCookie('yd_pvz_address');
    ydWidgetSelectedPointAddress = false;
    ydWidgetPointCode = false;
    ydWidgetPointName = false;
    jQuery('.nd-pvz-selected').remove();
}

/** Показать блок с выбранным ПВЗ рядом с кнопкой */
function ndShowPvzSelected(address) {
    if (!address) return;

    // Если блок уже есть с тем же адресом — не пересоздаём
    var $existing = jQuery('.nd-pvz-selected');
    if ($existing.length && $existing.data('pvz-addr') === address) return;

    $existing.remove();

    var $info = jQuery('<div class="nd-pvz-selected" style="margin:8px 0 4px 15px;padding:10px 14px;background:#f0fdf4;border:1px solid #86efac;border-radius:6px;font-size:14px;color:#166534;"></div>')
        .html('<strong>ПВЗ:</strong> ' + jQuery('<span>').text(address).html())
        .data('pvz-addr', address);

    // Вставляем после ссылки "Выберите пункт выдачи"
    var $link = jQuery('a[data-yandex-dostavka-open="true"]').first();
    if ($link.length) {
        $link.closest('p').length ? $link.closest('p').after($info) : $link.after($info);
    } else {
        // Фоллбэк: после метода доставки yd_self
        var $method = jQuery('input[value*="yd_self"]').closest('li, tr, .woocommerce-shipping-methods');
        if ($method.length) {
            $method.after($info);
        }
    }
}

async function ndAjaxPost(data) {
    if (!window.wp_data || !window.wp_data.ajax_url) {
        return;
    }
    await fetch(window.wp_data.ajax_url,
        {
            method: 'POST',
            body: data
        });
}

function getCityField(){
    if (jQuery('#billing_city').length && !jQuery('#ship-to-different-address-checkbox').prop('checked')){
        return jQuery('#billing_city');
    }

    if (jQuery('#shipping_city').length){
        return jQuery('#shipping_city');
    }

    if (jQuery('#billing-city').length && !jQuery('.wc-block-checkout__use-address-for-billing #checkbox-control-1').prop('checked')){
        return jQuery('#billing-city');
    }

    if (jQuery('#shipping-city').length){
        return jQuery('#shipping-city');
    }

    return false;
}

jQuery(document.body).on('updated_checkout', function () {
    // Зелёный блок ПВЗ теперь рендерится сервером (PHP из cookie)
    // Здесь только обновляем текст кнопки и восстанавливаем JS-переменные
    setTimeout(function() {
        // Восстанавливаем JS-переменные из cookie (нужны для повторной отправки)
        if (!ydWidgetSelectedPointAddress) {
            var cookieAddr = ndGetCookie('yd_pvz_address');
            var cookieCode = ndGetCookie('yd_pvz_code');
            if (cookieAddr && cookieCode) {
                ydWidgetSelectedPointAddress = decodeURIComponent(cookieAddr);
                ydWidgetPointCode = decodeURIComponent(cookieCode);
            }
        }

        // Обновляем текст кнопки
        if (ydWidgetSelectedPointAddress) {
            jQuery('a[data-yandex-dostavka-open="true"]').each(function() {
                jQuery(this).text(ydWidgetSelectedPointAddress);
            });
        }

        ndMaybeAttachAddressSuggest();
        ndToggleAddressField();
    }, 100);
});

jQuery(document).on('change', 'input[name="payment_method"]', function () {
    jQuery(document.body).trigger('update_checkout');
});

jQuery(document).on('change', 'input[name="shipping_method[0]"]', function () {
    ndMaybeAttachAddressSuggest();
    ndToggleAddressField();
});

function ndDeleteCookie(name) {
    if (!name) return;
    var d = new Date();
    d.setDate(d.getDate() - 1);
    var expires = ";expires=" + d;
    document.cookie = name + "=" + expires + "; path=/";
}

function ndGetCookie(name) {
    var match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1') + '=([^;]*)'));
    return match ? match[1] : '';
}

/** Проверка: выбран ли способ доставки курьером Яндекс Доставки */
function ndIsCourierSelected() {
    var chosen = jQuery('input[name="shipping_method[0]"]:checked').val() || '';
    return chosen.indexOf('yd_courier') !== -1;
}

/** Есть ли отдельный адрес доставки (галочка «доставить по другому адресу») */
function ndShipToDifferentAddress() {
    var cb = jQuery('#ship-to-different-address-checkbox');
    if (cb.length) return cb.prop('checked');
    var blockCb = jQuery('.wc-block-checkout__use-address-for-billing #checkbox-control-1');
    if (blockCb.length) return !blockCb.prop('checked');
    return false;
}

/** Подсказки адреса для курьера: без регистрации — минимум полей; при одном адресе вешаем на billing. */
function ndAttachAddressSuggest() {
    if (!window.wp_data || !window.wp_data.ajax_url || !ndIsCourierSelected()) return;
    var useBilling = !ndShipToDifferentAddress();
    var $addr = useBilling
        ? (jQuery('#billing_address_1').length ? jQuery('#billing_address_1') : jQuery('input[name="billing_address_1"]'))
        : (jQuery('#shipping_address_1').length ? jQuery('#shipping_address_1') : jQuery('input[name="shipping_address_1"]'));
    if (!$addr.length) return;
    if ($addr.data('nd-suggest')) return;
    $addr.data('nd-suggest', true);

    var $wrap = $addr.closest('.form-row');
    if ($wrap.length) $wrap.addClass('yandex-dostavka-courier-address');
    var $dropdown = jQuery('<div class="nd-address-suggest" role="listbox" aria-hidden="true"></div>').appendTo($wrap.length ? $wrap : $addr.parent());
    var debounceTimer = null;
    var lastQuery = '';

    function setFieldValue(value, idsOrNames) {
        if (!value) return;
        for (var i = 0; i < idsOrNames.length; i++) {
            var id = idsOrNames[i];
            var el = document.getElementById(id) || document.querySelector('input[name="' + id + '"]');
            if (el) { el.value = value; break; }
        }
    }
    function fillFromSuggestion(item) {
        var prefix = useBilling ? ['billing_postcode', 'billing-postcode', 'shipping_postcode', 'shipping-postcode']
            : ['shipping_postcode', 'shipping-postcode'];
        var cityIds = useBilling ? ['billing_city', 'billing-city', 'shipping_city', 'shipping-city']
            : ['shipping_city', 'shipping-city'];
        var stateIds = useBilling ? ['billing_state', 'billing-state', 'shipping_state', 'shipping-state']
            : ['shipping_state', 'shipping-state'];
        if (item.postal_code) setFieldValue(item.postal_code, prefix);
        if (item.city) setFieldValue(item.city, cityIds);
        if (item.region) setFieldValue(item.region, stateIds);
        $addr.val(item.value);
        if (useBilling) {
            var $ship = jQuery('#shipping_address_1').add(jQuery('input[name="shipping_address_1"]'));
            if ($ship.length) $ship.val(item.value);
        }
        $dropdown.hide().empty();
        jQuery(document.body).trigger('update_checkout');
    }

    function doSuggest(query) {
        query = (query || '').trim();
        if (query.length < 2) { $dropdown.hide().empty(); return; }
        var formData = new FormData();
        formData.append('action', 'yd_address_suggest');
        formData.append('nonce', window.wp_data.yd_address_suggest_nonce || '');
        formData.append('query', query);
        fetch(window.wp_data.ajax_url, { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                var list = (res && res.data && res.data.suggestions) ? res.data.suggestions : [];
                $dropdown.empty();
                if (list.length === 0) { $dropdown.hide(); return; }
                list.forEach(function(s) {
                    var $item = jQuery('<div class="nd-address-suggest-item" role="option" tabindex="0"></div>').text(s.value);
                    $item.on('mousedown', function(e) { e.preventDefault(); fillFromSuggestion(s); });
                    $item.on('keydown', function(e) { if (e.key === 'Enter') { fillFromSuggestion(s); } });
                    $dropdown.append($item);
                });
                $dropdown.attr('aria-hidden', 'false').show();
            })
            .catch(function() { $dropdown.hide().empty(); });
    }

    $addr.on('input.ndSuggest', function() {
        clearTimeout(debounceTimer);
        var q = $addr.val();
        debounceTimer = setTimeout(function() { lastQuery = q; doSuggest(q); }, 300);
    });
    $addr.on('focus.ndSuggest', function() { if (lastQuery.trim().length >= 2) doSuggest($addr.val()); });
    jQuery(document).on('click.ndSuggest', function(e) {
        if (!jQuery(e.target).closest('.nd-address-suggest, .yandex-dostavka-courier-address').length) $dropdown.hide();
    });
    $dropdown.on('keydown', function(e) { if (e.key === 'Escape') $dropdown.hide(); });
}

/** Показать/скрыть поля в зависимости от выбранного метода доставки.
 *  ПВЗ: скрываем адрес, индекс, address_2 — нужен только город + карта.
 *  Курьер: показываем адрес и индекс (подсказки Dadata).
 */
/** Предыдущий выбранный метод для определения переключения */
var ndPreviousMethod = '';

function ndToggleAddressField() {
    // WooCommerce: radio если несколько методов, hidden если один
    var chosen = jQuery('input[name="shipping_method[0]"]:checked').val()
              || jQuery('input[name="shipping_method[0]"]').val()
              || '';
    var isPVZ = chosen.indexOf('yd_self') !== -1;
    var isCourier = chosen.indexOf('yd_courier') !== -1;
    var wasPVZ = ndPreviousMethod.indexOf('yd_self') !== -1;
    console.log('[YD] toggleFields: chosen=' + chosen + ' isPVZ=' + isPVZ);

    // При переключении с ПВЗ на курьера — очищаем адрес ПВЗ из полей
    if (wasPVZ && !isPVZ) {
        var addrFields = ['billing_address_1', 'shipping_address_1'];
        addrFields.forEach(function(id) {
            var el = document.getElementById(id);
            if (el && el.value.indexOf('ПВЗ:') === 0) {
                el.value = '';
            }
        });
        jQuery('.nd-pvz-selected').remove();
        ydWidgetSelectedPointAddress = false;
        ydWidgetPointCode = false;
        ydWidgetPointName = false;
        ndDeleteCookie('yd_pvz_code');
    }

    ndPreviousMethod = chosen;

    // Поля, которые прячем при ПВЗ (classic checkout)
    // Для ПВЗ нужны только: Имя, Фамилия, Телефон, Email, Город
    var fieldsToToggle = [
        '#billing_address_1_field', '#shipping_address_1_field',
        '#billing_address_2_field', '#shipping_address_2_field',
        '#billing_postcode_field', '#shipping_postcode_field',
        '#billing_state_field', '#shipping_state_field'
    ];

    var $fields = jQuery(fieldsToToggle.join(','));

    if (isPVZ) {
        $fields.hide();
    } else {
        $fields.show();
    }

    // Block checkout: аналогичные селекторы
    var blockSelectors = [
        '.wc-block-components-address-form__address_1',
        '.wc-block-components-address-form__address_2',
        '.wc-block-components-address-form__postcode',
        '.wc-block-components-address-form__state'
    ];
    var $blockFields = jQuery(blockSelectors.join(','));
    if (isPVZ) {
        $blockFields.hide();
    } else {
        $blockFields.show();
    }
}

function ndMaybeAttachAddressSuggest() {
    if (!ndIsCourierSelected()) {
        jQuery('.yandex-dostavka-courier-address').removeClass('yandex-dostavka-courier-address');
        jQuery('.nd-address-suggest').remove();
        jQuery(document).off('click.ndSuggest');
        var $addrFields = jQuery('#shipping_address_1, #billing_address_1, input[name="shipping_address_1"], input[name="billing_address_1"]');
        $addrFields.off('input.ndSuggest focus.ndSuggest');
        $addrFields.removeData('nd-suggest');
        return;
    }
    ndAttachAddressSuggest();
}

/** Dadata подсказки для поля «Город» — работает для всех методов доставки.
 *  При выборе города автозаполняет индекс и регион. */
function ndAttachCitySuggest() {
    if (!window.wp_data || !window.wp_data.ajax_url) return;
    var cityIds = ['billing_city', 'shipping_city'];
    cityIds.forEach(function(cityId) {
        var $city = jQuery('#' + cityId);
        if (!$city.length || $city.data('nd-city-suggest')) return;
        $city.data('nd-city-suggest', true);

        var $wrap = $city.closest('.form-row');
        var $dropdown = jQuery('<div class="nd-city-suggest" style="position:absolute;z-index:99999;background:#fff;border:1px solid #ddd;border-radius:4px;box-shadow:0 2px 8px rgba(0,0,0,.15);max-height:200px;overflow-y:auto;display:none;width:100%;"></div>');
        if ($wrap.length) {
            $wrap.css('position', 'relative');
            $wrap.append($dropdown);
        } else {
            $city.parent().css('position', 'relative');
            $city.parent().append($dropdown);
        }
        var timer = null;

        function fillCity(item) {
            $city.val(item.city || item.value);
            // Индекс
            var prefix = cityId.replace('city', 'postcode');
            var $post = jQuery('#' + prefix);
            if ($post.length && item.postal_code) $post.val(item.postal_code);
            // Регион
            var stateId = cityId.replace('city', 'state');
            var $state = jQuery('#' + stateId);
            if ($state.length && item.region) $state.val(item.region);
            $dropdown.hide().empty();
            // Сброс ПВЗ при смене города — старый ПВЗ из другого города невалиден
            ndResetPvzSelection();
            jQuery(document.body).trigger('update_checkout');
        }

        $city.on('input.ndCitySuggest', function() {
            clearTimeout(timer);
            var q = $city.val();
            timer = setTimeout(function() {
                if ((q || '').trim().length < 2) { $dropdown.hide().empty(); return; }
                var fd = new FormData();
                fd.append('action', 'yd_address_suggest');
                fd.append('nonce', window.wp_data.yd_address_suggest_nonce || '');
                fd.append('query', q);
                fd.append('type', 'city');
                fetch(window.wp_data.ajax_url, { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        var list = (res && res.data && res.data.suggestions) ? res.data.suggestions : [];
                        $dropdown.empty();
                        if (!list.length) { $dropdown.hide(); return; }
                        list.forEach(function(s) {
                            var label = s.city || s.value;
                            if (s.region && s.region !== label) label += ', ' + s.region;
                            var $item = jQuery('<div style="padding:8px 12px;cursor:pointer;border-bottom:1px solid #f0f0f1;font-size:14px;color:#1d2327;background:#fff;"></div>').text(label);
                            $item.on('mouseenter', function() { jQuery(this).css({'background':'#f0f7ff','color':'#1d2327'}); });
                            $item.on('mouseleave', function() { jQuery(this).css({'background':'#fff','color':'#1d2327'}); });
                            $item.on('mousedown', function(e) { e.preventDefault(); fillCity(s); });
                            $dropdown.append($item);
                        });
                        $dropdown.show();
                    })
                    .catch(function() { $dropdown.hide().empty(); });
            }, 300);
        });

        jQuery(document).on('click', function(e) {
            if (!jQuery(e.target).closest('.nd-city-suggest, #' + cityId).length) $dropdown.hide();
        });
    });
}

jQuery(document).ready(function () {
    ndDeleteCookie('yd_pvz_code');
    ndAttachCitySuggest();

    // Скрываем/показываем поля при загрузке (с задержкой — WC может ещё не отрисовать методы)
    ndToggleAddressField();
    ndMaybeAttachAddressSuggest();
    setTimeout(function() { ndToggleAddressField(); }, 500);
    setTimeout(function() { ndToggleAddressField(); }, 1500);

    // И после каждого обновления чекаута
    jQuery(document.body).on('updated_checkout updated_shipping_method', function() {
        ndToggleAddressField();
        ndMaybeAttachAddressSuggest();
        ndAttachCitySuggest();
    });

    // Обновляем чекаут при загрузке, если город уже заполнен
    if (jQuery('.woocommerce-checkout').length || jQuery('.wp-block-woocommerce-checkout').length) {
        if (getCityField() && getCityField().val() && getCityField().val().length) {
            jQuery(document.body).trigger('update_checkout');
        }
    }

    jQuery('#billing_postcode').on('blur',function(){
        if (!jQuery('#ship-to-different-address-checkbox').prop('checked')){
            jQuery( document.body ).trigger( 'update_checkout' );
        }
    });
    jQuery('#billing_state').on('blur',function(){
        if (!jQuery('#ship-to-different-address-checkbox').prop('checked')){
            jQuery( document.body ).trigger( 'update_checkout' );
        }
    });
    jQuery('#billing_city').on('blur',function(){
        if (!jQuery('#ship-to-different-address-checkbox').prop('checked')){
            ndResetPvzSelection();
            setTimeout(function(){ jQuery( document.body ).trigger( 'update_checkout' ); }, 200);
        }
    });
    jQuery('#shipping_city').on('focusout',function(){
        ndResetPvzSelection();
        setTimeout(function(){ jQuery( document.body ).trigger( 'update_checkout' ); }, 200);
    });
    jQuery('#shipping_state').on('focusout',function(){
        jQuery( document.body ).trigger( 'update_checkout' );
    });
    jQuery('#shipping_postcode').on('focusout',function(){
        jQuery( document.body ).trigger( 'update_checkout' );
    });

    ndMaybeAttachAddressSuggest();
    ndToggleAddressField();
});
