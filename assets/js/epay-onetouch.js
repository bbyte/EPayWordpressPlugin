/**
 * EPay OneTouch Payment Gateway - Frontend JavaScript
 *
 * Handles all frontend payment processing functionality including:
 * - Device ID generation and storage
 * - Payment form handling
 * - Token management
 * - Error handling and user feedback
 *
 * @package     EPay OneTouch Payment Gateway
 * @author      Nikola Kotarov
 * @version     2.0.0
 * @license     GPL-2.0+
 */

jQuery(function($) {
    'use strict';

    const EPayOneTouchHandler = {
        init: function() {
            this.handleTokenChange = this.handleTokenChange.bind(this);
            this.handleSavePaymentMethod = this.handleSavePaymentMethod.bind(this);
            this.onSubmit = this.onSubmit.bind(this);
            
            this.initDeviceId();

            const body = $(document.body);
            body.on('change', '.wc-epay_onetouch-payment-token', this.handleTokenChange);
            body.on('change', '#wc-epay_onetouch-new-payment-method', this.handleSavePaymentMethod);

            const forms = $('form.checkout, form#add_payment_method, form#order_review');
            forms.on('submit checkout_place_order_epay_onetouch', this.onSubmit);

            this.handleTokenChange();
        },

        initDeviceId: function() {
            this.getDeviceId()
                .then((deviceId) => {
                    if (!deviceId) {
                        return this.generateDeviceId()
                            .then((newDeviceId) => {
                                return this.storeDeviceId(newDeviceId)
                                    .then(() => newDeviceId);
                            });
                    }
                    return deviceId;
                })
                .then((deviceId) => {
                    $('#epay_device_id').val(deviceId);
                })
                .catch((error) => {
                    console.error('Error initializing device ID:', error);
                    this.handleError(error);
                });
        },

        storeDeviceId: function(deviceId) {
            return new Promise((resolve, reject) => {
                if (!deviceId) {
                    reject(new Error('Device ID is required'));
                    return;
                }

                try {
                    if (this.isStorageAvailable('localStorage')) {
                        localStorage.setItem('epay_device_id', deviceId);
                        resolve();
                        return;
                    }
                    if (this.isStorageAvailable('sessionStorage')) {
                        sessionStorage.setItem('epay_device_id', deviceId);
                        resolve();
                        return;
                    }
                    reject(new Error('No storage mechanism available'));
                } catch (error) {
                    console.error('Storage error:', error);
                    reject(error);
                }
            });
        },

        isStorageAvailable: function(type) {
            try {
                const storage = window[type];
                const x = '__storage_test__';
                storage.setItem(x, x);
                storage.removeItem(x);
                return true;
            } catch (e) {
                return false;
            }
        },

        generateDeviceId: function() {
            return new Promise((resolve, reject) => {
                try {
                    if (window.crypto && window.crypto.randomUUID) {
                        resolve(window.crypto.randomUUID());
                        return;
                    }
                    
                    if (window.crypto && window.crypto.getRandomValues) {
                        const array = new Uint8Array(16);
                        window.crypto.getRandomValues(array);
                        
                        array[6] = (array[6] & 0x0f) | 0x40;
                        array[8] = (array[8] & 0x3f) | 0x80;
                        
                        const hexArray = Array.from(array);
                        const uuid = [
                            hexArray.slice(0, 4),
                            hexArray.slice(4, 6),
                            hexArray.slice(6, 8),
                            hexArray.slice(8, 10),
                            hexArray.slice(10)
                        ].map(chunk => 
                            chunk.map(b => b.toString(16).padStart(2, '0')).join('')
                        ).join('-');
                        
                        resolve(uuid);
                        return;
                    }
                    
                    reject(new Error('Cryptographic API not available'));
                } catch (error) {
                    console.error('Error generating device ID:', error);
                    reject(error);
                }
            });
        },

        getDeviceId: function() {
            return new Promise((resolve, reject) => {
                try {
                    let deviceId = null;
                    
                    if (this.isStorageAvailable('localStorage')) {
                        deviceId = localStorage.getItem('epay_device_id');
                    }
                    
                    if (!deviceId && this.isStorageAvailable('sessionStorage')) {
                        deviceId = sessionStorage.getItem('epay_device_id');
                    }
                    
                    if (deviceId && this.isValidUUID(deviceId)) {
                        resolve(deviceId);
                        return;
                    }
                    
                    resolve(null);
                } catch (error) {
                    console.error('Error retrieving device ID:', error);
                    reject(error);
                }
            });
        },

        isValidUUID: function(uuid) {
            const uuidRegex = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
            return uuidRegex.test(uuid);
        },

        handleTokenChange: function() {
            const selectedToken = $('.wc-epay_onetouch-payment-token:checked').val();
            const savePaymentMethodCheckbox = $('#wc-epay_onetouch-new-payment-method');
            const savePaymentMethodContainer = savePaymentMethodCheckbox.closest('p');
            
            if (selectedToken === 'new') {
                $('.epay-device-fields').show();
                savePaymentMethodContainer.show();
                savePaymentMethodCheckbox.prop('checked', true);
            } else {
                $('.epay-device-fields').hide();
                if (!$('.wc-epay_onetouch-payment-token').length) {
                    savePaymentMethodContainer.show();
                    savePaymentMethodCheckbox.prop('checked', true);
                } else {
                    savePaymentMethodContainer.hide();
                    savePaymentMethodCheckbox.prop('checked', false);
                }
            }
        },

        handleSavePaymentMethod: function() {
            const isNewPaymentMethod = $('.wc-epay_onetouch-payment-token:checked').val() === 'new';
            $('#wc-epay_onetouch-new-payment-method-container').toggle(isNewPaymentMethod);
        },

        onSubmit: function() {
            const selectedToken = $('.wc-epay_onetouch-payment-token:checked').val();
            if (!selectedToken) {
                this.handleError(new Error('Please select a payment method'));
                return false;
            }
            return true;
        },

        handleError: function(error) {
            console.error('EPay OneTouch Error:', error);
            if (window.wc && window.wc.notices) {
                window.wc.notices.add({
                    type: 'error',
                    content: 'An error occurred processing your payment. Please try again.'
                });
            }
        }
    };

    // Initialize handler
    EPayOneTouchHandler.init();
});
