/*
 * Cap CAPTCHA token for the atomic form element of Elementor 4.
 *
 * The element builds its request by hand and offers one way in: before it
 * sends, it fires an event whose addFields() takes extra fields, or a promise
 * of them. A fresh token is solved in the background for every attempt, so a
 * spent one is never sent twice.
 */
(function () {
    var cfg = window.CAP_CAPTCHA_ATOMIC_FORM;
    if (!cfg || !cfg.apiEndpoint) return;

    document.addEventListener('elementor/atomic-form/before-submit', function (event) {
        var detail = event.detail;
        if (!detail || typeof detail.addFields !== 'function' || typeof window.Cap === 'undefined') return;

        detail.addFields(new window.Cap({ apiEndpoint: cfg.apiEndpoint }).solve().then(function (result) {
            return { 'cap-token': result.token };
        }).catch(function () {
            // Without a token the server refuses, and the form shows its own error message.
            return {};
        }));
    });
})();
