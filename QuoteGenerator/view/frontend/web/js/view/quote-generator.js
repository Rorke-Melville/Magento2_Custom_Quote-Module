define([
    'uiComponent',
    'Magento_Checkout/js/model/quote',
    'mage/url',
    'jquery',
    'ko',
    'Magento_Ui/js/model/messageList',
    'Magento_Customer/js/customer-data'
], function (Component, quote, urlBuilder, $, ko, messageList, customerData) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Gelmar_QuoteGenerator/quote-generator'
        },

        isGenerating: false,
        isValidating: false,
        customerData: customerData,

        initialize: function () {
            this._super();
            this.moveButton();
            this.bindButtonClick();
            console.log('QuoteGenerator component initialized');
            return this;
        },

        isCustomerLoggedIn: function () {
            var customer = this.customerData.get('customer')();
            return !!customer.firstname;
        },

        moveButton: function () {
            var self = this;
            
            function positionButton() {
                var $button = $('.quote-generator-button');
                var $agreementsBlock = $('.checkout-agreements-block');
                
                if ($button.length && $agreementsBlock.length) {
                    // Only move if it's not already in the right place
                    if (!$agreementsBlock.next().hasClass('quote-generator-button')) {
                        $button.detach().insertAfter($agreementsBlock);
                    }
                    return true;
                }
                return false;
            }
            
            $(document).ready(function () {
                var attempts = 0;
                var maxAttempts = 50;
                
                var checkInterval = setInterval(function () {
                    attempts++;
                    
                    if (positionButton() || attempts >= maxAttempts) {
                        clearInterval(checkInterval);
                    }
                }, 200);
                
                // Simple fallback: re-check occasionally in case DOM changes
                setTimeout(function() {
                    var fallbackInterval = setInterval(function() {
                        positionButton();
                    }, 3000);
                    
                    // Stop fallback after 30 seconds
                    setTimeout(function() {
                        clearInterval(fallbackInterval);
                    }, 30000);
                }, 2000);
            });
        },

        bindButtonClick: function () {
            var self = this;
            var debounceTimeout = null;
            $(document).on('click', '.quote-generator-button', function (e) {
                e.preventDefault();
                e.stopPropagation();
                if (debounceTimeout) {
                    clearTimeout(debounceTimeout);
                }
                debounceTimeout = setTimeout(function () {
                    self.generateQuote();
                }, 200);
            });
        },

        generateQuote: function () {
            if (this.isGenerating || this.isValidating) {
                return;
            }

            if ($('#quote-warning-modal').length > 0) {
                return;
            }

            this.isValidating = true;

            // Check if the Terms & Conditions checkbox is checked
            var $agreementCheckbox = $('input[name="agreement[1]"]');
            var $label = $('label[for="agreement__1"]');

            // Remove only error message divs, not input elements or other content
            $('.checkout-agreement').find('div.mage-error').remove();

            if (!$agreementCheckbox.is(':checked')) {
                // Create new custom error message with unique ID
                var $errorMessage = $('<div id="custom-agreement[1]-error" class="mage-error">This is a required field.</div>');
                $label.after($errorMessage);
                $agreementCheckbox.addClass('mage-error').attr('aria-invalid', 'true').attr('aria-describedby', 'custom-agreement[1]-error');
                this.isValidating = false;
                return;
            } else {
                // Only clean up error state when checkbox is checked
                $agreementCheckbox.removeClass('mage-error').attr('aria-invalid', 'false').removeAttr('aria-describedby');
            }

            this.isValidating = false;

            if (!this.isCustomerLoggedIn()) {
                this.showSignInPopup();
                return;
            }

            var shippingMethod = this.getSelectedShippingMethod();

            if (shippingMethod !== 'flatrate_flatrate') {
                this.showDeliveryWarningPopup();
                return;
            }

            var selectedStore = this.getSelectedStore();
            if (!selectedStore) {
                this.showStoreSelectionPopup();
                return;
            }

            this.showCustomPopup();
        },

        showSignInPopup: function () {
            var self = this;
            var modalHtml = this.getModalHtml(
                'You must be signed in to generate a quote. Would you like to sign in now?',
                true // Has cancel/continue
            );
            
            $('body').append(modalHtml);
            
            this.openModal();
            
            $(document).on('click', '#quote-modal-ok', function(e) {
                e.preventDefault();
                e.stopPropagation();
                self.closeModal();
                setTimeout(function() {
                    window.location.href = urlBuilder.build('customer/account/login');
                }, 100);
            });
            
            this.bindModalEvents();
        },

        showDeliveryWarningPopup: function () {
            var self = this;
            var modalHtml = this.getModalHtml(
                'Quote is for in-store only. Please select click n collect to continue',
                false // Only OK
            );
            
            $('body').append(modalHtml);
            
            this.openModal();
            
            $(document).on('click', '#quote-modal-ok', function(e) {
                e.preventDefault();
                e.stopPropagation();
                self.closeModal();
            });
            
            this.bindModalEvents();
        },

        showStoreSelectionPopup: function () {
            var self = this;
            var modalHtml = this.getModalHtml(
                'Please select a store for Click-n-Collect.',
                false // Only OK
            );
            
            $('body').append(modalHtml);
            
            this.openModal();
            
            $(document).on('click', '#quote-modal-ok', function(e) {
                e.preventDefault();
                e.stopPropagation();
                self.closeModal();
            });
            
            this.bindModalEvents();
        },

        showCustomPopup: function () {
            var self = this;
            var modalHtml = this.getModalHtml(
                '<strong>Please note:</strong> Quotes are for in-store purchases only. Proceeding will make your current cart inactive, redirect you to the home page, and you will need to complete this order at your chosen store.',
                true // Has cancel/continue
            );
            
            $('body').append(modalHtml);
            
            this.openModal();
            
            $(document).on('click', '#quote-modal-ok', function(e) {
                e.preventDefault();
                e.stopPropagation();
                self.closeModal();
                setTimeout(function() {
                    self.proceedWithQuoteGeneration();
                }, 100);
            });
            
            this.bindModalEvents();
        },

        getModalHtml: function (message, hasCancel) {
            var buttonsHtml = hasCancel ? 
                '<button id="quote-modal-cancel">Cancel</button>' +
                '<button id="quote-modal-ok">Continue</button>' :
                '<button id="quote-modal-ok">OK</button>';
            
            return '<div id="quote-warning-modal">' +
                '<div class="modal-content">' +
                    '<div class="modal-header">' +
                        '<div class="icon-container">' +
                            '<svg width="46" height="46" viewBox="0 0 640 640" fill="#0099a8" stroke="none" xmlns="http://www.w3.org/2000/svg">' +
                                '<path d="M192 112L304 112L304 200C304 239.8 336.2 272 376 272L464 272L464 512C464 520.8 456.8 528 448 528L192 528C183.2 528 176 520.8 176 512L176 128C176 119.2 183.2 112 192 112zM352 131.9L444.1 224L376 224C362.7 224 352 213.3 352 200L352 131.9zM192 64C156.7 64 128 92.7 128 128L128 512C128 547.3 156.7 576 192 576L448 576C483.3 576 512 547.3 512 512L512 250.5C512 233.5 505.3 217.2 493.3 205.2L370.7 82.7C358.7 70.7 342.5 64 325.5 64L192 64zM248 320C234.7 320 224 330.7 224 344C224 357.3 234.7 368 248 368L392 368C405.3 368 416 357.3 416 344C416 330.7 405.3 320 392 320L248 320zM248 416C234.7 416 224 426.7 224 440C224 453.3 234.7 464 248 464L392 464C405.3 464 416 453.3 416 440C416 426.7 405.3 416 392 416L248 416z"/>' +
                            '</svg>' +
                        '</div>' +
                        '<div class="header-content">' +
                            '<h3>Quote Notice</h3>' +
                            '<p>Important information about your quote</p>' +
                        '</div>' +
                    '</div>' +
                    '<div class="modal-message">' +
                        '<p>' + message + '</p>' +
                    '</div>' +
                    '<div class="modal-buttons">' +
                        buttonsHtml +
                    '</div>' +
                '</div>' +
            '</div>';
        },

        openModal: function () {
            setTimeout(function() {
                $('#quote-warning-modal').css('opacity', '1');
                $('#quote-warning-modal .modal-content').css('transform', 'scale(1)');
            }, 10);
        },

        closeModal: function () {
            $(document).off('click', '#quote-modal-ok');
            $(document).off('click', '#quote-modal-cancel');
            $(document).off('mouseenter', '#quote-modal-cancel');
            $(document).off('mouseleave', '#quote-modal-cancel');
            $(document).off('mouseenter', '#quote-modal-ok');
            $(document).off('mouseleave', '#quote-modal-ok');
            $(document).off('click', '#quote-warning-modal');
            $(document).off('keydown.quoteModal');
            
            $('#quote-warning-modal').css('opacity', '0');
            $('#quote-warning-modal .modal-content').css('transform', 'scale(0.9)');
            
            setTimeout(function() {
                $('#quote-warning-modal').remove();
            }, 300);
        },

        bindModalEvents: function () {
            var self = this;
            
            $(document).on('click', '#quote-modal-cancel', function(e) {
                e.preventDefault();
                e.stopPropagation();
                self.closeModal();
            });
            
            $(document).on('mouseenter', '#quote-modal-cancel', function() {
                $(this).css('background', '#e5e7eb');
            });
            
            $(document).on('mouseleave', '#quote-modal-cancel', function() {
                $(this).css('background', '#f3f4f6');
            });
            
            $(document).on('mouseenter', '#quote-modal-ok', function() {
                $(this).css({
                    'transform': 'translateY(-1px)',
                    'box-shadow': '0 6px 16px rgba(0, 153, 168, 0.4)'
                });
            });
            
            $(document).on('mouseleave', '#quote-modal-ok', function() {
                $(this).css({
                    'transform': 'translateY(0)',
                    'box-shadow': '0 4px 12px rgba(0, 153, 168, 0.3)'
                });
            });
            
            $(document).on('click', '#quote-warning-modal', function(e) {
                if (e.target === this) {
                    self.closeModal();
                }
            });
            
            $(document).on('keydown.quoteModal', function(e) {
                if (e.key === 'Escape') {
                    self.closeModal();
                }
            });
        },

        proceedWithQuoteGeneration: function () {
            if (this.isGenerating) {
                return;
            }

            var quoteId = quote.getQuoteId();
            if (!quoteId) {
                alert('Error: No quote available. Please ensure your cart is not empty.');
                return;
            }

            var shippingMethod = this.getSelectedShippingMethod();
            var requestData = { quote_id: quoteId };
            
            if (shippingMethod === 'flatrate_flatrate') {
                var selectedStore = this.getSelectedStore();
                requestData.selected_store = selectedStore;
            }

            this.isGenerating = true;
            
            var url = urlBuilder.build('quote/cart/generatequote');
            var self = this;

            $.ajax({
                url: url,
                type: 'POST',
                data: requestData,
                xhrFields: {
                    responseType: 'blob'
                },
                cache: false,
                success: function (response, status, xhr) {
                    self.isGenerating = false;
                    var contentType = xhr.getResponseHeader('Content-Type') || '';
                    
                    if (contentType.includes('application/pdf')) {
                        self.handlePdfResponse(response, xhr, quoteId);
                    } else if (contentType.includes('application/json')) {
                        self.handleJsonResponse(response);
                    } else {
                        alert('Unexpected server response. Please refresh and try again.');
                    }
                },
                error: function (xhr, status, error) {
                    self.isGenerating = false;
                    self.handleErrorResponse(xhr);
                }
            });
        },

        getSelectedShippingMethod: function () {
            var selectedMethod = '';
            $('input[name*="shipping_method"]:checked, input[name*="ko_unique"]:checked').each(function() {
                selectedMethod = $(this).val();
                return false;
            });
            return selectedMethod;
        },

        getSelectedStore: function () {
            var storeSelectors = [
                '#osc_order_comment',
                'select[name="order_note"]',
                'select[id*="order_comment"]',
                'select[id*="store"]'
            ];
            
            var selectedStore = '';
            
            for (var i = 0; i < storeSelectors.length; i++) {
                var $dropdown = $(storeSelectors[i]);
                if ($dropdown.length && $dropdown.val() && $dropdown.val() !== '') {
                    selectedStore = $dropdown.val();
                    break;
                }
            }
            
            return selectedStore;
        },

        handlePdfResponse: function (response, xhr, quoteId) {
            try {
                var filename = xhr.getResponseHeader('Content-Disposition')?.match(/filename="(.+)"/)?.[1] || 'GelmarQuote_' + quoteId + '.pdf';
                var blob = new Blob([response], { type: 'application/pdf' });
                var link = document.createElement('a');
                link.href = window.URL.createObjectURL(blob);
                link.download = filename;
                
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                
                setTimeout(function() {
                    window.URL.revokeObjectURL(link.href);
                    window.location.href = 'https://dev.gelmar.co.za'; 
                }, 100);
            } catch (e) {
                alert('Error downloading quote. Please ensure all required fields are filled in and try again.');
            }
        },

        handleJsonResponse: function (response) {
            var reader = new FileReader();
            reader.onload = function () {
                try {
                    var json = JSON.parse(reader.result);
                    if (json.error && json.error.includes('signed in')) {
                        messageList.addErrorMessage({ message: 'You must be signed in to generate a quote.' });
                        setTimeout(function() {
                            window.location.href = json.redirect || urlBuilder.build('customer/account/login');
                        }, 3000);
                    } else {
                        alert('Error generating quote: ' + (json.error || 'Please try again.'));
                    }
                } catch (e) {
                    alert('Error generating quote. Please sign in and try again.');
                }
            };
            reader.readAsText(response);
        },

        handleErrorResponse: function (xhr) {
            if (xhr.response) {
                var reader = new FileReader();
                reader.onload = function () {
                    try {
                        var json = JSON.parse(reader.result);
                        if (json.error && json.error.includes('signed in')) {
                            messageList.addErrorMessage({ message: 'You must be signed in to generate a quote.' });
                            setTimeout(function() {
                                window.location.href = json.redirect || urlBuilder.build('customer/account/login');
                            }, 2000);
                        } else {
                            alert('Error generating quote: ' + (json.error || 'Please try again.'));
                        }
                    } catch (e) {
                        alert('Error generating quote. Please sign in and try again.');
                    }
                };
                reader.readAsText(xhr.response);
            } else {
                messageList.addErrorMessage({ message: 'You must be signed in to generate a quote.' });
                setTimeout(function() {
                    window.location.href = urlBuilder.build('customer/account/login');
                }, 2000);
            }
        }
    });
});