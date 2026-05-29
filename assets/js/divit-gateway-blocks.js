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

const Content = () => {
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
          window.wp.htmlEntities.decodeEntities(settings.total),
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
