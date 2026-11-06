const settings = window.wc.wcSettings.getSetting("divit_data", {});

const label = window.wp.element.createElement(
  "span",
  { style: { display: "inline-flex", alignItems: "center", gap: "8px" } },
  window.wp.htmlEntities.decodeEntities(settings.title),
  window.wp.element.createElement("img", {
    id: "divit_checkout_icon",
    src: settings.icon,
    style: { height: "24px", verticalAlign: "middle" },
  }),
);

// Live cart total from Checkout Block props; falls back to the static total.
const formatTotal = (billing) => {
  const cartTotal = billing && billing.cartTotal;
  const currency = billing && billing.currency;
  if (!cartTotal || !currency || cartTotal.value === undefined) {
    return window.wp.htmlEntities.decodeEntities(settings.total);
  }
  const minorUnit = Number(currency.minorUnit) || 0;
  const fixed = (Number(cartTotal.value) / Math.pow(10, minorUnit)).toFixed(minorUnit);
  const parts = fixed.split(".");
  parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, currency.thousandSeparator || ",");
  const amount = parts.join(currency.decimalSeparator || ".");
  return window.wp.htmlEntities.decodeEntities(
    (currency.prefix || "") + amount + (currency.suffix || ""),
  );
};

const Content = (props) => {
  window.wcdivit && window.wcdivit.appendFpsGuide();
  return window.wp.element.createElement(
    "div",
    { className: "payment_method_divit_desc" },
    window.wp.element.createElement(
      "div",
      { className: "divit_checkout_block divit_checkout_paynow checked" },
      window.wp.element.createElement("div", {
        className: "divit_checkout_block_overlay",
        "data-method-id": "paynow",
      }),
      window.wp.element.createElement(
        "div",
        { className: "divit_checkout_block_content" },
        window.wp.element.createElement(
          "div",
          { className: "divit_checkout_block_title" },
          window.wp.htmlEntities.decodeEntities(
            settings.pay_now_text || "pay now",
          ),
        ),
      ),
      window.wp.element.createElement(
        "div",
        { className: "divit_checkout_block_right" },
        window.wp.element.createElement(
          "div",
          { className: "divit_checkout_paynow_total" },
          formatTotal(props && props.billing),
        ),
      ),
    ),
  );
};
const ariaLabel = window.wp.htmlEntities.decodeEntities(settings.title);

const Block_Gateway = {
  name: "divit",
  label: label,
  content: Object(window.wp.element.createElement)(Content, null),
  edit: Object(window.wp.element.createElement)(Content, null),
  canMakePayment: () => true,
  ariaLabel: ariaLabel,
  supports: {
    features: settings.supports,
  },
};
window.wc.wcBlocksRegistry.registerPaymentMethod(Block_Gateway);
