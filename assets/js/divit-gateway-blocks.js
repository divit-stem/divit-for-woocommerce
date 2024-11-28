const settings = window.wc.wcSettings.getSetting("divit_data", {});

const label = window.wp.element.createElement("img", {
  id: "divit_checkout_icon",
  src: settings.icon,
});
const Content = () => {
  window.wcdivit && window.wcdivit.appendFpsGuide();
  return window.wp.htmlEntities.decodeEntities(settings.description || "");
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
