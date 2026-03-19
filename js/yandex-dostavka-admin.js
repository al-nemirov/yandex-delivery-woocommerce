/* Yandex Dostavka — Admin JS v2.34 */

var ndSettings = {
    apiKeyFieldId: '',
    receptionPointFieldId: ''
};

/* ======== Order edit page: reception_point select change ======== */
jQuery(document).on('change', 'select[id*="reception_point"]', function() {
    var pointId = jQuery(this).val();
    var postId = jQuery('input#post_ID').val();
    if (!window.wp_data || !window.wp_data.ajax_url) return;
    jQuery.ajax({
        url: window.wp_data.ajax_url,
        type: 'POST',
        data: {
            action: 'yd_admin_reception_point_update',
            nonce: window.wp_data.yd_admin_nonce || '',
            point_id: pointId,
            postId: postId
        }
    });
});

/* ======== Order edit page: yandex widget open ======== */
document.addEventListener('click', function(e) {
    if (e.target && (e.target instanceof HTMLElement) && e.target.getAttribute('data-yandex-dostavka-open') == 'true') {
        e.preventDefault();
        var selectedPointLink = e.target;
        (function(link) {
            var city = link.getAttribute('data-yandex-dostavka-city') || undefined;
            var token = link.getAttribute('data-yandex-dostavka-widget-key');
            var targetstart = link.getAttribute('data-yandex-dostavka-reception-point') || '';
            var weight = '1000';
            var data_id = link.getAttribute('data-id');
            var pointSelectedHandler = function(result) {
                var selectedPointName = result.name + ' (' + result.address + ')';
                link.textContent = selectedPointName;
                if (!window.wp_data || !window.wp_data.ajax_url) return;
                var xhr = new XMLHttpRequest();
                xhr.open('POST', window.wp_data.ajax_url, true);
                var fd = new FormData();
                fd.append('action', 'yd_admin_update');
                fd.append('nonce', window.wp_data.yd_admin_nonce || '');
                fd.append('id', data_id);
                fd.append('code', result.id);
                fd.append('address', selectedPointName);
                xhr.onreadystatechange = function() {
                    if (xhr.readyState == 4 && xhr.status == 200) {
                        location.reload();
                    }
                };
                xhr.send(fd);
            };
            if (typeof yandex !== 'undefined') {
                yandex.open(pointSelectedHandler, token, city, targetstart, '0', weight);
            }
        })(selectedPointLink);
    }
}, true);

/* ======== Settings page: Autocomplete for reception_point ======== */
function initYdAutocomplete(fieldId) {
    if (!window.wp_data || !window.wp_data.ajax_url) {
        console.warn('[ND] wp_data not available, cannot init autocomplete');
        return false;
    }
    if (typeof jQuery.fn.autocomplete !== 'function') {
        console.warn('[ND] jQuery UI Autocomplete not loaded');
        return false;
    }
    var $field = jQuery('#' + fieldId);
    if (!$field.length) {
        console.warn('[ND] Reception point field not found: #' + fieldId);
        return false;
    }
    if ($field.data('autocomplete-initialized')) {
        return true;
    }

    console.log('[ND] Initializing autocomplete on #' + fieldId);
    $field.attr('placeholder', 'Начните вводить название города...');

    // Ensure field is enabled for typing
    $field.removeAttr('disabled').removeAttr('readonly');

    $field.autocomplete({
        source: function(request, response) {
            console.log('[ND] Searching: "' + request.term + '"');
            jQuery.ajax({
                url: window.wp_data.ajax_url,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'yd_admin_reception_point_search',
                    nonce: window.wp_data.yd_admin_nonce || '',
                    term: request.term,
                    api_key: ndSettings.apiKeyFieldId ? jQuery('#' + ndSettings.apiKeyFieldId).val() || '' : ''
                },
                success: function(data) {
                    console.log('[ND] Search results:', data);
                    if (Array.isArray(data) && data.length > 0) {
                        response(data);
                    } else {
                        response([{label: 'Нет данных. Сохраните настройки с корректным API-токеном.', value: ''}]);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[ND] Search AJAX error:', status, error, xhr.responseText);
                    response([{label: 'Ошибка загрузки (' + status + ')', value: ''}]);
                }
            });
        },
        minLength: 2,
        select: function(event, ui) {
            if (!ui.item.value) return false;
            $field.val(ui.item.label);
            $field.data('selected-value', ui.item.value);
            console.log('[ND] Selected: ' + ui.item.label + ' (code: ' + ui.item.value + ')');
            return false;
        },
        focus: function(event, ui) {
            if (!ui.item.value) return false;
            $field.val(ui.item.label);
            return false;
        }
    });

    $field.data('autocomplete-initialized', true);
    console.log('[ND] Autocomplete ready on #' + fieldId);
    return true;
}

/* ======== Settings page: API key check ======== */
function checkYdApiKeys() {
    var apiKeyFieldIds = [
        'woocommerce_yd_self_key',
        'woocommerce_yd_self_after_key',
        'woocommerce_yd_courier_key',
        'woocommerce_yd_courier_after_key'
    ];

    apiKeyFieldIds.forEach(function(fieldId) {
        var field = jQuery('#' + fieldId);
        if (!field.length) return;

        var rpFieldId = fieldId.replace('key', 'reception_point');
        ndSettings.apiKeyFieldId = field.attr('id');
        ndSettings.receptionPointFieldId = rpFieldId;

        // Always init autocomplete, regardless of key check result
        initYdAutocomplete(rpFieldId);

        var $rpField = jQuery('#' + rpFieldId);
        var $desc = $rpField.closest('tr').find('.description');

        if (field.val() === '' || !window.wp_data || !window.wp_data.ajax_url) {
            if ($desc.length) {
                $desc.html('<span style="color:#dba617;">Введите API-токен для загрузки пунктов приёма.</span>');
            }
            console.log('[ND] Key field empty: ' + fieldId);
            return;
        }

        console.log('[ND] Checking API key for: ' + fieldId);
        $rpField.css('opacity', '0.6');

        (function(currentRpFieldId) {
            jQuery.ajax({
                url: window.wp_data.ajax_url,
                type: 'POST',
                data: {
                    action: 'yd_admin_check_api_key',
                    nonce: window.wp_data.yd_admin_nonce || '',
                    api_key: field.val()
                },
                success: function(response) {
                    console.log('[ND] API key check result for ' + currentRpFieldId + ':', response);
                    var $rp = jQuery('#' + currentRpFieldId);
                    var $d = $rp.closest('tr').find('.description');
                    if (response.success === true) {
                        if (response.data && response.data.reset_point_for_parcels === true) {
                            $rp.val('');
                        }
                        $rp.removeAttr('disabled');
                        if ($d.length) {
                            $d.html('<span style="color:#00a32a;">Токен проверен. Введите город для поиска.</span>');
                        }
                    } else {
                        // DON'T disable — just show warning
                        var msg = (response.data && response.data.message) ? response.data.message : 'Ошибка проверки токена';
                        if ($d.length) {
                            $d.html('<span style="color:#d63638;">' + msg + '</span>');
                        }
                        console.warn('[ND] API key check failed:', response);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[ND] API key check AJAX error:', status, error);
                    var $d = jQuery('#' + currentRpFieldId).closest('tr').find('.description');
                    if ($d.length) {
                        $d.html('<span style="color:#d63638;">Ошибка проверки токена (' + status + ')</span>');
                    }
                },
                complete: function() {
                    jQuery('#' + currentRpFieldId).css('opacity', '');
                }
            });
        })(rpFieldId);
    });
}

/* ======== Init on document ready ======== */
jQuery(document).ready(function() {
    console.log('[ND] Admin JS loaded, wp_data:', window.wp_data ? 'OK' : 'MISSING');
    console.log('[ND] jQuery UI Autocomplete:', typeof jQuery.fn.autocomplete === 'function' ? 'OK' : 'NOT LOADED');

    checkYdApiKeys();

    // Re-check keys on blur
    var apiKeyFieldIds = [
        'woocommerce_yd_self_key',
        'woocommerce_yd_self_after_key',
        'woocommerce_yd_courier_key',
        'woocommerce_yd_courier_after_key'
    ];
    apiKeyFieldIds.forEach(function(fieldId) {
        jQuery('#' + fieldId).on('blur', checkYdApiKeys);
    });

    // Fallback: init autocomplete on any text input matching reception_point pattern
    jQuery('input[type="text"][id$="_reception_point"]').each(function() {
        var id = jQuery(this).attr('id');
        if (id && !jQuery(this).data('autocomplete-initialized')) {
            console.log('[ND] Fallback: initializing autocomplete on #' + id);
            initYdAutocomplete(id);
        }
    });
});
