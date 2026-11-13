/**
 * @package   PlugixaTestimonial
 * @copyright Copyright (C) 2026 Plugixa (https://plugixa.com)
 * @license   GNU General Public License v2 or later
 * @link      https://www.gnu.org/licenses/gpl-2.0.html
 */
/**
 * Plugixa Testimonials - Frontend JavaScript
 * Handles: Swiper slider, form AJAX submission, star rating input
 */

/**
 * Initializes a Swiper instance for a testimonial slider container.
 */
class TestimonialSlider {
  static SINGLE_SLIDE_EFFECTS = ['fade', 'cube', 'flip', 'cards', 'creative'];

  constructor(container) {
    this.container = container;
    this.swiperEl = container.querySelector('.pxt-swiper-instance');
    if (!this.swiperEl) return;

    const slides = this.swiperEl.querySelectorAll('.swiper-slide');
    if (slides.length === 0) return;

    this.slideCount = slides.length;
    this.viewConfig = this.parseConfig();
    this.swiper = this.initSwiper();
  }

  parseConfig() {
    try {
      return JSON.parse(this.container.dataset.pxtCarouselConfig || '{}');
    } catch {
      return {};
    }
  }

  initSwiper() {
    const layout = this.viewConfig;

    const effect = layout.effect || 'slide';
    const isSingleSlideEffect = TestimonialSlider.SINGLE_SLIDE_EFFECTS.includes(effect);

    const spv = layout.slidesPerView || { desktop: 3, tablet: 2, mobile: 1 };
    const rows = layout.rows || { desktop: 1, tablet: 1, mobile: 1 };
    const hasMultiRow = (rows.desktop > 1 || rows.tablet > 1 || rows.mobile > 1);
    const spaceBetween = layout.spaceBetween ?? 24;
    const speed = layout.speed ?? 300;
    const slidesPerGroup = layout.slidesPerGroup ?? 1;

    const config = {
      effect,
      speed,
      direction: layout.direction || 'horizontal',
      grabCursor: layout.grabCursor !== false,
      centeredSlides: layout.centeredSlides || false,
      freeMode: layout.freeMode || false,
      keyboard: layout.keyboard ? { enabled: true } : false,
      mousewheel: layout.mousewheel || false,
    };

    // Slides per view and responsive breakpoints
    if (isSingleSlideEffect) {
      config.slidesPerView = 1;
      config.spaceBetween = 0;
    } else {
      config.slidesPerView = spv.mobile || 1;
      config.spaceBetween = spaceBetween;
      config.slidesPerGroup = slidesPerGroup;

      // Multi-row grid support (Swiper Grid module)
      if (hasMultiRow) {
        config.grid = { rows: rows.mobile || 1, fill: 'row' };
      }

      config.breakpoints = {
        641: {
          slidesPerView: spv.tablet || 2,
          spaceBetween,
          slidesPerGroup,
          ...(hasMultiRow && { grid: { rows: rows.tablet || 1, fill: 'row' } }),
        },
        1025: {
          slidesPerView: spv.desktop || 3,
          spaceBetween,
          slidesPerGroup,
          ...(hasMultiRow && { grid: { rows: rows.desktop || 1, fill: 'row' } }),
        },
      };
    }

    // Loop — disable when not enough slides or when multi-row grid is active
    const maxSpv = isSingleSlideEffect ? 1 : (spv.desktop || 3);
    config.loop = !hasMultiRow && (layout.loop !== false) && this.slideCount > maxSpv;

    // Autoplay
    if (layout.autoplay !== false) {
      config.autoplay = {
        delay: layout.autoplaySpeed || 5000,
        pauseOnMouseEnter: layout.pauseOnHover !== false,
        disableOnInteraction: layout.disableOnInteraction || false,
      };
    }

    // Navigation (arrows)
    if (layout.showArrows !== false) {
      const prevEl = this.swiperEl.querySelector('.swiper-button-prev');
      const nextEl = this.swiperEl.querySelector('.swiper-button-next');
      if (prevEl && nextEl) {
        config.navigation = { prevEl, nextEl };
      }
    }

    // Pagination (dots/fraction/progressbar)
    if (layout.showDots !== false) {
      const paginationEl = this.swiperEl.querySelector('.swiper-pagination');
      if (paginationEl) {
        config.pagination = {
          el: paginationEl,
          type: layout.paginationType || 'bullets',
          clickable: true,
        };
      }
    }

    // Effect-specific configs
    if (effect === 'fade') {
      config.fadeEffect = { crossFade: true };
    } else if (effect === 'cube') {
      config.cubeEffect = { shadow: true, slideShadows: true, shadowOffset: 20, shadowScale: 0.94 };
    } else if (effect === 'coverflow') {
      config.coverflowEffect = { rotate: 50, stretch: 0, depth: 100, modifier: 1, slideShadows: true };
    } else if (effect === 'flip') {
      config.flipEffect = { slideShadows: true, limitRotation: true };
    } else if (effect === 'cards') {
      config.cardsEffect = { slideShadows: true, perSlideRotate: 2, perSlideOffset: 8 };
    } else if (effect === 'creative') {
      config.creativeEffect = {
        prev: { shadow: true, translate: [0, 0, -400] },
        next: { translate: ['100%', 0, 0] },
      };
    }

    return new Swiper(this.swiperEl, config);
  }

  destroy() {
    if (this.swiper) {
      this.swiper.destroy(true, true);
      this.swiper = null;
    }
  }
}

/**
 * Per-field form validation with real-time feedback.
 */
class FormValidator {
  static EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  static URL_RE = /^https?:\/\/.+/i;

  constructor(wrapper) {
    this.wrapper = wrapper;
    this.form = wrapper.querySelector('form');
    this.requiredFields = JSON.parse(wrapper.dataset.requiredFields || '[]');
    this.minLength = parseInt(wrapper.dataset.minLength) || 10;
    this.maxLength = parseInt(wrapper.dataset.maxLength) || 2000;
    this.setupListeners();
    this.setupCharCounter();
  }

  setupListeners() {
    this.form.addEventListener('blur', (e) => {
      if (e.target.matches('input, textarea, select')) this.validateField(e.target);
    }, true);
    this.form.addEventListener('input', (e) => {
      if (e.target.matches('input, textarea, select') && e.target.closest('.pxt-field-invalid')) {
        this.clearFieldError(e.target);
      }
    }, true);
  }

  setupCharCounter() {
    const textarea = this.form.querySelector('textarea[name="testimonial_text"]');
    const counter = this.wrapper.querySelector('.pxt-char-counter');
    if (!textarea || !counter) return;

    const countEl = counter.querySelector('.pxt-char-count');
    const update = () => {
      const len = textarea.value.length;
      countEl.textContent = len;
      counter.classList.toggle('pxt-char-over', len > this.maxLength);
      counter.classList.toggle('pxt-char-under', len > 0 && len < this.minLength);
    };
    textarea.addEventListener('input', update);
    update();
  }

  validateField(input) {
    const name = this.getFieldName(input);
    const value = input.type === 'checkbox' ? (input.checked ? '1' : '') : input.value.trim();

    // Required check
    if (input.hasAttribute('required') || this.requiredFields.includes(name)) {
      if (!value) {
        this.showFieldError(input, 'This field is required');
        return false;
      }
    }

    if (!value) { this.clearFieldError(input); return true; }

    // Type-specific checks
    if (input.type === 'email' && !FormValidator.EMAIL_RE.test(value)) {
      this.showFieldError(input, 'Please enter a valid email address');
      return false;
    }
    if (input.type === 'url' && !FormValidator.URL_RE.test(value)) {
      this.showFieldError(input, 'Please enter a valid URL (starting with http)');
      return false;
    }
    if (input.type === 'number' && isNaN(value)) {
      this.showFieldError(input, 'Please enter a valid number');
      return false;
    }
    if (name === 'testimonial_text') {
      if (value.length < this.minLength) {
        this.showFieldError(input, `Must be at least ${this.minLength} characters`);
        return false;
      }
      if (value.length > this.maxLength) {
        this.showFieldError(input, `Must not exceed ${this.maxLength} characters`);
        return false;
      }
    }

    this.clearFieldError(input);
    return true;
  }

  getFieldName(input) {
    const name = input.name || '';
    const match = name.match(/^custom_fields\[(.+)\]$/);
    return match ? match[1] : name;
  }

  validatePanel(panel) {
    const inputs = panel.querySelectorAll('input:not([type="hidden"]), textarea, select');
    let valid = true;
    let firstError = null;
    inputs.forEach((input) => {
      if (!this.validateField(input)) {
        valid = false;
        if (!firstError) firstError = input;
      }
    });
    if (firstError) firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
    return valid;
  }

  validateAll() {
    const panels = this.form.querySelectorAll('.pxt-form-tab-panel');
    let valid = true;
    panels.forEach((panel) => {
      const inputs = panel.querySelectorAll('input:not([type="hidden"]), textarea, select');
      inputs.forEach((input) => { if (!this.validateField(input)) valid = false; });
    });
    return valid;
  }

  showFieldError(input, msg) {
    input.classList.add('pxt-field-invalid');
    const errorEl = input.closest('div')?.querySelector('.pxt-field-error')
                 || input.closest('label')?.parentElement?.querySelector('.pxt-field-error');
    if (errorEl) {
      errorEl.textContent = msg;
      errorEl.classList.add('visible');
    }
  }

  clearFieldError(input) {
    input.classList.remove('pxt-field-invalid');
    const errorEl = input.closest('div')?.querySelector('.pxt-field-error')
                 || input.closest('label')?.parentElement?.querySelector('.pxt-field-error');
    if (errorEl) {
      errorEl.textContent = '';
      errorEl.classList.remove('visible');
    }
  }

  clearAllErrors() {
    this.wrapper.querySelectorAll('.pxt-field-invalid').forEach((el) => el.classList.remove('pxt-field-invalid'));
    this.wrapper.querySelectorAll('.pxt-field-error.visible').forEach((el) => {
      el.textContent = '';
      el.classList.remove('visible');
    });
  }

  showServerErrors(errors) {
    if (!errors || typeof errors !== 'object') return;
    let firstError = null;
    for (const [key, msg] of Object.entries(errors)) {
      // Try finding by name attribute (standard fields and custom_fields[key])
      let input = this.form.querySelector(`[name="${key}"]`)
                || this.form.querySelector(`[name="custom_fields[${key}]"]`);
      if (input) {
        this.showFieldError(input, msg);
        if (!firstError) firstError = input;
      }
    }
    // Switch to the tab containing the first error
    if (firstError) {
      const panel = firstError.closest('.pxt-form-tab-panel');
      if (panel) {
        const tabIndex = parseInt(panel.dataset.tab);
        const tabs = this.wrapper.querySelector('.pxt-form-tabs');
        if (tabs) {
          const tabBtn = tabs.querySelector(`[data-tab="${tabIndex}"]`);
          if (tabBtn) tabBtn.click();
        }
      }
      firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  }
}

/**
 * Tab navigation for card-style form with pill buttons.
 */
class FormTabs {
  constructor(wrapper) {
    this.wrapper = wrapper;
    this.card = wrapper.querySelector('.pxt-form-card');
    if (!this.card) return;

    this.tabs = [...this.card.querySelectorAll('.pxt-form-tab-btn')];
    this.panels = [...this.card.querySelectorAll('.pxt-form-tab-panel')];
    this.prevBtn = this.card.querySelector('.pxt-form-prev-btn');
    this.nextBtn = this.card.querySelector('.pxt-form-next-btn');
    this.submitBtn = this.card.querySelector('.pxt-form-submit-btn');
    this.currentTab = 0;
    this.totalTabs = this.panels.length;
    this.validator = null; // Set externally

    this.tabs.forEach((tab, i) => {
      tab.addEventListener('click', () => this.onTabClick(i));
      // Keyboard: Arrow navigation
      tab.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowRight') { e.preventDefault(); this.focusTab(Math.min(i + 1, this.totalTabs - 1)); }
        if (e.key === 'ArrowLeft') { e.preventDefault(); this.focusTab(Math.max(i - 1, 0)); }
      });
    });

    if (this.nextBtn) this.nextBtn.addEventListener('click', () => this.next());
    if (this.prevBtn) this.prevBtn.addEventListener('click', () => this.prev());

    this.updateButtons();
  }

  focusTab(index) {
    if (this.tabs[index]) this.tabs[index].focus();
  }

  onTabClick(index) {
    // Allow clicking backward freely, validate before going forward
    if (index <= this.currentTab) {
      this.goTo(index);
    } else {
      // Validate all panels between current and target
      if (this.validator) {
        for (let i = this.currentTab; i < index; i++) {
          if (!this.validator.validatePanel(this.panels[i])) return;
        }
      }
      this.goTo(index);
    }
  }

  next() {
    if (this.currentTab >= this.totalTabs - 1) return;
    if (this.validator && !this.validator.validatePanel(this.panels[this.currentTab])) return;
    this.goTo(this.currentTab + 1);
  }

  prev() {
    if (this.currentTab <= 0) return;
    this.goTo(this.currentTab - 1);
  }

  goTo(index) {
    if (index < 0 || index >= this.totalTabs) return;
    this.currentTab = index;

    // Update tab buttons
    this.tabs.forEach((tab, i) => {
      tab.classList.toggle('active', i === index);
      tab.setAttribute('aria-selected', i === index ? 'true' : 'false');
    });

    // Update panels
    this.panels.forEach((panel, i) => {
      panel.classList.toggle('active', i === index);
    });

    this.updateButtons();
  }

  updateButtons() {
    const isFirst = this.currentTab === 0;
    const isLast = this.currentTab === this.totalTabs - 1;

    if (this.prevBtn) this.prevBtn.style.display = isFirst ? 'none' : '';
    if (this.nextBtn) this.nextBtn.style.display = isLast ? 'none' : '';
    if (this.submitBtn) this.submitBtn.style.display = isLast ? '' : 'none';
  }

  reset() {
    this.goTo(0);
  }
}

/**
 * Handles testimonial submission form with async/await fetch,
 * per-field validation, loading spinner, and success state.
 */
class TestimonialForm {
  constructor(form) {
    this.form = form;
    this.wrapper = form.closest('.pxt-custom-form-wrapper');
    if (!this.wrapper) return;

    this.apiUrl = this.wrapper.dataset.apiUrl;
    this.nonce = this.wrapper.dataset.nonce;
    this.successMessage = this.wrapper.dataset.successMessage;
    this.lang = this.wrapper.dataset.lang || '';
    this.messageEl = form.querySelector('.pxt-custom-form-message');
    this.fileInput = form.querySelector('.pxt-custom-photo-input');

    // Card elements
    this.submitBtn = form.querySelector('.pxt-form-submit-btn');
    this.btnText = this.submitBtn?.querySelector('.pxt-btn-text');
    this.btnSpinner = this.submitBtn?.querySelector('.pxt-btn-spinner');
    this.successEl = form.querySelector('.pxt-form-success');
    this.tabPanels = form.querySelectorAll('.pxt-form-tab-panel');
    this.footerEl = form.querySelector('.pxt-form-card-footer');

    // Validator + Tabs
    this.validator = new FormValidator(this.wrapper);
    this.formTabs = null; // Set externally from app init

    this.form.addEventListener('submit', (e) => this.handleSubmit(e));

    // Compact photo handling
    this.initCompactPhoto();

    // Success "submit another" button
    const againBtn = form.querySelector('.pxt-form-success-again');
    if (againBtn) againBtn.addEventListener('click', () => this.resetToForm());
  }

  initCompactPhoto() {
    const chooseBtn = this.wrapper.querySelector('.pxt-photo-choose-btn');
    const avatar = this.wrapper.querySelector('.pxt-photo-avatar');
    const filenameEl = this.wrapper.querySelector('.pxt-photo-filename');

    if (!chooseBtn || !this.fileInput) return;

    chooseBtn.addEventListener('click', () => this.fileInput.click());
    if (avatar) avatar.addEventListener('click', () => this.fileInput.click());

    this.fileInput.addEventListener('change', () => {
      const file = this.fileInput.files[0];
      if (!file) return;

      // Show filename
      if (filenameEl) filenameEl.textContent = file.name;

      // Show avatar preview
      if (avatar && file.type.startsWith('image/')) {
        const reader = new FileReader();
        reader.onload = (e) => {
          avatar.innerHTML = `<img src="${e.target.result}" alt="Preview" />`;
          avatar.classList.add('has-preview');
        };
        reader.readAsDataURL(file);
      }
    });
  }

  showLoading() {
    if (this.submitBtn) this.submitBtn.disabled = true;
    if (this.btnText) this.btnText.style.display = 'none';
    if (this.btnSpinner) this.btnSpinner.style.display = '';
  }

  hideLoading() {
    if (this.submitBtn) this.submitBtn.disabled = false;
    if (this.btnText) this.btnText.style.display = '';
    if (this.btnSpinner) this.btnSpinner.style.display = 'none';
  }

  showSuccess(message) {
    // Hide form content, show success
    this.tabPanels.forEach((p) => p.style.display = 'none');
    if (this.footerEl) this.footerEl.style.display = 'none';
    if (this.messageEl) this.messageEl.style.display = 'none';
    const tabsNav = this.wrapper.querySelector('.pxt-form-tabs');
    if (tabsNav) tabsNav.style.display = 'none';

    if (this.successEl) {
      const msgEl = this.successEl.querySelector('.pxt-form-success-message');
      if (msgEl) msgEl.textContent = message;
      this.successEl.style.display = '';
    }
  }

  resetToForm() {
    this.form.reset();
    this.validator.clearAllErrors();

    // Reset star rating
    const ratingInput = this.form.querySelector('.pxt-custom-star-input');
    if (ratingInput) {
      StarRatingInput.updateDisplay(ratingInput, 5);
      ratingInput.querySelector('input[name="rating"]').value = 5;
    }

    // Reset photo
    const avatar = this.wrapper.querySelector('.pxt-photo-avatar');
    const filenameEl = this.wrapper.querySelector('.pxt-photo-filename');
    if (avatar) {
      avatar.innerHTML = '<svg class="pxt:size-5 pxt:text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0" stroke-linecap="round" stroke-linejoin="round"/></svg>';
      avatar.classList.remove('has-preview');
    }
    if (filenameEl) filenameEl.textContent = '';

    // Reset char counter
    const charCount = this.wrapper.querySelector('.pxt-char-count');
    if (charCount) charCount.textContent = '0';
    const charCounter = this.wrapper.querySelector('.pxt-char-counter');
    if (charCounter) charCounter.className = 'pxt-char-counter';

    // Show form again
    this.tabPanels.forEach((p) => p.style.display = '');
    if (this.footerEl) this.footerEl.style.display = '';
    if (this.successEl) this.successEl.style.display = 'none';
    const tabsNav = this.wrapper.querySelector('.pxt-form-tabs');
    if (tabsNav) tabsNav.style.display = '';

    // Reset to first tab
    if (this.formTabs) this.formTabs.reset();
  }

  async handleSubmit(e) {
    e.preventDefault();

    // Client-side validation
    this.validator.clearAllErrors();
    if (!this.validator.validateAll()) return;

    const hasFile = this.fileInput && this.fileInput.files.length > 0;
    let body;
    const headers = this.nonce ? { 'X-WP-Nonce': this.nonce } : {}; // signed-out pages carry no nonce

    if (hasFile) {
      body = new FormData(this.form);
      if (this.lang) body.append('language_code', this.lang);
      if (typeof grecaptcha !== 'undefined' && typeof plugixaTestimonialVars !== 'undefined' && plugixaTestimonialVars.recaptchaSiteKey) {
        try {
          const token = await grecaptcha.execute(plugixaTestimonialVars.recaptchaSiteKey, { action: 'submit_testimonial' });
          body.append('recaptcha_token', token);
        } catch (err) {
          this.showMessage('error', 'reCAPTCHA verification failed. Please try again.');
          return;
        }
      }
    } else {
      const formData = new FormData(this.form);
      const data = {};
      for (const [key, value] of formData.entries()) {
        const match = key.match(/^custom_fields\[(.+)\]$/);
        if (match) {
          if (!data.custom_fields) data.custom_fields = {};
          data.custom_fields[match[1]] = value;
        } else {
          data[key] = value;
        }
      }
      if (this.lang) data.language_code = this.lang;
      if (typeof grecaptcha !== 'undefined' && typeof plugixaTestimonialVars !== 'undefined' && plugixaTestimonialVars.recaptchaSiteKey) {
        try {
          data.recaptcha_token = await grecaptcha.execute(plugixaTestimonialVars.recaptchaSiteKey, { action: 'submit_testimonial' });
        } catch (err) {
          this.showMessage('error', 'reCAPTCHA verification failed. Please try again.');
          return;
        }
      }
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(data);
    }

    this.showLoading();

    try {
      const response = await fetch(this.apiUrl, { method: 'POST', headers, body });
      const result = await response.json();

      if (result.success) {
        this.showSuccess(result.message || this.successMessage || 'Thank you!');
      } else {
        // Show per-field server errors
        if (result.data?.errors && typeof result.data.errors === 'object') {
          this.validator.showServerErrors(result.data.errors);
        }
        const errorMsg = result.message || 'An error occurred.';
        this.showMessage('error', errorMsg);
      }
    } catch {
      this.showMessage('error', 'An error occurred. Please try again.');
    } finally {
      this.hideLoading();
    }
  }

  showMessage(type, text) {
    if (!this.messageEl) return;
    this.messageEl.className = `pxt-custom-form-message pxt-custom-form-message--${type}`;
    this.messageEl.textContent = text;
    this.messageEl.style.display = 'block';
  }
}

/**
 * Handles modal open/close behavior for testimonial submission form.
 */
class TestimonialFormModal {
  constructor(triggerBtn) {
    this.triggerBtn = triggerBtn;
    this.overlay = triggerBtn.nextElementSibling;
    if (!this.overlay || !this.overlay.classList.contains('pxt-custom-modal-overlay')) return;

    this.closeBtn = this.overlay.querySelector('.pxt-custom-modal-close');

    this.triggerBtn.addEventListener('click', () => this.open());
    if (this.closeBtn) {
      this.closeBtn.addEventListener('click', () => this.close());
    }
    this.overlay.addEventListener('click', (e) => {
      // Close when clicking the backdrop or the scroll/centering containers (outside the panel)
      const panel = this.overlay.querySelector('.pxt-custom-modal-content');
      if (panel && !panel.contains(e.target)) this.close();
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && this.overlay.classList.contains('pxt-active')) {
        this.close();
      }
    });
  }

  open() {
    this.overlay.classList.add('pxt-active');
    document.body.style.overflow = 'hidden';
  }

  close() {
    this.overlay.classList.remove('pxt-active');
    document.body.style.overflow = '';
  }
}

/**
 * Handles interactive star rating input (click/hover).
 */
class StarRatingInput {
  constructor(container) {
    this.container = container;
    this.stars = container.querySelectorAll('.pxt-custom-star-btn');
    this.input = container.querySelector('input[name="rating"]');
    if (!this.input) return;

    this.stars.forEach((star) => {
      star.addEventListener('click', () => {
        const value = parseInt(star.dataset.value, 10);
        this.input.value = value;
        StarRatingInput.updateDisplay(this.container, value);
      });

      star.addEventListener('mouseenter', () => {
        const value = parseInt(star.dataset.value, 10);
        StarRatingInput.updateDisplay(this.container, value);
      });
    });

    this.container.addEventListener('mouseleave', () => {
      StarRatingInput.updateDisplay(this.container, parseInt(this.input.value, 10));
    });
  }

  static updateDisplay(container, rating) {
    container.querySelectorAll('.pxt-custom-star-btn').forEach((star) => {
      const value = parseInt(star.dataset.value, 10);
      star.classList.toggle('active', value <= rating);
      star.classList.toggle('inactive', value > rating);
    });
  }
}

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
 * Handles lazy iframe loading for video thumbnails (YouTube/Vimeo).
 * Clicking a thumbnail replaces it with the actual iframe embed.
 */
class VideoPlayer {
  constructor(container) {
    this.container = container;
    this.thumbs = container.querySelectorAll('.pxt-video-thumb');
    this.thumbs.forEach((thumb) => {
      thumb.addEventListener('click', () => this.loadVideo(thumb));
    });
  }

  loadVideo(thumb) {
    const type = thumb.dataset.pxtVideoType;
    const id = thumb.dataset.pxtVideoId;
    if (!type || !id) return;

    let src = '';
    if (type === 'youtube') {
      src = `https://www.youtube-nocookie.com/embed/${id}?autoplay=1&rel=0`;
    } else if (type === 'vimeo') {
      src = `https://player.vimeo.com/video/${id}?autoplay=1`;
    }

    if (!src) return;

    const iframe = document.createElement('iframe');
    iframe.src = src;
    iframe.setAttribute('frameborder', '0');
    iframe.setAttribute('allowfullscreen', '');
    iframe.setAttribute('allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture');
    iframe.className = 'pxt-video-iframe';

    const wrapper = thumb.closest('.pxt-video-wrapper');
    if (wrapper) {
      wrapper.innerHTML = '';
      wrapper.appendChild(iframe);
    }
  }
}

/**
 * Application orchestrator — initializes all components on DOMContentLoaded.
 */
class TestimonialApp {
  constructor() {
    this.sliders = [];
    this.forms = [];
    this.modals = [];
    this.starInputs = [];
    this.formTabs = [];
    this.paginations = [];
    this.categoryFilters = [];
    this.ratingFilters = [];
    this.videoPlayers = [];
  }

  init() {
    this.initSliders();
    this.initModals();
    this.initStarRatingInputs();
    this.initFormTabs();
    this.initForms();
    this.initPaginations();
    this.initCategoryFilters();
    this.initRatingFilters();
    this.initVideoPlayers();
  }

  initSliders() {
    document.querySelectorAll('.pxt-custom-testimonial-root').forEach((container) => {
      const slider = new TestimonialSlider(container);
      if (slider.swiper) {
        this.sliders.push(slider);
      }
    });
  }

  initModals() {
    document.querySelectorAll('.pxt-custom-open-modal-btn').forEach((btn) => {
      this.modals.push(new TestimonialFormModal(btn));
    });
  }

  initFormTabs() {
    document.querySelectorAll('.pxt-custom-form-wrapper').forEach((wrapper) => {
      if (wrapper.querySelector('.pxt-form-card')) {
        const tabs = new FormTabs(wrapper);
        this.formTabs.push(tabs);
      }
    });
  }

  initForms() {
    document.querySelectorAll('.plugixa-testimonial-form').forEach((form) => {
      const instance = new TestimonialForm(form);
      // Wire up FormTabs reference
      const wrapper = form.closest('.pxt-custom-form-wrapper');
      const tabsInstance = this.formTabs.find((t) => t.wrapper === wrapper);
      if (tabsInstance) {
        instance.formTabs = tabsInstance;
        tabsInstance.validator = instance.validator;
      }
      this.forms.push(instance);
    });
  }

  initStarRatingInputs() {
    document.querySelectorAll('.pxt-custom-star-input').forEach((container) => {
      this.starInputs.push(new StarRatingInput(container));
    });
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

  initVideoPlayers() {
    document.querySelectorAll('.pxt-custom-testimonial-root').forEach((container) => {
      if (container.querySelector('.pxt-video-thumb')) {
        this.videoPlayers.push(new VideoPlayer(container));
      }
    });
  }

  destroy() {
    this.sliders.forEach((slider) => slider.destroy());
    this.paginations.forEach((p) => p.destroy());
    this.sliders = [];
    this.forms = [];
    this.modals = [];
    this.starInputs = [];
    this.formTabs = [];
    this.paginations = [];
    this.categoryFilters = [];
    this.ratingFilters = [];
    this.videoPlayers = [];
  }
}

// Initialize on DOM ready
document.addEventListener('DOMContentLoaded', () => {
  window.PlugixaTestimonialApp = new TestimonialApp();
  window.PlugixaTestimonialApp.init();
});
