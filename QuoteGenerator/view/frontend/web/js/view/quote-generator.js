define([
    'uiComponent',
    'Magento_Checkout/js/model/quote',
    'mage/url',
    'jquery',
    'ko',
    'Magento_Ui/js/model/messageList'
], function (Component, quote, urlBuilder, $, ko, messageList) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Gelmar_QuoteGenerator/quote-generator'
        },

        isGenerating: false, // Add flag to prevent multiple requests

        initialize: function () {
            this._super();
            this.moveButton();
            this.bindButtonClick();
            console.log('QuoteGenerator component initialized');
            return this;
        },

        moveButton: function () {
            var self = this;
            $(document).ready(function () {
                var maxAttempts = 50;
                var attempts = 0;
                var checkExist = setInterval(function () {
                    attempts++;
                    var $button = $('.quote-generator-button');
                    var $agreementsBlock = $('.checkout-agreements-block');

                    if ($button.length && $agreementsBlock.length) {
                        $button.detach();
                        $agreementsBlock.after($button);
                        //console.log('Quote button moved successfully after .checkout-agreements-block');
                        clearInterval(checkExist);
                    } else if (attempts >= maxAttempts) {
                        //console.error('Failed to move button after ' + maxAttempts + ' attempts. Button:', $button.length, 'Agreements block:', $agreementsBlock.length);
                        clearInterval(checkExist);
                    }
                }, 200);
            });
        },

        bindButtonClick: function () {
            var self = this;
            $(document).on('click', '.quote-generator-button', function (e) {
                e.preventDefault();
                e.stopPropagation(); // Prevent event bubbling
                //console.log('Generate Quote button clicked');
                self.generateQuote();
            });
        },

        generateQuote: function () {
            // Prevent multiple simultaneous requests
            if (this.isGenerating) {
                //console.log('Quote generation already in progress, ignoring click');
                return;
            }

            var quoteId = quote.getQuoteId();
            if (!quoteId) {
                //console.error('No quote ID available');
                alert('Error: No quote available. Please ensure your cart is not empty.');
                return;
            }

            // Check if click-n-collect is selected and get store info
            var shippingMethod = this.getSelectedShippingMethod();
            var requestData = { quote_id: quoteId };
            
            //console.log('Selected shipping method:', shippingMethod);
            
            // If click-n-collect is selected, get the selected store
            if (shippingMethod === 'flatrate_flatrate') {
                var selectedStore = this.getSelectedStore();
                if (!selectedStore) {
                    alert('Please select a store for Click-n-Collect.');
                    return;
                }
                requestData.selected_store = selectedStore;
                //console.log('Selected store for click-n-collect:', selectedStore);
            }

            this.isGenerating = true;
            //console.log('Initiating AJAX request for quote ID:', quoteId);
            
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
                    self.isGenerating = false; // Reset flag
                    //console.log('AJAX response received. Status:', status, 'Content-Type:', xhr.getResponseHeader('Content-Type'));
                    
                    var contentType = xhr.getResponseHeader('Content-Type') || '';
                    
                    if (contentType.includes('application/pdf')) {
                        self.handlePdfResponse(response, xhr, quoteId);
                    } else if (contentType.includes('application/json')) {
                        self.handleJsonResponse(response);
                    } else {
                        //console.error('Unexpected Content-Type:', contentType);
                        alert('Unexpected server response. Please refresh and try again.');
                    }
                },
                error: function (xhr, status, error) {
                    self.isGenerating = false; // Reset flag
                    //console.error('AJAX error:', status, error);
                    self.handleErrorResponse(xhr);
                }
            });
        },

        getSelectedShippingMethod: function () {
            var selectedMethod = '';
            $('input[name*="shipping_method"]:checked, input[name*="ko_unique"]:checked').each(function() {
                selectedMethod = $(this).val();
                return false; // Break the loop
            });
            return selectedMethod;
        },

        getSelectedStore: function () {
            // Try multiple possible selectors for the store dropdown
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
                    //console.log('Found store dropdown with selector:', storeSelectors[i], 'Value:', selectedStore);
                    break;
                }
            }
            
            if (!selectedStore) {
                //console.log('No store selected or dropdown not found. Tried selectors:', storeSelectors);
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
                
                // Append to body, click, then remove to ensure single download
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                
                // Clean up the object URL
                setTimeout(function() {
                    window.URL.revokeObjectURL(link.href);
                }, 100);
                
                //console.log('PDF download initiated:', filename);
            } catch (e) {
                //console.error('Error handling PDF response:', e);
                alert('Error downloading quote. Please ensure all required fields are filled in and try again.');
            }
        },

        handleJsonResponse: function (response) {
            var reader = new FileReader();
            reader.onload = function () {
                try {
                    var json = JSON.parse(reader.result);
                    if (json.error && json.error.includes('signed in')) {
                        //console.log('User not signed in, redirecting to login:', json.redirect);
                        messageList.addErrorMessage({ message: 'You must be signed in to generate a quote.' });
                        setTimeout(function() {
                            window.location.href = json.redirect || urlBuilder.build('customer/account/login');
                        }, 3000);
                    } else {
                        //console.error('Error generating quote:', json.error || 'Unknown error');
                        alert('Error generating quote: ' + (json.error || 'Please try again.'));
                    }
                } catch (e) {
                    //console.error('Failed to parse JSON:', reader.result);
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
                            //console.log('User not signed in (error handler), redirecting to login:', json.redirect);
                            messageList.addErrorMessage({ message: 'You must be signed in to generate a quote.' });
                            setTimeout(function() {
                                window.location.href = json.redirect || urlBuilder.build('customer/account/login');
                            }, 2000);
                        } else {
                            alert('Error generating quote: ' + (json.error || 'Please try again.'));
                        }
                    } catch (e) {
                        //console.error('Failed to parse error response:', reader.result);
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