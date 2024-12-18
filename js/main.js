jQuery(document).ready(function ($) {
    function updateTaxDisplay() {
        setTimeout(function () {
            $(".includes_tax").each(function () {
                var amount = $(this)
                    .find(".woocommerce-Price-amount.amount")
                    .text();
                $(this).text("includes " + amount + " Tax");
            });
        }, 100);
    }

    updateTaxDisplay();

    $(document.body).on(
        "updated_wc_div updated_cart_totals updated_shipping_method",
        function () {
            updateTaxDisplay();
        }
    );

    $(document).ajaxComplete(function (event, xhr, settings) {
        if (
            settings.url.indexOf("wc-ajax=update_order_review") !== -1 ||
            settings.url.indexOf("wc-ajax=update_cart") !== -1
        ) {
            updateTaxDisplay();
        }
    });
});

jQuery(document).ready(function ($) {
    console.log("did this execute?");
    $(".pricing-translator").prepend('<span class="from-text">From </span>');
});
jQuery(document).ready(function ($) {
    function hidePayPalForZAR() {
        let currencySymbol = $(".woocommerce-Price-currencySymbol")
            .text()
            .trim();
        console.log("Detected currency symbol:", currencySymbol); // Log the detected symbol

        if (currencySymbol.includes("R")) {
            // Use includes to handle multiple Rs
            $(".payment_method_ppcp-gateway").hide();
			$(".payment_method_payfast").hide();
            console.log("PayPal option hidden for ZAR.");
        } else {
            $(".payment_method_ppcp-gateway").show();
            console.log("PayPal option shown for non-ZAR currency.");
        }
    }

    // Initial check on page load
    hidePayPalForZAR();

    // Re-check after every AJAX completion with a small delay
    $(document).ajaxComplete(function () {
        setTimeout(hidePayPalForZAR, 500);
    });
});
