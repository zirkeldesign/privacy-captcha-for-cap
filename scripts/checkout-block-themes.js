/*
 * Measures where the widget sits in the WooCommerce Checkout block, theme by
 * theme, at a phone and a desktop width. Paste into the browser console on the
 * checkout page of a test shop with something in the cart and the inline
 * display mode. Every line must read:
 *
 *   gap 24 | below button 36 | summary above | page height +0
 *
 * "page height" compares the page with and without the plugin's layout rules:
 * anything but +0 means they moved something else in the form.
 *
 * The test shop needs the themes installed and this mu-plugin, which picks the
 * theme per request:
 *
 *   foreach (['stylesheet', 'template'] as $hook) {
 *       add_filter($hook, static fn ($theme) => isset($_GET['capth']) ? sanitize_key($_GET['capth']) : $theme);
 *   }
 *
 * Last run on Twenty Twenty-Five, Twenty Twenty-Four, Storefront, Astra,
 * Kadence, GeneratePress, Blocksy, OceanWP, Neve and Hello Elementor. OceanWP
 * is the one with an unlayered `div { margin: 0 }` reset.
 */
(async () => {
    const themes = ['twentytwentyfive', 'twentytwentyfour', 'storefront', 'astra', 'kadence', 'generatepress', 'blocksy', 'oceanwp', 'neve', 'hello-elementor'];
    const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
    const rect = (element) => element.getBoundingClientRect();

    async function measure(theme, width) {
        const frame = document.createElement('iframe');
        frame.style.cssText = `position:fixed;left:0;top:0;width:${width}px;height:900px;border:0;z-index:99999;background:#fff`;
        frame.src = `${location.pathname}?capth=${theme}&nc=${Date.now()}`;
        document.body.appendChild(frame);
        await new Promise((resolve) => { frame.onload = resolve; });

        const doc = frame.contentDocument;
        let form, row, widget;
        for (let i = 0; i < 40; i++) {
            form = doc.querySelector('.wc-block-checkout__form');
            row = doc.querySelector('.wc-block-checkout__actions_row');
            widget = doc.querySelector('.wc-block-checkout__form > .cap-captcha--inline');
            if (form && row && widget && rect(widget).height > 10) break;
            await sleep(250);
        }
        if (!form || !row || !widget) {
            frame.remove();
            return `${theme} | ${width} | NO WIDGET`;
        }

        // WooCommerce shows the summary above the button once the top one is out of view.
        row.scrollIntoView({ block: 'end' });
        await sleep(900);

        const summary = doc.querySelector('.checkout-order-summary-block-fill-wrapper');
        const read = () => ({
            gap: Math.round(rect(row).top - rect(widget).bottom),
            below: Math.round(rect(form).bottom - rect(row).bottom),
            above: !summary || rect(summary).bottom <= rect(widget).top + 1,
            page: doc.documentElement.scrollHeight,
        });
        const on = read();

        const off = doc.createElement('style');
        off.textContent = '.wc-block-checkout__form,.wc-block-checkout__actions{display:block!important}'
            + '.wc-block-checkout__form .wc-block-checkout__actions .wc-block-checkout__actions_row.wc-block-checkout__actions_row{margin-block-end:0!important}';
        doc.head.appendChild(off);
        await sleep(300);
        const diff = on.page - read().page;
        frame.remove();

        return `${theme} | ${width} | gap ${on.gap} | below button ${on.below} | summary ${on.above ? 'above' : 'BELOW'} | page height ${diff >= 0 ? '+' : ''}${diff}`;
    }

    const lines = [];
    for (const theme of themes) {
        for (const width of [375, 1280]) {
            lines.push(await measure(theme, width));
        }
    }
    console.log(lines.join('\n'));

    return lines;
})();
