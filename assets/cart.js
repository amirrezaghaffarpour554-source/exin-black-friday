(function () {
    'use strict';
    function update() {
        const data = window.wp?.data?.select('wc/store/cart')?.getCartData()?.extensions?.['exin-bf'];
        if (!data) return;
        const host = document.querySelector('.wc-block-cart,.wc-block-checkout');
        if (!host) return;
        let box = document.getElementById('exin-bf-cart-summary');
        if (!box) { box = document.createElement('div'); box.id = 'exin-bf-cart-summary'; box.setAttribute('role', 'status'); box.setAttribute('aria-live', 'polite'); host.prepend(box); }
        const text = [data.price_change_message, /[1-9]/.test(data.savings || '') ? data.savings_label : '', data.cashback_message].filter(Boolean).join(' · ');
        if (box.textContent !== text) box.textContent = text;
    }
    if (window.wp?.data) window.wp.data.subscribe(update);
    document.addEventListener('DOMContentLoaded', update);
    update();
}());
