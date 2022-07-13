jQuery(function ($) {
  $loginedInButton = $(".divit-login-button");
  $popup = $(".divit-login-popup");

  $loginedInButton.click(function () {
    $popup.toggle();
  });
});
