/* ==========================================================================
   ORPHANS MODEL NURSERY AND PRIMARY SCHOOL (MAKARANTAR MARAYU)
   School Result Management Portal - shared behaviour
   ========================================================================== */

(function () {
    'use strict';

    var APP_URL = (function () {
        var link = document.querySelector('link[href*="assets/css/style.css"]');
        if (!link) { return '/school-result-portal'; }
        var href = link.getAttribute('href') || '';
        return href.replace(/assets\/css\/style\.css.*$/, '');
    })();

    /* ---------------------------------------------------------------- */
    /* Sidebar (off-canvas on small screens)                           */
    /* ---------------------------------------------------------------- */

    function initSidebar() {
        var sidebar = document.getElementById('appSidebar');
        var toggle = document.getElementById('sidebarToggle');
        var backdrop = document.querySelector('.sidebar-backdrop');

        if (!sidebar || !toggle) { return; }

        function close() {
            sidebar.classList.remove('is-open');
            if (backdrop) { backdrop.classList.remove('is-open'); }
        }

        toggle.addEventListener('click', function () {
            sidebar.classList.toggle('is-open');
            if (backdrop) {
                backdrop.classList.toggle('is-open', sidebar.classList.contains('is-open'));
            }
        });

        if (backdrop) {
            backdrop.addEventListener('click', close);
        }

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') { close(); }
        });
    }

    /* ---------------------------------------------------------------- */
    /* Show / hide password                                            */
    /* ---------------------------------------------------------------- */

    function initPasswordToggles() {
        document.querySelectorAll('[data-password-toggle]').forEach(function (button) {
            button.addEventListener('click', function () {
                var input = button.parentElement.querySelector('input');
                if (!input) { return; }

                var isPassword = input.type === 'password';
                input.type = isPassword ? 'text' : 'password';
                button.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');

                var icon = button.querySelector('i');
                if (icon) {
                    icon.className = isPassword ? 'bi bi-eye-slash' : 'bi bi-eye';
                }
            });
        });
    }

    /* ---------------------------------------------------------------- */
    /* Dependent class list (section -> class)                         */
    /* ---------------------------------------------------------------- */

    function initClassLoader() {
        var sectionSelect = document.querySelector('[data-class-section]');
        var classSelect = document.querySelector('[data-class-target]');
        var statusSelect = document.querySelector('[data-class-status]');

        if (!sectionSelect || !classSelect) { return; }

        function load() {
            var section = sectionSelect.value;
            var url = APP_URL + '/admin/ajax-classes.php?section=' + encodeURIComponent(section);

            if (statusSelect && statusSelect.value) {
                url += '&status=' + encodeURIComponent(statusSelect.value);
            }

            classSelect.disabled = true;
            classSelect.innerHTML = '<option value="">Loading classes...</option>';

            fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (response) {
                    if (!response.ok) { throw new Error('request failed'); }
                    return response.json();
                })
                .then(function (data) {
                    var classes = (data && data.classes) ? data.classes : [];
                    var selected = classSelect.dataset.selected || '';

                    if (classes.length === 0) {
                        classSelect.innerHTML = '<option value="">No class available for this section</option>';
                        return;
                    }

                    var html = '<option value="">Select class</option>';
                    classes.forEach(function (item) {
                        html += '<option value="' + item.id + '"' +
                            (String(item.id) === String(selected) ? ' selected' : '') + '>' +
                            item.class_name + '</option>';
                    });

                    classSelect.innerHTML = html;
                })
                .catch(function () {
                    classSelect.innerHTML = '<option value="">Could not load classes. Please reload.</option>';
                })
                .finally(function () {
                    classSelect.disabled = false;
                });
        }

        sectionSelect.addEventListener('change', load);

        if (statusSelect) {
            statusSelect.addEventListener('change', load);
        }
    }

    /* ---------------------------------------------------------------- */
    /* Passport photo preview                                          */
    /* ---------------------------------------------------------------- */

    function initPhotoPreview() {
        var input = document.querySelector('[data-photo-input]');
        var preview = document.querySelector('[data-photo-preview]');

        if (!input || !preview) { return; }

        input.addEventListener('change', function () {
            var file = input.files && input.files[0];

            if (!file) { return; }

            if (file.size > 2097152) {
                window.alert('Passport photo must be smaller than 2 MB.');
                input.value = '';
                return;
            }

            if (['image/jpeg', 'image/pjpeg', 'image/png', 'image/webp'].indexOf(file.type) === -1) {
                window.alert('Please choose a JPG, PNG or WEBP image.');
                input.value = '';
                return;
            }

            var reader = new FileReader();
            reader.onload = function (event) {
                preview.src = event.target.result;
            };
            reader.readAsDataURL(file);
        });
    }

    /* ---------------------------------------------------------------- */
    /* Confirmation for destructive actions                            */
    /* ---------------------------------------------------------------- */

    function initConfirmButtons() {
        document.querySelectorAll('[data-confirm]').forEach(function (element) {
            element.addEventListener('click', function (event) {
                if (!window.confirm(element.getAttribute('data-confirm'))) {
                    event.preventDefault();
                }
            });
        });
    }

    /* ---------------------------------------------------------------- */
    /* Buttons that need a reason typed in before they will submit     */
    /* ---------------------------------------------------------------- */

    function initReasonButtons() {
        document.querySelectorAll('[data-requires-reason]').forEach(function (button) {
            button.form.addEventListener('submit', function (event) {
                if (event.submitter !== button) {
                    return;
                }

                var reason = button.form.querySelector('[name="reason"]');
                var value = reason ? reason.value.trim() : '';

                if (value === '') {
                    event.preventDefault();
                    window.alert(button.getAttribute('data-requires-reason'));
                    if (reason) {
                        reason.focus();
                    }
                }
            });
        });
    }

    /* ---------------------------------------------------------------- */
    /* Select all checkboxes (bulk enrollment)                        */
    /* ---------------------------------------------------------------- */

    function initSelectAll() {
        var toggle = document.querySelector('[data-check-all]');
        if (!toggle) { return; }

        var boxes = Array.prototype.slice.call(document.querySelectorAll('[data-check-item]'));

        toggle.addEventListener('change', function () {
            boxes.forEach(function (box) { box.checked = toggle.checked; });
        });

        boxes.forEach(function (box) {
            box.addEventListener('change', function () {
                var checked = boxes.filter(function (b) { return b.checked; }).length;
                toggle.checked = checked > 0 && checked === boxes.length;
                toggle.indeterminate = checked > 0 && checked < boxes.length;
            });
        });
    }

    /* ---------------------------------------------------------------- */
    /* Client side validation                                          */
    /* ---------------------------------------------------------------- */

    function initValidation() {
        document.querySelectorAll('form[data-validate]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (!form.checkValidity()) {
                    event.preventDefault();
                    event.stopPropagation();

                    var firstInvalid = form.querySelector(':invalid');
                    if (firstInvalid) { firstInvalid.focus(); }

                    form.classList.add('was-validated');
                }
            });
        });
    }

    /* ---------------------------------------------------------------- */
    /* Flash auto dismiss                                              */
    /* ---------------------------------------------------------------- */

    function initFlashDismiss() {
        document.querySelectorAll('[data-flash]').forEach(function (alert) {
            window.setTimeout(function () {
                if (window.bootstrap && window.bootstrap.Alert) {
                    window.bootstrap.Alert.getOrCreateInstance(alert).close();
                }
            }, 7000);
        });
    }

    /* ---------------------------------------------------------------- */
    /* Init                                                             */
    /* ---------------------------------------------------------------- */

    document.addEventListener('DOMContentLoaded', function () {
        initSidebar();
        initPasswordToggles();
        initClassLoader();
        initPhotoPreview();
        initConfirmButtons();
        initReasonButtons();
        initSelectAll();
        initValidation();
        initFlashDismiss();
    });
})();
