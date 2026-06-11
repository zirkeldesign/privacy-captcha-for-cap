/*
 * Cap CAPTCHA — auto-submit helper for the standalone floating trigger.
 *
 * The vendored `cap-widget.floating.js` re-clicks the trigger button after
 * solving, but because our trigger is a `type="button"` element that only
 * exists to open the popover, that re-click is a no-op. The user is left
 * looking at a dimmed "verify" button with no clear next step.
 *
 * This helper listens for the `solve` event on every `<cap-widget>` marked
 * with `data-cap-autosubmit` and fires the form it lives in. The widget's
 * own behaviour injects a hidden `cap-token` input into that form before
 * `solve` fires, so the submission carries the token automatically.
 */

(function () {
    function attach(root) {
        var scope = root && root.querySelectorAll ? root : document;
        scope.querySelectorAll('cap-widget[data-cap-autosubmit]').forEach(function (widget) {
            if (widget.dataset.capAutosubmitInit === '1') return;
            widget.dataset.capAutosubmitInit = '1';

            widget.addEventListener('solve', function () {
                var form = widget.closest('form');
                if (!form) return;

                // Give the widget a tick to write the auto-injected
                // `cap-token` hidden input into the form before submit.
                setTimeout(function () {
                    var submit = form.querySelector(
                        'button[type="submit"], input[type="submit"]'
                    );
                    if (submit && typeof submit.click === 'function') {
                        submit.click();
                    } else if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit();
                    } else {
                        form.submit();
                    }
                }, 80);
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { attach(document); });
    } else {
        attach(document);
    }

    if (typeof MutationObserver !== 'undefined') {
        new MutationObserver(function (records) {
            for (var i = 0; i < records.length; i++) {
                var added = records[i].addedNodes;
                for (var j = 0; j < added.length; j++) {
                    if (added[j].nodeType === 1) attach(added[j]);
                }
            }
        }).observe(document.body, { childList: true, subtree: true });
    }
})();
