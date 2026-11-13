/**
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 */
/**
 * Plugixa Testimonial - Social Proof Notification Popups
 *
 * Fetches recent approved testimonials via REST API and displays
 * them as slide-in notification popups with configurable timing.
 */
(function () {
    'use strict';

    const config = window.plugixaTestimonialSocialProof;
    if (!config || !config.restUrl) return;

    const container = document.getElementById('pxt-social-proof-container');
    if (!container) return;

    // Apply position class
    container.classList.add('pxt-sp-' + (config.position || 'bottom-left'));

    // Apply mobile hide
    if (config.hideOnMobile) {
        container.classList.add('pxt-sp-hide-mobile');
    }

    let testimonials = [];
    let currentIndex = 0;
    let shownCount = 0;
    let timeoutId = null;
    let dismissed = false;

    /**
     * Generate time ago string from a date
     */
    function timeAgo(dateStr) {
        const date = new Date(dateStr.replace(' ', 'T') + 'Z');
        const now = new Date();
        const seconds = Math.floor((now - date) / 1000);

        if (seconds < 60) return 'just now';
        const minutes = Math.floor(seconds / 60);
        if (minutes < 60) return minutes + 'm ago';
        const hours = Math.floor(minutes / 60);
        if (hours < 24) return hours + 'h ago';
        const days = Math.floor(hours / 24);
        if (days < 7) return days + 'd ago';
        const weeks = Math.floor(days / 7);
        if (weeks < 4) return weeks + 'w ago';
        const months = Math.floor(days / 30);
        if (months < 12) return months + 'mo ago';
        return Math.floor(days / 365) + 'y ago';
    }

    /**
     * Get initials from a name
     */
    function getInitials(name) {
        return name
            .split(' ')
            .map(function (w) { return w.charAt(0).toUpperCase(); })
            .slice(0, 2)
            .join('');
    }

    /**
     * Generate star HTML
     */
    function starsHtml(rating) {
        var html = '<div class="pxt-sp-stars">';
        for (var i = 1; i <= 5; i++) {
            html += '<span class="pxt-sp-star">' + (i <= rating ? '★' : '☆') + '</span>';
        }
        html += '</div>';
        return html;
    }

    /**
     * Build and show a popup for a testimonial
     */
    function showPopup(item) {
        if (dismissed) return;

        // Remove existing popup
        var existing = container.querySelector('.pxt-sp-popup');
        if (existing) {
            existing.remove();
        }

        var popup = document.createElement('div');
        popup.className = 'pxt-sp-popup';
        popup.style.setProperty('--pxt-sp-bg', config.bgColor || '#ffffff');
        popup.style.setProperty('--pxt-sp-text', config.textColor || '#1d2327');
        popup.style.setProperty('--pxt-sp-border', config.borderColor || '#e0e0e0');
        popup.setAttribute('role', 'status');

        var html = '';

        // Photo or initials
        if (config.showPhoto) {
            if (item.client_photo_url) {
                html += '<img class="pxt-sp-photo" src="' + escHtml(item.client_photo_url) + '" alt="' + escHtml(item.client_name) + '" loading="lazy" />';
            } else {
                html += '<div class="pxt-sp-initials">' + escHtml(getInitials(item.client_name)) + '</div>';
            }
        }

        html += '<div class="pxt-sp-content">';
        html += '<p class="pxt-sp-name">' + escHtml(item.client_name) + '</p>';

        // Company/role detail line
        var detail = [];
        if (item.client_role) detail.push(item.client_role);
        if (item.client_company) detail.push(item.client_company);
        if (detail.length) {
            html += '<p class="pxt-sp-detail">' + escHtml(detail.join(' at ')) + '</p>';
        }

        // Stars
        if (config.showRating && item.rating) {
            html += starsHtml(parseInt(item.rating));
        }

        // Time ago
        if (config.showTimeAgo && item.created_at) {
            html += '<p class="pxt-sp-time">' + escHtml(timeAgo(item.created_at)) + '</p>';
        }

        html += '</div>';

        // Close button
        html += '<button class="pxt-sp-close" aria-label="Close">&times;</button>';

        popup.innerHTML = html;

        // Close on button click
        popup.querySelector('.pxt-sp-close').addEventListener('click', function (e) {
            e.stopPropagation();
            dismissed = true;
            hidePopup(popup);
        });

        // Click popup to dismiss
        popup.addEventListener('click', function () {
            hidePopup(popup);
        });

        container.appendChild(popup);

        // Trigger slide-in animation
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                popup.classList.add('pxt-sp-visible');
            });
        });

        shownCount++;

        // Auto-hide after display duration
        timeoutId = setTimeout(function () {
            hidePopup(popup, function () {
                scheduleNext();
            });
        }, (config.displayDuration || 5) * 1000);
    }

    /**
     * Hide a popup with animation
     */
    function hidePopup(popup, callback) {
        if (!popup || !popup.parentNode) {
            if (callback) callback();
            return;
        }

        popup.classList.remove('pxt-sp-visible');
        popup.classList.add('pxt-sp-hiding');

        setTimeout(function () {
            if (popup.parentNode) popup.remove();
            if (callback) callback();
        }, 400);
    }

    /**
     * Schedule the next popup
     */
    function scheduleNext() {
        if (dismissed) return;
        if (shownCount >= (config.maxPopups || 5)) return;
        if (currentIndex >= testimonials.length) return;

        timeoutId = setTimeout(function () {
            showPopup(testimonials[currentIndex]);
            currentIndex++;
        }, (config.delayBetween || 10) * 1000);
    }

    /**
     * Escape HTML entities
     */
    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    /**
     * Fetch testimonials and start the popup cycle
     */
    function init() {
        fetch(config.restUrl, {
            headers: config.nonce ? { 'X-WP-Nonce': config.nonce } : {}
        })
            .then(function (res) { return res.json(); })
            .then(function (response) {
                if (!response.success || !response.data || !response.data.length) return;

                testimonials = response.data;
                currentIndex = 0;
                shownCount = 0;

                // Start after initial delay
                timeoutId = setTimeout(function () {
                    showPopup(testimonials[currentIndex]);
                    currentIndex++;
                }, (config.initialDelay || 5) * 1000);
            })
            .catch(function () {
                // Silently fail — social proof is non-critical
            });
    }

    // Start when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
