(function () {
    // The editor draws the form preview in JavaScript; an unknown field type would show nothing.
    function register() {
        if (!window.elementor || !window.elementor.hooks) return;
        window.elementor.hooks.addFilter('elementor_pro/forms/content_template/field/cap_captcha', function () {
            var box = document.createElement('div');
            box.className = 'elementor-field';
            box.style.cssText = 'padding:12px 16px;border:1px dashed currentColor;border-radius:8px;opacity:.7';
            box.textContent = window.CAP_CAPTCHA_ELEMENTOR_LABEL || 'Privacy CAPTCHA for Cap';
            return box.outerHTML;
        }, 10, 1);
    }

    if (window.elementor && window.elementor.hooks) {
        register();
    } else {
        window.addEventListener('elementor/init', register);
    }
})();
