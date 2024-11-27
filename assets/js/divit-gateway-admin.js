jQuery(function ($) {
  // console.log("admin", wcdivit);

  /**
   * check installation status in admin page
   * if already success then do not need call anything.
   * if not, shows test connection button,
   * click the button then call api, after n seconds check again.
   */

  var installationStatus = wcdivit.installationStatus;

  var $installationStatusField = $("#installationStatusField");
  var $testConnectionButton = $(
    '<button id="webhook-test-button" class="components-button is-primary">test connection</button>'
  );

  if (installationStatus !== "success") {
    $installationStatusField.append($testConnectionButton);
  }

  function setInstallationStatus(status) {
    if (status === "success") {
      $testConnectionButton.remove();
    } else {
      $testConnectionButton.attr("disabled", false);
    }

    var text = status === "success" ? "success" : "webhook test not done";
    $("#installationStatusText").text(text);
  }

  function getInstallationStatus(callback) {
    $.ajax({
      method: "POST",
      url: wcdivit.ajaxUrl,
      data: {
        action: "divit_get_installation_status",
      },
      success: function (data) {
        var status = data.data.installationStatus;
        callback(status);
      },
    });
  }

  $testConnectionButton.on("click", function () {
    $testConnectionButton.attr("disabled", "true");
    $("#installationStatusText").text("testing...");

    // check status or testing after n seconds
    getInstallationStatus(function (status) {
      if (status === "success") {
        setInstallationStatus(status);
      } else {
        // check againg after status
        setTimeout(() => {
          getInstallationStatus(function (status) {
            setInstallationStatus(status);
          });
        }, 5 * 1000);
      }
    });
  });

  // check status again for the first time
  setTimeout(function () {
    setInstallationStatus(installationStatus);
  }, 2 * 1000);
});
