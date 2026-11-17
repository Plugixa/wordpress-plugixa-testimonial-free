/**
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 */
/**
 * Plugixa Testimonials - Frontend JavaScript
 * Handles: category and rating filters, pagination
 */

/**
 * Handles category-based filtering of testimonial cards.
 * Supports tabs, pills, dropdown, and sidebar navigation styles.
 */
class TestimonialCategoryFilter {
  constructor(container) {
    this.container = container;
    this.nav = container.querySelector('.pxt-category-nav');
    if (!this.nav) return;

    this.descriptionEl = container.querySelector('.pxt-category-description');
    this.cards = container.querySelectorAll('.pxt-custom-card[data-pxt-categories]');
    this.activeCategory = 'all';

    this.bindEvents();
  }

  bindEvents() {
    // Button-based nav (tabs, pills, sidebar)
    const buttons = this.nav.querySelectorAll('.pxt-category-item');
    if (buttons.length) {
      this.nav.addEventListener('click', (e) => {
        const btn = e.target.closest('.pxt-category-item');
        if (!btn) return;
        this.filter(btn.dataset.category, btn.dataset.description || '');
        // Update active state
        buttons.forEach((b) => b.classList.remove('pxt-category-item--active'));
        btn.classList.add('pxt-category-item--active');
      });
    }

    // Dropdown-based nav
    const select = this.nav.querySelector('.pxt-category-select');
    if (select) {
      select.addEventListener('change', () => {
        const option = select.options[select.selectedIndex];
        this.filter(select.value, option.dataset.description || '');
      });
    }
  }

  filter(categoryId, description) {
    this.activeCategory = categoryId;

    this.cards.forEach((card) => {
      if (categoryId === 'all') {
        card.removeAttribute('data-pxt-hidden');
      } else {
        const ids = (card.dataset.pxtCategories || '').split(',');
        if (ids.includes(String(categoryId))) {
          card.removeAttribute('data-pxt-hidden');
        } else {
          card.setAttribute('data-pxt-hidden', '');
        }
      }
    });

    // Re-sort visible cards by per-category order
    if (categoryId === 'all') {
      this.sortCardsByGlobalOrder();
    } else {
      this.sortCardsByCategory(categoryId);
    }

    // Update description
    if (this.descriptionEl) {
      if (description && categoryId !== 'all') {
        this.descriptionEl.textContent = description;
        this.descriptionEl.classList.add('pxt-category-description--visible');
      } else {
        this.descriptionEl.textContent = '';
        this.descriptionEl.classList.remove('pxt-category-description--visible');
      }
    }
  }

  /**
   * Sort visible cards by their per-category display_order.
   */
  sortCardsByCategory(categoryId) {
    const parent = this.cards[0]?.parentElement;
    if (!parent) return;

    const cardsArray = Array.from(this.cards);
    cardsArray.sort((a, b) => {
      const ordersA = this.parseCategoryOrders(a);
      const ordersB = this.parseCategoryOrders(b);
      const orderA = ordersA[String(categoryId)] ?? 9999;
      const orderB = ordersB[String(categoryId)] ?? 9999;
      return orderA - orderB;
    });

    cardsArray.forEach((card) => parent.appendChild(card));
  }

  /**
   * Restore original DOM order for the "All" tab.
   */
  sortCardsByGlobalOrder() {
    const parent = this.cards[0]?.parentElement;
    if (!parent) return;

    const cardsArray = Array.from(this.cards);
    cardsArray.sort((a, b) => {
      const orderA = parseInt(a.dataset.pxtGlobalOrder || '0', 10);
      const orderB = parseInt(b.dataset.pxtGlobalOrder || '0', 10);
      return orderA - orderB;
    });

    cardsArray.forEach((card) => parent.appendChild(card));
  }

  /**
   * Parse the data-pxt-category-orders JSON attribute.
   */
  parseCategoryOrders(card) {
    try {
      return JSON.parse(card.dataset.pxtCategoryOrders || '{}');
    } catch {
      return {};
    }
  }
}

/**
 * Handles rating-based filtering of testimonial cards.
 * Uses data-pxt-rating-hidden (separate from data-pxt-hidden) so both
 * category and rating filters compose via AND logic.
 */
class TestimonialRatingFilter {
  constructor(container) {
    this.container = container;
    this.nav = container.querySelector('.pxt-rating-filter-nav');
    if (!this.nav) return;

    this.behavior = this.nav.dataset.pxtRatingBehavior || 'min';
    this.cards = container.querySelectorAll('.pxt-custom-card[data-pxt-rating]');
    this.activeRating = 'all';

    this.bindEvents();
  }

  bindEvents() {
    // Button-based nav (tabs, pills)
    const buttons = this.nav.querySelectorAll('.pxt-rating-filter-item');
    if (buttons.length) {
      this.nav.addEventListener('click', (e) => {
        const btn = e.target.closest('.pxt-rating-filter-item');
        if (!btn) return;
        this.filter(btn.dataset.rating);
        buttons.forEach((b) => b.classList.remove('pxt-rating-filter-item--active'));
        btn.classList.add('pxt-rating-filter-item--active');
      });
    }

    // Dropdown-based nav
    const select = this.nav.querySelector('.pxt-rating-filter-select');
    if (select) {
      select.addEventListener('change', () => {
        this.filter(select.value);
      });
    }
  }

  filter(ratingValue) {
    this.activeRating = ratingValue;

    this.cards.forEach((card) => {
      if (ratingValue === 'all') {
        card.removeAttribute('data-pxt-rating-hidden');
      } else {
        const cardRating = parseInt(card.dataset.pxtRating, 10);
        const target = parseInt(ratingValue, 10);
        const visible = this.behavior === 'min'
          ? cardRating >= target
          : cardRating === target;
        if (visible) {
          card.removeAttribute('data-pxt-rating-hidden');
        } else {
          card.setAttribute('data-pxt-rating-hidden', '');
        }
      }
    });
  }

  refresh() {
    this.cards = this.container.querySelectorAll('.pxt-custom-card[data-pxt-rating]');
    if (this.activeRating !== 'all') {
      this.filter(this.activeRating);
    }
  }
}

/**
 * Handles pagination for testimonial containers (numbers, load_more, infinite_scroll).
 */
class TestimonialPagination {
  constructor(container) {
    this.container = container;
    this.itemsContainer = container.querySelector('.pxt-items-container');
    if (!this.itemsContainer) return;

    try {
      this.config = JSON.parse(container.dataset.pxtPagination || '{}');
    } catch {
      this.config = {};
    }

    if (!this.config.enabled) return;

    this.currentPage = this.config.currentPage || 1;
    this.totalPages = this.config.totalPages || 1;
    this.loading = false;
    this.observer = null;

    this.bindEvents();
  }

  bindEvents() {
    const { style } = this.config;

    if (style === 'numbers') {
      this.bindNumbersEvents();
    } else if (style === 'load_more') {
      this.bindLoadMoreEvents();
    } else if (style === 'infinite_scroll') {
      this.initInfiniteScroll();
    }
  }

  bindNumbersEvents() {
    const nav = this.container.querySelector('.pxt-pagination-numbers');
    if (!nav) return;

    nav.addEventListener('click', (e) => {
      const btn = e.target.closest('.pxt-page-link');
      if (!btn || btn.disabled || btn.classList.contains('pxt-page-current')) return;

      const page = parseInt(btn.dataset.page, 10);
      if (page >= 1 && page <= this.totalPages) {
        this.loadPage(page, 'replace');
      }
    });
  }

  bindLoadMoreEvents() {
    const btn = this.container.querySelector('.pxt-load-more-btn');
    if (!btn) return;

    btn.addEventListener('click', () => {
      if (this.loading || this.currentPage >= this.totalPages) return;
      btn.textContent = 'Loading...';
      btn.disabled = true;
      this.loadPage(this.currentPage + 1, 'append').then(() => {
        btn.textContent = this.config.loadMoreLabel || 'Load More';
        btn.disabled = false;
        if (this.currentPage >= this.totalPages) {
          btn.closest('.pxt-pagination-loadmore').style.display = 'none';
        }
      });
    });
  }

  initInfiniteScroll() {
    const sentinel = this.container.querySelector('.pxt-infinite-scroll-sentinel');
    if (!sentinel) return;

    this.observer = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting && !this.loading && this.currentPage < this.totalPages) {
          this.loadPage(this.currentPage + 1, 'append').then(() => {
            if (this.currentPage >= this.totalPages) {
              const indicator = this.container.querySelector('.pxt-infinite-scroll-indicator');
              if (indicator) indicator.style.display = 'none';
              this.observer.disconnect();
            } else {
              // Re-observe sentinel to reset intersection state
              // so the observer fires again for the next page
              this.observer.unobserve(sentinel);
              this.observer.observe(sentinel);
            }
          });
        }
      });
    }, { rootMargin: '400px' });

    this.observer.observe(sentinel);
  }

  async loadPage(page, mode) {
    if (this.loading) return;
    this.loading = true;

    const params = new URLSearchParams({
      shortcode_id: this.config.shortcodeId,
      page: page,
      per_page: this.config.perPage,
    });

    const url = this.config.restUrl + '?' + params.toString();

    try {
      const response = await fetch(url, {
        headers: this.config.nonce ? { 'X-WP-Nonce': this.config.nonce } : {},
      });
      const result = await response.json();

      if (result.success && result.data) {
        const { items_html, pagination_html, current_page, total_pages } = result.data;

        this.currentPage = current_page;
        this.totalPages = total_pages;

        if (mode === 'replace') {
          this.itemsContainer.innerHTML = items_html;
          // Update pagination nav
          const existingNav = this.container.querySelector('.pxt-pagination-numbers');
          if (existingNav && pagination_html) {
            existingNav.outerHTML = pagination_html;
            // Re-bind events for new nav
            this.bindNumbersEvents();
          }
          // Scroll to top of container
          this.container.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } else {
          // Append mode for load_more and infinite_scroll
          const temp = document.createElement('div');
          temp.innerHTML = items_html;
          // Extract inner items from the layout wrapper
          const wrapper = temp.firstElementChild;
          if (wrapper) {
            const existingWrapper = this.itemsContainer.firstElementChild;
            if (existingWrapper) {
              const newItems = [];
              while (wrapper.firstChild) {
                const child = wrapper.firstChild;
                if (child.nodeType === Node.ELEMENT_NODE) {
                  child.classList.add('pxt-item-entering');
                  newItems.push(child);
                }
                existingWrapper.appendChild(child);
              }
              // Trigger fade-in after DOM insertion
              requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                  newItems.forEach((item) => item.classList.add('pxt-item-visible'));
                });
              });
            }
          }
        }
      }
    } catch (err) {
      console.error('Pagination fetch error:', err);
    } finally {
      this.loading = false;
    }
  }

  destroy() {
    if (this.observer) {
      this.observer.disconnect();
      this.observer = null;
    }
  }
}

/**
 * Application orchestrator - initializes all components on DOMContentLoaded.
 *
 * Another script can add components by pushing
 * an object with init(app) and destroy(app) onto window.plugixaTestimonialExtensions
 * before DOMContentLoaded.
 */
class TestimonialApp {
  constructor() {
    this.paginations = [];
    this.categoryFilters = [];
    this.ratingFilters = [];
  }

  init() {
    this.extensions().forEach((ext) => ext.init?.(this));
    this.initPaginations();
    this.initCategoryFilters();
    this.initRatingFilters();
  }

  extensions() {
    return Array.isArray(window.plugixaTestimonialExtensions) ? window.plugixaTestimonialExtensions : [];
  }

  initPaginations() {
    document.querySelectorAll('.pxt-custom-testimonial-root[data-pxt-pagination]').forEach((container) => {
      this.paginations.push(new TestimonialPagination(container));
    });
  }

  initCategoryFilters() {
    document.querySelectorAll('.pxt-custom-testimonial-root').forEach((container) => {
      if (container.querySelector('.pxt-category-nav')) {
        this.categoryFilters.push(new TestimonialCategoryFilter(container));
      }
    });
  }

  initRatingFilters() {
    document.querySelectorAll('.pxt-custom-testimonial-root').forEach((container) => {
      if (container.querySelector('.pxt-rating-filter-nav')) {
        this.ratingFilters.push(new TestimonialRatingFilter(container));
      }
    });
  }

  destroy() {
    this.extensions().forEach((ext) => ext.destroy?.(this));
    this.paginations.forEach((p) => p.destroy());
    this.paginations = [];
    this.categoryFilters = [];
    this.ratingFilters = [];
  }
}

window.plugixaTestimonialExtensions = window.plugixaTestimonialExtensions || [];

// Initialize on DOM ready
document.addEventListener('DOMContentLoaded', () => {
  window.PlugixaTestimonialApp = new TestimonialApp();
  window.PlugixaTestimonialApp.init();
});
