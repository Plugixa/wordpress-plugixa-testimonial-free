/**
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Re-initialise the frontend runtime (Swiper) after Gutenberg's ServerSideRender
 * swaps the block markup. Lives in a real file rather than an inline heredoc: Plugin
 * Check disallows heredocs, and a static asset is cacheable anyway.
 */
(function () {
    'use strict';

    if (window.plugixaTestimonialBlockObserver) {
        return;
    }
    window.plugixaTestimonialBlockObserver = true;

    var observer = new MutationObserver(function (mutations) {
        mutations.forEach(function (m) {
            m.addedNodes.forEach(function (node) {
                if (
                    node.nodeType === 1 &&
                    node.querySelector &&
                    node.querySelector('.pxt-custom-testimonial-root')
                ) {
                    if (window.PlugixaTestimonialApp) {
                        setTimeout(function () {
                            window.PlugixaTestimonialApp.init();
                        }, 100);
                    }
                }
            });
        });
    });

    document.addEventListener('DOMContentLoaded', function () {
        var target = document.querySelector('.block-editor-block-list__layout');
        if (target) {
            observer.observe(target, { childList: true, subtree: true });
        }
    });
})();
