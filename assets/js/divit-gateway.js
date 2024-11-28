jQuery(function ($) {
  // add overlay to html
  $loadingOverlay = $("<div></div>")
    .addClass("divit_loading_overlay")
    .html(
      '<div class="divit_loading_overlay_content">' +
        '<img src="https://static.divit.com.hk/icons/spinner.svg" width="40px" height="40px" />' +
        "</div>"
    )
    .appendTo(document.body);

  // patch login, logout redirect url
  function patchRedirectUrl() {
    var loginAnchor = $(".divit_redirect_url");
    loginAnchor.each(function () {
      var url = $(this).attr("href");
      var newUrl = new URL(url);
      newUrl.searchParams.set("redirect", window.location.href);
      $(this).attr("href", newUrl.href);
    });
  }
  patchRedirectUrl();
  $(document.body).on("updated_checkout", patchRedirectUrl);

  // fps guide
  var $fpsGuideButton = null;
  function appendFpsGuide() {
    var url = wcdivit.fpsGuideURL;
    if (!url) return;

    if ($fpsGuideButton) {
      $fpsGuideButton.remove();
    }

    var $gatewayIcon = $("#divit_checkout_icon");
    $fpsGuideButton = $(
      `<div class="divit_checkout_fps_guide_button">
        <svg viewBox="0 0 920 1000" fill="#D56A54" height="18px" width="18px" ><path d="M454 40c126.667-1.333 235.333 42 326 130s137.333 195.333 140 322c1.333 126.667-42.333 235.667-131 327S592.667 957.333 466 960c-126.667 1.333-235.667-42.333-327-131S1.333 632.667 0 506c-2.667-126.667 40.667-235.667 130-327S327.333 41.333 454 40m52 152c-28 0-49.667 8-65 24-15.333 16-23 32.667-23 50-1.333 18.667 3.667 33.333 15 44 11.333 10.667 27.667 16 49 16 25.333 0 45.667-7.333 61-22 15.333-14.667 23-32.667 23-54 0-38.667-20-58-60-58M386 786c20 0 48-8.667 84-26s71.333-43.333 106-78l-18-24c-32 24-56 36-72 36-9.333 0-10.667-12.667-4-38l42-160c17.333-64 10-96-22-96-20 0-49.667 9.667-89 29s-77.667 44.333-115 75l16 26c34.667-22.667 59.333-34 74-34 8 0 8 11.333 0 34l-36 152c-17.333 69.333-6 104 34 104" /></svg>
      </div>`
    ).insertAfter($gatewayIcon);
    $fpsGuideButton.on("click", function () {
      window.open(url, "__blank");
    });
  }
  appendFpsGuide();
  window.wcdivit && (window.wcdivit.appendFpsGuide = appendFpsGuide);
  $(document.body).on("updated_checkout", appendFpsGuide);

  function showLoading() {
    $loadingOverlay.show();
  }

  function hideLoading() {
    $loadingOverlay.hide();
  }

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

  function num2(value) {
    const places = 10 ** 2;
    return Math.round(value * places) / places;
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

  function showNotice(html) {
    var $noticeWrapper = $(".woocommerce-notices-wrapper");
    if ($noticeWrapper.length > 0) {
      $noticeWrapper.html(html);
      var elY = $noticeWrapper.get(0).getBoundingClientRect().top;
      var scrollY = elY + window.pageYOffset - 50;
      window.scrollTo({ top: scrollY, behavior: "smooth" });
    }
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
    if (isEmptyBillingForm && profile) {
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

    var deliveryAddresses = ((profile && profile.addresses) || []).filter(
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
      var address = deliveryAddresses[0];
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
      var method = $(
        "[class*=divit_checkout_].checked .divit_checkout_block_overlay"
      ).data("method-id");

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
      $cashInput.val(num2(cash));
    }

    function updateCash(cash) {
      var fiat = orderTotal - cash;
      var miles = fiat / buyRate;
      $milesInput.val(num(miles));
      $cashInput.val(num(cash));
    }

    // choose different divit method
    $(".divit_checkout_block_overlay").on("click", function () {
      var method = $(this).data("method-id");
      $("[class*=divit_checkout_]")
        .removeClass("checked")
        .find(".divit_checkout_block_overlay")
        .show();
      $("[class*=divit_radio_]").removeClass("checked");
      $(".divit_checkout_" + method)
        .addClass("checked")
        .find(".divit_checkout_block_overlay")
        .hide();
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

    // initialize
    updateMiles(0);
    updateMilesSlider(0);
    $milesSlider.attr({ max: maxMilesToBurn });
    $milesSlider.rangeslider("update", true);

    // select previous checkout option if logged in
    if (isLoggedIn) {
      var paymentMethod = d.checkoutOption.method;
      var paymentMeta = d.checkoutOption.meta || {};
      if (paymentMethod === "spend" && paymentMeta.cash >= 0) {
        updateCash(parseInt(paymentMeta.cash, 10));
        updateMilesSlider(undefined, num2(parseFloat(paymentMeta.cash)));
      }
      $('[data-method-id="' + paymentMethod + '"]').click();
    }

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
    if (wcdivit.isCheckoutPage === "1") {
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

    showLoading();

    $.ajax({
      method: "POST",
      url: wcdivit.ajaxUrl,
      data: {
        action: "divit_express_checkout",
        data: submitData,
      },
      success: function (data) {
        hideLoading();
        if (data.success) {
          window.location.href = data.data.redirectURL;
        } else {
          if (data.data.messages) {
            showNotice(data.data.messages);
          } else {
            window.location.reload();
          }
        }
      },
      error: function (err) {
        hideLoading();
        console.log("express checkout error", err);
        window.location.reload();
      },
    });
  };

  $("#place_express_order").on("click", function (e) {
    e.preventDefault();
    var data = serializeFormData($("#divit_express_checkout_form"));
    expressCheckout(data || {});
  });
});
