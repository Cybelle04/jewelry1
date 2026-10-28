    <script>
    (function () {
        'use strict';

        var notificationsData = <?php echo json_encode($admin_notifications, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

        var NOTICE_STATE_KEY = 'rjewel_admin_notice_state_v1';
        var SETTINGS_KEY     = 'rjewel_admin_settings_v1';

        /* ---------- Load saved state ---------- */
        var savedNoticeState = {};
        try { savedNoticeState = JSON.parse(localStorage.getItem(NOTICE_STATE_KEY) || '{}'); } catch (e) {}

        function loadPrefs() {
            try { return JSON.parse(localStorage.getItem(SETTINGS_KEY) || '{}'); }
            catch (e) { return {}; }
        }

        function persistNoticeState() {
            var payload = {
                notif: {
                    read:    state.notif.data.filter(function (x) { return !x.unread; }).map(function (x) { return String(x.id); }),
                    deleted: state.notif.deleted.map(String)
                }
            };
            try { localStorage.setItem(NOTICE_STATE_KEY, JSON.stringify(payload)); } catch (e) {}
        }

        function restoreNoticeItems(items) {
            var saved   = savedNoticeState.notif || { read: [], deleted: [] };
            var deleted = (saved.deleted || []).map(String);
            var read    = (saved.read || []).map(String);
            return items
                .filter(function (item) { return !deleted.includes(String(item.id)); })
                .map(function (item) {
                    return Object.assign({}, item, {
                        unread: read.includes(String(item.id)) ? false : Boolean(item.unread)
                    });
                });
        }

        /* ---------- State ---------- */
        var state = {
            notif: {
                data:    restoreNoticeItems(notificationsData || []),
                deleted: (savedNoticeState.notif && savedNoticeState.notif.deleted) || []
            }
        };

        /* ---------- Helpers ---------- */
        function escapeHtml(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }

        function getFiltered() {
            var prefs = loadPrefs();
            var list  = state.notif.data.slice();

            list = list.filter(function (item) {
                var id = String(item.id || '');
                if (id.indexOf('order-') === 0) {
                    var isNew = String(item.title || '').indexOf('New order') === 0;
                    if (isNew) return prefs.toggleNewOrders !== false;
                    return prefs.toggleOrderStatus === true;
                }
                if (id.indexOf('stock-') === 0)    return prefs.toggleLowStock !== false;
                if (id.indexOf('customer-') === 0) return prefs.toggleNewCustomer !== false;
                return true;
            });

            return list;
        }

        function unreadCount() {
            return state.notif.data.filter(function (i) { return i.unread; }).length;
        }

        /* ---------- Render ---------- */
        function render() {
            var listEl  = document.getElementById('notifList');
            var badgeEl = document.getElementById('notifBadge');
            var unreadEl = document.getElementById('notifUnreadCount');
            var totalEl  = document.getElementById('notifTotalCount');
            var markAll  = document.getElementById('markAllNotif');
            if (!listEl || !badgeEl) return;

            var items = getFiltered();
            listEl.innerHTML = '';

            if (items.length === 0) {
                listEl.innerHTML = '<div class="notif-empty"><i class="fas fa-inbox"></i><p>No notifications yet</p></div>';
            } else {
                items.slice(0, 8).forEach(function (item) {
                    var div = document.createElement('div');
                    div.className = 'notif-item' + (item.unread ? ' unread' : '');
                    div.innerHTML =
                        '<div class="notif-icon ' + item.iconClass + '"><i class="fas fa-' + item.icon + '"></i></div>' +
                        '<div class="notif-content">' +
                            '<p>' + escapeHtml(item.title) + '</p>' +
                            (item.desc ? '<div class="notif-desc">' + escapeHtml(item.desc) + '</div>' : '') +
                            '<div class="notif-meta"><i class="fas fa-clock"></i> ' + escapeHtml(item.meta) + '</div>' +
                        '</div>';

                    div.addEventListener('click', function (e) {
                        e.stopPropagation();
                        if (item.unread) {
                            item.unread = false;
                            persistNoticeState();
                            render();
                        }
                    });

                    listEl.appendChild(div);
                });
            }

            var count = unreadCount();
            var total = state.notif.data.length;

            if (unreadEl) unreadEl.textContent = count;
            if (totalEl)  totalEl.textContent  = total;
            if (markAll)  markAll.disabled     = (count === 0);

            if (count > 0) {
                var prev = parseInt(badgeEl.textContent, 10) || 0;
                badgeEl.textContent = count > 99 ? '99+' : count;
                badgeEl.classList.remove('hidden');
                if (count > prev) {
                    badgeEl.classList.remove('bump');
                    void badgeEl.offsetWidth;
                    badgeEl.classList.add('bump');
                }
            } else {
                badgeEl.classList.add('hidden');
            }
        }

        /* ---------- Toggle panel ---------- */
        var notifBtn   = document.getElementById('notifBtn');
        var notifPanel = document.getElementById('notifPanel');

        function closePanel() {
            if (notifPanel) notifPanel.classList.remove('open');
            if (notifBtn)   { notifBtn.classList.remove('active'); notifBtn.setAttribute('aria-expanded', 'false'); }
        }

        if (notifBtn && notifPanel) {
            notifBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                var isOpen = notifPanel.classList.contains('open');
                if (isOpen) {
                    closePanel();
                } else {
                    notifPanel.classList.add('open');
                    notifBtn.classList.add('active');
                    notifBtn.setAttribute('aria-expanded', 'true');
                }
            });

            document.addEventListener('click', function (e) {
                if (!e.target.closest('.notif-wrapper')) closePanel();
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') closePanel();
            });
        }

        /* ---------- Mark all read ---------- */
        var markAll = document.getElementById('markAllNotif');
        if (markAll) {
            markAll.addEventListener('click', function (e) {
                e.stopPropagation();
                state.notif.data.forEach(function (n) { n.unread = false; });
                persistNoticeState();
                render();
            });
        }

        /* ---------- Initial render ---------- */
        render();
    })();
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>