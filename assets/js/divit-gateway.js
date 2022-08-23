jQuery(function ($) {
  // utils

  var REGION_MAP = {
    "new territories": "NEW TERRITORIES",
    "hong kong island": "HONG KONG",
    kowloon: "KOWLOON",
  };

  function getRegion(region) {
    return REGION_MAP[region] || "";
  }

  function num(value) {
    return Math.round(value);
  }

  function joinAddress(arr, sep) {
    return arr.filter((a) => !!a && !!a.trim()).join(sep);
  }

  function serializeFormData($form) {
    return $form.serializeArray().reduce(function (obj, item) {
      obj[item.name] = item.value;
      return obj;
    }, {});
  }

  // login button

  var $loginedInButton = $(".divit-login-button");
  var $popup = $(".divit-login-popup").removeClass("active").hide();

  // click button toggle popup
  $loginedInButton.click(function () {
    if ($popup.hasClass("active")) {
      $popup.removeClass("active").hide();
    } else {
      $popup.addClass("active").show();
    }
  });

  // click outside of popup close popup
  $(document).on("click", function (e) {
    if (
      !($(e.target).closest($popup).length > 0) &&
      !($(e.target).closest($loginedInButton).length > 0)
    ) {
      if ($popup.hasClass("active")) {
        $popup.removeClass("active").hide();
      }
    }
  });

  // checkout

  var autoFillDone = false;
  function autoFillAddresses(d) {
    // if (!wcdivit.autoFillAddressEnabled) return;
    if (autoFillDone) return;

    var profile = d.profile;

    var $billingAddress1 = $("#billing_address_1");
    var $billingAddress2 = $("#billing_address_2");
    var $billingCity = $("#billing_city");
    var $billingState = $("#billing_state");

    var isEmptyBillingForm =
      $billingAddress1.val() === "" &&
      $billingAddress2.val() === "" &&
      $billingCity.val() === "";
    if (isEmptyBillingForm) {
      $billingAddress1.val(profile.floor);
      $billingAddress2.val(
        joinAddress([profile.building, profile.street], ", ")
      );
      $billingCity.val(profile.district);
      $billingState.val(getRegion(profile.region)).change();
    }

    var $shippingCheckbox = $("#ship-to-different-address-checkbox");
    var $shippingAddress1 = $("#shipping_address_1");
    var $shippingAddress2 = $("#shipping_address_2");
    var $shippingCity = $("#shipping_city");
    var $shippingState = $("#shipping_state");

    var deliveryAddresses = (profile.addresses || []).filter(
      (a) => a.addressType === "delivery"
    );
    var isEmptyShippingForm =
      $shippingAddress1.val() === "" &&
      $shippingAddress2.val() === "" &&
      $shippingCity.val() === "";
    if (
      isEmptyBillingForm &&
      isEmptyShippingForm &&
      deliveryAddresses.length > 0
    ) {
      var address = profile.addresses[0];
      if (!$shippingCheckbox.prop("checked")) {
        $shippingCheckbox.click();
      }
      $shippingAddress1.val(address.address1);
      $shippingAddress2.val(address.address2);
      $shippingCity.val(address.district);
      $shippingState.val(getRegion(address.region)).change();
    }

    autoFillDone = true;
  }

  function initPayByMilesOption(d) {
    var orderTotal = d.orderTotal;
    var buyRate = d.buyRate;
    var isLoggedIn = d.isLoggedIn;
    var userMilesBalance = isLoggedIn ? d.profile.milesBalance.amount / 100 : 0;
    var maxMilesToBurn = Math.min(orderTotal / buyRate, userMilesBalance);

    var $milesSlider = $("#divit_checkout_miles_slider");
    var $milesInput = $("#divit_checkout_miles_input");
    var $cashInput = $("#divit_checkout_cash_input");

    function updateServer() {
      var method = $("[class*=divit_radio_].checked").data("radio-id");

      $.ajax({
        method: "POST",
        url: wcdivit.ajaxUrl,
        data: {
          action: "divit_set_checkout_method",
          checkout_method: method,
          checkout_meta: {
            miles: $milesInput.val(),
            cash: $cashInput.val(),
          },
        },
      });
    }

    function updateMilesSlider(miles, cash) {
      if (cash > 0) {
        var fiat = orderTotal - cash;
        miles = fiat / buyRate;
      }
      $milesSlider.val(num(miles)).change();
    }

    function updateMiles(miles) {
      var fiat = miles * buyRate;
      var cash = orderTotal - fiat;
      $milesInput.val(num(miles));
      $cashInput.val(num(cash));
    }

    function updateCash(cash) {
      var fiat = orderTotal - cash;
      var miles = fiat / buyRate;
      $milesInput.val(num(miles));
      $cashInput.val(num(cash));
    }

    // choose different divit method
    $(".divit_radio").on("click", function () {
      var method = $(this).data("radio-id");
      $("[class*=divit_checkout_]").removeClass("checked");
      $("[class*=divit_radio_]").removeClass("checked");
      $(".divit_checkout_" + method).addClass("checked");
      $(".divit_radio_" + method).addClass("checked");
      updateServer();
    });

    // miles desc
    $("#divit_checkout_miles_base").text(parseInt(1 / buyRate, 10) + " = $1");

    // miles input change
    $milesInput.on("input", function () {});

    $milesInput.on("change", function () {
      var miles = Math.max(0, Math.min(userMilesBalance, Number(this.value)));
      updateMiles(miles);
      updateMilesSlider(miles);
      updateServer();
    });

    // cash input change
    $cashInput.on("input", function () {});

    $cashInput.on("change", function () {
      var fiat = orderTotal - userMilesBalance * buyRate;
      var cash = Math.max(fiat, Math.min(orderTotal, Number(this.value)));
      updateCash(cash);
      updateMilesSlider(undefined, cash);
      updateServer();
    });

    // miles slider
    $milesSlider
      .rangeslider({
        polyfill: false,
        rangeClass: "divit_rangeslider rangeslider",
        onInit: function () {},
        onSlide: function (position, value) {},
        onSlideEnd: function (position, value) {
          updateServer();
        },
      })
      .on("input", function () {
        updateMiles(this.value);
      });

    updateMiles(0);
    updateMilesSlider(0);
    $milesSlider.attr({ max: maxMilesToBurn });
    $milesSlider.rangeslider("update", true);

    // show content
    $(".divit_checkout_spend_loading").hide();
    $(".divit_checkout_spend_container").show();
  }

  function handleErrorInit() {
    $(".divit_checkout_spend").hide();
  }

  function init() {
    $.ajax({
      method: "POST",
      url: wcdivit.ajaxUrl,
      data: {
        action: "divit_get_checkout_data",
      },
      success: function (data) {
        if (data.success) {
          initPayByMilesOption(data.data);
          autoFillAddresses(data.data);
        } else {
          handleErrorInit();
          console.log("init error", data.data);
        }
      },
      error: function (err) {
        console.log("init error", err);
      },
    });
  }

  // only render when payment method applied
  if ($(".payment_method_divit_desc").length > 0) {
    if ($(document.body).hasClass("woocommerce-checkout")) {
      $(document.body).on("updated_checkout", init);
    } else {
      init();
    }
  }

  function getVariations(formEl) {
    var attrs = Array.from(formEl.querySelectorAll("[name^='attribute_']"));
    return attrs.map(function (element) {
      return { value: element.value, name: element.name };
    });
  }

  var expressCheckout = function (formData) {
    var formEl = document.querySelector("form.cart");
    var isGroupedProduct = formEl.classList.contains("grouped_form");
    var isVariantProduct = formEl.classList.contains("variations_form");
    var products = [];

    if (!isGroupedProduct) {
      products = [
        {
          id: document.querySelector('[name="add-to-cart"]').value,
          quantity: document.querySelector('[name="quantity"]').value,
          variations: isVariantProduct ? getVariations(formEl) : [],
        },
      ];
    } else {
      // TODO: group products
    }

    var submitData = formData;
    submitData.products = products;

    $.ajax({
      method: "POST",
      url: wcdivit.ajaxUrl,
      data: {
        action: "divit_express_checkout",
        data: submitData,
      },
      success: function (data) {
        console.log(data);
        if (data.success) {
          window.location.href = data.data.redirectURL;
        } else {
          window.location.reload();
        }
      },
      error: function (err) {
        console.log("express checkout error", err);
        window.location.reload();
      },
    });
  };

  $("#place_express_order").on("click", function (e) {
    e.preventDefault();
    var data = serializeFormData($("#divit_express_checkout_form"));
    console.log(data);
    expressCheckout(data || {});
  });
});
