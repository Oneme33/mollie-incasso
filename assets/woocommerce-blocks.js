(function () {
    'use strict';
    const { createElement: el, useState, useEffect } = window.wp.element;
    const settings = window.wc.wcSettings.getSetting('mollie_incasso_data', {});
    const decode = window.wp.htmlEntities.decodeEntities;
    function Consent(props) {
        const [accepted, setAccepted] = useState(false);
        const onSetup = props.eventRegistration && props.eventRegistration.onPaymentSetup;
        const types = props.emitResponse && props.emitResponse.responseTypes;
        useEffect(function () {
            if (!onSetup || !types) return;
            return onSetup(function () {
                if (!accepted) return { type: types.ERROR, message: settings.consent_error };
                return { type: types.SUCCESS, meta: { paymentMethodData: { mi_consent: '1', mi_disclosure_hash: settings.hash } } };
            });
        }, [accepted, onSetup, types]);
        return el('div', { className: 'mi-woo-consent' },
            el('div', { style: { whiteSpace: 'pre-line', fontSize: '0.875em', lineHeight: '1.6' } }, decode(settings.disclosure || '')),
            el('label', { style: { display: 'flex', gap: '0.5rem', alignItems: 'flex-start', marginTop: '1rem' } },
                el('input', { type: 'checkbox', checked: accepted, required: true, onChange: function (event) { setAccepted(event.target.checked); } }),
                el('span', null, decode(settings.consent || settings.title || 'Mollie Incasso'))));
    }
    window.wc.wcBlocksRegistry.registerPaymentMethod({
        name: 'mollie_incasso', label: el('span', null, decode(settings.title || 'Mollie Incasso')),
        content: el(Consent), edit: el('span', null, decode(settings.title || 'Mollie Incasso')),
        canMakePayment: function () { return !!settings.available; },
        placeOrderButtonLabel: settings.button,
        supports: { features: ['products'] }
    });
}());
