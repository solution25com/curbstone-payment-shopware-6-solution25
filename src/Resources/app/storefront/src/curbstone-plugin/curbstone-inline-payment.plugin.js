import Plugin from 'src/plugin-system/plugin.class';

export default class CurbstoneInlinePaymentPlugin extends Plugin {
    init() {
        this.preauthStarted = false;
        this.wrapper          = this.el;
        this.iframeContainer  = this.el.querySelector('#curbstone-iframe-container');
        this.statusBox        = this.el.querySelector('#curbstone-inline-status');
        this.errorBox         = this.el.querySelector('#curbstone-inline-error');
        this.methodIdInput    = document.getElementById('curbstone-payment-method-id');
        this.mfkeypField      = document.getElementById('curbstone-mfkeyp');
        this.saveCardCheckbox = document.getElementById('curbstone-save-card');
        this.saveCardWrapper  = document.getElementById('curbstone-save-card-wrapper');

        this.savedCardRadios = document.querySelectorAll('.curbstone-saved-card-radio');
        this.payRadios       = document.querySelectorAll('input[name="paymentMethodId"]');

        this.statusField = document.getElementById('curbstone-pre-auth-status');
        this.tokenField  = document.getElementById('curbstone-pre-auth-token');

        this.preauthUrl        = this.el.dataset.curbstonePreauthUrl || null;
        this.curbstoneMethodId = this.methodIdInput ? this.methodIdInput.value : null;

        this.cardsGrid     = document.querySelector('.curbstone-saved-cards-grid');
        this.paginationEl  = document.querySelector('[data-curbstone-pagination]');
        this.cardsPerPage  = 5;
        this.savedCardTiles = [];

        if (this.cardsGrid) {
            const perPageAttr = parseInt(this.cardsGrid.dataset.curbstoneCardsPerPage || '5', 10);
            this.cardsPerPage  = Number.isNaN(perPageAttr) ? 5 : perPageAttr;
            this.savedCardTiles = Array.from(this.cardsGrid.querySelectorAll('.curbstone-card-tile--saved'));
        }

        if (!this.preauthUrl) {
            console.warn('[Curbstone] Missing preauth URL on wrapper dataset');
            return;
        }

        this.initPagination();
        this.registerEvents();
        this.handleInitialSelection();
    }

    showIframe() {
        if (!this.wrapper) return;

        this.wrapper.style.display = 'block';
        this.wrapper.setAttribute('aria-hidden', 'false');
    }

    hideIframe() {
        if (!this.wrapper) return;

        this.wrapper.style.display = 'none';
        this.wrapper.setAttribute('aria-hidden', 'true');
    }

    focusSavedCard(value) {
        if (!value || value === 'new') {
            return;
        }

        const selectedRadio = Array.from(this.savedCardRadios).find(
            (radio) => radio.value === value,
        );

        if (!selectedRadio || typeof selectedRadio.focus !== 'function') {
            return;
        }

        const focusRadio = () => {
            try {
                selectedRadio.focus({ preventScroll: true });
            } catch (error) {
                selectedRadio.focus();
            }
        };

        if (typeof window.requestAnimationFrame === 'function') {
            window.requestAnimationFrame(() => {
                window.requestAnimationFrame(focusRadio);
            });
        } else {
            window.setTimeout(focusRadio, 0);
        }
    }

    updateSaveCardVisibility() {
        if (!this.saveCardWrapper || !this.saveCardCheckbox) {
            return;
        }

        const selected = document.querySelector('input[name="curbstone_card_choice"]:checked');

        if (!selected) {
            this.saveCardWrapper.style.display = '';
            this.saveCardCheckbox.disabled = false;
            return;
        }

        if (selected.value === 'new') {
            this.saveCardWrapper.style.display = '';
            this.saveCardCheckbox.disabled = false;
        } else {
            this.saveCardWrapper.style.display = 'none';
            this.saveCardCheckbox.checked = false;
            this.saveCardCheckbox.disabled = true;
        }

        console.log('[Curbstone] Card choice changed', {
            value: selected.value,
            showSaveToggle: this.saveCardWrapper.style.display !== 'none',
        });
    }

    initPagination() {
        if (!this.cardsGrid || !this.paginationEl || !this.savedCardTiles.length) {
            if (this.paginationEl) {
                this.paginationEl.style.display = 'none';
            }
            return;
        }

        this.currentPage = 1;
        this.totalPages  = Math.ceil(this.savedCardTiles.length / this.cardsPerPage);

        this.prevBtn       = this.paginationEl.querySelector('[data-curbstone-page-prev]');
        this.nextBtn       = this.paginationEl.querySelector('[data-curbstone-page-next]');
        this.pageCurrentEl = this.paginationEl.querySelector('[data-curbstone-page-current]');
        this.pageTotalEl   = this.paginationEl.querySelector('[data-curbstone-page-total]');

        if (this.pageTotalEl) {
            this.pageTotalEl.textContent = String(this.totalPages);
        }

        if (this.totalPages <= 1) {
            this.paginationEl.style.display = 'none';
        } else {
            this.paginationEl.style.display = '';
        }

        if (this.prevBtn) {
            this.prevBtn.addEventListener('click', () => {
                this.goToPage(this.currentPage - 1);
            });
        }

        if (this.nextBtn) {
            this.nextBtn.addEventListener('click', () => {
                this.goToPage(this.currentPage + 1);
            });
        }

        this.goToPage(1);
    }

    goToPage(page) {
        if (!this.savedCardTiles.length) {
            return;
        }

        const clamped = Math.min(Math.max(page, 1), this.totalPages);
        this.currentPage = clamped;

        const startIndex = (this.currentPage - 1) * this.cardsPerPage;
        const endIndex   = startIndex + this.cardsPerPage;

        this.savedCardTiles.forEach((tile, index) => {
            if (index >= startIndex && index < endIndex) {
                tile.style.display = '';
            } else {
                tile.style.display = 'none';
            }
        });

        if (this.pageCurrentEl) {
            this.pageCurrentEl.textContent = String(this.currentPage);
        }

        if (this.prevBtn) {
            this.prevBtn.disabled = this.currentPage === 1;
        }

        if (this.nextBtn) {
            this.nextBtn.disabled = this.currentPage === this.totalPages;
        }
    }

    initInlinePreauth() {
        if (this.preauthStarted) {
            return;
        }
        this.preauthStarted = true;

        if (!this.statusBox || !this.errorBox || !this.iframeContainer) {
            console.warn('[Curbstone] Inline elements missing, cannot init preauth.');
            return;
        }

        this.statusBox.textContent = 'Loading card form…';
        this.errorBox.textContent  = '';
        this.iframeContainer.innerHTML = '';

        const saveCardValue = (this.saveCardCheckbox && this.saveCardCheckbox.checked) ? '1' : '0';
        const pathMatch = window.location.pathname.match(/\/account\/order\/edit\/([^/?#]+)/);
        const isAccountEdit = !!pathMatch;
        const orderId = isAccountEdit ? pathMatch[1] : '';
        const from = isAccountEdit ? 'account_edit' : 'checkout';
        const body = [
            'saveCard=' + encodeURIComponent(saveCardValue),
            'from=' + encodeURIComponent(from),
            'orderId=' + encodeURIComponent(orderId),
        ].join('&');

        fetch(this.preauthUrl, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            },
            credentials: 'same-origin',
            body: body,
        })
            .then((resp) => resp.json())
            .then((data) => {
                if (!data.success || !data.iframeUrl) {
                    this.errorBox.textContent = data.error || 'Could not start card verification';
                    this.statusBox.textContent = '';
                    return;
                }

                const iframe = document.createElement('iframe');
                iframe.src = data.iframeUrl;
                iframe.width = '100%';
                iframe.height = '480';
                iframe.frameBorder = '0';
                this.iframeContainer.appendChild(iframe);
                this.statusBox.textContent = '';
            })
            .catch((err) => {
                console.error('[Curbstone] Preauth error:', err);
                this.errorBox.textContent = 'HTTP error starting card form.';
                this.statusBox.textContent = '';
            });
    }

    registerEvents() {
        if (this.saveCardCheckbox) {
            this.saveCardCheckbox.addEventListener('change', () => {
                this.preauthStarted = false;
                this.initInlinePreauth();
            });
        } else {
            console.warn('[Curbstone] Save-card checkbox NOT found in DOM');
        }

        this.savedCardRadios.forEach((radio) => {
            radio.addEventListener('change', (event) => {
                const value = event.target.value;

                this.updateSaveCardVisibility();

                if (value !== 'new') {
                    if (this.mfkeypField) {
                        this.mfkeypField.value = value;
                    }

                    this.hideIframe();
                    this.focusSavedCard(value);

                    if (this.statusField) this.statusField.value = 'OK';
                    if (this.tokenField)  this.tokenField.value  = 'VAULTED';
                } else {
                    if (this.mfkeypField) {
                        this.mfkeypField.value = '';
                    }

                    this.showIframe();
                    this.preauthStarted = false;

                    if (this.statusField) this.statusField.value = 'pending';
                    if (this.tokenField)  this.tokenField.value  = '';

                    this.initInlinePreauth();
                }
            });
        });

        this.payRadios.forEach((radio) => {
            radio.addEventListener('change', (event) => {
                if (!this.curbstoneMethodId) {
                    return;
                }

                if (event.target.value === this.curbstoneMethodId) {
                    this.showIframe();

                    const selectedSaved = document.querySelector('.curbstone-saved-card-radio:checked');
                    if (!selectedSaved || selectedSaved.value === 'new') {
                        this.preauthStarted = false;
                        this.initInlinePreauth();
                    }

                    this.updateSaveCardVisibility();
                } else {
                    this.hideIframe();
                }
            });
        });
    }

    handleInitialSelection() {
        if (!this.curbstoneMethodId) {
            return;
        }

        const curbstoneRadio = Array.from(this.payRadios).find(
            (r) => r.checked && r.value === this.curbstoneMethodId,
        );

        if (curbstoneRadio) {
            this.showIframe();

            const selectedSaved = document.querySelector('.curbstone-saved-card-radio:checked');
            if (!selectedSaved || selectedSaved.value === 'new') {
                this.preauthStarted = false;
                this.initInlinePreauth();
            } else {
                this.focusSavedCard(selectedSaved.value);
            }
        } else {
            this.hideIframe();
        }

        this.updateSaveCardVisibility();
    }
}
