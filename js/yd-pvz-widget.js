/**
 * YD PVZ Widget — Виджет выбора ПВЗ на Яндекс Картах
 */
(function() {
    'use strict';

    var YD_PVZ = {
        map: null,
        modal: null,
        points: [],
        selectedPoint: null,
        onSelect: null,
        city: '',

        open: function(city, onSelectCallback) {
            console.log('[YD_PVZ] open() called, city:', city);
            try {
            this.city = city || '';
            this.onSelect = onSelectCallback;
            this.createModal();
            console.log('[YD_PVZ] modal created, loading points...');
            this.loadPoints(city);
            } catch(err) { console.error('[YD_PVZ] ERROR in open():', err); alert('Ошибка виджета ПВЗ: ' + err.message); }
        },

        createModal: function() {
            if (this.modal) {
                this.modal.style.display = 'flex';
                return;
            }

            var overlay = document.createElement('div');
            overlay.id = 'yd-pvz-overlay';
            overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:2147483647;display:flex;align-items:center;justify-content:center;';

            var container = document.createElement('div');
            container.style.cssText = 'background:#fff;border-radius:12px;width:90%;max-width:900px;height:80vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 8px 32px rgba(0,0,0,0.3);';

            var header = document.createElement('div');
            header.style.cssText = 'padding:16px 20px;border-bottom:1px solid #e0e0e0;display:flex;align-items:center;gap:12px;flex-shrink:0;';
            header.innerHTML = '<div style="flex:1"><strong style="font-size:18px;">Выберите пункт выдачи</strong></div>';

            var searchInput = document.createElement('input');
            searchInput.type = 'text';
            searchInput.placeholder = 'Поиск по адресу...';
            searchInput.style.cssText = 'padding:8px 12px;border:1px solid #ddd;border-radius:6px;font-size:14px;width:250px;';
            header.appendChild(searchInput);

            var closeBtn = document.createElement('button');
            closeBtn.innerHTML = '&times;';
            closeBtn.style.cssText = 'background:none;border:none;font-size:24px;cursor:pointer;color:#666;padding:0 4px;';
            closeBtn.onclick = this.close.bind(this);
            header.appendChild(closeBtn);

            var body = document.createElement('div');
            body.style.cssText = 'display:flex;flex:1;overflow:hidden;';

            var mapDiv = document.createElement('div');
            mapDiv.id = 'yd-pvz-map';
            mapDiv.style.cssText = 'flex:1;min-height:300px;';

            var listDiv = document.createElement('div');
            listDiv.id = 'yd-pvz-list';
            listDiv.style.cssText = 'width:320px;overflow-y:auto;border-left:1px solid #e0e0e0;';

            body.appendChild(mapDiv);
            body.appendChild(listDiv);
            container.appendChild(header);
            container.appendChild(body);
            overlay.appendChild(container);
            document.body.appendChild(overlay);
            this.modal = overlay;

            var self = this;
            overlay.addEventListener('click', function(e) { if (e.target === overlay) self.close(); });
            searchInput.addEventListener('input', function() { self.filterPoints(this.value); });
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && self.modal && self.modal.style.display !== 'none') self.close();
            });
        },

        close: function() {
            if (this.modal) this.modal.style.display = 'none';
        },

        loadPoints: function(city) {
            var self = this;
            var listDiv = document.getElementById('yd-pvz-list');
            if (listDiv) listDiv.innerHTML = '<div style="padding:20px;text-align:center;color:#666;">Загрузка ПВЗ...</div>';

            // Очищаем карту
            var mapDiv = document.getElementById('yd-pvz-map');
            if (mapDiv && !this.map) {
                mapDiv.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#999;">Загрузка карты...</div>';
            }

            if (!window.wp_data || !window.wp_data.ajax_url) {
                if (listDiv) listDiv.innerHTML = '<div style="padding:20px;color:#d63638;">wp_data не найден</div>';
                return;
            }

            var fd = new FormData();
            fd.append('action', 'yd_get_pvz_points');
            fd.append('nonce', window.wp_data.yd_nonce || '');
            fd.append('city', city);

            console.log('[YD_PVZ] Fetching points for city:', city, 'url:', window.wp_data.ajax_url);
            fetch(window.wp_data.ajax_url, { method: 'POST', body: fd })
                .then(function(r) { console.log('[YD_PVZ] Response status:', r.status); return r.json(); })
                .then(function(res) {
                    console.log('[YD_PVZ] AJAX result:', res.success, 'points:', res.data && res.data.points ? res.data.points.length : 0);
                    if (res.success && res.data && res.data.points) {
                        self.points = res.data.points;
                        self.renderList(self.points);
                        self.initMap(self.points);
                    } else {
                        var msg = (res.data && res.data.message) ? res.data.message : 'Пункты не найдены';
                        if (listDiv) listDiv.innerHTML = '<div style="padding:20px;text-align:center;color:#d63638;">' + self.esc(msg) + '</div>';
                    }
                })
                .catch(function(err) {
                    if (listDiv) listDiv.innerHTML = '<div style="padding:20px;text-align:center;color:#d63638;">Ошибка: ' + self.esc(err.message) + '</div>';
                });
        },

        renderList: function(points) {
            var listDiv = document.getElementById('yd-pvz-list');
            if (!listDiv) return;
            listDiv.innerHTML = '';

            if (!points.length) {
                listDiv.innerHTML = '<div style="padding:20px;text-align:center;color:#666;">Нет пунктов</div>';
                return;
            }

            var self = this;
            points.forEach(function(p) {
                var item = document.createElement('div');
                item.style.cssText = 'padding:12px 16px;border-bottom:1px solid #f0f0f1;cursor:pointer;transition:background 0.15s;';
                item.innerHTML = '<div style="font-weight:600;font-size:14px;margin-bottom:4px;">' + self.esc(p.address) + '</div>' +
                    (p.schedule ? '<div style="font-size:12px;color:#666;">' + self.esc(p.schedule) + '</div>' : '');
                item.addEventListener('mouseenter', function() { this.style.background = '#f0f7ff'; });
                item.addEventListener('mouseleave', function() { this.style.background = ''; });
                item.addEventListener('click', function() {
                    self.selectPoint(p);
                    // Подсветить на карте
                    if (self.map && p.lat && p.lng) {
                        self.map.setCenter([parseFloat(p.lat), parseFloat(p.lng)], 16);
                    }
                });
                listDiv.appendChild(item);
            });
        },

        filterPoints: function(query) {
            query = (query || '').toLowerCase();
            var filtered = query ? this.points.filter(function(p) {
                return (p.address || '').toLowerCase().indexOf(query) !== -1;
            }) : this.points;
            this.renderList(filtered);
        },

        initMap: function(points) {
            var mapDiv = document.getElementById('yd-pvz-map');
            if (!mapDiv) return;

            // Убираем placeholder "Загрузка карты..."
            mapDiv.innerHTML = '';

            // Фильтруем точки с координатами
            var geoPoints = points.filter(function(p) {
                return p.lat && p.lng && parseFloat(p.lat) !== 0 && parseFloat(p.lng) !== 0;
            });

            if (!geoPoints.length) {
                mapDiv.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#999;font-size:14px;">Координаты ПВЗ не загружены.<br>Пересохраните настройки метода доставки.</div>';
                return;
            }

            // Ждём загрузки ymaps
            if (typeof ymaps === 'undefined') {
                mapDiv.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#999;">API Яндекс Карт не загружен. Проверьте ключ в настройках.</div>';
                return;
            }

            var self = this;
            ymaps.ready(function() {
                if (self.map) {
                    try { self.map.destroy(); } catch(e) {}
                    self.map = null;
                }

                var center = [parseFloat(geoPoints[0].lat), parseFloat(geoPoints[0].lng)];

                self.map = new ymaps.Map('yd-pvz-map', {
                    center: center,
                    zoom: 12,
                    controls: ['zoomControl', 'geolocationControl']
                });

                var clusterer = new ymaps.Clusterer({
                    preset: 'islands#invertedOrangeClusterIcons',
                    clusterDisableClickZoom: false
                });

                var placemarks = [];
                geoPoints.forEach(function(p) {
                    var pm = new ymaps.Placemark(
                        [parseFloat(p.lat), parseFloat(p.lng)],
                        {
                            balloonContentHeader: '<strong style="font-size:14px;">' + self.esc(p.address) + '</strong>',
                            balloonContentBody: (p.schedule ? '<div style="margin:4px 0;color:#666;">' + self.esc(p.schedule) + '</div>' : '') +
                                '<button data-yd-pvz-select="' + self.esc(p.id) + '" style="margin-top:8px;padding:8px 20px;background:#FFD60A;border:none;border-radius:6px;cursor:pointer;font-weight:600;font-size:14px;">Выбрать этот ПВЗ</button>',
                            hintContent: p.address
                        },
                        { preset: 'islands#orangeDotIcon' }
                    );
                    placemarks.push(pm);
                });

                clusterer.add(placemarks);
                self.map.geoObjects.add(clusterer);

                // Центрируем на все точки
                if (placemarks.length > 1) {
                    self.map.setBounds(clusterer.getBounds(), { checkZoomRange: true, zoomMargin: 50 });
                }
            });
        },

        selectById: function(id) {
            for (var i = 0; i < this.points.length; i++) {
                if (this.points[i].id === id) {
                    this.selectPoint(this.points[i]);
                    return;
                }
            }
        },

        selectPoint: function(point) {
            if (!point) return;
            this.selectedPoint = point;
            this.close();

            document.cookie = 'yd_pvz_code=' + encodeURIComponent(point.id) + ';path=/;SameSite=Lax';
            document.cookie = 'yd_pvz_address=' + encodeURIComponent(point.address || '') + ';path=/;SameSite=Lax';

            if (typeof this.onSelect === 'function') {
                this.onSelect(point);
                // update_checkout вызывается из onSelect callback (yandex-dostavka.js)
                // после AJAX yd_update — не дублируем здесь
            } else if (typeof jQuery !== 'undefined') {
                // Fallback: если onSelect не задан, обновляем сами
                jQuery(document.body).trigger('update_checkout');
            }
        },

        esc: function(str) {
            var div = document.createElement('div');
            div.textContent = str || '';
            return div.innerHTML;
        }
    };

    window.YD_PVZ = YD_PVZ;

    // Полифилл closest для IE11 и старых браузеров
    if (!Element.prototype.closest) {
        Element.prototype.closest = function(s) {
            var el = this;
            do {
                if (el.matches ? el.matches(s) : el.msMatchesSelector ? el.msMatchesSelector(s) : false) return el;
                el = el.parentElement || el.parentNode;
            } while (el !== null && el.nodeType === 1);
            return null;
        };
    }

    // Делегация: клик по кнопке «Выбрать этот ПВЗ» в балуне Яндекс Карт
    // (inline onclick не работает в Яндекс Браузере из-за CSP)
    document.addEventListener('click', function(e) {
        var el = e.target;
        var btn = null;
        // Проверяем сам элемент и родителей
        if (el.getAttribute && el.getAttribute('data-yd-pvz-select')) {
            btn = el;
        } else if (el.closest) {
            btn = el.closest('[data-yd-pvz-select]');
        } else {
            // Ручной обход для совсем старых браузеров
            var node = el;
            while (node && node !== document.body) {
                if (node.getAttribute && node.getAttribute('data-yd-pvz-select')) { btn = node; break; }
                node = node.parentElement;
            }
        }
        if (btn) {
            var pvzId = btn.getAttribute('data-yd-pvz-select');
            if (pvzId) YD_PVZ.selectById(pvzId);
        }
    });
})();
